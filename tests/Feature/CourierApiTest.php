<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryConfirmation;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CourierApiTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Guards cache the resolved user for the test's application lifetime, so each request re-resolves its bearer token.
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function courier(int $driver): User
    {
        return User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
    }

    private function token(User $user, string $device = 'Moto G'): string
    {
        return $this->postJson('/api/v1/courier/login', ['email' => $user->email, 'password' => 'password', 'device_name' => $device])->assertCreated()->json('token');
    }

    /**
     * @return array{latitude: float, longitude: float, accuracy: int, captured_at: int}
     */
    private function location(): array
    {
        return ['latitude' => -23.55, 'longitude' => -46.63, 'accuracy' => 10, 'captured_at' => now()->timestamp];
    }

    /**
     * @return array{tenant: int, store: int, driver: int, ride: int, offer: int, courier: User, token: string}
     */
    private function offered(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        app(DispatchService::class)->dispatch($ride);
        $courier = $this->courier($driver);

        return ['tenant' => $tenant, 'store' => $store, 'driver' => $driver, 'ride' => $ride, 'offer' => (int) DB::table('offers')->where('ride_id', $ride)->value('id'), 'courier' => $courier, 'token' => $this->token($courier)];
    }

    public function test_login_is_limited_to_enabled_couriers_and_returns_profile_with_presence(): void
    {
        $driver = $this->driver();
        $courier = $this->courier($driver);
        $this->postJson('/api/v1/courier/login', ['email' => $courier->email, 'password' => 'wrong', 'device_name' => 'Moto G'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/courier/login', ['email' => $courier->email, 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('device_name');
        $owner = $this->owner($this->tenant());
        $this->postJson('/api/v1/courier/login', ['email' => $owner->email, 'password' => 'password', 'device_name' => 'Moto G'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $response = $this->postJson('/api/v1/courier/login', ['email' => $courier->email, 'password' => 'password', 'device_name' => 'Moto G'])->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')->assertJsonPath('driver.id', $driver)->assertJsonPath('driver.status', 'approved')->assertJsonPath('driver.email', $courier->email)
            ->assertJsonPath('presence.code', 'region')->assertJsonPath('presence.renew', true)->assertJsonPath('intervals.heartbeat_seconds', 30)->assertJsonPath('intervals.presence_ttl_seconds', 90);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $courier->id, 'name' => 'Moto G']);
        $this->assertDatabaseHas('audit_events', ['action' => 'courier.api.login']);
        $this->as($response->json('token'))->getJson('/api/v1/courier/me')->assertOk()->assertJsonPath('driver.id', $driver)->assertJsonMissingPath('token');
        $courier->forceFill(['access_enabled' => false])->save();
        $this->as($response->json('token'))->getJson('/api/v1/courier/me')->assertForbidden();
        $this->postJson('/api/v1/courier/login', ['email' => $courier->email, 'password' => 'password', 'device_name' => 'Moto G'])->assertUnprocessable();
    }

    public function test_protected_routes_reject_missing_tokens_and_non_courier_accounts(): void
    {
        $this->getJson('/api/v1/courier/board')->assertUnauthorized();
        $this->get('/api/v1/courier/board')->assertUnauthorized()->assertJsonStructure(['message']);
        $owner = $this->owner($this->tenant());
        $this->as($owner->createToken('web')->plainTextToken)->getJson('/api/v1/courier/board')->assertForbidden();
        $this->as('invalid-token')->getJson('/api/v1/courier/board')->assertUnauthorized();
    }

    public function test_presence_heartbeat_queue_and_offline_follow_web_rules(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $first = $this->driver($store);
        $this->travel(1)->seconds();
        $driver = $this->driver($store, 91);
        $token = $this->token($this->courier($driver));
        $this->as($token)->getJson('/api/v1/courier/stores')->assertOk()->assertJsonCount(1, 'stores')->assertJsonPath('stores.0.id', $store)->assertJsonPath('stores.0.latitude', -23.55);
        $this->as($token)->getJson('/api/v1/courier/board')->assertOk()->assertJsonPath('presence.code', 'presence_expired')->assertJsonPath('presence.renew', false)->assertJsonCount(0, 'offers');
        $this->as($token)->postJson('/api/v1/courier/heartbeat', $this->location())->assertConflict()->assertJsonPath('accepted', false)->assertJsonPath('presence.code', 'offline');
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false, 'checkin_store_id' => null]);
        $this->as($token)->postJson('/api/v1/courier/presence', array_replace($this->location(), ['accuracy' => 80]) + ['store_id' => $store])->assertUnprocessable()->assertJsonValidationErrors('accuracy');
        $this->as($token)->postJson('/api/v1/courier/presence', array_replace($this->location(), ['latitude' => -23.60]) + ['store_id' => $store])->assertUnprocessable();
        $this->as($token)->postJson('/api/v1/courier/presence', $this->location() + ['store_id' => $store])->assertOk()->assertJsonPath('presence.code', 'queued')->assertJsonPath('presence.queue_position', 2)->assertJsonPath('presence.queue_total', 2);
        $this->travel(30)->seconds();
        $this->as($token)->postJson('/api/v1/courier/heartbeat', $this->location())->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('presence.queue_position', 2);
        $this->as($token)->postJson('/api/v1/courier/heartbeat', array_replace($this->location(), ['captured_at' => now()->subSeconds(61)->timestamp]))->assertUnprocessable();
        $this->as($token)->postJson('/api/v1/courier/offline')->assertOk()->assertJsonPath('presence.code', 'offline');
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'available' => false]);
        $this->assertDatabaseHas('drivers', ['id' => $first, 'available' => true, 'checkin_store_id' => $store]);
        DB::table('drivers')->where('id', $driver)->update(['status' => 'pending']);
        $this->as($token)->postJson('/api/v1/courier/presence', $this->location())->assertForbidden();
        $this->as($token)->getJson('/api/v1/courier/me')->assertOk()->assertJsonPath('presence.code', 'not_approved')->assertJsonPath('driver.status_label', 'Aguardando aprovação');
    }

    public function test_offer_can_be_declined_or_accepted_and_ride_advances_idempotently_with_customer_code(): void
    {
        $f = $this->offered();
        DB::table('rides')->where('id', $f['ride'])->update(['confirmation_required' => true]);
        $this->as($f['token'])->getJson('/api/v1/courier/board')->assertOk()->assertJsonPath('presence.code', 'offer_reserved')->assertJsonCount(1, 'offers')
            ->assertJsonPath('offers.0.id', $f['offer'])->assertJsonPath('offers.0.kind', 'exclusive')->assertJsonPath('offers.0.fare_cents', 800)->assertJsonPath('offers.0.payment_label', 'Dinheiro')->assertJsonPath('offers.0.store_name', 'Loja loja')->assertJsonMissingPath('offers.0.address');
        $this->as($f['token'])->postJson('/api/v1/courier/offers/'.$f['offer'].'/accept')->assertOk()->assertJsonPath('ride.id', $f['ride'])->assertJsonPath('ride.status', 'assigned')->assertJsonPath('ride.next_step', 'collected')->assertJsonPath('ride.customer_name', 'Cliente')->assertJsonPath('ride.address', 'Rua do Cliente, 200')->assertJsonPath('presence.code', 'on_ride');
        $this->as($f['token'])->postJson('/api/v1/courier/offers/'.$f['offer'].'/decline')->assertConflict();
        $url = '/api/v1/courier/rides/'.$f['ride'].'/progress';
        $this->as($f['token'])->postJson($url, ['status' => 'completed', 'expected_round' => 1])->assertConflict();
        $this->as($f['token'])->postJson($url, ['status' => 'collected', 'expected_round' => 2])->assertConflict();
        $this->as($f['token'])->postJson($url, ['status' => 'collected', 'expected_round' => 1])->assertOk()->assertJsonPath('ride.status', 'collected')->assertJsonPath('ride.next_step', 'completed')->assertJsonPath('ride.confirmation_required', true)->assertJsonMissingPath('ride.confirmation_cipher');
        $this->as($f['token'])->postJson($url, ['status' => 'collected', 'expected_round' => 1])->assertOk()->assertJsonPath('ride.status', 'collected');
        $ride = DB::table('rides')->find($f['ride']);
        $code = app(DeliveryConfirmation::class)->forCustomer($ride);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'ride.progress')->count());
        $this->travel(61)->seconds();
        $this->as($f['token'])->postJson($url, ['status' => 'completed', 'expected_round' => 1])->assertUnprocessable()->assertJsonValidationErrors('delivery_code');
        $this->as($f['token'])->post($url, ['status' => 'completed', 'expected_round' => 1, 'delivery_code' => $code, 'received_by' => 'Portaria', 'photo' => UploadedFile::fake()->image('entrega.jpg', 20, 20)])->assertOk()->assertJsonPath('ride.status', 'completed')->assertJsonPath('ride.received_by', 'Portaria')->assertJsonPath('ride.next_step', null)->assertJsonPath('presence.code', 'offline');
        $this->as($f['token'])->postJson($url, ['status' => 'completed', 'expected_round' => 1])->assertOk()->assertJsonPath('ride.status', 'completed');
        $this->assertSame(2, DB::table('audit_events')->where('action', 'ride.progress')->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
        $this->assertDatabaseHas('orders', ['id' => $ride->order_id, 'status' => 'completed', 'payment_status' => 'paid']);
        $this->assertDatabaseMissing('driver_claims', ['ride_id' => $f['ride']]);
        $this->as($f['token'])->getJson('/api/v1/courier/rides/'.$f['ride'])->assertOk()->assertJsonPath('ride.status_label', 'Concluída')->assertJsonPath('ride.proof_recorded_at', fn ($value) => $value !== null)->assertJsonCount(0, 'incidents');
    }

    public function test_declining_and_other_drivers_rides_stay_isolated(): void
    {
        $f = $this->offered();
        $this->as($f['token'])->postJson('/api/v1/courier/offers/'.$f['offer'].'/decline')->assertOk()->assertJsonCount(0, 'offers');
        $this->assertDatabaseHas('offers', ['id' => $f['offer'], 'status' => 'declined']);
        $this->as($f['token'])->postJson('/api/v1/courier/offers/'.$f['offer'].'/accept')->assertConflict();
        $other = $this->token($this->courier($this->driver($f['store'])), 'Outro');
        $this->as($other)->postJson('/api/v1/courier/offers/'.$f['offer'].'/accept')->assertNotFound();
        $this->as($other)->getJson('/api/v1/courier/rides/'.$f['ride'])->assertNotFound();
        $this->as($other)->postJson('/api/v1/courier/rides/'.$f['ride'].'/progress', ['status' => 'collected'])->assertNotFound();
    }

    public function test_courier_can_report_incidents_and_return_to_store_with_idempotent_keys(): void
    {
        $f = $this->offered();
        $this->as($f['token'])->postJson('/api/v1/courier/offers/'.$f['offer'].'/accept')->assertOk();
        $incident = ['request_key' => (string) Str::uuid(), 'kind' => 'address_issue', 'description' => 'Número não existe na rua informada.'];
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/incidents', ['kind' => 'no_courier'] + $incident)->assertUnprocessable();
        $this->as($f['token'])->post('/api/v1/courier/rides/'.$f['ride'].'/incidents', $incident + ['photo' => UploadedFile::fake()->image('rua.jpg', 20, 20)])->assertCreated()->assertJsonPath('incident.kind_label', 'Problema no endereço')->assertJsonPath('incident.has_photo', true)->assertJsonPath('incident.status', 'open');
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/incidents', $incident)->assertCreated();
        $this->assertDatabaseCount('delivery_incidents', 1);
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'status' => 'assigned', 'driver_id' => $f['driver']]);
        $this->as($f['token'])->getJson('/api/v1/courier/rides/'.$f['ride'])->assertOk()->assertJsonCount(1, 'incidents')->assertJsonPath('incidents.0.author_name', $f['courier']->name);
        $return = ['request_key' => (string) Str::uuid(), 'expected_round' => 1, 'reason' => 'Cliente não atendeu após três tentativas.'];
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/return', $return)->assertConflict();
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/progress', ['status' => 'collected'])->assertOk();
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/return', $return)->assertOk()->assertJsonPath('ride.status', 'returning')->assertJsonPath('ride.return_reason', $return['reason'])->assertJsonPath('presence.code', 'on_ride');
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/return', $return)->assertOk()->assertJsonPath('ride.status', 'returning');
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/progress', ['status' => 'completed'])->assertConflict();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'delivery.return')->count());
    }

    public function test_statement_lists_only_own_entries_and_validates_period(): void
    {
        $f = $this->offered();
        DB::table('orders')->where('id', DB::table('rides')->where('id', $f['ride'])->value('order_id'))->update(['payment_method' => 'cash']);
        $this->as($f['token'])->postJson('/api/v1/courier/offers/'.$f['offer'].'/accept')->assertOk();
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/progress', ['status' => 'collected'])->assertOk();
        $this->as($f['token'])->postJson('/api/v1/courier/rides/'.$f['ride'].'/progress', ['status' => 'completed'])->assertOk();
        $otherStore = $this->store($this->tenant('other'), 'other');
        $otherDriver = $this->driver($otherStore);
        $otherRide = $this->ride(DB::table('stores')->where('id', $otherStore)->value('tenant_id'), $otherStore);
        DB::table('rides')->where('id', $otherRide)->update(['driver_id' => $otherDriver, 'status' => 'completed', 'completed_at' => now(), 'fare_cents' => 1500]);
        $this->as($f['token'])->getJson('/api/v1/courier/statement')->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.ride_id', $f['ride'])->assertJsonPath('entries.0.kind', 'completed')->assertJsonPath('entries.0.compensation_cents', 800)->assertJsonPath('entries.0.settled', false)
            ->assertJsonPath('totals.entries_count', 1)->assertJsonPath('totals.compensation_cents', 800)->assertJsonPath('totals.pending_compensation_cents', 800)->assertJsonPath('filters.timezone', 'America/Sao_Paulo')->assertJsonPath('filters.settlement', 'all')->assertJsonCount(1, 'stores')->assertJsonPath('pagination.total', 1);
        $this->as($f['token'])->getJson('/api/v1/courier/statement?settlement=settled')->assertOk()->assertJsonCount(0, 'entries');
        $this->as($f['token'])->getJson('/api/v1/courier/statement?store_id='.$otherStore)->assertNotFound();
        $this->as($f['token'])->getJson('/api/v1/courier/statement?start=2020-01-01&end='.now()->toDateString())->assertUnprocessable()->assertJsonValidationErrors('start');
        $this->as($f['token'])->getJson('/api/v1/courier/statement?timezone=Mars/Olympus')->assertUnprocessable();
    }

    public function test_devices_are_registered_per_token_and_logout_or_password_reset_revoke_access(): void
    {
        $driver = $this->driver();
        $courier = $this->courier($driver);
        $phone = $this->token($courier, 'Telefone');
        $tablet = $this->token($courier, 'Tablet');
        $this->as($phone)->putJson('/api/v1/courier/devices', ['platform' => 'android', 'push_token' => 'fcm-token-phone-0001', 'app_version' => '1.0.0'])->assertOk()->assertJsonPath('push_delivery', 'pending_provider');
        $this->as($phone)->putJson('/api/v1/courier/devices', ['platform' => 'android', 'push_token' => 'fcm-token-phone-0001', 'app_version' => '1.0.1'])->assertOk();
        $this->as($tablet)->putJson('/api/v1/courier/devices', ['platform' => 'ios', 'push_token' => 'apns-token-tablet-0001'])->assertOk();
        $this->as($tablet)->putJson('/api/v1/courier/devices', ['platform' => 'windows', 'push_token' => 'x'])->assertUnprocessable();
        $this->assertDatabaseCount('courier_devices', 2);
        $this->assertDatabaseHas('courier_devices', ['user_id' => $courier->id, 'push_token' => 'fcm-token-phone-0001', 'app_version' => '1.0.1']);
        $this->as($phone)->postJson('/api/v1/courier/logout')->assertOk();
        $this->as($phone)->getJson('/api/v1/courier/me')->assertUnauthorized();
        $this->as($tablet)->getJson('/api/v1/courier/me')->assertOk();
        $this->assertDatabaseCount('courier_devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'courier.api.logout']);
        $reset = Password::createToken($courier);
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->post('/reset-password', ['email' => $courier->email, 'token' => $reset, 'password' => 'nova-senha-muito-segura', 'password_confirmation' => 'nova-senha-muito-segura'])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->as($tablet)->getJson('/api/v1/courier/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('courier_devices', 0);
    }
}
