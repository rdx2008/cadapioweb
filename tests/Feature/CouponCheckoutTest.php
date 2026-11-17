<?php

namespace Tests\Feature;

use App\Services\Coupons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CouponCheckoutTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_coupon_is_rechecked_at_confirmation_and_changes_the_authoritative_total(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $this->post(route('coupons.create', $store), [
            'code' => 'FEIRA', 'kind' => 'percent', 'amount' => '20', 'minimum' => '20,00',
            'starts_on' => '2020-01-01', 'ends_on' => '2099-12-31', 'maximum_uses' => 1, 'per_customer' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('coupons.index', $store))->assertOk()->assertSee('FEIRA');
        $product = DB::table('products')->insertGetId([
            'tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Lanche', 'category' => 'Menu', 'price_cents' => 3000,
        ]);
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $cart = ['customer_name' => 'Cliente', 'mode' => 'pickup', 'items' => [$product => 1], 'payment_method' => 'cash', 'coupon_code' => 'feira'];
        $draft = $this->post('/cardapio/loja/review', $cart + ['phone' => '11999999999', 'idempotency_key' => $first])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($draft->headers->get('Location'))->assertOk()->assertSee('Cupom FEIRA')->assertSee('24,00');
        $this->post('/cardapio/loja/review', $cart + ['phone' => '11988888888', 'idempotency_key' => $second])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/cardapio/loja', ['draft_id' => $first, 'discount_cents' => 2900])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/cardapio/loja', ['draft_id' => $first])->assertRedirect();
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 3000, 'discount_cents' => 600, 'total_cents' => 2400, 'coupon_code' => 'FEIRA']);
        $this->postJson('/cardapio/loja', ['draft_id' => $second])->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_limits_follow_normalized_phone_and_void_unpaid_orders_release_use(): void
    {
        $tenant = $this->tenant();
        $storeId = $this->store($tenant);
        $store = DB::table('stores')->find($storeId);
        $coupon = DB::table('store_coupons')->insertGetId([
            'store_id' => $storeId, 'code' => 'VOLTE', 'kind' => 'fixed', 'value' => 500,
            'maximum_uses' => 10, 'per_customer' => 1, 'starts_on' => '2020-01-01', 'ends_on' => '2099-12-31',
        ]);
        $service = app(Coupons::class);
        $usage = $service->quote($store, 'VOLTE', '+55 (11) 99999-9999', 3000, 500);
        $id = $this->order($tenant, $storeId);
        DB::table('orders')->where('id', $id)->update($usage);
        try {
            $service->quote($store, 'VOLTE', '11999999999', 3000, 500);
            $this->fail('A segunda compra com o mesmo telefone deveria ser recusada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
        DB::table('orders')->where('id', $id)->update(['status' => 'cancelled', 'payment_status' => 'pending']);
        $this->assertEquals(500, $service->quote($store, 'VOLTE', '11999999999', 3000, 500)['discount_cents']);
        $foreign = $this->store($this->tenant('foreign'), 'foreign');
        $this->actingAs($this->owner($tenant))->get(route('coupons.index', $foreign))->assertNotFound();
        $this->post(route('coupons.availability', [$foreign, $coupon]), ['enabled' => 0])->assertNotFound();
    }
}
