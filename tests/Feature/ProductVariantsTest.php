<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function product(int $tenant, int $store, string $name = 'Pizza da casa'): int
    {
        return DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => $name, 'category' => 'Pizzas', 'price_cents' => 1000]);
    }

    private function variant(int $product, string $name = 'Grande', int $price = 4500): int
    {
        return DB::table('product_variants')->insertGetId(['product_id' => $product, 'name' => $name, 'price_cents' => $price, 'available' => true]);
    }

    private function cart(int $product, int $variant): array
    {
        return ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'items' => [$product => 2], 'variants' => [$product => $variant], 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_owner_can_manage_variants_and_mode_without_accessing_foreign_products(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $foreignTenant = $this->tenant('other');
        $foreignProduct = $this->product($foreignTenant, $this->store($foreignTenant, 'other'));
        $foreignVariant = $this->variant($foreignProduct);
        $this->actingAs($this->owner($tenant));
        $this->postJson('/products/'.$product.'/pricing-mode', ['uses_variants' => 1])->assertUnprocessable();
        $this->post('/products/'.$product.'/variants', ['name' => 'Pequena', 'variant_price' => '30,90', 'available' => 1, 'product_id' => $foreignProduct])->assertRedirect();
        $variant = DB::table('product_variants')->where('product_id', $product)->first();
        $this->assertSame(3090, $variant->price_cents);
        $this->post('/products/'.$product.'/pricing-mode', ['uses_variants' => 1])->assertRedirect();
        $this->get('/products/'.$product.'/edit')->assertOk()->assertSee('Pequena')->assertSee('Preço por tamanho');
        $this->post('/products/'.$foreignProduct.'/variants', ['name' => 'Invasão', 'price_cents' => 1, 'available' => 1])->assertNotFound();
        $this->post('/products/'.$foreignProduct.'/pricing-mode', ['uses_variants' => 0])->assertNotFound();
        $this->post('/products/'.$product.'/variants', ['variant_id' => $foreignVariant, 'name' => 'Invasão', 'price_cents' => 1, 'available' => 1])->assertNotFound();
        $this->postJson('/products/'.$product.'/variants', ['name' => 'Inválida', 'variant_price' => '-1', 'available' => 1])->assertUnprocessable();
        $this->post('/products/'.$product.'/variants', ['variant_id' => $variant->id, 'name' => 'Pequena atualizada', 'price_cents' => 3500, 'available' => 0])->assertRedirect();
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'product_id' => $product, 'price_cents' => 3500, 'available' => false]);
        $this->assertDatabaseHas('product_variants', ['id' => $foreignVariant, 'name' => 'Grande']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant, 'action' => 'product.variant.saved']);
        $this->actingAs(User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant]))->post('/products/'.$product.'/pricing-mode', ['uses_variants' => 0])->assertForbidden();
    }

    public function test_variant_replaces_base_addons_are_per_unit_and_order_snapshot_survives_edits(): void
    {
        $tenant = $this->tenant();
        $product = $this->product($tenant, $this->store($tenant));
        $variant = $this->variant($product);
        DB::table('products')->where('id', $product)->update(['uses_variants' => true]);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $product, 'name' => 'Borda', 'minimum' => 1, 'maximum' => 1]);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Catupiry', 'price_cents' => 500]);
        $cart = $this->cart($product, $variant) + ['choices' => [$product => [$group => [$option]]], 'price_cents' => 1];
        $review = $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('100,00')->assertSee('Grande')->assertSee('preço base R$ 45,00');
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('order_items', ['unit_cents' => 5000, 'quantity' => 2]);
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 10000]);
        $order = DB::table('orders')->first();
        DB::table('product_variants')->where('id', $variant)->update(['name' => 'Nome novo', 'price_cents' => 9999, 'available' => false]);
        $this->get('/pedido/'.$order->public_id)->assertOk()->assertSee('Grande')->assertSee('Catupiry')->assertDontSee('Nome novo');
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_missing_paused_or_foreign_variant_is_rejected_and_cannot_fall_back_to_base_price(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $variant = $this->variant($product);
        $foreign = $this->variant($this->product($tenant, $this->store($tenant, 'outra')));
        DB::table('products')->where('id', $product)->update(['uses_variants' => true]);
        $cart = $this->cart($product, $foreign);
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        unset($cart['variants']);
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['variants'] = [$product => $variant];
        DB::table('product_variants')->where('id', $variant)->update(['available' => false]);
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->get('/cardapio/loja')->assertOk()->assertDontSee('Pizza da casa');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_price_availability_and_mode_are_revalidated_before_final_confirmation(): void
    {
        $tenant = $this->tenant();
        $product = $this->product($tenant, $this->store($tenant));
        $variant = $this->variant($product);
        DB::table('products')->where('id', $product)->update(['uses_variants' => true]);
        $cart = $this->cart($product, $variant);
        foreach (['price_cents' => 5500, 'available' => false] as $field => $value) {
            DB::table('product_variants')->where('id', $variant)->update(['price_cents' => 4500, 'available' => true]);
            $this->post('/cardapio/loja/review', $cart)->assertRedirect();
            DB::table('product_variants')->where('id', $variant)->update([$field => $value]);
            $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable();
        }
        DB::table('product_variants')->where('id', $variant)->update(['available' => true]);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        DB::table('products')->where('id', $product)->update(['uses_variants' => false]);
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_menu_displays_lowest_available_price_and_edit_restores_selected_variant(): void
    {
        $tenant = $this->tenant();
        $product = $this->product($tenant, $this->store($tenant));
        $large = $this->variant($product);
        $this->variant($product, 'Pequena', 3000);
        $paused = $this->variant($product, 'Indisponível', 500);
        DB::table('product_variants')->where('id', $paused)->update(['available' => false]);
        DB::table('products')->where('id', $product)->update(['uses_variants' => true]);
        $this->get('/cardapio/loja')->assertOk()->assertSee('A partir de R$ 30,00')->assertSee('Grande')->assertSee('Pequena')->assertDontSee('Indisponível');
        $cart = $this->cart($product, $large);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $response = $this->get('/cardapio/loja?edit='.$cart['idempotency_key'])->assertOk();
        $this->assertMatchesRegularExpression('/value="'.$large.'"[^>]*selected/', $response->getContent());
    }
}
