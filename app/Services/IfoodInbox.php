<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class IfoodInbox
{
    private const STATES = ['PLC' => 'pending', 'CFM' => 'accepted', 'SPS' => 'preparing', 'RTP' => 'ready', 'DSP' => 'dispatched', 'CON' => 'completed', 'CAN' => 'cancelled'];

    private const PROGRESS = ['pending' => 0, 'accepted' => 1, 'preparing' => 2, 'ready' => 3, 'dispatched' => 4, 'completed' => 5, 'cancelled' => 6];

    public function __construct(private IfoodClient $client) {}

    public function sync(int $appId): array
    {
        return Cache::lock('ifood:inbox:'.$appId, 600)->get(function () use ($appId): array {
            $app = DB::table('ifood_apps')->where('id', $appId)->where('environment', 'test')->firstOrFail();
            $result = ['received' => 0, 'processed' => 0, 'failed' => 0];
            try {
                $connections = $this->reservePoll($app);
                if ($connections !== []) {
                    $events = $this->client->poll($app, array_keys($connections));
                    foreach ($events as $payload) {
                        try {
                            $result['received'] += $this->receive($app, $connections, $payload);
                        } catch (ValidationException $exception) {
                            $this->pollError($app, $exception->errors()['ifood'][0] ?? 'Evento inválido não confirmado.');
                            $result['failed']++;
                        }
                    }
                }
            } catch (Throwable $exception) {
                $this->pollError($app, $this->safeError($exception));
                $result['failed']++;
            }
            try {
                $unread = DB::table('ifood_events')->where('app_id', $app->id)->whereNull('acknowledged_at')->orderBy('id')->limit(2000)->get(['id', 'external_id']);
                if ($unread->isNotEmpty()) {
                    $this->currentApp($app);
                    $this->client->acknowledge($app, $unread->pluck('external_id')->all());
                    DB::table('ifood_events')->where('app_id', $app->id)->whereIn('id', $unread->pluck('id'))->update(['acknowledged_at' => now(), 'updated_at' => now()]);
                }
            } catch (Throwable $exception) {
                $this->pollError($app, $this->safeError($exception));
                $result['failed']++;
            }
            $events = DB::table('ifood_events')->where('app_id', $app->id)->whereIn('status', ['received', 'processing', 'error'])
                ->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
                ->orderBy('occurred_at')->orderBy('id')->limit(5)->get();
            foreach ($events as $event) {
                DB::table('ifood_events')->where('id', $event->id)->update(['status' => 'processing', 'attempts' => $event->attempts + 1, 'retry_at' => now()->addSeconds(30), 'updated_at' => now()]);
                try {
                    $this->process($app, $event);
                    $result['processed']++;
                } catch (Throwable $exception) {
                    DB::table('ifood_events')->where('id', $event->id)->update([
                        'status' => 'error', 'last_error' => $this->safeError($exception),
                        'retry_at' => now()->addSeconds(min(3600, 30 * (2 ** min(7, $event->attempts)))), 'updated_at' => now(),
                    ]);
                    $result['failed']++;
                }
            }

            return $result;
        }) ?: ['received' => 0, 'processed' => 0, 'failed' => 0];
    }

    private function reservePoll(object $app): array
    {
        return DB::transaction(function () use ($app): array {
            $current = $this->currentApp($app, true);
            if ($current->last_polled_at && Carbon::parse($current->last_polled_at)->gt(now()->subSeconds(30))) {
                return [];
            }
            $connections = DB::table('ifood_connections')->where('app_id', $app->id)->where('receiving_enabled', true)->where('status', 'verified')->get()->keyBy('merchant_id')->all();
            if (count($connections) > 100) {
                $this->fail('O piloto admite até 100 lojas de teste por aplicativo.');
            }
            if ($connections !== []) {
                DB::table('ifood_apps')->where('id', $app->id)->update(['last_polled_at' => now(), 'last_poll_error' => null]);
            }

            return $connections;
        });
    }

    private function receive(object $app, array $connections, mixed $payload): int
    {
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'id' => 'required|uuid', 'merchantId' => 'required|uuid', 'orderId' => 'nullable|uuid',
            'code' => 'required|string|max:80', 'createdAt' => 'required|string|max:50',
        ]);
        if ($validator->fails()) {
            $this->fail('Evento inválido não confirmado. Verifique o aplicativo no portal iFood.');
        }
        $data = $validator->validated();
        $merchant = strtolower($data['merchantId']);
        $connection = $connections[$merchant] ?? null;
        if (! $connection) {
            $this->fail('Evento de loja não vinculada ao recebimento. Nenhuma leitura foi confirmada para esse evento.');
        }
        $occurredAt = $this->date($data['createdAt']);

        return DB::transaction(function () use ($app, $data, $payload, $connection, $merchant, $occurredAt): int {
            $this->currentApp($app, true);
            $binding = DB::table('ifood_connections')->where('id', $connection->id)->lockForUpdate()->first();
            if (! $binding || ! $binding->receiving_enabled || $binding->merchant_id !== $merchant || $binding->tenant_id !== $connection->tenant_id) {
                $this->fail('Vínculo alterado durante o recebimento. O evento não foi confirmado.');
            }
            $existing = DB::table('ifood_events')->where('app_id', $app->id)->where('external_id', strtolower($data['id']))->first();
            if ($existing) {
                if ($existing->merchant_id !== $merchant || $existing->external_order_id !== (isset($data['orderId']) ? strtolower($data['orderId']) : null) || $existing->code !== $data['code']) {
                    $this->fail('Conflito de identidade em evento iFood. Confira o aplicativo antes de continuar.');
                }
                DB::table('ifood_events')->where('id', $existing->id)->update(['acknowledged_at' => null, 'updated_at' => now()]);

                return 0;
            }
            DB::table('ifood_events')->insert([
                'app_id' => $app->id, 'connection_id' => $binding->id, 'tenant_id' => $binding->tenant_id, 'store_id' => $binding->store_id,
                'external_id' => strtolower($data['id']), 'merchant_id' => $merchant, 'external_order_id' => isset($data['orderId']) ? strtolower($data['orderId']) : null,
                'code' => $data['code'], 'occurred_at' => $occurredAt, 'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return 1;
        });
    }

    private function process(object $app, object $event): void
    {
        $state = self::STATES[$event->code] ?? null;
        if (! $state) {
            DB::table('ifood_events')->where('id', $event->id)->update(['status' => 'ignored', 'retry_at' => null, 'last_error' => null, 'updated_at' => now()]);

            return;
        }
        if (! $event->external_order_id) {
            $this->fail('Evento de pedido sem identificador. Confira no portal iFood.');
        }
        $existing = DB::table('ifood_orders')->where('app_id', $app->id)->where('external_order_id', $event->external_order_id)->first();
        $normalized = $existing ? null : $this->normalize($this->client->order($app, $event->external_order_id), $event);
        DB::transaction(function () use ($app, $event, $state, $normalized): void {
            $this->currentApp($app, true);
            $connection = DB::table('ifood_connections')->where('id', $event->connection_id)->lockForUpdate()->first();
            if (! $connection || $connection->merchant_id !== $event->merchant_id || $connection->store_id !== $event->store_id || $connection->tenant_id !== $event->tenant_id) {
                $this->fail('Vínculo do evento indisponível. Solicite revisão ao superadmin.');
            }
            $order = DB::table('ifood_orders')->where('app_id', $app->id)->where('external_order_id', $event->external_order_id)->lockForUpdate()->first();
            if ($order && ($order->tenant_id !== $event->tenant_id || $order->store_id !== $event->store_id || $order->merchant_id !== $event->merchant_id)) {
                $this->fail('Pedido associado a outro vínculo. Importação bloqueada.');
            }
            if (! $order) {
                DB::table('ifood_orders')->insert($normalized + [
                    'app_id' => $app->id, 'tenant_id' => $event->tenant_id, 'store_id' => $event->store_id,
                    'merchant_id' => $event->merchant_id, 'external_order_id' => $event->external_order_id,
                    'status' => $state, 'last_event_at' => $event->occurred_at, 'created_at' => now(), 'updated_at' => now(),
                ]);
                Audit::record('ifood.order_received', ['external_order_id' => $event->external_order_id, 'store_id' => $event->store_id], $event->tenant_id);
            } elseif (Carbon::parse($event->occurred_at)->gte(Carbon::parse($order->last_event_at)) && self::PROGRESS[$state] >= self::PROGRESS[$order->status]) {
                DB::table('ifood_orders')->where('id', $order->id)->update(['status' => $state, 'last_event_at' => $event->occurred_at, 'updated_at' => now()]);
            }
            DB::table('ifood_events')->where('id', $event->id)->update(['status' => 'processed', 'retry_at' => null, 'last_error' => null, 'updated_at' => now()]);
        });
    }

    private function normalize(array $payload, object $event): array
    {
        $validator = Validator::make($payload, [
            'id' => 'required|uuid', 'merchant.id' => 'required|uuid', 'displayId' => 'required|string|max:80',
            'category' => 'required|in:FOOD', 'orderType' => 'required|in:DELIVERY,TAKEOUT', 'orderTiming' => 'required|in:IMMEDIATE,SCHEDULED',
            'createdAt' => 'required|string|max:50', 'preparationStartDateTime' => 'required_if:orderTiming,SCHEDULED|nullable|string|max:50',
            'customer.name' => 'required|string|max:255', 'customer.phone.number' => 'nullable|string|max:100', 'customer.phone.localizer' => 'nullable|string|max:80',
            'total.subTotal' => 'required|numeric|min:0', 'total.deliveryFee' => 'required|numeric|min:0', 'total.additionalFees' => 'required|numeric|min:0',
            'total.benefits' => 'required|numeric|min:0', 'total.orderAmount' => 'required|numeric|min:0',
            'payments.prepaid' => 'required|numeric|min:0', 'payments.pending' => 'required|numeric|min:0',
            'items' => 'required|array|min:1|max:500', 'items.*.name' => 'required|string|max:1000', 'items.*.quantity' => 'required|numeric|gt:0|max:100000',
            'items.*.unitPrice' => 'required|numeric|min:0', 'items.*.totalPrice' => 'required|numeric|min:0', 'items.*.observations' => 'nullable|string|max:1024',
            'items.*.options' => 'nullable|array|max:100',
        ]);
        if ($validator->fails()) {
            $this->fail('Formato de pedido ainda não suportado ou incompleto. Revisão necessária; o evento está preservado.');
        }
        if (($payload['isTest'] ?? $payload['test'] ?? false) !== true) {
            $this->fail('Pedido sem identificação de teste. Importação de pedidos reais bloqueada nesta versão.');
        }
        $data = $validator->validated();
        if (strtolower($data['id']) !== $event->external_order_id || strtolower($data['merchant']['id']) !== $event->merchant_id) {
            $this->fail('Pedido e loja não correspondem ao evento. Importação bloqueada.');
        }
        $items = [];
        foreach ($data['items'] as $item) {
            $items[] = ['name' => $item['name'], 'quantity' => (string) $item['quantity'], 'unit_cents' => $this->money($item['unitPrice']), 'total_cents' => $this->money($item['totalPrice']), 'observations' => $item['observations'] ?? '', 'options' => $this->options($item['options'] ?? [])];
        }
        $totals = array_map(fn ($value) => $this->money($value), $data['total']);
        $snapshot = ['items' => $items, 'totals' => $totals, 'phone' => $data['customer']['phone']['number'] ?? null, 'localizer' => $data['customer']['phone']['localizer'] ?? null];

        return [
            'display_id' => $data['displayId'], 'customer_name' => $data['customer']['name'], 'order_type' => $data['orderType'], 'timing' => $data['orderTiming'],
            'ordered_at' => $this->date($data['createdAt']), 'preparation_at' => ! empty($data['preparationStartDateTime']) ? $this->date($data['preparationStartDateTime']) : null,
            'total_cents' => $totals['orderAmount'], 'prepaid_cents' => $this->money($data['payments']['prepaid']), 'pending_cents' => $this->money($data['payments']['pending']),
            'snapshot' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
        ];
    }

    private function options(array $options, int $depth = 0): array
    {
        if ($depth > 3 || count($options) > 100) {
            $this->fail('Estrutura de adicionais excede o limite suportado. Evento preservado para revisão.');
        }
        $result = [];
        foreach ($options as $option) {
            $validator = Validator::make(is_array($option) ? $option : [], ['name' => 'required|string|max:1000', 'quantity' => 'required|numeric|gt:0|max:100000', 'customization' => 'nullable|array|max:100', 'customizations' => 'nullable|array|max:100']);
            if ($validator->fails()) {
                $this->fail('Adicional iFood inválido. Evento preservado para revisão.');
            }
            $data = $validator->validated();
            $result[] = ['name' => $data['name'], 'quantity' => (string) $data['quantity']];
            $result = array_merge($result, $this->options($data['customization'] ?? $data['customizations'] ?? [], $depth + 1));
        }

        return $result;
    }

    private function money(mixed $amount): int
    {
        if (! is_scalar($amount) || ! preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', (string) $amount)) {
            $this->fail('Valor monetário iFood inválido ou fora do limite suportado.');
        }
        $parts = explode('.', (string) $amount);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function date(string $value): string
    {
        try {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                $this->fail('Data de evento ou pedido inválida.');
            }

            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            $this->fail('Data de evento ou pedido inválida.');
        }
    }

    private function currentApp(object $snapshot, bool $lock = false): object
    {
        $app = DB::table('ifood_apps')->where('id', $snapshot->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ($app->revision !== $snapshot->revision || $app->environment !== 'test') {
            $this->fail('Configuração iFood alterada. Aguardando nova tentativa com as credenciais atuais.');
        }

        return $app;
    }

    private function pollError(object $app, string $message): void
    {
        DB::table('ifood_apps')->where('id', $app->id)->where('revision', $app->revision)->update(['last_poll_error' => $message]);
    }

    private function safeError(Throwable $exception): string
    {
        return $exception instanceof ValidationException ? ($exception->errors()['ifood'][0] ?? 'Resposta iFood inválida. Evento preservado para revisão.') : 'Falha interna ao sincronizar iFood. Dados preservados para nova tentativa.';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ifood' => $message]);
    }
}
