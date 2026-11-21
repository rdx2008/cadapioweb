<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CartCombinationsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Pizza da casa', 'category' => 'Pizzas', 'price_cents' => 1000, 'uses_variants' => true]);
        $small = DB::table('product_variants')->insertGetId(['product_id' => $product, 'name' => 'Pequena', 'price_cents' => 3000]);
        $large = DB::table('product_variants')->insertGetId(['product_id' => $product, 'name' => 'Grande', 'price_cents' => 4500]);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $product, 'name' => 'Borda', 'minimum' => 1, 'maximum' => 1]);
        $cheese = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Queijo', 'price_cents' => 500]);
        $plain = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Sem borda', 'price_cents' => 0]);
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'idempotency_key' => (string) Str::uuid(), 'cart_lines' => [
            ['product_id' => $product, 'quantity' => 1, 'variant_id' => $small, 'choices' => [$group => [$plain]]],
            ['product_id' => $product, 'quantity' => 2, 'variant_id' => $large, 'choices' => [$group => [$cheese]]],
        ]];

        return compact('tenant', 'store', 'product', 'small', 'large', 'group', 'cheese', 'plain', 'cart');
    }

    public function test_different_combinations_are_priced_independently_preserved_and_idempotent(): void
    {
        $f = $this->fixture();
        $cart = $f['cart'];
        $review = $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('Pequena')->assertSee('Grande')->assertSee('Queijo')->assertSee('130,00');
        $this->assertDatabaseCount('orders', 0);
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseHas('order_items', ['quantity' => 1, 'unit_cents' => 3000]);
        $this->assertDatabaseHas('order_items', ['quantity' => 2, 'unit_cents' => 5000]);
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 13000]);
        DB::table('product_variants')->where('id', $f['large'])->update(['name' => 'Mudou', 'price_cents' => 8000]);
        $this->get('/pedido/'.DB::table('orders')->value('public_id'))->assertOk()->assertSee('Pequena')->assertSee('Grande')->assertDontSee('Mudou');
        $this->actingAs($this->owner($f['tenant']))->get('/orders/'.DB::table('orders')->value('id'))->assertOk()->assertSee('Pequena')->assertSee('Grande');
    }

    public function test_one_invalid_combination_rejects_whole_cart_and_foreign_products_or_options_are_not_accepted(): void
    {
        $f = $this->fixture();
        $cart = $f['cart'];
        $cart['cart_lines'][1]['choices'] = [];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'][1]['choices'][$f['group']] = [$f['cheese'], $f['cheese']];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $other = $this->tenant('other');
        $product = DB::table('products')->insertGetId(['tenant_id' => $other, 'store_id' => $this->store($other, 'other'), 'name' => 'Outro', 'category' => 'Outros', 'price_cents' => 100]);
        $cart = $f['cart'];
        $cart['cart_lines'][1]['product_id'] = $product;
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'name' => 'Outro', 'price_cents' => 1]);
        $cart = $f['cart'];
        $cart['cart_lines'][1]['variant_id'] = $variant;
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_changing_one_lines_price_or_availability_requires_new_review(): void
    {
        $f = $this->fixture();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('product_options')->where('id', $f['cheese'])->update(['price_cents' => 600]);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('product_variants')->where('id', $f['small'])->update(['available' => false]);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quantity_limits_and_mixed_or_tampered_formats_are_rejected(): void
    {
        $f = $this->fixture();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['quantity'] = 25;
        $cart['cart_lines'][1]['quantity'] = 26;
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['quantity'] = 0;
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['items'] = [$f['product'] => 1];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['unit_cents'] = 1;
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'] = array_fill(0, 51, $cart['cart_lines'][0]);
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_edit_restores_all_combinations_and_order_without_mixing_choices(): void
    {
        $f = $this->fixture();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $response = $this->get('/cardapio/loja?edit='.$f['cart']['idempotency_key'])->assertOk();
        $seed = $response->getContent();
        preg_match('/data-cart-seed>(.*?)<\/script>/s', $seed, $match);
        $data = json_decode($match[1], true);
        $this->assertSame($f['small'], $data['cart_lines'][0]['variant_id']);
        $this->assertSame($f['large'], $data['cart_lines'][1]['variant_id']);
        $this->assertSame([$f['cheese']], $data['cart_lines'][1]['choices'][$f['group']]);
        $this->assertSame([$f['plain']], $data['cart_lines'][0]['choices'][$f['group']]);
    }
}
