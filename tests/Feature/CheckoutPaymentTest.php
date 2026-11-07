<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CheckoutPaymentTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        DB::table('stores')->where('id', $store)->update(['accepts_card' => true, 'accepts_pix' => true]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Lanche', 'category' => 'Lanches', 'price_cents' => 3000]);
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'items' => [$product => 1], 'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid()];

        return compact('tenant', 'store', 'cart');
    }

    public function test_cash_change_is_reviewed_and_snapshotted_without_changing_order_total(): void
    {
        $f = $this->fixture();
        $cart = $f['cart'] + ['change_for' => '50,00', 'payment_status' => 'paid'];
        $review = $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('Troco para R$ 50,00')->assertSee('devolver R$ 20,00');
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['payment_method' => 'cash', 'change_for_cents' => 5000, 'total_cents' => 3000, 'payment_status' => 'pending']);
        $this->get('/pedido/'.DB::table('orders')->value('public_id'))->assertOk()->assertSee('devolver R$ 20,00');
    }

    public function test_invalid_change_unknown_methods_and_disabled_methods_are_rejected(): void
    {
        $f = $this->fixture();
        foreach ([['change_for' => '29,99'], ['change_for' => '-50'], ['change_for' => '1.000,00'], ['change_for_cents' => 0], ['payment_method' => 'online'], ['payment_method' => 'pix', 'change_for' => '50'], ['payment_method' => 'card', 'change_for_cents' => 5000]] as $patch) {
            $this->postJson('/cardapio/loja/review', array_replace($f['cart'], $patch))->assertUnprocessable();
        }
        DB::table('stores')->where('id', $f['store'])->update(['accepts_card' => false]);
        $this->postJson('/cardapio/loja/review', array_replace($f['cart'], ['payment_method' => 'card']))->assertUnprocessable();
        $this->get('/cardapio/loja')->assertOk()->assertDontSee('value="card"', false)->assertSee('value="pix"', false);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_disabling_method_after_review_prevents_confirmation(): void
    {
        $f = $this->fixture();
        $cart = array_replace($f['cart'], ['payment_method' => 'pix']);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        DB::table('stores')->where('id', $f['store'])->update(['accepts_pix' => false]);
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_freight_is_included_in_the_minimum_value_for_change(): void
    {
        $f = $this->fixture();
        DB::table('delivery_zones')->insert(['store_id' => $f['store'], 'name' => 'Centro', 'city' => 'São Paulo', 'state_code' => 'SP', 'fee_cents' => 500]);
        $address = 'Rua do Cliente, 100 - Centro, São Paulo - SP';
        $cart = array_replace($f['cart'], ['mode' => 'delivery', 'address' => $address, 'delivery_token' => $this->deliveryToken($f['store'], $address), 'change_for' => '30']);
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart['change_for'] = '50';
        $review = $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('devolver R$ 15,00');
    }

    public function test_non_cash_delivery_requires_store_confirmation_and_has_no_driver_cash_liability(): void
    {
        foreach (['card', 'pix'] as $method) {
            $tenant = $this->tenant('tenant-'.$method);
            $store = $this->store($tenant, 'loja-'.$method);
            $driver = $this->driver($store);
            $owner = $this->owner($tenant);
            $order = $this->order($tenant, $store);
            DB::table('orders')->where('id', $order)->update(['payment_method' => $method]);
            $this->actingAs($owner)->post('/orders/'.$order.'/dispatch')->assertRedirect();
            $ride = DB::table('rides')->where('order_id', $order)->first();
            $offer = DB::table('offers')->where('ride_id', $ride->id)->first();
            $this->assertSame(0, $ride->cash_cents);
            $courier = User::findOrFail(DB::table('drivers')->find($driver)->user_id);
            $this->actingAs($courier)->get('/courier')->assertOk()->assertSee($method === 'card' ? 'maquininha' : 'Pix');
            $this->post('/courier/offers/'.$offer->id.'/accept')->assertRedirect();
            $this->post('/courier/rides/'.$ride->id, ['status' => 'collected'])->assertRedirect();
            $this->post('/courier/rides/'.$ride->id, ['status' => 'completed'])->assertConflict();
            $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'pending']);
            $this->get('/courier')->assertOk()->assertSee('Aguarde a loja');
            $this->actingAs($owner)->post('/orders/'.$order.'/paid')->assertRedirect();
            $this->actingAs($courier)->post('/courier/rides/'.$ride->id, ['status' => 'completed', 'expected_round' => $ride->dispatch_round, 'delivery_code' => app(DeliveryConfirmation::class)->forCustomer(DB::table('rides')->find($ride->id))])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'paid', 'status' => 'completed']);
            $this->assertDatabaseHas('rides', ['id' => $ride->id, 'cash_cents' => 0, 'status' => 'completed']);
        }
    }

    public function test_only_the_order_tenant_can_confirm_non_cash_receipt(): void
    {
        $f = $this->fixture();
        $order = $this->order($f['tenant'], $f['store']);
        DB::table('orders')->where('id', $order)->update(['payment_method' => 'pix']);
        $this->actingAs($this->owner($this->tenant('other')))->post('/orders/'.$order.'/paid')->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'pending']);

    }

    public function test_store_payment_settings_require_one_method_and_remain_tenant_scoped(): void
    {
        $f = $this->fixture();
        $data = ['name' => 'Loja', 'slug' => 'loja', 'address' => 'Rua da Loja, 100', 'latitude' => -23.55, 'longitude' => -46.63, 'open' => 1, 'timezone' => 'America/Sao_Paulo', 'minimum_order_cents' => 0, 'use_hours' => 0, 'hours' => array_fill(0, 7, ['enabled' => 1, 'opens_at' => '00:00', 'closes_at' => '23:59']), 'rate_mode' => 'fixed', 'base_cents' => 800, 'per_km_cents' => 200, 'minimum_cents' => 800, 'accepts_cash' => 0, 'accepts_card' => 0, 'accepts_pix' => 0];
        $this->actingAs($this->owner($f['tenant']));
        $this->postJson('/stores/'.$f['store'].'/edit', $data)->assertUnprocessable();
        $data['accepts_pix'] = 1;
        $this->post('/stores/'.$f['store'].'/edit', $data)->assertRedirect();
        $this->assertDatabaseHas('stores', ['id' => $f['store'], 'accepts_cash' => false, 'accepts_pix' => true]);
        $this->actingAs($this->owner($this->tenant('other')))->post('/stores/'.$f['store'].'/edit', $data)->assertNotFound();
    }
}
