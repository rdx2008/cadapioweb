<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CourierWebPresenceTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function courier(int $driver): User
    {
        return User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
    }

    private function location(): array
    {
        return ['latitude' => -23.55, 'longitude' => -46.63, 'accuracy' => 10, 'captured_at' => now()->timestamp];
    }

    public function test_heartbeat_preserves_fifo_and_queue_shows_only_eligible_waiting_drivers(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $first = $this->driver($store);
        $this->travel(1)->seconds();
        $driver = $this->driver($store);
        $stale = $this->driver($store, 120);
        $blocked = $this->driver($store);
        DB::table('drivers')->where('id', $blocked)->update(['status' => 'blocked']);
        $other = $this->driver($this->store($this->tenant('other'), 'other'));
        $checkedIn = DB::table('drivers')->where('id', $driver)->value('checked_in_at');
        $this->actingAs($this->courier($driver))->getJson('/courier/live')->assertOk()->assertJsonPath('presence.queue_position', 2)->assertJsonPath('presence.queue_total', 2)->assertJsonPath('presence.renew', true);
        $this->travel(30)->seconds();
        $this->postJson('/courier/heartbeat', $this->location() + ['store_id' => 999, 'driver_id' => $first])->assertOk()->assertJsonPath('presence.queue_position', 2);
        $this->assertSame($checkedIn, DB::table('drivers')->where('id', $driver)->value('checked_in_at'));
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'checkin_store_id' => $store, 'available' => true]);
        $this->assertDatabaseHas('drivers', ['id' => $blocked, 'status' => 'blocked']);
        $this->assertDatabaseHas('drivers', ['id' => $stale, 'available' => true]);
        $this->assertNotNull($other);
    }

    public function test_expired_or_offline_presence_never_auto_rejoins_and_explicit_confirmation_moves_to_end(): void
    {
        $store = $this->store($this->tenant());
        $driver = $this->driver($store, 91);
        $this->travel(1)->seconds();
        $other = $this->driver($store);
        $this->actingAs($this->courier($driver))->getJson('/courier/live')->assertOk()->assertJsonPath('presence.renew', false)->assertJsonPath('presence.queue_position', null);
        $this->postJson('/courier/heartbeat', $this->location())->assertConflict();
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false, 'checkin_store_id' => null]);
        $this->post('/courier/presence', $this->location() + ['store_id' => $store])->assertRedirect();
        $this->getJson('/courier/live')->assertOk()->assertJsonPath('presence.queue_position', 2);
        $this->post('/courier/offline')->assertRedirect();
        $this->postJson('/courier/heartbeat', $this->location())->assertConflict();
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false]);
        $this->assertDatabaseHas('drivers', ['id' => $other, 'available' => true]);
    }

    public function test_imprecise_old_future_or_unapproved_locations_cannot_renew(): void
    {
        $driver = $this->driver();
        $this->actingAs($this->courier($driver));
        foreach ([['accuracy' => 51], ['captured_at' => now()->subSeconds(61)->timestamp], ['captured_at' => now()->addSeconds(10)->timestamp]] as $invalid) {
            $this->postJson('/courier/heartbeat', array_replace($this->location(), $invalid))->assertUnprocessable();
        }
        DB::table('drivers')->where('id', $driver)->update(['status' => 'blocked']);
        $this->postJson('/courier/heartbeat', $this->location())->assertForbidden();
        $this->getJson('/courier/live')->assertOk()->assertJsonPath('presence.renew', false);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'tenant_id' => $this->tenant()]))->getJson('/courier/live')->assertForbidden();
        $this->postJson('/courier/heartbeat', $this->location())->assertForbidden();
    }

    public function test_leaving_checkin_radius_or_module_shutdown_suspends_queue(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $this->actingAs($this->courier($driver))->postJson('/courier/heartbeat', array_replace($this->location(), ['latitude' => 0]))->assertConflict()->assertJsonPath('presence.renew', false);
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false, 'checkin_store_id' => null]);
        $this->post('/courier/presence', $this->location() + ['store_id' => $store])->assertRedirect();
        DB::table('tenants')->where('id', $tenant)->update(['courier_module' => 'disabled']);
        $this->postJson('/courier/heartbeat', $this->location())->assertConflict();
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false]);
    }

    public function test_active_ride_location_renews_without_new_availability_even_after_module_shutdown(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        $service = app(DispatchService::class);
        $service->dispatch($ride);
        $service->accept(DB::table('offers')->value('id'), $driver);
        DB::table('tenants')->where('id', $tenant)->update(['courier_module' => 'closing']);
        $this->travel(100)->seconds();
        $this->actingAs($this->courier($driver))->postJson('/courier/heartbeat', array_replace($this->location(), ['latitude' => -23.60]))->assertOk()->assertJsonPath('presence.renew', true)->assertJsonPath('presence.queue_position', null);
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false, 'checkin_store_id' => null, 'latitude' => -23.60]);
        $this->assertDatabaseHas('rides', ['id' => $ride, 'status' => 'assigned', 'driver_id' => $driver]);
        $this->assertDatabaseHas('driver_claims', ['driver_id' => $driver, 'ride_id' => $ride, 'expires_at' => null]);
    }

    public function test_expired_exclusive_offer_cannot_be_revived_by_automatic_heartbeat(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        app(DispatchService::class)->dispatch($ride);
        $offer = DB::table('offers')->value('id');
        $this->travel(31)->seconds();
        $this->actingAs($this->courier($driver))->getJson('/courier/live')->assertOk()->assertJsonPath('presence.renew', false);
        $this->postJson('/courier/heartbeat', $this->location())->assertConflict();
        $this->post('/courier/offers/'.$offer.'/accept')->assertConflict();
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false]);
        $this->assertDatabaseHas('rides', ['id' => $ride, 'driver_id' => null]);
    }

    public function test_live_feed_contains_only_own_offers_and_rides_and_never_customer_details_before_acceptance(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        DB::table('orders')->where('id', DB::table('rides')->where('id', $ride)->value('order_id'))->update(['customer_name' => 'Cliente sigiloso', 'phone' => '11987654321', 'address' => 'Destino sigiloso']);
        app(DispatchService::class)->dispatch($ride);
        $foreignTenant = $this->tenant('foreign');
        $foreign = $this->ride($foreignTenant, $this->store($foreignTenant, 'outra'));
        DB::table('rides')->where('id', $foreign)->update(['driver_id' => $this->driver(), 'status' => 'assigned']);
        $this->actingAs($this->courier($driver))->get('/courier')->assertOk()->assertSee('data-courier-workspace', false)->assertSee('data-offer-expires', false)->assertDontSee('Cliente sigiloso')->assertDontSee('Destino sigiloso')->assertDontSee('11987654321');
        $response = $this->getJson('/courier/live')->assertOk()->assertJsonPath('presence.queue_position', null);
        $this->assertStringNotContainsString('Destino sigiloso', $response->json('html'));
        $this->assertStringNotContainsString('Corrida #'.$foreign, $response->json('html'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        DB::table('offers')->update(['expires_at' => now()->subSecond()]);
        $response = $this->getJson('/courier/live')->assertOk();
        $this->assertStringNotContainsString('data-offer-id=', $response->json('html'));
    }
}
