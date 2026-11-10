<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DeliveryExceptionsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        DB::table('rides')->where('id', $ride)->update(['cash_cents' => 3500]);
        app(DispatchService::class)->dispatch($ride);
        $offer = DB::table('offers')->where('ride_id', $ride)->firstOrFail();
        app(DispatchService::class)->accept($offer->id, $driver);
        $courier = User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
        $owner = $this->owner($tenant);
        $order = DB::table('rides')->where('id', $ride)->value('order_id');

        return compact('tenant', 'store', 'driver', 'ride', 'offer', 'courier', 'owner', 'order');
    }

    private function payload(int $ride, array $extra = []): array
    {
        return $extra + ['request_key' => (string) Str::uuid(), 'expected_round' => DB::table('rides')->where('id', $ride)->value('dispatch_round'), 'reason' => 'Conferido com o motoboy na loja.', 'physical_confirmation' => 1, 'compensation' => '4,50', 'customer_cash' => '0'];
    }

    private function url(int $ride, string $action): string
    {
        return $action === 'return' ? '/deliveries/'.$ride.'/return' : '/deliveries/'.$ride.'/exceptions/'.$action;
    }

    public function test_release_keeps_financial_snapshot_and_retry_rejects_old_offers_and_old_page(): void
    {
        $f = $this->fixture();
        $release = $this->payload($f['ride']);
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'release'), $release)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url($f['ride'], 'release'), $release)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseHas('delivery_attempts', ['ride_id' => $f['ride'], 'driver_id' => $f['driver'], 'quoted_fare_cents' => 800, 'compensation_cents' => 450, 'expected_cash_cents' => 3500, 'customer_cash_cents' => 0, 'outcome' => 'released_before_pickup']);
        $this->assertDatabaseMissing('driver_claims', ['ride_id' => $f['ride']]);
        $this->assertDatabaseHas('drivers', ['id' => $f['driver'], 'available' => false, 'checkin_store_id' => null]);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'payment_status' => 'pending', 'status' => 'ready', 'total_cents' => 3500]);
        $other = $this->driver($f['store']);
        $retry = $this->payload($f['ride']);
        $this->post($this->url($f['ride'], 'retry'), $retry)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url($f['ride'], 'retry'), $retry)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('offers')->where('driver_id', $other)->count());
        $new = DB::table('offers')->where('driver_id', $other)->firstOrFail();
        $this->assertEquals(3, $new->dispatch_round);
        $this->actingAs($f['courier'])->post('/courier/offers/'.$f['offer']->id.'/accept')->assertStatus(409);
        app(DispatchService::class)->accept($new->id, $other);
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'release'), $release)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'driver_id' => $other, 'status' => 'assigned']);
        $this->post($this->url($f['ride'], 'release'), $this->payload($f['ride'], ['expected_round' => 1]))->assertStatus(409);
        $this->assertDatabaseCount('delivery_attempts', 1);
    }

    public function test_return_holds_assignment_until_physical_receipt_then_cash_requires_review_before_retry(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['courier'])->post('/courier/rides/'.$f['ride'], ['status' => 'collected'])->assertRedirect();
        $payload = $this->payload($f['ride']);
        $this->post($this->url($f['ride'], 'return'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url($f['ride'], 'return'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('driver_claims', ['ride_id' => $f['ride'], 'driver_id' => $f['driver'], 'expires_at' => null]);
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'status' => 'returning']);
        $this->post('/courier/rides/'.$f['ride'], ['status' => 'completed'])->assertStatus(409);
        $this->get('/courier')->assertOk()->assertSee('Em retorno');
        $this->actingAs($f['owner'])->post('/orders/'.$f['order'].'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Teste retorno'])->assertStatus(409);
        $this->post('/orders/'.$f['order'].'/paid')->assertStatus(409);
        $this->post($this->url($f['ride'], 'receive'), $this->payload($f['ride'], ['physical_confirmation' => 0]))->assertSessionHasErrors('physical_confirmation');
        $receive = $this->payload($f['ride'], ['customer_cash' => '10,00']);
        $this->post($this->url($f['ride'], 'receive'), $receive)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url($f['ride'], 'receive'), $receive)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'payment_status' => 'review', 'status' => 'ready', 'total_cents' => 3500]);
        $this->assertDatabaseHas('delivery_attempts', ['outcome' => 'returned', 'customer_cash_cents' => 1000, 'compensation_cents' => 450]);
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'status' => 'returned', 'fare_cents' => 800, 'cash_cents' => 3500]);
        $this->assertDatabaseMissing('driver_claims', ['ride_id' => $f['ride']]);
        $this->post($this->url($f['ride'], 'retry'), $this->payload($f['ride']))->assertStatus(409);
        $this->post('/orders/'.$f['order'].'/dispatch', [])->assertStatus(409);
        $this->post('/orders/'.$f['order'].'/paid')->assertRedirect();
        $this->post($this->url($f['ride'], 'retry'), $this->payload($f['ride']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'cash_cents' => 0, 'driver_id' => null, 'status' => 'unassigned']);
    }

    public function test_attempt_history_is_private_and_remains_available_to_previous_driver_after_reassignment(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'release'), $this->payload($f['ride']))->assertRedirect();
        $attempt = DB::table('delivery_attempts')->firstOrFail();
        $this->actingAs($f['courier'])->get('/delivery-attempts/'.$attempt->id)->assertOk()->assertDontSee('Rua do Cliente')->assertDontSee('11999999999');
        $this->get('/deliveries/'.$f['ride'])->assertNotFound();
        $this->get('/courier')->assertOk()->assertSee('Ver tentativa e acerto');
        $otherTenant = $this->tenant('outro');
        $this->actingAs($this->owner($otherTenant))->get('/delivery-attempts/'.$attempt->id)->assertNotFound();
        $this->post('/delivery-attempts/'.$attempt->id.'/settle', ['confirmation' => 1])->assertNotFound();
        $this->post($this->url($f['ride'], 'retry'), $this->payload($f['ride']))->assertNotFound();
        $otherDriver = $this->driver();
        $otherUser = User::findOrFail(DB::table('drivers')->where('id', $otherDriver)->value('user_id'));
        $this->actingAs($otherUser)->get('/delivery-attempts/'.$attempt->id)->assertNotFound();
        $this->actingAs($f['courier'])->post('/delivery-attempts/'.$attempt->id.'/settle', ['confirmation' => 1])->assertForbidden();
        $this->actingAs($f['owner'])->post('/delivery-attempts/'.$attempt->id.'/settle', ['confirmation' => 0])->assertSessionHasErrors('confirmation');
        $this->post('/delivery-attempts/'.$attempt->id.'/settle', ['confirmation' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/delivery-attempts/'.$attempt->id.'/settle', ['confirmation' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'delivery.attempt.settled')->count());
        $this->assertDatabaseHas('delivery_attempts', ['id' => $attempt->id, 'settled' => true, 'settled_by' => $f['owner']->id]);
    }

    public function test_return_can_be_reported_under_suspension_and_module_closes_only_after_receipt(): void
    {
        $f = $this->fixture();
        DB::table('rides')->where('id', $f['ride'])->update(['status' => 'collected']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended', 'courier_module' => 'closing']);
        $this->actingAs($f['courier'])->post($this->url($f['ride'], 'return'), $this->payload($f['ride']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenants', ['id' => $f['tenant'], 'courier_module' => 'closing']);
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'receive'), $this->payload($f['ride']))->assertForbidden();
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'active']);
        $this->post($this->url($f['ride'], 'receive'), $this->payload($f['ride']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenants', ['id' => $f['tenant'], 'courier_module' => 'disabled']);
        $this->get('/deliveries/'.$f['ride'])->assertOk()->assertSee('Histórico');
        $this->post($this->url($f['ride'], 'retry'), $this->payload($f['ride']))->assertStatus(409);
    }

    public function test_validation_and_state_guards_do_not_drop_assignment_or_create_financial_records(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['courier'])->post($this->url($f['ride'], 'release'), $this->payload($f['ride']))->assertForbidden();
        $this->post($this->url($f['ride'], 'return'), $this->payload($f['ride']))->assertStatus(409);
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'release'), $this->payload($f['ride'], ['compensation' => '']))->assertSessionHasErrors('compensation_cents');
        $this->post($this->url($f['ride'], 'release'), $this->payload($f['ride'], ['compensation' => '-1']))->assertSessionHasErrors('compensation');
        DB::table('rides')->where('id', $f['ride'])->update(['status' => 'collected']);
        $this->post($this->url($f['ride'], 'release'), $this->payload($f['ride']))->assertStatus(409);
        $this->post($this->url($f['ride'], 'return'), $this->payload($f['ride']))->assertRedirect();
        $this->post($this->url($f['ride'], 'receive'), $this->payload($f['ride'], ['customer_cash' => '36']))->assertStatus(422);
        DB::table('orders')->where('id', $f['order'])->update(['payment_method' => 'pix']);
        $this->post($this->url($f['ride'], 'receive'), $this->payload($f['ride'], ['customer_cash' => '5']))->assertStatus(422);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseHas('driver_claims', ['ride_id' => $f['ride'], 'driver_id' => $f['driver']]);
    }

    public function test_cancel_after_return_preserves_history_and_no_automatic_payment_or_refund_occurs(): void
    {
        $f = $this->fixture();
        DB::table('rides')->where('id', $f['ride'])->update(['status' => 'collected']);
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'return'), $this->payload($f['ride']))->assertRedirect();
        $this->post($this->url($f['ride'], 'receive'), $this->payload($f['ride'], ['compensation' => '0']))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/orders/'.$f['order'].'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Pedido retornou.'])->assertRedirect();
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'status' => 'returned']);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'status' => 'cancelled', 'payment_status' => 'pending']);
        $this->assertDatabaseHas('delivery_attempts', ['ride_id' => $f['ride'], 'compensation_cents' => 0, 'settled' => false]);
        $this->post($this->url($f['ride'], 'retry'), $this->payload($f['ride']))->assertStatus(409);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_same_driver_can_receive_a_new_offer_but_previous_round_can_never_be_accepted_again(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'release'), $this->payload($f['ride']))->assertRedirect();
        $this->actingAs($f['courier'])->post('/courier/presence', ['latitude' => -23.55, 'longitude' => -46.63, 'accuracy' => 10, 'store_id' => $f['store']])->assertRedirect();
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'retry'), $this->payload($f['ride']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('offers', 2);
        $new = DB::table('offers')->orderByDesc('id')->firstOrFail();
        $this->actingAs($f['courier'])->post('/courier/offers/'.$new->id.'/accept')->assertRedirect();
        $this->post('/courier/offers/'.$f['offer']->id.'/accept')->assertStatus(409);
        $this->post('/courier/offers/'.$f['offer']->id.'/decline')->assertStatus(409);
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'driver_id' => $f['driver'], 'status' => 'assigned']);
        $this->assertDatabaseCount('driver_claims', 1);
    }

    public function test_audit_failure_rolls_back_release_including_snapshot_claim_and_driver(): void
    {
        $f = $this->fixture();
        DB::statement("CREATE TRIGGER fail_release_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'delivery.release' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->actingAs($f['owner'])->post($this->url($f['ride'], 'release'), $this->payload($f['ride']))->assertStatus(500);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'driver_id' => $f['driver'], 'status' => 'assigned', 'dispatch_round' => 1]);
        $this->assertDatabaseHas('driver_claims', ['driver_id' => $f['driver'], 'ride_id' => $f['ride'], 'expires_at' => null]);
    }
}
