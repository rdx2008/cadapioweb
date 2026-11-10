<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrderInventory;
use App\Services\ProductRecipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class OrderInventoryTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        DB::table('stores')->where('id', $store)->update(['inventory_auto' => true]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Prato da casa', 'category' => 'Pratos', 'price_cents' => 3000]);
        $item = DB::table('inventory_items')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Farinha privada', 'name_key' => 'farinha', 'unit' => 'kg', 'balance' => 10000, 'minimum' => 0, 'active' => true]);
        $this->actingAs($owner);
        $f = compact('tenant', 'store', 'owner', 'product', 'item');
        $this->recipe($f, $product, 'base', [$item => '0,250']);

        return $f;
    }

    private function recipe(array $f, int $productId, string $target, array $lines): void
    {
        $product = DB::table('products')->find($productId);
        $service = app(ProductRecipes::class);
        $current = DB::table('product_recipes')->where('product_id', $productId)->where('target_key', $target)->first();
        $this->post('/products/'.$productId.'/recipe', ['target' => $target, 'revision' => $current?->revision ?? 0, 'context_hash' => $service->context($product, $target, $service->targets($product)),
            'request_key' => (string) Str::uuid(), 'reason' => 'Ficha conferida', 'confirmed' => 1, 'lines' => collect($lines)->map(fn ($quantity, $id) => ['item_id' => $id, 'quantity' => $quantity])->values()->all()])->assertSessionHasNoErrors();
    }

    private function posOrder(array $f, ?array $entries = null): object
    {
        $key = (string) Str::uuid();
        $this->post('/pos/'.$f['store'].'/review', ['idempotency_key' => $key, 'mode' => 'pickup', 'payment_method' => 'cash', 'received' => 'no',
            'cart_lines' => $entries ?? [['product_id' => $f['product'], 'quantity' => 1]]])->assertSessionHasNoErrors();
        $draft = $this->get('/pos/'.$f['store'].'/review/'.$key)->assertOk()->viewData('draft');
        $this->post('/pos/'.$f['store'].'/review/'.$key.'/confirm', ['review_hash' => hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR))])->assertSessionHasNoErrors();

        return DB::table('orders')->where('idempotency_key', $key)->firstOrFail();
    }

    public function test_begin_preparation_consumes_frozen_recipe_once_and_cancellation_does_not_invent_returns(): void
    {
        $f = $this->fixture();
        $order = $this->posOrder($f);
        $this->recipe($f, $f['product'], 'base', [$f['item'] => '0,900']);
        $this->post('/inventory/settings', ['store_id' => $f['store'], 'inventory_auto' => 0, 'confirmed' => 1])->assertSessionHasNoErrors();
        $path = '/orders/'.$order->id.'/status';
        $this->post($path, ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 9750]);
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'quantity' => 250, 'kind' => 'out']);
        $this->assertNotNull(DB::table('orders')->find($order->id)->inventory_consumed_at);
        $this->post($path, ['status' => 'preparing'])->assertConflict();
        DB::transaction(fn () => app(OrderInventory::class)->consume($order, $f['owner']->id));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->post($path, ['status' => 'cancelled', 'cancellation_reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 9750]);
        $manual = $this->posOrder($f);
        $this->assertNull($manual->inventory_plan);
        $this->post('/orders/'.$manual->id.'/status', ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_shortage_or_audit_failure_rolls_back_all_ingredients_and_production_state(): void
    {
        $f = $this->fixture();
        $second = DB::table('inventory_items')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Leite', 'name_key' => 'leite', 'unit' => 'l', 'balance' => 0]);
        $this->recipe($f, $f['product'], 'base', [$f['item'] => '0,250', $second => '0,100']);
        $order = $this->posOrder($f);
        $path = '/orders/'.$order->id.'/status';
        $this->post($path, ['status' => 'preparing'])->assertSessionHasErrors('stock');
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'accepted', 'inventory_consumed_at' => null]);
        DB::table('inventory_items')->where('id', $second)->update(['balance' => 100]);
        DB::statement("CREATE TRIGGER fail_order_stock BEFORE INSERT ON audit_events WHEN NEW.action = 'inventory.order_consumed' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post($path, ['status' => 'preparing'])->assertStatus(500);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 10000]);
        $this->assertDatabaseHas('inventory_items', ['id' => $second, 'balance' => 100]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'accepted', 'inventory_consumed_at' => null]);
    }

    public function test_pizza_uses_size_override_addon_and_aggregates_equal_flavor_fractions_before_rounding(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['product_type' => 'pizza', 'uses_variants' => true, 'pizza_enabled' => true]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $f['product'], 'name' => 'Grande', 'price_cents' => 5000, 'max_flavors' => 4]);
        $topping = DB::table('inventory_items')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Cobertura', 'name_key' => 'cobertura', 'unit' => 'kg', 'balance' => 1000]);
        $this->recipe($f, $f['product'], 'variant:'.$variant, [$f['item'] => '0,250']);
        $flavors = [];
        foreach (['A', 'B', 'C'] as $name) {
            $flavor = DB::table('pizza_flavors')->insertGetId(['product_id' => $f['product'], 'name' => $name, 'prices' => json_encode([$variant => 5000])]);
            $flavors[] = $flavor;
            $this->recipe($f, $f['product'], 'flavor:'.$flavor.':'.$variant, [$topping => '0,001']);
        }
        $group = DB::table('option_groups')->insertGetId(['product_id' => $f['product'], 'name' => 'Borda', 'kind' => 'border']);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Recheada', 'price_cents' => 0]);
        $this->recipe($f, $f['product'], 'option:'.$option, [$f['item'] => '0,050']);
        $order = $this->posOrder($f, [['product_id' => $f['product'], 'quantity' => 2, 'variant_id' => $variant, 'flavors' => $flavors, 'choices' => [$group => [$option]]]]);
        $plan = collect(json_decode($order->inventory_plan, true)['items'])->keyBy('item_id');
        $this->assertSame(600, $plan[$f['item']]['quantity']);
        $this->assertSame(2, $plan[$topping]['quantity']);
        $this->post('/orders/'.$order->id.'/status', ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item'], 'balance' => 9400]);
        $this->assertDatabaseHas('inventory_items', ['id' => $topping, 'balance' => 998]);
    }

    public function test_combo_consumes_packaging_and_component_size_for_each_selected_unit(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['uses_variants' => true]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $f['product'], 'name' => 'Maior', 'price_cents' => 1000]);
        $this->recipe($f, $f['product'], 'variant:'.$variant, [$f['item'] => '0,200']);
        $combo = DB::table('products')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Combo', 'category' => 'Combos', 'price_cents' => 3000, 'product_type' => 'combo']);
        $packaging = DB::table('inventory_items')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Embalagem', 'name_key' => 'embalagem', 'unit' => 'un', 'balance' => 10000]);
        $this->recipe($f, $combo, 'base', [$packaging => '1']);
        $group = DB::table('combo_groups')->insertGetId(['product_id' => $combo, 'name' => 'Pratos', 'minimum' => 1, 'maximum' => 1]);
        $option = DB::table('combo_options')->insertGetId(['combo_group_id' => $group, 'component_product_id' => $f['product'], 'component_variant_id' => $variant, 'quantity' => 2]);
        $order = $this->posOrder($f, [['product_id' => $combo, 'quantity' => 3, 'combo_choices' => [$group => [$option]]]]);
        $plan = collect(json_decode($order->inventory_plan, true)['items'])->keyBy('item_id');
        $this->assertSame(1200, $plan[$f['item']]['quantity']);
        $this->assertSame(3000, $plan[$packaging]['quantity']);
    }

    public function test_public_checkout_freezes_private_stock_plan_and_legacy_orders_remain_manual(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'payment_method' => 'cash', 'idempotency_key' => $key, 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1]]];
        $review = $this->post('/cardapio/loja/review', $cart)->assertRedirect()->assertSessionHasNoErrors();
        $this->get($review->headers->get('Location'))->assertOk()->assertDontSee('Farinha privada')->assertDontSee('inventory_plan');
        $this->post('/cardapio/loja', ['draft_id' => $key])->assertRedirect()->assertSessionHasNoErrors();
        $order = DB::table('orders')->where('idempotency_key', $key)->firstOrFail();
        $this->assertNotNull($order->inventory_plan);
        $this->get('/pedido/'.$order->public_id.'/status')->assertOk()->assertDontSee('inventory_plan')->assertDontSee('Farinha privada');
        $this->post('/orders/'.$order->id.'/status', ['status' => 'accepted'])->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();
        $legacy = $this->order($f['tenant'], $f['store']);
        DB::table('orders')->where('id', $legacy)->update(['status' => 'accepted']);
        $this->post('/orders/'.$legacy.'/status', ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_missing_recipe_blocks_new_checkout_without_exposing_inventory_and_setting_is_scoped(): void
    {
        $f = $this->fixture();
        $this->recipe($f, $f['product'], 'base', []);
        $this->post('/cardapio/loja/review', ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1]]])->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $foreign = $this->store($this->tenant('other'), 'other');
        $this->post('/inventory/settings', ['store_id' => $foreign, 'inventory_auto' => 1, 'confirmed' => 1])->assertNotFound();
        $this->post('/inventory/settings', ['store_id' => $f['store'], 'inventory_auto' => 0, 'confirmed' => 1])->assertSessionHasNoErrors();
        $order = $this->posOrder($f);
        $this->assertNull($order->inventory_plan);
    }

    public function test_kitchen_permission_is_rechecked_and_automatic_move_records_kitchen_actor(): void
    {
        $f = $this->fixture();
        $order = $this->posOrder($f);
        $user = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'kitchen']);
        $path = '/kitchen/orders/'.$order->id.'/status';
        $this->actingAs($user)->post($path, ['status' => 'preparing'])->assertNotFound();
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'user_id' => $user->id]);
        $this->post($path, ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_movements', ['order_id' => $order->id, 'user_id' => $user->id]);
    }

    public function test_recipe_changed_during_checkout_requires_review_before_creating_order(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $this->post('/pos/'.$f['store'].'/review', ['idempotency_key' => $key, 'mode' => 'pickup', 'payment_method' => 'cash', 'received' => 'no', 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1]]])->assertSessionHasNoErrors();
        $draft = $this->get('/pos/'.$f['store'].'/review/'.$key)->assertOk()->viewData('draft');
        $this->recipe($f, $f['product'], 'base', [$f['item'] => '0,500']);
        $this->post('/pos/'.$f['store'].'/review/'.$key.'/confirm', ['review_hash' => hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR))])->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
