<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderPrinting
{
    public const STATUSES = ['pending' => 'Aguardando aceite', 'accepted' => 'Aceito', 'preparing' => 'Em preparo', 'ready' => 'Pronto', 'completed' => 'Concluído', 'cancelled' => 'Cancelado'];

    public function authorize(object $user, object $order, string $kind): void
    {
        $member = DB::table('users')->where('id', $user->id)->firstOrFail();
        abort_unless($member->access_enabled && in_array($member->role, ['owner', 'staff'], true) && $member->tenant_id === $order->tenant_id && app(TenantState::class)->active($order->tenant_id), 403);
        if ($member->role === 'staff') {
            abort_unless(in_array($member->staff_profile, ['operator', 'kitchen'], true), 403);
            abort_unless(DB::table('user_store_access')->where('user_id', $member->id)->where('tenant_id', $order->tenant_id)->where('store_id', $order->store_id)->exists(), 404);
            abort_if($member->staff_profile === 'kitchen' && $kind !== 'production', 403);
        }
    }

    public function notice(object $order): ?string
    {
        if ($order->status === 'cancelled') {
            return 'PEDIDO CANCELADO — NÃO PRODUZIR';
        }
        if ($order->status === 'completed') {
            return 'PEDIDO CONCLUÍDO';
        }
        if (app(OnlinePayment::class)->isOnline($order) && $order->payment_status !== 'paid') {
            return 'PAGAMENTO ONLINE NÃO CONFIRMADO — NÃO PRODUZIR';
        }
        if ($order->status === 'pending') {
            return 'AGUARDANDO ACEITE — NÃO PRODUZIR';
        }
        if (! app(OrderScheduling::class)->canPrepare($order)) {
            return 'AGENDADO — AGUARDE O HORÁRIO DE PREPARO';
        }

        return null;
    }

    public function prepare(object $user, int $orderId, array $data): string
    {
        return DB::transaction(function () use ($user, $orderId, $data): string {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->firstOrFail();
            $order = DB::table('orders')->where('id', $orderId)->where('tenant_id', $user->tenant_id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $order, $data['kind']);
            $existing = DB::table('order_prints')->where('order_id', $orderId)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $user->id && $existing->kind === $data['kind'] && $existing->paper === $data['paper'], 409);

                return $existing->reference;
            }
            $store = DB::table('stores')->where('id', $order->store_id)->firstOrFail();
            $snapshot = ['store_name' => $store->name, 'order_id' => $order->id, 'mode' => $order->mode, 'status' => $order->status, 'notice' => $this->notice($order), 'timezone' => $store->timezone, 'ordered_at' => Carbon::parse($order->created_at, 'UTC')->timezone($store->timezone)->format('d/m/Y H:i'), 'scheduled' => app(OrderScheduling::class)->label($order), 'prepare_at' => $order->prepare_at ? Carbon::parse($order->prepare_at, 'UTC')->timezone($store->timezone)->format('d/m/Y H:i') : null, 'items' => []];
            foreach (DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get() as $item) {
                $line = ['name' => $item->name, 'quantity' => $item->quantity, 'options' => []];
                $options = json_decode($item->options ?? '[]', true);
                foreach (is_array($options) ? $options : [] as $option) {
                    if (is_array($option) && isset($option['group'], $option['name']) && is_string($option['group']) && is_string($option['name'])) {
                        $line['options'][] = ['group' => $option['group'], 'name' => $option['name']];
                    }
                }
                if ($data['kind'] === 'conference') {
                    $line['unit_cents'] = $item->unit_cents;
                }
                $snapshot['items'][] = $line;
            }
            if ($data['kind'] === 'conference') {
                $snapshot['customer'] = ['name' => $order->customer_name, 'phone' => $order->phone, 'address' => $order->mode === 'delivery' ? $order->address : null];
                $snapshot['payment'] = ['method' => $order->payment_method, 'status' => $order->payment_status, 'change_for_cents' => $order->change_for_cents, 'subtotal_cents' => $order->subtotal_cents, 'fee_cents' => $order->fee_cents, 'total_cents' => $order->total_cents];
            }
            $reference = (string) Str::uuid();
            $copy = (int) DB::table('order_prints')->where('order_id', $orderId)->where('kind', $data['kind'])->max('copy_number') + 1;
            DB::table('order_prints')->insert(['reference' => $reference, 'tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'order_id' => $orderId, 'created_by' => $user->id, 'request_key' => $data['request_key'], 'kind' => $data['kind'], 'paper' => $data['paper'], 'copy_number' => $copy, 'payload' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('order.print.prepared', ['order_id' => $orderId, 'reference' => $reference, 'kind' => $data['kind'], 'copy_number' => $copy, 'paper' => $data['paper']], $order->tenant_id);

            return $reference;
        }, 3);
    }
}
