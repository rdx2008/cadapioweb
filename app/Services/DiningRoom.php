<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiningRoom
{
    public function member(object $actor, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $actor->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($user->access_enabled && $user->tenant_id && $user->tenant_id === $actor->tenant_id && ($user->role === 'owner' || ($user->role === 'staff' && $user->staff_profile === 'operator')), 403);
        if ($lock) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->firstOrFail();
        }
        abort_unless(app(TenantState::class)->active($user->tenant_id), 403);

        return $user;
    }

    public function stores(object $user): Builder
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->when($user->role === 'staff', fn ($q) => $q->whereIn('id', DB::table('user_store_access')->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->select('store_id')));
    }

    public function tab(object $user, int $id, bool $lock = false): object
    {
        return DB::table('dining_tabs')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $this->stores($user)->select('id'))->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function requireOpen(int $tenantId, int $storeId, int $id, bool $lock = false): object
    {
        $tab = DB::table('dining_tabs')->where('tenant_id', $tenantId)->where('store_id', $storeId)->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_if($tab->closed_at !== null, 409, 'Esta comanda já foi encerrada. Abra um novo atendimento.');

        return $tab;
    }

    public function summary(object $tab): array
    {
        $orders = DB::table('orders')->where('dining_tab_id', $tab->id)->where('tenant_id', $tab->tenant_id)->where('store_id', $tab->store_id)->orderBy('id')->get();
        $valid = $orders->where('status', '!=', 'cancelled');
        $receipts = DB::table('cash_entries')->whereIn('order_id', $orders->pluck('id'))->where('kind', 'sale')->selectRaw('order_id, SUM(amount_cents) as amount')->groupBy('order_id')->pluck('amount', 'order_id');
        foreach ($orders as $order) {
            $order->received_cents = $order->payment_status === 'paid' ? $order->total_cents : (int) $receipts->get($order->id, 0);
            $order->balance_cents = max(0, $order->total_cents - $order->received_cents);
        }
        $pending = $valid->where('payment_status', '!=', 'paid');
        $refunds = DB::table('cash_entries')->whereIn('order_id', $orders->where('status', 'cancelled')->pluck('id'))->where('kind', 'sale')->whereNotIn('order_id', DB::table('cash_entries')->where('kind', 'refund')->select('order_id'))->distinct()->count('order_id');

        return ['orders' => $orders, 'total' => (int) $valid->sum('total_cents'), 'paid' => (int) $valid->sum('received_cents'),
            'pending' => (int) $pending->sum('balance_cents'), 'pending_orders' => $pending, 'unfinished' => $valid->where('status', '!=', 'completed')->count(), 'refunds_pending' => $refunds,
            'review_hash' => hash('sha256', json_encode([$tab->current_table_id, $pending->map(fn ($o) => [$o->id, $o->total_cents, $o->payment_status, $o->received_cents])->values()->all()], JSON_THROW_ON_ERROR))];
    }

    public function open(object $table, object $user, string $guest, int $guests, string $key): int
    {
        DB::table('stores')->where('id', $table->store_id)->lockForUpdate()->firstOrFail();
        $current = DB::table('dining_tables')->where('id', $table->id)->lockForUpdate()->firstOrFail();
        $existing = DB::table('dining_tabs')->where('request_key', $key)->first();
        if ($existing) {
            abort_unless($existing->dining_table_id === $table->id && $existing->opened_by === $user->id && ($existing->guest_label ?? '') === $guest && $existing->guests === $guests, 409);

            return $existing->id;
        }
        abort_unless($current->enabled, 409, 'Ative a mesa antes de iniciar um atendimento.');
        abort_if(DB::table('dining_tabs')->where('active_table_id', $table->id)->exists(), 409, 'Esta mesa já tem uma comanda aberta.');
        $id = DB::table('dining_tabs')->insertGetId(['tenant_id' => $table->tenant_id, 'store_id' => $table->store_id, 'dining_table_id' => $table->id, 'current_table_id' => $table->id, 'active_table_id' => $table->id,
            'table_name' => $current->name, 'guest_label' => $guest ?: null, 'guests' => $guests, 'opened_by' => $user->id, 'request_key' => $key, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('dining.open', ['tab_id' => $id, 'table_id' => $table->id], $table->tenant_id);

        return $id;
    }

    public function transfer(object $tab, object $user, int $from, int $to, string $reason, string $key): void
    {
        $existing = DB::table('dining_transfers')->where('request_key', $key)->first();
        if ($existing) {
            abort_unless($existing->dining_tab_id === $tab->id && $existing->user_id === $user->id && $existing->from_table_id === $from && $existing->to_table_id === $to && $existing->reason === $reason, 409);

            return;
        }
        abort_if($tab->closed_at !== null, 409, 'Esta comanda já foi encerrada.');
        abort_unless($tab->current_table_id === $from && $tab->active_table_id === $from, 409, 'A mesa desta comanda mudou. Atualize a tela antes de transferir.');
        abort_if($from === $to, 422, 'Escolha uma mesa de destino diferente.');
        DB::table('stores')->where('tenant_id', $tab->tenant_id)->where('id', $tab->store_id)->lockForUpdate()->firstOrFail();
        $tables = DB::table('dining_tables')->where('tenant_id', $tab->tenant_id)->where('store_id', $tab->store_id)->whereIn('id', [$from, $to])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $target = $tables->get($to);
        abort_unless($target, 404);
        abort_unless($target->enabled, 409, 'A mesa de destino está desativada.');
        abort_if(DB::table('dining_tabs')->where('active_table_id', $to)->exists(), 409, 'A mesa de destino já está ocupada. Escolha uma mesa livre.');
        DB::table('dining_tabs')->where('id', $tab->id)->update(['active_table_id' => $to, 'current_table_id' => $to, 'table_name' => $target->name, 'updated_at' => now()]);
        DB::table('orders')->where('tenant_id', $tab->tenant_id)->where('dining_tab_id', $tab->id)->whereIn('status', ['accepted', 'preparing', 'ready'])
            ->update(['dining_label' => $target->name.' · Comanda #'.$tab->id, 'updated_at' => now()]);
        DB::table('dining_transfers')->insert(['tenant_id' => $tab->tenant_id, 'store_id' => $tab->store_id, 'dining_tab_id' => $tab->id, 'user_id' => $user->id,
            'from_table_id' => $from, 'to_table_id' => $to, 'from_name' => $tab->table_name, 'to_name' => $target->name, 'reason' => $reason, 'request_key' => $key, 'created_at' => now()]);
        Audit::record('dining.transferred', ['tab_id' => $tab->id, 'from_table_id' => $from, 'to_table_id' => $to, 'reason' => $reason], $tab->tenant_id);
    }

    public function pay(object $tab, object $user, string $method, string $hash, string $key, ?int $amount = null): void
    {
        $existing = DB::table('dining_settlements')->where('request_key', $key)->first();
        if ($existing) {
            abort_unless($existing->dining_tab_id === $tab->id && $existing->user_id === $user->id && $existing->payment_method === $method && ($amount === null || $existing->amount_cents === $amount) && hash_equals($existing->review_hash, $hash), 409);

            return;
        }
        abort_if($tab->closed_at !== null, 409, 'Esta comanda já foi encerrada.');
        DB::table('stores')->where('id', $tab->store_id)->lockForUpdate()->firstOrFail();
        DB::table('orders')->where('dining_tab_id', $tab->id)->orderBy('id')->lockForUpdate()->get();
        $summary = $this->summary($tab);
        if (! hash_equals($summary['review_hash'], $hash)) {
            throw ValidationException::withMessages(['payment' => 'O consumo ou recebimento mudou. Atualize a comanda e confira o saldo antes de receber.']);
        }
        abort_if($summary['pending_orders']->isEmpty(), 409, 'Não há pedidos pendentes de recebimento.');
        $amount ??= $summary['pending'];
        if ($amount < 1 || $amount > $summary['pending']) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero e até o saldo restante da comanda.']);
        }
        $store = DB::table('stores')->find($tab->store_id);
        abort_unless(in_array($method, ['cash', 'pix', 'card'], true) && $store->{'accepts_'.$method}, 422, 'Escolha uma forma de pagamento habilitada na loja.');
        $remaining = $amount;
        $orderIds = [];
        foreach ($summary['pending_orders'] as $order) {
            if ($remaining === 0) {
                break;
            }
            abort_unless($order->source === 'pos' && $order->mode === 'dine_in' && ! str_starts_with($order->payment_method, 'online_'), 409);
            $portion = min($remaining, $order->balance_cents);
            $order->payment_method = $method;
            app(CashRegister::class)->receipt($order, $user->id, $portion);
            $methods = DB::table('cash_entries')->where('order_id', $order->id)->where('kind', 'sale')->distinct()->pluck('payment_method');
            DB::table('orders')->where('id', $order->id)->update(['payment_method' => $methods->count() > 1 ? 'mixed' : $method, 'payment_status' => $portion === $order->balance_cents ? 'paid' : 'partial', 'change_for_cents' => null, 'updated_at' => now()]);
            $orderIds[] = $order->id;
            $remaining -= $portion;
        }
        DB::table('dining_settlements')->insert(['dining_tab_id' => $tab->id, 'user_id' => $user->id, 'request_key' => $key, 'review_hash' => $hash,
            'payment_method' => $method, 'amount_cents' => $amount, 'order_ids' => json_encode($orderIds, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Audit::record('dining.paid', ['tab_id' => $tab->id, 'amount_cents' => $amount, 'payment_method' => $method], $tab->tenant_id);
    }

    public function close(object $tab, object $user, string $key): void
    {
        if ($tab->closed_at !== null) {
            abort_unless($tab->close_key === $key && $tab->closed_by === $user->id, 409);

            return;
        }
        abort_if(DB::table('dining_tabs')->where('close_key', $key)->where('id', '!=', $tab->id)->exists(), 409);
        $summary = $this->summary($tab);
        if ($summary['pending_orders']->isNotEmpty() || $summary['unfinished'] || $summary['refunds_pending']) {
            throw ValidationException::withMessages(['tab' => 'Confira os recebimentos, marque os pedidos como servidos e registre as devoluções pendentes antes de encerrar.']);
        }
        DB::table('dining_tabs')->where('id', $tab->id)->update(['active_table_id' => null, 'closed_by' => $user->id, 'close_key' => $key, 'closed_at' => now(), 'closing_total_cents' => $summary['total'], 'updated_at' => now()]);
        Audit::record('dining.closed', ['tab_id' => $tab->id, 'total_cents' => $summary['total']], $tab->tenant_id);
    }
}
