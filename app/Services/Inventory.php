<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public const UNITS = ['un' => 'Unidade', 'kg' => 'Quilograma (kg)', 'g' => 'Grama (g)', 'l' => 'Litro (l)', 'ml' => 'Mililitro (ml)'];

    public function member(object $actor, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $actor->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($user->role === 'owner' && $user->access_enabled && $user->tenant_id && $user->tenant_id === $actor->tenant_id, 403);
        if ($lock) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->firstOrFail();
        }
        abort_unless(app(TenantState::class)->active($user->tenant_id), 403);

        return $user;
    }

    public function quantity(string $value, string $field = 'quantity'): int
    {
        $value = str_replace(',', '.', trim($value));
        if (! preg_match('/^[0-9]{1,6}(?:\.[0-9]{1,3})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Informe até 999999,999, com no máximo três casas decimais e sem separador de milhares.']);
        }
        $parts = explode('.', $value);

        return ((int) $parts[0] * 1000) + (int) str_pad($parts[1] ?? '', 3, '0');
    }

    public static function format(int $quantity): string
    {
        return number_format(intdiv($quantity, 1000), 0, ',', '.').','.str_pad((string) ($quantity % 1000), 3, '0', STR_PAD_LEFT);
    }

    public function move(object $item, object $user, string $kind, int $quantity, string $reason, string $key, ?int $orderId = null, ?int $purchaseLineId = null, ?int $entryValue = null): void
    {
        $existing = DB::table('inventory_movements')->where('request_key', $key)->first();
        if ($existing) {
            abort_unless($existing->item_id === $item->id && $existing->user_id === $user->id && $existing->kind === $kind && $existing->quantity === $quantity && $existing->reason === $reason && $existing->order_id === $orderId && $existing->purchase_line_id === $purchaseLineId && ($kind !== 'in' || $existing->value_cents === $entryValue), 409, 'Esta solicitação já foi usada com outros dados.');

            return;
        }
        abort_unless($item->active, 409, 'Reative o insumo antes de movimentar o estoque.');
        abort_unless(in_array($kind, ['in', 'out'], true) && $quantity > 0, 422, 'Informe uma quantidade maior que zero.');
        abort_unless($entryValue === null || ($kind === 'in' && $entryValue >= 0 && $entryValue <= InventoryCosts::MAX_VALUE), 422);
        $balance = $item->balance + ($kind === 'in' ? $quantity : -$quantity);
        if ($balance < 0 || $balance > 999999999) {
            throw ValidationException::withMessages(['quantity' => $balance < 0 ? 'A saída é maior que o saldo disponível. Confira o estoque.' : 'O saldo ultrapassaria o limite de 999999,999.']);
        }
        $costs = InventoryCosts::transition($item, $kind, $quantity, $entryValue);
        DB::table('inventory_items')->where('id', $item->id)->update(['balance' => $balance, 'stock_value_cents' => $costs['stock_value_after_cents'], 'average_cost_micros' => $costs['average_cost_micros'], 'updated_at' => now()]);
        $id = DB::table('inventory_movements')->insertGetId(['tenant_id' => $item->tenant_id, 'store_id' => $item->store_id, 'item_id' => $item->id, 'user_id' => $user->id,
            'item_name' => $item->name, 'unit' => $item->unit, 'kind' => $kind, 'quantity' => $quantity, 'balance_after' => $balance, 'reason' => $reason, 'request_key' => $key, 'order_id' => $orderId, 'purchase_line_id' => $purchaseLineId, 'created_at' => now()] + $costs);
        Audit::record('inventory.moved', ['movement_id' => $id, 'item_id' => $item->id, 'kind' => $kind, 'quantity' => $quantity, 'balance_after' => $balance], $item->tenant_id);
    }
}
