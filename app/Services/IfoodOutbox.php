<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IfoodOutbox
{
    public const ACTIVE = ['queued', 'checking', 'sending', 'awaiting_event', 'uncertain'];

    public const LABELS = ['confirm' => 'Aceitar pedido', 'prepare' => 'Iniciar preparo', 'ready' => 'Informar pronto', 'dispatch' => 'Despachar entrega própria', 'cancel' => 'Solicitar cancelamento'];

    public function __construct(private IfoodClient $client) {}

    public function enqueue(int $tenantId, int $orderId, string $action, string $key, ?string $reason = null): int
    {
        return DB::transaction(function () use ($tenantId, $orderId, $action, $key, $reason): int {
            $snapshot = DB::table('ifood_orders')->where('tenant_id', $tenantId)->where('id', $orderId)->firstOrFail();
            $app = DB::table('ifood_apps')->where('id', $snapshot->app_id)->lockForUpdate()->firstOrFail();
            $connection = $this->binding($snapshot, true);
            $order = DB::table('ifood_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('ifood_commands')->where('order_id', $orderId)->where('request_key', $key)->first();
            if ($existing) {
                if ($existing->action !== $action || (json_decode(Crypt::decryptString($existing->payload), true)['reason'] ?? null) !== $reason) {
                    $this->fail('Esta solicitação já foi usada com outra ação. Atualize a página.');
                }

                return $existing->id;
            }
            $this->guard($app, $connection, $order, $action);
            if (DB::table('ifood_commands')->where('order_id', $orderId)->whereIn('status', self::ACTIVE)->exists()) {
                $this->fail('Existe uma ação pendente de confirmação. Aguarde o retorno do iFood antes de solicitar outra.');
            }
            $payload = $action === 'dispatch' ? ['deliveredBy' => 'MERCHANT'] : [];
            if ($action === 'cancel') {
                $reasons = $order->cancellation_reasons ? json_decode(Crypt::decryptString($order->cancellation_reasons), true) : [];
                if (! $order->reasons_checked_at || Carbon::parse($order->reasons_checked_at)->lt(now()->subMinutes(5)) || ! array_key_exists($reason ?? '', $reasons)) {
                    $this->fail('Consulte os motivos atuais do iFood e selecione um código válido.');
                }
                $payload = ['reason' => $reason];
            }
            $id = DB::table('ifood_commands')->insertGetId([
                'app_id' => $app->id, 'app_revision' => $app->revision, 'connection_id' => $connection->id,
                'order_id' => $orderId, 'tenant_id' => $tenantId, 'store_id' => $order->store_id, 'user_id' => auth()->id(),
                'request_key' => $key, 'action' => $action, 'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('ifood.action_queued', ['command_id' => $id, 'order_id' => $orderId, 'action' => $action], $tenantId);

            return $id;
        });
    }

    public function reasons(int $tenantId, int $id): void
    {
        $order = DB::table('ifood_orders')->where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
        $app = DB::table('ifood_apps')->where('id', $order->app_id)->firstOrFail();
        $this->guard($app, $this->binding($order), $order, 'cancel');
        $reasons = $this->client->cancellationReasons($app, $order->external_order_id);
        DB::transaction(function () use ($order, $app, $reasons): void {
            $current = DB::table('ifood_apps')->where('id', $app->id)->lockForUpdate()->firstOrFail();
            if ($current->revision !== $app->revision) {
                $this->fail('As credenciais mudaram durante a consulta. Consulte os motivos novamente.');
            }
            DB::table('ifood_orders')->where('id', $order->id)->update(['cancellation_reasons' => Crypt::encryptString(json_encode($reasons, JSON_THROW_ON_ERROR)), 'reasons_checked_at' => now()]);
        });
    }

    public function run(): int
    {
        DB::table('ifood_commands')->where('status', 'checking')->where('locked_at', '<', now()->subMinutes(2))->update(['status' => 'queued', 'locked_at' => null, 'updated_at' => now()]);
        DB::table('ifood_commands')->where('status', 'sending')->where('locked_at', '<', now()->subMinutes(2))->update(['status' => 'uncertain', 'last_error' => 'Envio interrompido. Aguarde o evento do iFood; não houve reenvio automático.', 'updated_at' => now()]);
        DB::table('ifood_commands')->where('status', 'awaiting_event')->where('sent_at', '<', now()->subMinutes(5))->update(['status' => 'uncertain', 'last_error' => 'Ainda não chegou evento de confirmação. Confira o pedido no iFood.', 'updated_at' => now()]);
        $count = 0;
        foreach (DB::table('ifood_commands')->where('status', 'queued')->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('id')->limit(3)->pluck('id') as $id) {
            Cache::lock('ifood:command:'.$id, 120)->get(function () use ($id, &$count): void {
                $this->send((int) $id);
                $count++;
            });
        }

        return $count;
    }

    private function send(int $id): void
    {
        $command = DB::transaction(function () use ($id): ?object {
            $command = DB::table('ifood_commands')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($command->status !== 'queued') {
                return null;
            }
            DB::table('ifood_commands')->where('id', $id)->update(['status' => 'checking', 'locked_at' => now(), 'updated_at' => now()]);

            return $command;
        });
        if (! $command) {
            return;
        }
        try {
            $app = DB::table('ifood_apps')->where('id', $command->app_id)->firstOrFail();
            if ($app->revision !== $command->app_revision) {
                $this->fail('Credenciais alteradas. Esta ação não foi enviada. Solicite novamente após verificar a loja.');
            }
            $order = DB::table('ifood_orders')->where('id', $command->order_id)->firstOrFail();
            $binding = $this->binding($order);
            if ($binding->id !== $command->connection_id) {
                $this->fail('Vínculo alterado após a solicitação. Ação bloqueada.');
            }
            $this->guard($app, $binding, $order, $command->action);
            $detail = $this->client->order($app, $order->external_order_id);
            if (($detail['isTest'] ?? $detail['test'] ?? false) !== true || strtolower((string) ($detail['id'] ?? '')) !== $order->external_order_id || strtolower((string) ($detail['merchant']['id'] ?? '')) !== $order->merchant_id || ($detail['orderType'] ?? null) !== $order->order_type || ($detail['category'] ?? null) !== 'FOOD' || ($detail['orderTiming'] ?? null) !== $order->timing) {
                $this->fail('A API não confirmou a identidade e o ambiente de teste deste pedido. Envio bloqueado.');
            }
            if ($command->action !== 'cancel' && $order->timing === 'SCHEDULED' && (! is_string($detail['preparationStartDateTime'] ?? null) || Carbon::parse($detail['preparationStartDateTime'])->isFuture())) {
                $this->fail('A API informa preparo agendado para o futuro. Aguarde o horário correto.');
            }
            $deliveredBy = $detail['delivery']['deliveredBy'] ?? null;
            if ($command->action === 'dispatch' && $deliveredBy !== 'MERCHANT') {
                $this->fail('Despacho manual permitido somente para entrega própria confirmada pela API.');
            }
            $payload = json_decode(Crypt::decryptString($command->payload), true, flags: JSON_THROW_ON_ERROR);
            if ($command->action === 'cancel' && ! array_key_exists($payload['reason'], $this->client->cancellationReasons($app, $order->external_order_id))) {
                $this->fail('O motivo de cancelamento não está mais disponível no iFood. Consulte os motivos novamente.');
            }
            $ready = DB::transaction(function () use ($app, $order, $command, $deliveredBy): bool {
                $current = DB::table('ifood_apps')->where('id', $app->id)->lockForUpdate()->firstOrFail();
                if ($current->revision !== $command->app_revision) {
                    $this->fail('Credenciais alteradas antes do envio. Ação bloqueada.');
                }
                $connection = $this->binding($order, true);
                $currentOrder = DB::table('ifood_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $this->guard($current, $connection, $currentOrder, $command->action);
                if (DB::table('ifood_commands')->where('id', $command->id)->value('status') !== 'checking') {
                    return false;
                }
                DB::table('ifood_orders')->where('id', $order->id)->update(['delivered_by' => in_array($deliveredBy, ['MERCHANT', 'IFOOD'], true) ? $deliveredBy : null]);
                DB::table('ifood_commands')->where('id', $command->id)->update(['status' => 'sending', 'sent_at' => now()->utc()->format('Y-m-d H:i:s.u'), 'locked_at' => now(), 'attempts' => $command->attempts + 1, 'last_error' => null, 'updated_at' => now()]);

                return true;
            });
            if (! $ready) {
                return;
            }
        } catch (Throwable $exception) {
            DB::table('ifood_commands')->where('id', $id)->where('status', 'checking')->update(['status' => 'rejected', 'last_error' => $this->safeError($exception), 'updated_at' => now()]);

            return;
        }
        try {
            $result = $this->client->execute($app, $order->external_order_id, $command->action, $payload);
        } catch (Throwable) {
            $result = ['status' => 'uncertain', 'message' => 'Envio sem resultado confirmado. Aguarde o evento do iFood antes de solicitar novamente.'];
        }
        $status = $result['status'];
        if ($status === 'retry') {
            $status = $command->attempts >= 2 ? 'rejected' : 'queued';
        }
        DB::table('ifood_commands')->where('id', $id)->where('status', 'sending')->update([
            'status' => $status, 'last_error' => $result['message'], 'locked_at' => null,
            'retry_at' => $status === 'queued' ? now()->addSeconds($result['retry_after'] ?? 30) : null, 'updated_at' => now(),
        ]);
    }

    public function observe(object $event, int $orderId): void
    {
        $expected = ['CFM' => 'confirm', 'PRS' => 'prepare', 'SPS' => 'prepare', 'RTP' => 'ready', 'DSP' => 'dispatch', 'CAN' => 'cancel'];
        $query = DB::table('ifood_commands')->where('order_id', $orderId)->where('app_id', $event->app_id)->whereIn('status', ['sending', 'awaiting_event', 'uncertain'])
            ->whereNotNull('sent_at')->where('sent_at', '<=', $event->occurred_at);
        if (isset($expected[$event->code])) {
            (clone $query)->where('action', $expected[$event->code])->update(['status' => 'confirmed', 'last_error' => null, 'updated_at' => now()]);
        }
        if ($event->code === 'CARF') {
            (clone $query)->where('action', 'cancel')->update(['status' => 'uncertain', 'last_error' => 'O iFood informou falha de cancelamento. Confira a solicitação no portal antes de tentar novamente.', 'updated_at' => now()]);
        }
        if (in_array($event->code, ['CAN', 'CON'], true)) {
            DB::table('ifood_commands')->where('order_id', $orderId)->whereIn('status', self::ACTIVE)->update(['status' => 'obsolete', 'last_error' => 'Pedido encerrado por evento do iFood. Esta ação não será reenviada.', 'updated_at' => now()]);
        }
    }

    public function available(object $order): array
    {
        $app = DB::table('ifood_apps')->where('id', $order->app_id)->first();
        $connection = DB::table('ifood_connections')->where('app_id', $order->app_id)->where('store_id', $order->store_id)->where('merchant_id', $order->merchant_id)->first();
        $available = [];
        if (! $app || ! $connection || DB::table('ifood_commands')->where('order_id', $order->id)->whereIn('status', self::ACTIVE)->exists()) {
            return [];
        }
        foreach (array_keys(self::LABELS) as $action) {
            try {
                $this->guard($app, $connection, $order, $action);
                $available[] = $action;
            } catch (ValidationException) {
                continue;
            }
        }

        return $available;
    }

    private function guard(object $app, object $connection, object $order, string $action): void
    {
        if ($app->environment !== 'test' || ! $connection->actions_enabled || ! $connection->receiving_enabled || $connection->status !== 'verified') {
            $this->fail('As ações de teste precisam ser habilitadas pelo superadmin em uma conexão verificada com recebimento ativo.');
        }
        $allowed = ['confirm' => ['pending'], 'prepare' => ['accepted'], 'ready' => ['accepted', 'preparing'], 'dispatch' => ['ready'], 'cancel' => ['pending', 'accepted', 'preparing', 'ready']];
        if (! in_array($order->status, $allowed[$action] ?? [], true)) {
            $this->fail('Esta ação não é permitida na situação atual do pedido.');
        }
        if ($action !== 'cancel' && $order->timing === 'SCHEDULED' && (! $order->preparation_at || Carbon::parse($order->preparation_at)->isFuture())) {
            $this->fail('Aguarde o horário previsto de preparo do pedido agendado.');
        }
        if ($action === 'confirm' && Carbon::parse($order->timing === 'SCHEDULED' ? $order->preparation_at : $order->ordered_at)->addMinutes(8)->isPast()) {
            $this->fail('Prazo de confirmação expirado. Confira o estado atual no iFood.');
        }
        if ($action === 'dispatch' && $order->order_type !== 'DELIVERY') {
            $this->fail('Despacho permitido somente para pedido de entrega própria.');
        }
        if ($action === 'dispatch' && $order->delivered_by === 'IFOOD') {
            $this->fail('Entrega iFood não admite despacho manual pela loja.');
        }
        if (DB::table('ifood_events')->where('app_id', $order->app_id)->where('external_order_id', $order->external_order_id)->whereIn('code', ['OPA', 'HSD', 'DAR'])->exists()) {
            $this->fail('Este pedido possui alteração ou negociação que exige tratamento no portal iFood nesta versão.');
        }
        if (DB::table('ifood_events')->where('app_id', $order->app_id)->where('external_order_id', $order->external_order_id)->whereIn('status', ['received', 'processing', 'error'])->exists()) {
            $this->fail('Há eventos deste pedido aguardando processamento. Aguarde a atualização antes de enviar uma ação.');
        }
    }

    private function binding(object $order, bool $lock = false): object
    {
        $connection = DB::table('ifood_connections')->where('app_id', $order->app_id)->where('tenant_id', $order->tenant_id)->where('store_id', $order->store_id)->where('merchant_id', $order->merchant_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $connection) {
            $this->fail('Vínculo iFood não encontrado para esta loja.');
        }

        return $connection;
    }

    private function safeError(Throwable $exception): string
    {
        return $exception instanceof ValidationException ? ($exception->errors()['ifood'][0] ?? 'Pedido inválido para envio.') : 'Não foi possível validar a solicitação. Nenhuma ação foi enviada.';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ifood' => $message]);
    }
}
