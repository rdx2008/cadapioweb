<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderInventory
{
    private function unavailable(): never
    {
        throw ValidationException::withMessages(['cart' => 'A composição de estoque de um item precisa ser conferida pela loja. Revise o pedido ou entre em contato com a loja.']);
    }

    private function recipe(object $product, string $target, int $factor, array &$demand, array &$references, bool $required = true): void
    {
        $recipe = DB::table('product_recipes')->where('tenant_id', $product->tenant_id)->where('store_id', $product->store_id)->where('product_id', $product->id)->where('target_key', $target)->first();
        $lines = $recipe ? json_decode($recipe->lines, true) : [];
        if (! $lines && $required) {
            $this->unavailable();
        }
        if ($recipe) {
            $references[$recipe->id] = ['id' => $recipe->id, 'revision' => $recipe->revision, 'product_id' => $product->id, 'target' => $target];
        }
        foreach ($lines as $line) {
            if (! is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || ! is_int($line['item_id'] ?? null)) {
                $this->unavailable();
            }
            $id = $line['item_id'];
            if (isset($demand[$id]) && $demand[$id]['unit'] !== $line['unit']) {
                $this->unavailable();
            }
            $demand[$id] ??= ['unit' => $line['unit'], 'twelfths' => 0];
            $demand[$id]['twelfths'] += $line['quantity'] * $factor;
        }
    }

    private function base(object $product, ?int $variant, int $factor, array &$demand, array &$references): void
    {
        $target = 'base';
        if ($variant) {
            $specific = DB::table('product_recipes')->where('product_id', $product->id)->where('target_key', 'variant:'.$variant)->first();
            if ($specific && json_decode($specific->lines, true)) {
                $target = 'variant:'.$variant;
            }
        }
        $this->recipe($product, $target, $factor, $demand, $references, $product->product_type !== 'combo');
    }

    /**
     * Aggregate twelfths of a milli-unit so 1–4 equal pizza flavors stay exact
     * until the total for each ingredient is rounded up once per order.
     */
    public function snapshot(object $store, array $lines): ?array
    {
        if (! ($store->inventory_auto ?? false)) {
            return null;
        }
        $demand = $references = [];
        foreach ($lines as $line) {
            $product = $line['product'];
            $entry = $line['entry'];
            $factor = (int) $entry['quantity'] * 12;
            $this->base($product, isset($entry['variant_id']) ? (int) $entry['variant_id'] : null, $factor, $demand, $references);
            foreach ($entry['choices'] ?? [] as $ids) {
                foreach ($ids as $id) {
                    $this->recipe($product, 'option:'.(int) $id, $factor, $demand, $references);
                }
            }
            if ($product->pizza_enabled) {
                $flavors = $entry['flavors'] ?? [];
                if (count($flavors) < 1 || count($flavors) > 4) {
                    $this->unavailable();
                }
                foreach ($flavors as $flavor) {
                    $this->recipe($product, 'flavor:'.(int) $flavor.':'.(int) $entry['variant_id'], intdiv($factor, count($flavors)), $demand, $references);
                }
            }
            if ($product->product_type === 'combo') {
                $selected = array_merge([], ...array_values($entry['combo_choices'] ?? []));
                if ($selected === []) {
                    $this->unavailable();
                }
                $components = DB::table('combo_options')->join('combo_groups', 'combo_groups.id', '=', 'combo_options.combo_group_id')->where('combo_groups.product_id', $product->id)->whereIn('combo_options.id', $selected)->select('combo_options.*')->get();
                if ($components->count() !== count($selected)) {
                    $this->unavailable();
                }
                foreach ($components as $component) {
                    $child = DB::table('products')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('product_type', 'common')->where('id', $component->component_product_id)->first();
                    if (! $child || $component->quantity < 1) {
                        $this->unavailable();
                    }
                    $this->base($child, $component->component_variant_id, $factor * $component->quantity, $demand, $references);
                }
            }
        }
        ksort($demand);
        ksort($references);
        $items = DB::table('inventory_items')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('active', true)->whereIn('id', array_keys($demand))->get()->keyBy('id');
        if ($items->count() !== count($demand)) {
            $this->unavailable();
        }
        $snapshot = [];
        foreach ($demand as $id => $amount) {
            $quantity = intdiv($amount['twelfths'] + 11, 12);
            if ($quantity > 999999999 || $quantity < 1 || $items[$id]->unit !== $amount['unit']) {
                $this->unavailable();
            }
            $snapshot[] = ['item_id' => $id, 'name' => $items[$id]->name, 'unit' => $items[$id]->unit, 'quantity' => $quantity];
        }

        return ['version' => 1, 'items' => $snapshot, 'recipes' => array_values($references)];
    }

    public function consume(object $order, int $userId): void
    {
        $current = DB::table('orders')->where('tenant_id', $order->tenant_id)->where('id', $order->id)->lockForUpdate()->firstOrFail();
        if ($current->inventory_plan === null || $current->inventory_consumed_at !== null) {
            return;
        }
        $plan = json_decode($current->inventory_plan, true);
        abort_unless(($plan['version'] ?? null) === 1 && is_array($plan['items'] ?? null), 409, 'Composição de estoque inválida. Confira o pedido com o responsável.');
        $ids = array_column($plan['items'], 'item_id');
        $items = DB::table('inventory_items')->where('tenant_id', $current->tenant_id)->where('store_id', $current->store_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($plan['items'] as $line) {
            $item = $items->get($line['item_id']);
            if (! $item || ! $item->active || $item->unit !== $line['unit'] || $item->balance < $line['quantity']) {
                throw ValidationException::withMessages(['stock' => 'Não foi possível iniciar o preparo: confira saldo, unidade e situação do insumo '.($line['name'] ?? '').' no estoque.']);
            }
        }
        $actor = DB::table('users')->where('id', $userId)->firstOrFail();
        foreach ($plan['items'] as $line) {
            app(Inventory::class)->move($items[$line['item_id']], $actor, 'out', $line['quantity'], 'Preparo do pedido #'.$current->id, (string) Str::uuid(), $current->id);
        }
        DB::table('orders')->where('id', $current->id)->update(['inventory_consumed_at' => now()]);
        Audit::record('inventory.order_consumed', ['order_id' => $current->id, 'items' => count($plan['items'])], $current->tenant_id);
    }
}
