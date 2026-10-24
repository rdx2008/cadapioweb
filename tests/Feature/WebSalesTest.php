<?php

namespace Tests\Feature;

use App\Services\StoreAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class WebSalesTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function product(int $tenant, int $store): int
    {
        return DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Lanche da casa', 'category' => 'Lanches', 'price_cents' => 1000]);
    }

    private function cart(int $product): array
    {
        return ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'items' => [$product => 2], 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_required_addons_are_priced_per_unit_and_preserved_as_snapshots(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $product, 'name' => 'Queijo', 'minimum' => 1, 'maximum' => 1]);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Cheddar', 'price_cents' => 250]);
        $cart = $this->cart($product);
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['choices'] = [$product => [$group => [$option]]];
        $review = $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('25,00')->assertSee('Cheddar');
        $this->assertDatabaseCount('orders', 0);
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key'], 'total_cents' => 1])->assertRedirect();
        $this->assertDatabaseHas('orders', ['total_cents' => 2500]);
        $this->assertDatabaseHas('order_items', ['quantity' => 2, 'unit_cents' => 1250]);
        DB::table('product_options')->where('id', $option)->update(['name' => 'Outro queijo', 'price_cents' => 500]);
        $order = DB::table('orders')->first();
        $this->get('/pedido/'.$order->public_id)->assertOk()->assertSee('Cheddar')->assertDontSee('Outro queijo');
        $this->actingAs($this->owner($tenant))->get('/orders/'.$order->id)->assertOk()->assertSee('Cheddar')->assertSee('Ana');
    }

    public function test_final_confirmation_rejects_changed_price_and_requires_review_session(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $cart = $this->cart($product);
        $this->postJson('/cardapio/loja', $cart)->assertUnprocessable();
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        DB::table('products')->where('id', $product)->update(['price_cents' => 1200]);
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->app['session']->flush();
        $this->get('/cardapio/loja/review/'.$cart['idempotency_key'])->assertNotFound();
    }

    public function test_review_can_be_edited_and_expired_drafts_cannot_confirm(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $cart = $this->cart($product);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->get('/cardapio/loja?edit='.$cart['idempotency_key'])->assertOk()->assertSee('value="2"', false);
        $this->travel(16)->minutes();
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_coverage_and_minimum_are_enforced_with_freight_added_separately(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $other = $this->store($tenant, 'outra');
        $foreignZone = DB::table('delivery_zones')->insertGetId(['store_id' => $other, 'name' => 'Outra região', 'fee_cents' => 1]);
        $zone = DB::table('delivery_zones')->insertGetId(['store_id' => $store, 'name' => 'Centro', 'fee_cents' => 600]);
        $cart = $this->cart($product) + ['delivery_zone_id' => $foreignZone, 'address' => 'Rua A, 10'];
        $cart['mode'] = 'delivery';
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['delivery_zone_id'] = $zone;
        DB::table('stores')->where('id', $store)->update(['minimum_order_cents' => 2500]);
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['items'][$product] = 3;
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 3000, 'fee_cents' => 600, 'total_cents' => 3600, 'delivery_zone_name' => 'Centro']);
    }

    public function test_pausing_after_review_prevents_creation_and_paused_zone_is_rejected(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $cart = $this->cart($product);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        DB::table('stores')->where('id', $store)->update(['open' => false]);
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        DB::table('stores')->where('id', $store)->update(['open' => true]);
        $zone = DB::table('delivery_zones')->insertGetId(['store_id' => $store, 'name' => 'Centro', 'fee_cents' => 500, 'enabled' => false]);
        $cart['mode'] = 'delivery';
        $cart['address'] = 'Rua A, 10';
        $cart['delivery_zone_id'] = $zone;
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
    }

    public function test_overnight_hours_use_the_stores_timezone_and_manual_pause(): void
    {
        $hours = array_fill(0, 7, ['enabled' => false, 'opens_at' => '18:00', 'closes_at' => '23:00']);
        $hours[5] = ['enabled' => true, 'opens_at' => '22:00', 'closes_at' => '02:00'];
        $store = (object) ['open' => true, 'timezone' => 'America/Sao_Paulo', 'weekly_hours' => json_encode($hours)];
        $service = app(StoreAvailability::class);
        $this->assertTrue($service->isOpen($store, Carbon::parse('2026-10-24 04:00:00', 'UTC')));
        $this->assertFalse($service->isOpen($store, Carbon::parse('2026-10-24 05:00:00', 'UTC')));
        $store->open = false;
        $this->assertFalse($service->isOpen($store, Carbon::parse('2026-10-24 04:00:00', 'UTC')));
    }

    public function test_catalog_edits_and_photos_are_isolated_and_views_render(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $otherTenant = $this->tenant('other');
        $otherStore = $this->store($otherTenant, 'other');
        $otherProduct = $this->product($otherTenant, $otherStore);
        $this->actingAs($this->owner($tenant));
        $this->get('/stores/'.$store.'/edit')->assertOk();
        $this->get('/products/'.$product.'/edit')->assertOk();
        $this->get('/products/'.$otherProduct.'/edit')->assertNotFound();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII=');
        $payload = ['name' => 'Lanche editado', 'category' => 'Novo', 'price_cents' => 1500, 'available' => 1, 'photo' => UploadedFile::fake()->createWithContent('foto.png', $png)];
        $this->post('/products/'.$product.'/edit', $payload)->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product, 'name' => 'Lanche editado', 'price_cents' => 1500]);
        $this->get('/media/products/'.$product)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/products/'.$otherProduct.'/edit', $payload)->assertNotFound();
        $this->get('/cardapio/loja')->assertOk()->assertSee('Lanche editado');
    }

    public function test_option_and_zone_mutations_cannot_target_another_tenant(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $otherTenant = $this->tenant('other');
        $otherStore = $this->store($otherTenant, 'other');
        $otherProduct = $this->product($otherTenant, $otherStore);
        $foreignGroup = DB::table('option_groups')->insertGetId(['product_id' => $otherProduct, 'name' => 'Grupo de outra empresa']);
        $foreignZone = DB::table('delivery_zones')->insertGetId(['store_id' => $otherStore, 'name' => 'Zona alheia', 'fee_cents' => 500]);
        $this->actingAs($this->owner($tenant));
        $this->post('/products/'.$product.'/options', ['option_group_id' => $foreignGroup, 'name' => 'Teste', 'price_cents' => 100, 'available' => 1])->assertNotFound();
        $this->post('/stores/'.$store.'/zones', ['zone_id' => $foreignZone, 'name' => 'Invasão', 'fee_cents' => 0, 'enabled' => 1])->assertNotFound();
        $this->assertDatabaseHas('delivery_zones', ['id' => $foreignZone, 'name' => 'Zona alheia']);
    }

    public function test_live_board_keeps_old_active_orders_and_never_leaks_other_tenant(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $active = $this->order($tenant, $store);
        for ($index = 0; $index < 31; $index++) {
            $id = $this->order($tenant, $store);
            DB::table('orders')->where('id', $id)->update(['status' => 'completed']);
        }
        $otherTenant = $this->tenant('other');
        $otherStore = $this->store($otherTenant, 'other');
        $private = $this->order($otherTenant, $otherStore);
        DB::table('orders')->where('id', $private)->update(['customer_name' => 'Segredo de outra empresa']);
        $this->actingAs($this->owner($tenant));
        $response = $this->getJson('/orders/live')->assertOk();
        $this->assertStringContainsString('#'.$active, $response->json('html'));
        $this->assertStringNotContainsString('Segredo de outra empresa', $response->json('html'));
        $this->get('/orders/'.$private)->assertNotFound();
        $this->getJson('/orders/live?status=completed')->assertOk();
        $this->get('/dashboard?status=all')->assertOk();
    }

    public function test_cancellation_needs_reason_and_tracking_only_returns_public_fields(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $order = $this->order($tenant, $store);
        $this->actingAs($this->owner($tenant));
        $this->postJson('/orders/'.$order.'/status', ['status' => 'cancelled'])->assertUnprocessable();
        $this->post('/orders/'.$order.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Produto esgotado'])->assertRedirect();
        $public = DB::table('orders')->find($order)->public_id;
        $response = $this->getJson('/pedido/'.$public.'/status')->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancellation_reason', 'Produto esgotado');
        $this->assertArrayNotHasKey('phone', $response->json());
        $this->assertArrayNotHasKey('address', $response->json());
    }

    public function test_store_configuration_can_save_hours_tariffs_and_delivery_zones(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $hours = array_fill(0, 7, ['enabled' => 1, 'opens_at' => '18:00', 'closes_at' => '01:00']);
        $data = ['name' => 'Minha Loja', 'slug' => 'loja', 'address' => 'Rua B, 20', 'latitude' => -23.55, 'longitude' => -46.63, 'open' => 1, 'timezone' => 'America/Sao_Paulo', 'minimum_order_cents' => 2500, 'use_hours' => 1, 'hours' => $hours, 'rate_mode' => 'per_km', 'base_cents' => 400, 'per_km_cents' => 200, 'minimum_cents' => 800];
        $this->post('/stores/'.$store.'/edit', $data)->assertRedirect();
        $this->assertDatabaseHas('stores', ['id' => $store, 'name' => 'Minha Loja', 'minimum_order_cents' => 2500]);
        $this->post('/stores/'.$store.'/zones', ['name' => 'Centro', 'fee_cents' => 750, 'enabled' => 1])->assertRedirect();
        $this->assertDatabaseHas('delivery_zones', ['store_id' => $store, 'name' => 'Centro', 'fee_cents' => 750]);
        $this->get('/stores/'.$store.'/edit')->assertOk()->assertSee('Centro');
    }

    public function test_duplicate_and_foreign_addon_selections_are_rejected(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $product, 'name' => 'Molhos', 'minimum' => 0, 'maximum' => 2]);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Molho', 'price_cents' => 100]);
        $cart = $this->cart($product);
        $cart['choices'] = [$product => [$group => [$option, $option]]];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['choices'] = [$product => [99999 => [$option]]];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_money_fields_accept_reais_without_float_rounding_or_ambiguous_separators(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = $this->product($tenant, $store);
        $this->actingAs($this->owner($tenant));
        $payload = ['name' => 'Preço em reais', 'category' => 'Lanches', 'price' => '19,90', 'available' => 1];
        $this->post('/products/'.$product.'/edit', $payload)->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product, 'price_cents' => 1990]);
        $this->get('/products/'.$product.'/edit')->assertOk()->assertSee('value="19,90"', false);
        $payload['price'] = '0.01';
        $this->post('/products/'.$product.'/edit', $payload)->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product, 'price_cents' => 1]);
        $payload['price'] = '1.234,56';
        $this->postJson('/products/'.$product.'/edit', $payload)->assertUnprocessable();
        $payload['price'] = '12,345';
        $this->postJson('/products/'.$product.'/edit', $payload)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $product, 'price_cents' => 1]);
        $this->post('/stores/'.$store.'/zones', ['name' => 'Centro', 'fee' => '7,50', 'enabled' => 1])->assertRedirect();
        $this->assertDatabaseHas('delivery_zones', ['store_id' => $store, 'fee_cents' => 750]);
    }
}
