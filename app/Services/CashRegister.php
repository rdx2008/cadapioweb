<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashRegister
{
    public function current(int $storeId): ?object
    {
        return DB::table('cash_sessions')->where('open_store_id', $storeId)->first();
    }

    private function lockStore(int $tenantId, int $storeId): void
    {
        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
        DB::table('stores')->where('tenant_id', $tenantId)->where('id', $storeId)->lockForUpdate()->firstOrFail();
    }

    private function active(int $storeId): object
    {
        $session = DB::table('cash_sessions')->where('open_store_id', $storeId)->lockForUpdate()->first();
        if (! $session) {
            throw ValidationException::withMessages(['cash' => 'Abra o caixa da loja antes de registrar um recebimento presencial no PDV.']);
        }

        return $session;
    }

    public function open(object $store, int $userId, int $opening, string $key): int
    {
        return DB::transaction(function () use ($store, $userId, $opening, $key): int {
            $this->lockStore($store->tenant_id, $store->id);
            $existing = DB::table('cash_sessions')->where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->store_id === $store->id && $existing->opened_by === $userId && $existing->opening_cents === $opening, 409);

                return $existing->id;
            }
            abort_if($this->current($store->id) !== null, 409, 'Esta loja já tem um caixa aberto.');
            $id = DB::table('cash_sessions')->insertGetId(['tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'open_store_id' => $store->id, 'opened_by' => $userId,
                'request_key' => $key, 'opening_cents' => $opening, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('cash.open', ['session_id' => $id, 'opening_cents' => $opening], $store->tenant_id);

            return $id;
        }, 3);
    }

    public function receipt(object $order, int $userId, ?int $partial = null): void
    {
        if ($order->source !== 'pos' || ! in_array($order->payment_method, ['cash', 'pix', 'card'], true)) {
            return;
        }
        $this->lockStore($order->tenant_id, $order->store_id);
        $received = (int) DB::table('cash_entries')->where('order_id', $order->id)->where('kind', 'sale')->sum('amount_cents');
        if ($partial === null && $received > 0) {
            return;
        }
        abort_if($partial !== null && ($order->mode !== 'dine_in' || ! $order->dining_tab_id || $partial < 1 || $partial > $order->total_cents - $received), 409, 'Confira o saldo do pedido antes de receber.');
        $session = $this->active($order->store_id);
        $this->insert($session, $userId, 'sale', $partial ?? $order->total_cents, $order->payment_method, 'Recebimento do pedido #'.$order->id, (string) Str::uuid(), $order->id);
    }

    private function insert(object $session, int $userId, string $kind, int $amount, string $method, string $note, string $key, ?int $orderId = null): void
    {
        DB::table('cash_entries')->insert(['tenant_id' => $session->tenant_id, 'store_id' => $session->store_id, 'cash_session_id' => $session->id,
            'user_id' => $userId, 'order_id' => $orderId, 'kind' => $kind, 'amount_cents' => $amount, 'payment_method' => $method,
            'note' => $note, 'request_key' => $key, 'created_at' => now()]);
        Audit::record('cash.'.$kind, ['session_id' => $session->id, 'amount_cents' => $amount, 'payment_method' => $method, 'order_id' => $orderId], $session->tenant_id);
    }

    public function totals(object $session): array
    {
        $totals = ['cash' => ['sales' => 0, 'refunds' => 0], 'pix' => ['sales' => 0, 'refunds' => 0], 'card' => ['sales' => 0, 'refunds' => 0], 'supply' => 0, 'withdrawal' => 0];
        foreach (DB::table('cash_entries')->where('cash_session_id', $session->id)->selectRaw('kind, payment_method, SUM(amount_cents) as amount')->groupBy('kind', 'payment_method')->get() as $row) {
            if (in_array($row->kind, ['supply', 'withdrawal'], true)) {
                $totals[$row->kind] += (int) $row->amount;
            } else {
                $totals[$row->payment_method][$row->kind === 'sale' ? 'sales' : 'refunds'] += (int) $row->amount;
            }
        }
        $totals['expected'] = $session->opening_cents + $totals['cash']['sales'] - $totals['cash']['refunds'] + $totals['supply'] - $totals['withdrawal'];

        return $totals;
    }

    public function movement(object $session, int $userId, string $kind, int $amount, string $note, string $key): void
    {
        DB::transaction(function () use ($session, $userId, $kind, $amount, $note, $key): void {
            $this->lockStore($session->tenant_id, $session->store_id);
            $current = DB::table('cash_sessions')->where('id', $session->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('cash_entries')->where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->cash_session_id === $session->id && $existing->user_id === $userId && $existing->kind === $kind && $existing->amount_cents === $amount && $existing->note === $note, 409);

                return;
            }
            abort_if($current->closed_at !== null, 409, 'Este caixa já foi fechado.');
            if ($kind === 'withdrawal' && $amount > $this->totals($current)['expected']) {
                throw ValidationException::withMessages(['amount' => 'A sangria não pode ultrapassar o dinheiro esperado no caixa.']);
            }
            $this->insert($current, $userId, $kind, $amount, 'cash', $note, $key);
        }, 3);
    }

    public function refund(object $session, int $orderId, int $userId, string $note, string $key): void
    {
        DB::transaction(function () use ($session, $orderId, $userId, $note, $key): void {
            $this->lockStore($session->tenant_id, $session->store_id);
            $current = DB::table('cash_sessions')->where('id', $session->id)->lockForUpdate()->firstOrFail();
            $order = DB::table('orders')->where('tenant_id', $session->tenant_id)->where('store_id', $session->store_id)->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $sales = DB::table('cash_entries')->where('order_id', $orderId)->where('kind', 'sale')->get();
            abort_unless($sales->isNotEmpty() && $order->status === 'cancelled', 409, 'Somente pedidos cancelados com recebimento registrado no caixa podem ter devolução.');
            $existing = DB::table('cash_entries')->where('order_id', $orderId)->where('kind', 'refund')->orderBy('id')->first();
            if ($existing) {
                abort_unless($existing->request_key === $key && $existing->cash_session_id === $session->id && $existing->user_id === $userId && $existing->note === $note, 409, 'A devolução deste pedido já foi registrada.');

                return;
            }
            abort_if(DB::table('cash_entries')->where('request_key', $key)->exists(), 409);
            abort_if($current->closed_at !== null, 409, 'Este caixa já foi fechado.');
            if ($sales->where('payment_method', 'cash')->sum('amount_cents') > $this->totals($current)['expected']) {
                throw ValidationException::withMessages(['cash' => 'Dinheiro insuficiente no caixa para registrar esta devolução.']);
            }
            foreach ($sales->groupBy('payment_method') as $method => $receipts) {
                $this->insert($current, $userId, 'refund', (int) $receipts->sum('amount_cents'), $method, $note, $key, $orderId);
                $key = (string) Str::uuid();
            }
        }, 3);
    }

    public function close(object $session, int $userId, int $counted, string $note, string $key): void
    {
        DB::transaction(function () use ($session, $userId, $counted, $note, $key): void {
            $this->lockStore($session->tenant_id, $session->store_id);
            $current = DB::table('cash_sessions')->where('id', $session->id)->lockForUpdate()->firstOrFail();
            if ($current->closed_at !== null) {
                abort_unless($current->close_key === $key && $current->closed_by === $userId && $current->counted_cents === $counted && ($current->closing_note ?? '') === $note, 409);

                return;
            }
            abort_if(DB::table('cash_sessions')->where('close_key', $key)->where('id', '!=', $session->id)->exists(), 409, 'Esta confirmação já foi usada em outro turno.');
            $totals = $this->totals($current);
            $difference = $counted - $totals['expected'];
            if ($difference !== 0 && trim($note) === '') {
                throw ValidationException::withMessages(['closing_note' => 'Explique a diferença entre o dinheiro contado e o esperado.']);
            }
            DB::table('cash_sessions')->where('id', $session->id)->update(['open_store_id' => null, 'closed_by' => $userId, 'closed_at' => now(),
                'close_key' => $key, 'counted_cents' => $counted, 'expected_cents' => $totals['expected'], 'difference_cents' => $difference,
                'closing_note' => $note ?: null, 'closing_totals' => json_encode($totals, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            Audit::record('cash.close', ['session_id' => $session->id, 'counted_cents' => $counted, 'difference_cents' => $difference], $session->tenant_id);
        }, 3);
    }
}
