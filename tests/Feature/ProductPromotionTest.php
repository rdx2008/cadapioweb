<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CheckoutQuote;
use App\Services\MenuCatalog;
use App\Services\ProductPromotions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ProductPromotionTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 21)->setTime(15, 0));
        $tenant = $this->tenant();
        $storeId = $this->store($tenant);
        $store = DB::table('stores')->find($storeId);
        $owner = $this->owner($tenant);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $storeId, 'name' => 'Lanche especial', 'category' => 'Menu', 'price_cents' => 3090]);
        $this->actingAs($owner);

        return compact('tenant', 'store', 'owner', 'product');
    }

    private function offer(array $f, string $percent = '10', array $changes = []): array
    {
        return array_replace(['product_id' => $f['product'], 'name' => 'Semana especial', 'percent' => $percent, 'starts_on' => '2026-11-21', 'ends_on' => '2026-11-25', 'request_key' => (string) Str::uuid(), 'confirmed' => 1], $changes);
    }

    private function create(array $f, string $percent = '10', array $changes = []): int
    {
        $this->post('/catalog/promotions/'.$f['store']->id, $this->offer($f, $percent, $changes))->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('product_promotions')->max('id');
    }

    private function cart(array $f): array
    {
        return ['customer_name' => 'Cliente', 'phone' => '11999999999', 'mode' => 'pickup', 'payment_method' => 'cash', 'items' => [$f['product'] => 1], 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_creation_is_idempotent_periods_cannot_overlap_and_paused_offer_cannot_resume_over_another(): void
    {
        $f = $this->fixture();
        $url = '/catalog/promotions/'.$f['store']->id;
        $data = $this->offer($f);
        $this->get($url)->assertOk()->assertSee('Promoções por período');
        $this->post($url, $data)->assertRedirect();
        $this->post($url, $data)->assertRedirect();
        $this->assertDatabaseCount('product_promotions', 1);
        $this->post($url, $this->offer($f, '15'))->assertConflict();
        $id = DB::table('product_promotions')->value('id');
        $this->post($url.'/'.$id, ['revision' => 1, 'enabled' => 0, 'confirmed' => 1])->assertRedirect();
        $this->create($f, '15');
        $this->post($url.'/'.$id, ['revision' => 2, 'enabled' => 1, 'confirmed' => 1])->assertConflict();
        $this->create($f, '5', ['starts_on' => '2026-11-26', 'ends_on' => '2026-11-27']);
        $this->get($url)->assertOk()->assertSee('Programada')->assertSee('Pausada');
    }

    public function test_base_is_discounted_before_extras_and_pdv_stays_full_price_with_immutable_order_snapshot(): void
    {
        $f = $this->fixture();
        $id = $this->create($f, '12,50');
        $group = DB::table('option_groups')->insertGetId(['product_id' => $f['product'], 'name' => 'Extras', 'minimum' => 0, 'maximum' => 1]);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Queijo', 'price_cents' => 250]);
        $cart = array_replace($this->cart($f), ['items' => [$f['product'] => 2], 'choices' => [$f['product'] => [$group => [$option]]]]);
        $quote = app(CheckoutQuote::class)->calculate($f['store'], $cart);
        $this->assertSame(5908, $quote['total_cents']);
        $this->assertSame(386, $quote['items'][0]['options'][0]['discount_cents']);
        $this->assertSame(6680, app(CheckoutQuote::class)->calculate($f['store'], $cart, true)['total_cents']);
        $this->assertSame(3090, app(MenuCatalog::class)->data($f['store'], false)['products']->first()->display_price_cents);
        $this->get('/cardapio/loja')->assertOk()->assertSee('27,04')->assertSee('data-promotion-bps="1250"', false)->assertSee('não acumula com cupom');
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_items', ['unit_cents' => 2954, 'quantity' => 2]);
        $pending = array_replace($cart, ['idempotency_key' => (string) Str::uuid()]);
        $this->post('/cardapio/loja/review', $pending)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/catalog/promotions/'.$f['store']->id.'/'.$id, ['revision' => 1, 'enabled' => 0, 'confirmed' => 1])->assertRedirect();
        $this->postJson('/cardapio/loja', ['draft_id' => $pending['idempotency_key']])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['total_cents' => 5908]);
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'price_cents' => 3090]);
    }

    public function test_pizza_uses_selected_flavor_price_and_combo_surcharges_stay_outside_promotion(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['product_type' => 'pizza', 'uses_variants' => true, 'pizza_enabled' => true]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $f['product'], 'name' => 'Grande', 'price_cents' => 4000, 'max_flavors' => 2]);
        $flavors = [];
        foreach ([3500, 5001] as $price) {
            $flavors[] = DB::table('pizza_flavors')->insertGetId(['product_id' => $f['product'], 'name' => 'Sabor '.$price, 'prices' => json_encode([$variant => $price])]);
        }
        $this->create($f);
        $cart = $this->cart($f) + ['cart_lines' => [['product_id' => $f['product'], 'quantity' => 1, 'variant_id' => $variant, 'flavors' => $flavors, 'choices' => []]]];
        $this->assertSame(4501, app(CheckoutQuote::class)->calculate($f['store'], $cart)['total_cents']);
        $this->assertSame(3150, app(MenuCatalog::class)->data($f['store'])['products']->first()->display_price_cents);
        $combo = DB::table('products')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store']->id, 'name' => 'Combo', 'category' => 'Menu', 'product_type' => 'combo', 'price_cents' => 1000, 'combo_fixed' => true]);
        $group = DB::table('combo_groups')->insertGetId(['product_id' => $combo, 'name' => 'Escolha', 'minimum' => 1, 'maximum' => 1]);
        $option = DB::table('combo_options')->insertGetId(['combo_group_id' => $group, 'component_product_id' => null, 'label' => 'Bebida', 'quantity' => 2, 'surcharge_cents' => 250]);
        $this->create($f, '20', ['product_id' => $combo]);
        $cart['cart_lines'] = [['product_id' => $combo, 'quantity' => 1, 'combo_choices' => [$group => [$option]], 'choices' => []]];
        $this->assertSame(1300, app(CheckoutQuote::class)->calculate($f['store'], $cart)['total_cents']);
    }

    public function test_expiration_uses_store_day_and_invalidates_a_previously_reviewed_checkout(): void
    {
        $f = $this->fixture();
        $this->create($f, '10', ['ends_on' => '2026-11-21']);
        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(2, 59));
        $cart = $this->cart($f);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2781, app(CheckoutQuote::class)->calculate($f['store'], $cart)['total_cents']);
        $this->travel(2)->minutes();
        $this->assertSame(3090, app(CheckoutQuote::class)->calculate($f['store'], $cart)['total_cents']);
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_coupon_combination_validation_and_tenant_boundaries(): void
    {
        $f = $this->fixture();
        foreach (['0', '0,99', '90,01', '-1', '10,001'] as $percent) {
            $this->postJson('/catalog/promotions/'.$f['store']->id, $this->offer($f, $percent))->assertUnprocessable();
        }
        $this->create($f);
        DB::table('store_coupons')->insert(['store_id' => $f['store']->id, 'code' => 'TESTE', 'kind' => 'fixed', 'value' => 100, 'minimum_cents' => 0, 'maximum_uses' => 10, 'per_customer' => 1, 'starts_on' => '2026-11-21', 'ends_on' => '2026-11-25']);
        $this->postJson('/cardapio/loja/review', $this->cart($f) + ['coupon_code' => 'TESTE'])->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
        $foreign = $this->store($this->tenant('foreign'), 'foreign');
        $this->get('/catalog/promotions/'.$foreign)->assertNotFound();
        $this->post('/catalog/promotions/'.$foreign, $this->offer($f))->assertNotFound();
        $staff = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff']);
        $this->actingAs($staff)->get('/catalog/promotions/'.$f['store']->id)->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->post('/catalog/promotions/'.$f['store']->id, $this->offer($f))->assertForbidden();
        $this->assertSame(1, ProductPromotions::price(1, 9000));

    }
}
