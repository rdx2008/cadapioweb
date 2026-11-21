<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryConfirmation;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DeliveryConfirmationTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $driver = $this->driver($store);
        $courier = User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
        $order = $this->order($tenant, $store);
        $this->actingAs($owner)->post('/orders/'.$order.'/dispatch')->assertRedirect()->assertSessionHasNoErrors();
        $ride = DB::table('rides')->where('order_id', $order)->firstOrFail();
        $offer = DB::table('offers')->where('ride_id', $ride->id)->firstOrFail();
        app(DispatchService::class)->accept($offer->id, $driver);
        $public = DB::table('orders')->where('id', $order)->value('public_id');

        return compact('tenant', 'store', 'owner', 'driver', 'courier', 'order', 'ride', 'public');
    }

    private function collect(array $f): string
    {
        $this->actingAs($f['courier'])->post('/courier/rides/'.$f['ride']->id, ['status' => 'collected', 'expected_round' => $f['ride']->dispatch_round])->assertRedirect()->assertSessionHasNoErrors();

        return app(DeliveryConfirmation::class)->forCustomer(DB::table('rides')->find($f['ride']->id));
    }

    public function test_new_ride_requires_code_only_after_collection_and_completion_clears_it_without_leaking_to_panels_or_audit(): void
    {
        $f = $this->fixture();
        $this->assertTrue((bool) $f['ride']->confirmation_required);
        $this->get('/pedido/'.$f['public'].'/status')->assertJsonPath('delivery_code', null);
        $code = $this->collect($f);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $code);
        $ride = DB::table('rides')->find($f['ride']->id);
        $this->assertNotSame($code, $ride->confirmation_cipher);
        $this->assertSame($code, Crypt::decryptString($ride->confirmation_cipher));
        $this->get('/pedido/'.$f['public'])->assertOk()->assertSee($code)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/pedido/'.$f['public'].'/status')->assertJsonPath('delivery_code', $code)->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/deliveries/'.$ride->id)->assertOk()->assertSee('name="delivery_code"', false)->assertDontSee($code)->assertDontSee($ride->confirmation_cipher);
        $this->get('/courier/live')->assertOk()->assertDontSee($code)->assertDontSee($ride->confirmation_cipher);
        $this->actingAs($f['owner'])->get('/deliveries/'.$ride->id)->assertOk()->assertDontSee($code);
        $this->actingAs($f['courier'])->post('/courier/rides/'.$ride->id, ['status' => 'completed', 'expected_round' => 1, 'delivery_code' => $code])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'status' => 'completed', 'payment_status' => 'paid']);
        $this->assertDatabaseMissing('driver_claims', ['ride_id' => $ride->id]);
        $completed = DB::table('rides')->find($ride->id);
        $this->assertNotNull($completed->confirmation_verified_at);
        $this->assertNull($completed->confirmation_cipher);
        $this->assertStringNotContainsString($code, DB::table('audit_events')->pluck('details')->implode(' '));
        $this->get('/pedido/'.$f['public'].'/status')->assertJsonPath('delivery_code', null);
    }

    public function test_missing_wrong_and_stale_codes_cannot_finish_or_mark_paid_and_are_not_flashed(): void
    {
        $f = $this->fixture();
        $code = $this->collect($f);
        $url = '/courier/rides/'.$f['ride']->id;
        $this->post($url, ['status' => 'completed', 'expected_round' => 1])->assertSessionHasErrors('delivery_code');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post($url, ['status' => 'completed', 'expected_round' => 1, 'delivery_code' => $wrong])->assertSessionHasErrors('delivery_code')->assertSessionMissing('_old_input.delivery_code');
        $this->post($url, ['status' => 'completed', 'expected_round' => 2, 'delivery_code' => $code])->assertStatus(409);
        $this->assertDatabaseHas('rides', ['id' => $f['ride']->id, 'status' => 'collected', 'confirmation_verified_at' => null]);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'payment_status' => 'pending']);
        $this->assertDatabaseHas('driver_claims', ['ride_id' => $f['ride']->id, 'driver_id' => $f['driver']]);
    }

    public function test_code_is_not_a_substitute_for_payment_and_existing_rides_keep_the_previous_flow(): void
    {
        $f = $this->fixture();
        $code = $this->collect($f);
        DB::table('orders')->where('id', $f['order'])->update(['payment_method' => 'pix']);
        $this->post('/courier/rides/'.$f['ride']->id, ['status' => 'completed', 'expected_round' => 1, 'delivery_code' => $code])->assertStatus(409);
        $this->assertDatabaseHas('rides', ['id' => $f['ride']->id, 'status' => 'collected', 'confirmation_verified_at' => null]);
        DB::table('orders')->where('id', $f['order'])->update(['payment_status' => 'paid']);
        DB::table('rides')->where('id', $f['ride']->id)->update(['confirmation_required' => false, 'confirmation_cipher' => null]);
        $this->post('/courier/rides/'.$f['ride']->id, ['status' => 'completed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $f['ride']->id, 'status' => 'completed', 'confirmation_verified_at' => null]);
    }

    public function test_return_hides_code_and_retry_discards_previous_secret(): void
    {
        $f = $this->fixture();
        $this->collect($f);
        $ride = $f['ride']->id;
        $payload = ['expected_round' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'Retorno solicitado pelo cliente.'];
        $this->post('/deliveries/'.$ride.'/return', $payload)->assertRedirect();
        $this->get('/pedido/'.$f['public'].'/status')->assertJsonPath('delivery_code', null);
        $this->actingAs($f['owner'])->post('/deliveries/'.$ride.'/exceptions/receive', ['request_key' => (string) Str::uuid(), 'expected_round' => 1, 'reason' => 'Recebido de volta na loja.', 'physical_confirmation' => 1, 'compensation' => '8', 'customer_cash' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/deliveries/'.$ride.'/exceptions/retry', ['request_key' => (string) Str::uuid(), 'expected_round' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $ride, 'confirmation_required' => true, 'confirmation_cipher' => null, 'confirmation_verified_at' => null, 'dispatch_round' => 3]);
        $this->get('/pedido/'.$f['public'].'/status')->assertJsonPath('delivery_code', null);
    }

    public function test_wrong_attempts_are_rate_limited_separately_from_gps_and_foreign_users_cannot_finish(): void
    {
        $f = $this->fixture();
        $code = $this->collect($f);
        $this->travel(61)->seconds();
        for ($i = 0; $i < 6; $i++) {
            $this->post('/courier/rides/'.$f['ride']->id, ['status' => 'completed', 'expected_round' => 1])->assertSessionHasErrors('delivery_code');
        }
        $this->post('/courier/rides/'.$f['ride']->id, ['status' => 'completed', 'expected_round' => 1, 'delivery_code' => $code])->assertStatus(429);
        $this->post('/courier/heartbeat', ['latitude' => -23.55, 'longitude' => -46.63, 'accuracy' => 10, 'captured_at' => now()->timestamp])->assertOk();
        $other = User::findOrFail(DB::table('drivers')->where('id', $this->driver())->value('user_id'));
        $this->actingAs($other)->post('/courier/rides/'.$f['ride']->id, ['status' => 'completed', 'expected_round' => 1, 'delivery_code' => $code])->assertNotFound();
        $this->assertDatabaseHas('rides', ['id' => $f['ride']->id, 'status' => 'collected']);
    }

    public function test_alert_feed_is_tenant_scoped_does_not_expose_customer_or_description_and_updates_after_resolution(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['courier'])->post('/deliveries/'.$f['ride']->id.'/incidents', ['request_key' => (string) Str::uuid(), 'kind' => 'address_issue', 'description' => 'Dados privados do cliente.'])->assertRedirect();
        $this->get('/delivery-alerts/live')->assertForbidden();
        $this->actingAs($f['owner'])->get('/delivery-alerts/live')->assertOk()->assertJsonPath('open_count', 1)->assertJsonPath('items.0.ride_id', $f['ride']->id)->assertDontSee('Dados privados')->assertDontSee('11999999999')->assertHeader('Cache-Control', 'no-store, private');
        $incident = DB::table('delivery_incidents')->firstOrFail();
        $other = $this->owner($this->tenant('other'));
        $this->actingAs($other)->get('/delivery-alerts/live')->assertJsonPath('open_count', 0)->assertJsonPath('items', []);
        $this->actingAs($f['owner'])->post('/delivery-incidents/'.$incident->id.'/resolve', ['resolution' => 'Resolvido com o cliente.'])->assertRedirect();
        $this->get('/delivery-alerts/live')->assertJsonPath('open_count', 0)->assertJsonPath('latest_id', $incident->id)->assertJsonPath('items', []);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/delivery-alerts/live')->assertForbidden();
    }

    public function test_confirmation_survives_a_failed_completion_and_works_during_commercial_suspension(): void
    {
        $f = $this->fixture();
        $this->collect($f);
        DB::table('rides')->where('id', $f['ride']->id)->update(['confirmation_cipher' => Crypt::encryptString('000123')]);
        $this->get('/pedido/'.$f['public'].'/status')->assertJsonPath('delivery_code', '000123');
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended', 'courier_module' => 'closing']);
        DB::statement("CREATE TRIGGER fail_confirmation_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'ride.progress' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $payload = ['status' => 'completed', 'expected_round' => 1, 'delivery_code' => '000123'];
        $this->post('/courier/rides/'.$f['ride']->id, $payload)->assertStatus(500);
        $this->assertDatabaseHas('rides', ['id' => $f['ride']->id, 'status' => 'collected', 'confirmation_verified_at' => null]);
        $this->assertDatabaseHas('driver_claims', ['ride_id' => $f['ride']->id]);
        $this->assertSame('000123', app(DeliveryConfirmation::class)->forCustomer(DB::table('rides')->find($f['ride']->id)));
        DB::statement('DROP TRIGGER fail_confirmation_audit');
        $this->post('/courier/rides/'.$f['ride']->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $f['ride']->id, 'status' => 'completed', 'confirmation_cipher' => null]);
        $this->assertDatabaseHas('tenants', ['id' => $f['tenant'], 'courier_module' => 'disabled']);
    }
}
