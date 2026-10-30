<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryConfirmation;
use App\Services\DispatchService;
use App\Services\FareCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\BuildsPlatform;
use Tests\TestCase;

class LogisticsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_fifo_decline_advances_queue(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $a = $this->driver($s);
        $b = $this->driver($s);
        $ride = $this->ride($t, $s);
        $service = app(DispatchService::class);
        $service->dispatch($ride);
        $this->assertDatabaseHas('offers', ['ride_id' => $ride, 'driver_id' => $a, 'status' => 'open']);
        $this->assertDatabaseCount('offers', 1);
        $offer = DB::table('offers')->first();
        $service->decline($offer->id, $a);
        $service->dispatch($ride);
        $this->assertDatabaseHas('offers', ['ride_id' => $ride, 'driver_id' => $b, 'status' => 'open']);
    }

    public function test_open_offer_has_one_winner_and_repeat_accept_is_idempotent(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $a = $this->driver();
        $b = $this->driver();
        $ride = $this->ride($t, $s);
        $service = app(DispatchService::class);
        $service->dispatch($ride);
        $offers = DB::table('offers')->orderBy('id')->get();
        $this->assertCount(2, $offers);
        $service->accept($offers[0]->id, $a);
        $service->accept($offers[0]->id, $a);
        $this->assertDatabaseCount('driver_claims', 1);
        $this->assertDatabaseHas('offers', ['id' => $offers[1]->id, 'status' => 'lost']);
        try {
            $service->accept($offers[1]->id, $b);
            $this->fail('Segundo aceite deveria falhar.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }$this->assertDatabaseHas('rides', ['id' => $ride, 'driver_id' => $a]);
    }

    public function test_reservation_prevents_conflicting_offers_between_tenants(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $sa = $this->store($a, 'a');
        $sb = $this->store($b, 'b');
        $d = $this->driver($sa);
        $ra = $this->ride($a, $sa);
        $rb = $this->ride($b, $sb);
        $service = app(DispatchService::class);
        $service->dispatch($ra);
        $service->dispatch($rb);
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseHas('driver_claims', ['driver_id' => $d, 'ride_id' => $ra]);
    }

    public function test_stale_presence_and_expired_offer_cannot_accept(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $d = $this->driver($s, 120);
        $ride = $this->ride($t, $s);
        $service = app(DispatchService::class);
        $service->dispatch($ride);
        $this->assertDatabaseCount('offers', 0);
        DB::table('drivers')->where('id', $d)->update(['heartbeat_at' => now()]);
        DB::table('rides')->where('id', $ride)->update(['status' => 'searching']);
        $service->dispatch($ride);
        $offer = DB::table('offers')->first();
        $this->travel(31)->seconds();
        $this->expectException(HttpException::class);
        $service->accept($offer->id, $d);
    }

    public function test_remote_checkin_is_rejected(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $d = $this->driver();
        $u = User::findOrFail(DB::table('drivers')->find($d)->user_id);
        $this->actingAs($u)->post('/courier/presence', ['store_id' => $s, 'latitude' => 0, 'longitude' => 0, 'accuracy' => 10])->assertUnprocessable();
    }

    public function test_fares_use_integer_cents_and_minimum(): void
    {
        $c = app(FareCalculator::class);
        $s = (object) ['rate_mode' => 'per_km', 'base_cents' => 400, 'per_km_cents' => 200, 'minimum_cents' => 1200];
        $this->assertSame(1200, $c->quote($s, 3200));
        $this->assertSame(1400, $c->quote($s, 5000));
        $s->rate_mode = 'fixed';
        $s->base_cents = 1500;
        $this->assertSame(1500, $c->quote($s));
        $s->rate_mode = 'per_ride';
        $this->assertSame(1800, $c->quote($s, null, 1800));
    }

    public function test_assigned_ride_survives_module_shutdown_and_can_complete_and_settle(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $owner = $this->owner($tenant);
        $order = $this->order($tenant, $store);
        $this->actingAs($owner)->post('/orders/'.$order.'/dispatch')->assertRedirect();
        $ride = DB::table('rides')->first();
        $offer = DB::table('offers')->first();
        $courier = User::findOrFail(DB::table('drivers')->find($driver)->user_id);
        $this->actingAs($courier)->post('/courier/offers/'.$offer->id.'/accept')->assertRedirect();
        $this->actingAs($owner)->post('/orders/'.$order.'/paid')->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/tenants/'.$tenant.'/module', ['state' => 'disabled'])->assertRedirect();
        $this->assertDatabaseHas('tenants', ['id' => $tenant, 'courier_module' => 'closing']);
        $this->actingAs($courier)->post('/courier/rides/'.$ride->id, ['status' => 'collected'])->assertRedirect();
        $this->post('/courier/rides/'.$ride->id, ['status' => 'completed', 'expected_round' => $ride->dispatch_round, 'delivery_code' => app(DeliveryConfirmation::class)->forCustomer(DB::table('rides')->find($ride->id))])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('driver_claims', ['driver_id' => $driver]);
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'completed', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('tenants', ['id' => $tenant, 'courier_module' => 'disabled']);
        $this->actingAs($owner)->post('/rides/'.$ride->id.'/settle')->assertRedirect();
        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'settled' => true, 'fare_cents' => 800, 'cash_cents' => 3500]);
    }

    public function test_search_expands_to_five_km_when_no_candidate_is_within_three(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver();
        DB::table('drivers')->where('id', $driver)->update(['latitude' => -23.515]);
        $ride = $this->ride($tenant, $store);
        app(DispatchService::class)->dispatch($ride);
        $this->assertDatabaseHas('offers', ['ride_id' => $ride, 'driver_id' => $driver, 'status' => 'open']);
    }
}
