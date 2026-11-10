<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryPurchases
{
    /** Allocate all cents by largest remainder, with stable item ordering. */
    private function allocate(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum === 0) {
            $weights = array_fill(0, count($weights), 1);
            $sum = count($weights);
        }
        $shares = $remainders = [];
        foreach ($weights as $index => $weight) {
            $product = $amount * $weight;
            $shares[$index] = intdiv($product, $sum);
            $remainders[$index] = $product % $sum;
        }
        arsort($remainders, SORT_NUMERIC);
        $remaining = $amount - array_sum($shares);
        foreach ($remainders as $index => $remainder) {
            if ($remaining-- <= 0) {
                break;
            }
            $shares[$index]++;
        }
        ksort($shares);

        return $shares;
    }

    private function normalized(array $data): array
    {
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            if (empty($line['item_id']) && empty($line['quantity']) && ! isset($line['unit_price'])) {
                continue;
            }
            if (empty($line['item_id']) || ! isset($line['quantity'], $line['unit_price'])) {
                throw ValidationException::withMessages(['lines' => 'Selecione insumo, quantidade e preço por unidade em cada linha.']);
            }
            $quantity = app(Inventory::class)->quantity($line['quantity'], 'lines.'.$index.'.quantity');
            if ($quantity < 1 || isset($lines[(int) $line['item_id']])) {
                throw ValidationException::withMessages(['lines' => 'Use quantidades positivas e uma linha por insumo.']);
            }
            $price = InventoryCosts::money($line['unit_price'], 'lines.'.$index.'.unit_price', 100000000);
            $lines[(int) $line['item_id']] = ['item_id' => (int) $line['item_id'], 'quantity' => $quantity, 'unit_price_cents' => $price, 'subtotal_cents' => intdiv($quantity * $price + 500, 1000)];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Adicione pelo menos um insumo.']);
        }
        ksort($lines);
        $lines = array_values($lines);
        $subtotal = array_sum(array_column($lines, 'subtotal_cents'));
        $freight = InventoryCosts::money($data['freight'] ?? '0', 'freight');
        $discount = InventoryCosts::money($data['discount'] ?? '0', 'discount');
        if ($discount > $subtotal || $subtotal + $freight - $discount > 999999999) {
            throw ValidationException::withMessages(['discount' => 'O desconto não pode ultrapassar os produtos e o total deve ser até R$ 9.999.999,99.']);
        }
        $weights = array_column($lines, 'subtotal_cents');
        $freights = $this->allocate($freight, $weights);
        $discounts = $this->allocate($discount, $weights);
        foreach ($lines as $index => &$line) {
            $line['freight_cents'] = $freights[$index];
            $line['discount_cents'] = $discounts[$index];
            $line['total_cents'] = $line['subtotal_cents'] + $freights[$index] - $discounts[$index];
        }
        unset($line);

        return ['store_id' => (int) $data['store_id'], 'supplier_id' => (int) $data['supplier_id'], 'purchased_on' => $data['purchased_on'], 'reference' => trim($data['reference'] ?? '') ?: null,
            'notes' => trim($data['notes'] ?? '') ?: null, 'subtotal_cents' => $subtotal, 'freight_cents' => $freight, 'discount_cents' => $discount, 'total_cents' => $subtotal + $freight - $discount, 'lines' => $lines];
    }

    private function replay(string $key, string $hash, string $action, object $user, ?int $purchaseId): ?int
    {
        $event = DB::table('inventory_purchase_events')->join('inventory_purchases', 'inventory_purchases.id', '=', 'inventory_purchase_events.purchase_id')->where('request_key', $key)->select('inventory_purchase_events.*', 'inventory_purchases.tenant_id')->first();
        if (! $event) {
            return null;
        }
        abort_unless($event->tenant_id === $user->tenant_id && $event->user_id === $user->id && $event->action === $action && hash_equals($event->request_hash, $hash) && ($purchaseId === null || $purchaseId === $event->purchase_id), 409, 'Esta solicitação já foi utilizada com outros dados.');

        return $event->purchase_id;
    }

    private function event(int $id, object $user, string $action, string $key, string $hash): void
    {
        $purchase = DB::table('inventory_purchases')->find($id);
        $lines = DB::table('inventory_purchase_lines')->where('purchase_id', $id)->orderBy('id')->get();
        DB::table('inventory_purchase_events')->insert(['purchase_id' => $id, 'user_id' => $user->id, 'action' => $action, 'snapshot' => json_encode(['purchase' => $purchase, 'lines' => $lines], JSON_THROW_ON_ERROR), 'request_key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
        Audit::record('inventory.purchase_'.$action, ['purchase_id' => $id, 'revision' => $purchase->revision, 'total_cents' => $purchase->total_cents], $user->tenant_id);
    }

    public function save(object $user, array $data, ?int $id = null): int
    {
        $payload = $this->normalized($data);
        $action = $id ? 'edited' : 'created';
        $hash = hash('sha256', json_encode([$id, (int) ($data['revision'] ?? 0), $payload], JSON_THROW_ON_ERROR));
        if ($previous = $this->replay($data['request_key'], $hash, $action, $user, $id)) {
            return $previous;
        }
        DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $payload['store_id'])->lockForUpdate()->firstOrFail();
        $current = $id ? DB::table('inventory_purchases')->where('tenant_id', $user->tenant_id)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
        if ($current) {
            abort_unless($current->status === 'draft' && $current->store_id === $payload['store_id'] && $current->revision === (int) $data['revision'], 409, 'A compra mudou ou já foi encerrada. Reabra a conferência atual.');
        }
        $supplier = DB::table('inventory_suppliers')->where('tenant_id', $user->tenant_id)->where('store_id', $payload['store_id'])->where('active', true)->where('id', $payload['supplier_id'])->first();
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Escolha um fornecedor ativo desta loja.']);
        }
        $items = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $payload['store_id'])->where('active', true)->whereIn('id', array_column($payload['lines'], 'item_id'))->get()->keyBy('id');
        if ($items->count() !== count($payload['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Escolha somente insumos ativos da loja desta compra.']);
        }
        $lines = $payload['lines'];
        unset($payload['lines']);
        $values = $payload + ['supplier_name' => $supplier->name, 'revision' => ($current?->revision ?? 0) + 1, 'updated_at' => now()];
        if ($id) {
            DB::table('inventory_purchases')->where('id', $id)->update($values);
            DB::table('inventory_purchase_lines')->where('purchase_id', $id)->delete();
        } else {
            $id = DB::table('inventory_purchases')->insertGetId($values + ['tenant_id' => $user->tenant_id, 'created_by' => $user->id, 'status' => 'draft', 'created_at' => now()]);
        }
        foreach ($lines as $line) {
            DB::table('inventory_purchase_lines')->insert($line + ['purchase_id' => $id, 'item_name' => $items[$line['item_id']]->name, 'unit' => $items[$line['item_id']]->unit]);
        }
        $this->event($id, $user, $action, $data['request_key'], $hash);

        return $id;
    }

    public function reviewHash(object $purchase, iterable $lines): string
    {
        return hash('sha256', json_encode([$purchase->id, $purchase->tenant_id, $purchase->store_id, $purchase->supplier_id, $purchase->supplier_name, $purchase->status,
            $purchase->reference, $purchase->purchased_on, $purchase->notes, $purchase->total_cents, $purchase->revision, collect($lines)->map(fn ($line) => [$line->id, $line->item_id, $line->item_name, $line->unit, $line->quantity, $line->unit_price_cents, $line->subtotal_cents, $line->freight_cents, $line->discount_cents, $line->total_cents])->all()], JSON_THROW_ON_ERROR));
    }

    public function receive(object $user, int $id, string $key, string $review): void
    {
        $hash = hash('sha256', $id.':'.$review);
        if ($this->replay($key, $hash, 'received', $user, $id)) {
            return;
        }
        $purchase = DB::table('inventory_purchases')->where('tenant_id', $user->tenant_id)->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($purchase->status === 'draft', 409, 'Esta compra já foi recebida ou cancelada.');
        $lines = DB::table('inventory_purchase_lines')->where('purchase_id', $id)->orderBy('id')->get();
        abort_unless(hash_equals($this->reviewHash($purchase, $lines), $review), 409, 'A compra mudou. Confira os itens e valores atuais antes de receber.');
        $items = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $purchase->store_id)->whereIn('id', $lines->pluck('item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($lines as $line) {
            $item = $items->get($line->item_id);
            if (! $item || ! $item->active || $item->unit !== $line->unit) {
                throw ValidationException::withMessages(['purchase' => 'Confira os insumos: todos precisam estar ativos e na unidade original da compra.']);
            }
            app(Inventory::class)->move($item, $user, 'in', $line->quantity, 'Recebimento da compra #'.$id, (string) Str::uuid(), purchaseLineId: $line->id, entryValue: $line->total_cents);
        }
        DB::table('inventory_purchases')->where('id', $id)->update(['status' => 'received', 'received_by' => $user->id, 'received_at' => now(), 'revision' => $purchase->revision + 1, 'updated_at' => now()]);
        $this->event($id, $user, 'received', $key, $hash);
    }

    public function cancel(object $user, int $id, int $revision, string $reason, string $key): void
    {
        $reason = trim($reason);
        abort_if($reason === '', 422);
        $hash = hash('sha256', json_encode([$id, $revision, $reason], JSON_THROW_ON_ERROR));
        if ($this->replay($key, $hash, 'cancelled', $user, $id)) {
            return;
        }
        $purchase = DB::table('inventory_purchases')->where('tenant_id', $user->tenant_id)->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($purchase->status === 'draft' && $purchase->revision === $revision, 409, 'Esta compra mudou, foi recebida ou cancelada.');
        DB::table('inventory_purchases')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'revision' => $revision + 1, 'updated_at' => now()]);
        $this->event($id, $user, 'cancelled', $key, $hash);
    }
}
