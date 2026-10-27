<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class PizzaOrderingTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Monte sua pizza', 'category' => 'Pizzas', 'price_cents' => 100, 'uses_variants' => true, 'pizza_enabled' => true]);
        $small = DB::table('product_variants')->insertGetId(['product_id' => $product, 'name' => 'Pequena', 'price_cents' => 100, 'max_flavors' => 1]);
        $large = DB::table('product_variants')->insertGetId(['product_id' => $product, 'name' => 'Grande', 'price_cents' => 100, 'max_flavors' => 4]);
        $a = DB::table('pizza_flavors')->insertGetId(['product_id' => $product, 'name' => 'Calabresa', 'prices' => json_encode([$small => 3000, $large => 5000])]);
        $b = DB::table('pizza_flavors')->insertGetId(['product_id' => $product, 'name' => 'Quatro queijos', 'prices' => json_encode([$large => 7000])]);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $product, 'name' => 'Escolha a borda', 'kind' => 'border', 'minimum' => 1, 'maximum' => 1]);
        $border = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Borda recheada', 'price_cents' => 800]);
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'idempotency_key' => (string) Str::uuid(), 'cart_lines' => [['product_id' => $product, 'quantity' => 2, 'variant_id' => $large, 'flavors' => [$a, $b], 'choices' => [$group => [$border]]]]];

        return compact('tenant', 'store', 'product', 'small', 'large', 'a', 'b', 'group', 'border', 'cart');
    }

    public function test_highest_flavor_price_plus_one_border_per_pizza_is_snapshotted_for_customer_and_kitchen(): void
    {
        $f = $this->fixture();
        $response = $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('156,00')->assertSee('1/2 Calabresa')->assertSee('1/2 Quatro queijos')->assertSee('Borda recheada')->assertSee('Maior preço entre os sabores');
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('order_items', ['unit_cents' => 7800, 'quantity' => 2]);
        DB::table('pizza_flavors')->where('id', $f['a'])->update(['name' => 'Alterado', 'available' => false]);
        $this->get('/pedido/'.DB::table('orders')->value('public_id'))->assertOk()->assertSee('Calabresa')->assertSee('Quatro queijos')->assertDontSee('Alterado');
        $kitchen = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'kitchen']);
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'user_id' => $kitchen->id, 'store_id' => $f['store']]);
        $this->actingAs($kitchen)->get('/operation/orders/'.DB::table('orders')->value('id'))->assertOk()->assertSee('Calabresa')->assertSee('Borda recheada')->assertDontSee('156,00');
    }

    public function test_average_uses_equal_parts_integer_rounding_and_current_rule_is_rechecked_at_confirmation(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['pizza_pricing' => 'average']);
        $response = $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('136,00')->assertSee('Média dos sabores');
        DB::table('pizza_flavors')->where('id', $f['a'])->update(['prices' => json_encode([$f['large'] => 5001])]);
        $response = $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('136,02');
        DB::table('products')->where('id', $f['product'])->update(['pizza_pricing' => 'highest']);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_different_sizes_and_flavor_combinations_of_same_pizza_remain_independent(): void
    {
        $f = $this->fixture();
        $cart = $f['cart'];
        $cart['cart_lines'][] = ['product_id' => $f['product'], 'quantity' => 1, 'variant_id' => $f['small'], 'flavors' => [$f['a']], 'choices' => [$f['group'] => [$f['border']]]];
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseHas('order_items', ['unit_cents' => 7800, 'quantity' => 2]);
        $this->assertDatabaseHas('order_items', ['unit_cents' => 3800, 'quantity' => 1]);
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 19400]);
    }

    public function test_flavor_limits_missing_prices_duplicates_and_foreign_flavors_are_rejected(): void
    {
        $f = $this->fixture();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['variant_id'] = $f['small'];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['cart_lines'][0]['flavors'] = [$f['b']];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['flavors'] = [$f['a'], $f['a']];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['cart_lines'][0]['flavors'] = [];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $other = DB::table('products')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Outro', 'category' => 'Pizzas', 'price_cents' => 100]);
        $foreign = DB::table('pizza_flavors')->insertGetId(['product_id' => $other, 'name' => 'Outro sabor', 'prices' => json_encode([$f['large'] => 1])]);
        $cart['cart_lines'][0]['flavors'] = [$foreign];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_paused_flavor_or_changed_price_after_review_blocks_entire_order(): void
    {
        $f = $this->fixture();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('pizza_flavors')->where('id', $f['b'])->update(['prices' => json_encode([$f['large'] => 8000])]);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('pizza_flavors')->where('id', $f['b'])->update(['available' => false]);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_owner_manages_size_prices_and_pizza_mode_with_store_isolation_and_border_limits(): void
    {
        $f = $this->fixture();
        $foreignTenant = $this->tenant('other');
        $foreignProduct = DB::table('products')->insertGetId(['tenant_id' => $foreignTenant, 'store_id' => $this->store($foreignTenant, 'other'), 'name' => 'Outra', 'category' => 'Pizzas', 'price_cents' => 100]);
        $this->actingAs($this->owner($f['tenant']));
        $this->post('/products/'.$f['product'].'/flavors', ['name' => 'Portuguesa', 'available' => 1, 'prices' => [$f['small'] => '35,90', $f['large'] => '59,90']])->assertRedirect();
        $this->assertDatabaseHas('pizza_flavors', ['product_id' => $f['product'], 'name' => 'Portuguesa']);
        $this->post('/products/'.$foreignProduct.'/flavors', ['name' => 'Invasão', 'available' => 1, 'prices' => [$f['small'] => '1,00']])->assertNotFound();
        $foreignSize = DB::table('product_variants')->insertGetId(['product_id' => $foreignProduct, 'name' => 'Outro', 'price_cents' => 100]);
        $this->postJson('/products/'.$f['product'].'/flavors', ['name' => 'Inválido', 'available' => 1, 'prices' => [$foreignSize => '1,00']])->assertUnprocessable();
        $this->postJson('/products/'.$f['product'].'/pricing-mode', ['uses_variants' => 0])->assertUnprocessable();
        $this->postJson('/products/'.$f['product'].'/groups', ['name' => 'Outra borda', 'kind' => 'border', 'minimum' => 1, 'maximum' => 2])->assertUnprocessable();
        $this->postJson('/products/'.$f['product'].'/groups', ['name' => 'Outra borda', 'kind' => 'border', 'minimum' => 1, 'maximum' => 1])->assertUnprocessable();
        $this->post('/products/'.$f['product'].'/pizza', ['pizza_enabled' => 1, 'pizza_pricing' => 'average'])->assertRedirect();
        $this->get('/products/'.$f['product'].'/edit')->assertOk()->assertSee('Portuguesa')->assertSee('Máximo de sabores');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $f['tenant'], 'action' => 'product.flavor.saved']);
    }

    public function test_public_menu_uses_flavor_price_and_removes_sizes_without_available_flavors(): void
    {
        $f = $this->fixture();
        $this->get('/cardapio/loja')->assertOk()->assertSee('A partir de R$ 30,00')->assertSee('Monte seus sabores')->assertSee('Calabresa');
        DB::table('pizza_flavors')->where('id', $f['a'])->update(['available' => false]);
        $response = $this->get('/cardapio/loja')->assertOk()->assertSee('A partir de R$ 70,00');
        $this->assertStringNotContainsString('data-variant-name="Pequena"', $response->getContent());
        DB::table('pizza_flavors')->where('id', $f['b'])->update(['available' => false]);
        $this->get('/cardapio/loja')->assertOk()->assertDontSee('Monte sua pizza');
    }
}
