<?php

namespace Tests\Feature;

use App\Services\CashRegister;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\BuildsPlatform;
use Tests\TestCase;

class PosDeliveryTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(bool $online = false): array
    {
        Http::preventStrayRequests();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        app(CashRegister::class)->open(DB::table('stores')->find($store), auth()->id(), 0, (string) Str::uuid());
        DB::table('stores')->where('id', $store)->update(['delivery_mode' => 'radius', 'delivery_pricing' => 'fixed', 'delivery_max_meters' => 5000, 'delivery_fixed_cents' => 500]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Food', 'category' => 'Menu', 'price_cents' => 3000]);
        $fields = ['street' => 'Rua A', 'number' => '10', 'neighborhood' => 'Centro', 'city' => 'Sao Paulo', 'state' => 'SP', 'postcode' => '01001000'];
        $address = 'Rua A, 10 - Centro, Sao Paulo - SP, 01001000';
        $key = (string) Str::uuid();
        $data = ['mode' => 'delivery', 'pos_channel' => 'whatsapp', 'customer_name' => 'Ana', 'phone' => '11999999999', 'address_fields' => $fields, 'delivery_token' => $this->deliveryToken($store, $address), 'payment_method' => $online ? 'online_infinitepay' : 'cash', 'received' => 'yes', 'idempotency_key' => $key, 'cart_lines' => [['product_id' => $product, 'quantity' => 1]]];
        if ($online) {
            DB::table('store_payment_accounts')->insert(['store_id' => $store, 'provider' => 'infinitepay', 'environment' => 'production', 'credential' => Crypt::encryptString(''), 'handle' => 'test', 'webhook_secret' => Str::random(32), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return compact('tenant', 'store', 'key', 'data');
    }

    private function confirm(array $f): object
    {
        $this->post('/pos/'.$f['store'].'/review', $f['data'])->assertRedirect('/pos/'.$f['store'].'/review/'.$f['key'])->assertSessionHasNoErrors();
        $draft = $this->get('/pos/'.$f['store'].'/review/'.$f['key'])->assertOk()->viewData('draft');
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', ['review_hash' => hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR))])->assertRedirect();

        return DB::table('orders')->first();
    }

    public function test_delivery_quote_customer_channel_and_freight_are_frozen(): void
    {
        $f = $this->fixture();
        $order = $this->confirm($f);
        $this->assertSame('delivery', $order->mode);
        $this->assertSame('whatsapp', $order->pos_channel);
        $this->assertEquals(3500, $order->total_cents);
        $this->assertEquals(500, $order->fee_cents);
        $this->assertStringContainsString('Rua A, 10', $order->address);
        $this->get('/pos/orders/'.$order->id)->assertOk()->assertSee('Compartilhar no WhatsApp')->assertSee('/pedido/'.$order->public_id);
        $this->assertDatabaseCount('rides', 0);
    }

    public function test_online_sale_cannot_be_forged_paid_and_reserves_without_external_request(): void
    {
        $f = $this->fixture(true);
        $session = DB::table('cash_sessions')->where('open_store_id', $f['store'])->firstOrFail();
        app(CashRegister::class)->close($session, auth()->id(), 0, '', (string) Str::uuid());
        $order = $this->confirm($f);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertSame('pending', $order->payment_status);
        $this->assertDatabaseHas('payment_attempts', ['order_id' => $order->id, 'state' => 'reserved', 'amount_cents' => 3500]);
        $this->post('/orders/'.$order->id.'/status', ['status' => 'preparing'])->assertConflict();
        $this->get('/pos/orders/'.$order->id)->assertOk()->assertSee('Link de pagamento')->assertDontSee('webhook_secret');
        Http::assertNothingSent();
    }

    public function test_address_token_missing_or_altered_and_missing_contact_are_rejected(): void
    {
        $f = $this->fixture();
        $data = $f['data'];
        unset($data['delivery_token']);
        $this->post('/pos/'.$f['store'].'/review', $data)->assertSessionHasErrors();
        $data = $f['data'];
        $data['address_fields']['number'] = '99';
        $this->post('/pos/'.$f['store'].'/review', $data)->assertSessionHasErrors();
        $data = $f['data'];
        $data['phone'] = '';
        $this->post('/pos/'.$f['store'].'/review', $data)->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_selected_driver_is_not_reserved_before_ready_and_must_accept_offer(): void
    {
        $f = $this->fixture();
        $driver = $this->driver($f['store']);
        $f['data']['preferred_driver_id'] = $driver;
        $order = $this->confirm($f);
        $this->assertDatabaseCount('driver_claims', 0);
        $this->post('/pos/orders/'.$order->id.'/dispatch', ['preferred_driver_id' => $driver])->assertConflict();
        $this->assertDatabaseCount('rides', 0);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'ready']);
        $this->post('/pos/orders/'.$order->id.'/dispatch', ['preferred_driver_id' => $driver])->assertRedirect();
        $ride = DB::table('rides')->first();
        $this->assertSame('offered', $ride->status);
        $this->assertNull($ride->driver_id);
        $offer = DB::table('offers')->first();
        $this->assertEquals($driver, $offer->driver_id);
        $driverUser = DB::table('drivers')->where('id', $driver)->value('user_id');
        DB::table('users')->where('id', $driverUser)->update(['access_enabled' => false]);
        $this->expectExceptionForRevokedDriver($offer->id, $driver);
        DB::table('users')->where('id', $driverUser)->update(['access_enabled' => true]);
        app(DispatchService::class)->accept($offer->id, $driver);
        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'driver_id' => $driver, 'status' => 'assigned']);
        $this->post('/pos/orders/'.$order->id.'/dispatch', ['preferred_driver_id' => $driver])->assertConflict();
        $this->assertDatabaseCount('rides', 1);
    }

    public function test_busy_selected_driver_never_silently_falls_back_and_foreign_store_driver_is_rejected(): void
    {
        $f = $this->fixture();
        $driver = $this->driver($f['store']);
        $other = $this->driver($this->store($f['tenant'], 'other'));
        $data = $f['data'];
        $data['preferred_driver_id'] = $other;
        $this->post('/pos/'.$f['store'].'/review', $data)->assertSessionHasErrors('preferred_driver_id');
        $f['data']['preferred_driver_id'] = $driver;
        $order = $this->confirm($f);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'ready']);
        $rideId = DB::table('rides')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'order_id' => $order->id, 'status' => 'searching', 'fare_cents' => 800, 'rate_mode' => 'fixed']);
        DB::table('drivers')->where('id', $driver)->update(['available' => false]);
        app(DispatchService::class)->dispatch($rideId);
        $this->assertDatabaseHas('rides', ['id' => $rideId, 'status' => 'unassigned']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_result_and_address_endpoints_remain_scoped_to_authorized_stores(): void
    {
        $f = $this->fixture();
        $order = $this->confirm($f);
        $this->actingAs($this->owner($this->tenant('foreign')));
        $this->get('/pos/orders/'.$order->id)->assertNotFound();
        $this->post('/pos/'.$f['store'].'/postcode', ['postcode' => '01001000'])->assertNotFound();
        $this->post('/pos/'.$f['store'].'/delivery', ['address' => 'Some address'])->assertNotFound();
    }

    private function expectExceptionForRevokedDriver(int $offer, int $driver): void
    {
        try {
            app(DispatchService::class)->accept($offer, $driver);
            $this->fail('Revoked courier accepted an offer.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }
}
