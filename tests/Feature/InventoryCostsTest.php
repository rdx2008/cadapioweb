<?php

namespace Tests\Feature;

use App\Services\InventoryCosts;
use App\Services\OrderInventory;
use App\Services\ProductRecipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class InventoryCostsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        $item = DB::table('inventory_items')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Farinha', 'name_key' => 'farinha', 'unit' => 'kg', 'balance' => 10000]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Prato', 'category' => 'Pratos', 'price_cents' => 2500]);

        return compact('tenant', 'store', 'owner', 'item', 'product');
    }

    private function value(int $item, string $amount = '50'): array
    {
        return ['value' => $amount, 'reason' => 'Estoque inicial conferido', 'confirmed' => 1, 'request_key' => (string) Str::uuid(), 'review_hash' => InventoryCosts::reviewHash(DB::table('inventory_items')->find($item), (int) DB::table('inventory_movements')->where('item_id', $item)->max('id'))];
    }

    private function recipe(array $f, int $product, string $target, string $quantity): void
    {
        $p = DB::table('products')->find($product);
        $recipes = app(ProductRecipes::class);
        $this->post('/products/'.$product.'/recipe', ['target' => $target, 'revision' => 0, 'reason' => 'Ficha inicial', 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'context_hash' => $recipes->context($p, $target, $recipes->targets($p)),
            'lines' => [['item_id' => $f['item'], 'quantity' => $quantity]]])->assertSessionHasNoErrors();
    }

    public function test_valuation_is_reviewed_idempotent_and_preserves_old_movement_costs(): void
    {
        $f = $this->fixture();
        $path = '/inventory/items/'.$f['item'].'/value';
        $value = $this->value($f['item']);
        $this->post($path, $value)->assertSessionHasNoErrors();
        $this->post($path, $value)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_valuations', 1);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 10000, 'stock_value_cents' => 5000, 'average_cost_micros' => 5000000]);
        $this->post($path, array_replace($value, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $movement = ['kind' => 'out', 'quantity' => '1', 'reason' => 'Perda', 'confirmed' => 1, 'request_key' => (string) Str::uuid()];
        $this->post('/inventory/items/'.$f['item'].'/movements', $movement)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_movements', ['kind' => 'out', 'value_cents' => 500, 'stock_value_after_cents' => 4500]);
        $this->post($path, $this->value($f['item'], '90'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_movements', ['kind' => 'out', 'value_cents' => 500]);
        $this->get('/inventory/items/'.$f['item'].'/cost-history')->assertOk()->assertSee('Estoque inicial conferido');
        $this->get('/inventory')->assertOk()->assertSee('R$ 10,0000');
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_unknown_incoming_cost_is_not_zero_and_last_known_average_survives_empty_stock(): void
    {
        $f = $this->fixture();
        $path = '/inventory/items/'.$f['item'];
        $this->post($path.'/value', $this->value($f['item']))->assertSessionHasNoErrors();
        $move = ['kind' => 'out', 'quantity' => '10', 'reason' => 'Consumo', 'confirmed' => 1, 'request_key' => (string) Str::uuid()];
        $this->post($path.'/movements', $move)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 0, 'stock_value_cents' => 0, 'average_cost_micros' => 5000000]);
        $cost = app(InventoryCosts::class)->estimate([['item_id' => $f['item'], 'quantity' => 250]], $f['tenant'], $f['store'], 2500);
        $this->assertSame(1250000, $cost['cost_micros']);
        $this->assertTrue($cost['lines'][0]['last_cost']);
        $this->post($path.'/movements', array_replace($move, ['kind' => 'in', 'quantity' => '1', 'request_key' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 1000, 'stock_value_cents' => null, 'average_cost_micros' => null]);
        $this->post($path.'/movements', array_replace($move, ['kind' => 'in', 'quantity' => '1', 'entry_value' => '20', 'request_key' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 2000, 'stock_value_cents' => null]);
        $cost = app(InventoryCosts::class)->estimate([['item_id' => $f['item'], 'quantity' => 250]], $f['tenant'], $f['store'], 2500);
        $this->assertNull($cost['cost_micros']);
        $this->assertNull($cost['margin_micros']);
        $this->post($path.'/value', $this->value($f['item'], '0'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'average_cost_micros' => 0]);
    }

    public function test_simple_recipe_and_simulation_calculate_margin_without_any_operational_writes(): void
    {
        $f = $this->fixture();
        $this->recipe($f, $f['product'], 'base', '0,250');
        $this->post('/inventory/items/'.$f['item'].'/value', $this->value($f['item']))->assertSessionHasNoErrors();
        $this->get('/inventory/costs')->assertOk()->assertViewHas('estimates', fn ($estimates) => $estimates[$f['product']]['cost_micros'] === 1250000 && $estimates[$f['product']]['cmv_basis_points'] === 500);
        $this->get('/products/'.$f['product'].'/recipe')->assertOk()->assertSee('R$ 1,2500');
        $this->get('/inventory/costs/products/'.$f['product'])->assertOk();
        $this->post('/inventory/costs/products/'.$f['product'], ['entry' => ['quantity' => 2]])->assertOk()->assertViewHas('result', fn ($result) => $result['cost_micros'] === 2500000 && $result['price_cents'] === 5000 && $result['margin_micros'] === 47500000);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 10000]);
    }

    public function test_pizza_variant_flavors_and_border_are_costed_as_selected(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['product_type' => 'pizza', 'pizza_enabled' => true, 'uses_variants' => true]);
        $size = DB::table('product_variants')->insertGetId(['product_id' => $f['product'], 'name' => 'Grande', 'price_cents' => 5000, 'max_flavors' => 2]);
        $this->recipe($f, $f['product'], 'variant:'.$size, '0,250');
        $flavors = [];
        foreach (['A', 'B'] as $name) {
            $id = DB::table('pizza_flavors')->insertGetId(['product_id' => $f['product'], 'name' => $name, 'prices' => json_encode([$size => 5000])]);
            $flavors[] = $id;
            $this->recipe($f, $f['product'], 'flavor:'.$id.':'.$size, '0,100');
        }
        $group = DB::table('option_groups')->insertGetId(['product_id' => $f['product'], 'name' => 'Borda', 'kind' => 'border']);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Recheada', 'price_cents' => 500]);
        $this->recipe($f, $f['product'], 'option:'.$option, '0,050');
        $this->post('/inventory/items/'.$f['item'].'/value', $this->value($f['item']))->assertSessionHasNoErrors();
        $this->get('/inventory/costs/products/'.$f['product'])->assertOk()->assertSee('Grande')->assertSee('Recheada');
        $this->post('/inventory/costs/products/'.$f['product'], ['entry' => ['quantity' => 2, 'variant_id' => $size, 'flavors' => $flavors, 'choices' => [$group => [$option]]]])->assertOk()->assertViewHas('result', fn ($result) => $result['cost_micros'] === 4000000 && $result['price_cents'] === 11000 && $result['lines'][0]['quantity'] === 800);
        $this->get('/inventory/costs')->assertOk()->assertViewHas('estimates', fn ($estimates) => $estimates[$f['product']]['price_cents'] === null);
    }

    public function test_combo_counts_component_units_and_missing_recipe_blocks_partial_costs(): void
    {
        $f = $this->fixture();
        $this->recipe($f, $f['product'], 'base', '0,250');
        $combo = DB::table('products')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Combo', 'category' => 'Combos', 'product_type' => 'combo', 'price_cents' => 4000]);
        $group = DB::table('combo_groups')->insertGetId(['product_id' => $combo, 'name' => 'Pratos', 'minimum' => 1, 'maximum' => 1]);
        $option = DB::table('combo_options')->insertGetId(['combo_group_id' => $group, 'component_product_id' => $f['product'], 'quantity' => 2, 'surcharge_cents' => 100]);
        $this->post('/inventory/items/'.$f['item'].'/value', $this->value($f['item']))->assertSessionHasNoErrors();
        $this->get('/inventory/costs/products/'.$combo)->assertOk()->assertSee('Pratos');
        $entry = ['quantity' => 2, 'combo_choices' => [$group => [$option]]];
        $this->post('/inventory/costs/products/'.$combo, ['entry' => $entry])->assertOk()->assertViewHas('result', fn ($result) => $result['cost_micros'] === 5000000 && $result['price_cents'] === 8400 && $result['lines'][0]['quantity'] === 1000);
        DB::table('product_recipes')->where('product_id', $f['product'])->update(['lines' => '[]']);
        $this->post('/inventory/costs/products/'.$combo, ['entry' => $entry])->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_valuation_stale_after_movements_cross_tenant_and_audit_failure_are_blocked(): void
    {
        $f = $this->fixture();
        $value = $this->value($f['item']);
        foreach (['in', 'out'] as $kind) {
            $this->post('/inventory/items/'.$f['item'].'/movements', ['kind' => $kind, 'quantity' => '1', 'reason' => 'Contagem', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $this->post('/inventory/items/'.$f['item'].'/value', $value)->assertConflict();
        $this->assertDatabaseCount('inventory_valuations', 0);
        DB::statement("CREATE TRIGGER fail_value BEFORE INSERT ON audit_events WHEN NEW.action='inventory.valued' BEGIN SELECT RAISE(FAIL,'audit unavailable'); END");
        $this->post('/inventory/items/'.$f['item'].'/value', $this->value($f['item']))->assertStatus(500);
        $this->assertDatabaseCount('inventory_valuations', 0);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'stock_value_cents' => null]);
        $this->actingAs($this->owner($this->tenant('foreign')));
        $this->get('/inventory/items/'.$f['item'].'/cost-history')->assertNotFound();
        $this->post('/inventory/items/'.$f['item'].'/value', $value)->assertNotFound();
        $this->get('/inventory/costs/products/'.$f['product'])->assertNotFound();
    }

    public function test_cost_math_handles_fractional_quantities_large_values_and_unknown_inputs(): void
    {
        $this->assertSame(1250000, InventoryCosts::ratio(5000, 250, 10000, 10000));
        $this->assertSame(333333333330000000, InventoryCosts::ratio(99999999999, 1000, 3, 10000));
        $this->assertNull(InventoryCosts::ratio(PHP_INT_MAX, 1000, 1, 10000));
        $this->assertSame(33, InventoryCosts::ratio(100, 1, 3, round: false));
        $this->assertNull(InventoryCosts::ratio(100, 1, 0));
        $this->assertNull(app(InventoryCosts::class)->estimate([], 1, 1, 100)['cost_micros']);
    }

    public function test_preparation_freezes_actual_ingredient_cost_and_revaluation_is_not_retroactive(): void
    {
        $f = $this->fixture();
        $this->recipe($f, $f['product'], 'base', '0,250');
        $this->post('/inventory/items/'.$f['item'].'/value', $this->value($f['item']))->assertSessionHasNoErrors();
        $store = DB::table('stores')->find($f['store']);
        $store->inventory_auto = true;
        $plan = app(OrderInventory::class)->snapshot($store, [['product' => DB::table('products')->find($f['product']), 'entry' => ['quantity' => 2]]]);
        $id = $this->order($f['tenant'], $f['store']);
        DB::table('orders')->where('id', $id)->update(['status' => 'accepted', 'inventory_plan' => json_encode($plan)]);
        $this->post('/orders/'.$id.'/status', ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $id, 'kind' => 'out', 'quantity' => 500, 'value_cents' => 250, 'stock_value_after_cents' => 4750]);
        $this->post('/inventory/items/'.$f['item'].'/value', $this->value($f['item'],'95'))->assertSessionHasNoErrors();
        DB::transaction(fn () => app(OrderInventory::class)->consume(DB::table('orders')->find($id),$f['owner']->id));
        $this->assertDatabaseCount('inventory_movements',1);
        $this->assertDatabaseHas('inventory_movements',['order_id' => $id, 'value_cents' => 250]);
        $this->assertDatabaseHas('inventory_items',['id' => $f['item'], 'balance' => 9500, 'stock_value_cents' => 9500]);
    }
}
