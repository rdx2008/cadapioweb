<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class DeliveryExceptions
{
    public function act(int $id, object $user, string $action, array $data): bool
    {
        return DB::transaction(function () use ($id, $user, $action, $data): bool {
            abort_unless($user->access_enabled ?? true, 403);
            $initial = DB::table('rides')->find($id);
            abort_unless($initial, 404);
            $tenant = DB::table('tenants')->where('id', $initial->tenant_id)->lockForUpdate()->firstOrFail();
            $ride = DB::table('rides')->where('id', $id)->lockForUpdate()->firstOrFail();
            $owner = $user->role === 'owner' && $user->tenant_id === $ride->tenant_id;
            $courier = $user->role === 'courier' && DB::table('drivers')->where('id', $ride->driver_id)->where('user_id', $user->id)->exists();
            abort_unless($owner || ($courier && $action === 'return'), 404);
            if ($owner) {
                abort_unless(app(TenantState::class)->active($tenant->id), 403);
            }
            $prior = DB::table('delivery_attempts')->where('ride_id', $id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless($owner && $prior->closed_by === $user->id && $prior->outcome === ($action === 'release' ? 'released_before_pickup' : ($action === 'receive' ? 'returned' : '')), 409);

                return false;
            }
            if (($action === 'retry' && $ride->last_retry_key === $data['request_key']) || ($action === 'return' && $ride->return_request_key === $data['request_key'])) {
                return false;
            }
            abort_unless($ride->dispatch_round === (int) $data['expected_round'], 409, 'A entrega mudou. Atualize a página.');
            $order = DB::table('orders')->where('id', $ride->order_id)->lockForUpdate()->firstOrFail();
            if ($action === 'return') {
                abort_unless($ride->status === 'collected', 409, 'Somente uma entrega coletada pode retornar.');
                DB::table('rides')->where('id', $id)->update(['status' => 'returning', 'return_request_key' => $data['request_key'], 'return_reason' => $data['reason'], 'return_requested_by' => $user->id, 'return_requested_at' => now(), 'updated_at' => now()]);
            } elseif ($action === 'retry') {
                abort_unless($tenant->courier_module === 'active' && in_array($ride->status, ['unassigned', 'returned'], true), 409, 'A entrega não está disponível para nova busca.');
                abort_unless($order->mode === 'delivery' && $order->status === 'ready' && $order->payment_status !== 'review', 409, 'Confira o pedido e o pagamento antes de buscar outro motoboy.');
                app(OnlinePayment::class)->requirePaid($order);
                $this->revoke($id);
                DB::table('rides')->where('id', $id)->update(['status' => 'searching', 'driver_id' => null, 'dispatch_round' => $ride->dispatch_round + 1, 'last_retry_key' => $data['request_key'], 'cash_cents' => $order->payment_method === 'cash' && $order->payment_status !== 'paid' ? $order->total_cents : 0, 'settled' => false, 'received_by' => null, 'proof_photo_path' => null, 'proof_recorded_at' => null, 'return_request_key' => null, 'return_reason' => null, 'return_requested_by' => null, 'return_requested_at' => null, 'return_confirmed_at' => null, 'updated_at' => now()]);
            } else {
                abort_unless($owner && (($action === 'release' && $ride->status === 'assigned') || ($action === 'receive' && $ride->status === 'returning')), 409, 'A situação da entrega mudou.');
                $cash = $action === 'receive' ? $data['customer_cash_cents'] : 0;
                abort_unless($cash <= $order->total_cents && ($cash === 0 || ($order->payment_method === 'cash' && $order->payment_status !== 'paid' && $ride->cash_cents > 0)), 422, 'O dinheiro informado não corresponde à cobrança desta tentativa.');
                DB::table('delivery_attempts')->insert(['tenant_id' => $ride->tenant_id, 'ride_id' => $id, 'driver_id' => $ride->driver_id, 'closed_by' => $user->id, 'request_key' => $data['request_key'], 'outcome' => $action === 'release' ? 'released_before_pickup' : 'returned', 'reason' => $data['reason'], 'return_reason' => $ride->return_reason, 'quoted_fare_cents' => $ride->fare_cents, 'compensation_cents' => $data['compensation_cents'], 'expected_cash_cents' => $ride->cash_cents, 'customer_cash_cents' => $cash, 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->revoke($id);
                DB::table('drivers')->where('id', $ride->driver_id)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);
                DB::table('rides')->where('id', $id)->update(['status' => $action === 'release' ? 'unassigned' : 'returned', 'driver_id' => $action === 'release' ? null : $ride->driver_id, 'dispatch_round' => $ride->dispatch_round + 1, 'return_confirmed_at' => $action === 'receive' ? now() : null, 'updated_at' => now()]);
                if ($cash > 0) {
                    DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'review', 'updated_at' => now()]);
                }
                if ($tenant->courier_module === 'closing' && ! DB::table('rides')->where('tenant_id', $tenant->id)->whereIn('status', ['assigned', 'collected', 'returning'])->exists()) {
                    DB::table('tenants')->where('id', $tenant->id)->update(['courier_module' => 'disabled']);
                }
            }
            Audit::record('delivery.'.$action, ['ride_id' => $id, 'round' => $ride->dispatch_round, 'driver_id' => $ride->driver_id, 'request_key' => $data['request_key']], $ride->tenant_id);

            return $action === 'retry';
        }, 3);
    }

    private function revoke(int $id): void
    {
        DB::table('offers')->where('ride_id', $id)->where('status', 'open')->update(['status' => 'revoked']);
        DB::table('driver_claims')->where('ride_id', $id)->delete();
    }
}
