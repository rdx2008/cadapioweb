<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IfoodClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class IfoodConnectionTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private const MERCHANT = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function application(): object
    {
        $id = DB::table('ifood_apps')->insertGetId(['environment' => 'test', 'client_id' => 'test-client', 'client_secret' => Crypt::encryptString('SECRET-ONLY-SERVER'), 'revision' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('ifood_apps')->find($id);
    }

    private function fakeApi(): void
    {
        Http::fake([
            '*/authentication/v1.0/oauth/token' => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
            '*/merchants/'.self::MERCHANT => Http::response(['id' => self::MERCHANT, 'name' => 'Loja Teste iFood']),
            '*/merchants/'.self::MERCHANT.'/status' => Http::response([['operation' => 'DELIVERY', 'salesChannel' => 'MARKETPLACE', 'available' => false, 'state' => 'CLOSED']]),
        ]);
    }

    public function test_only_admin_can_configure_secrets_and_validation_never_flashes_them(): void
    {
        $owner = $this->owner($this->tenant());
        $this->actingAs($owner)->post('/admin/ifood/configure', ['client_id' => 'x', 'client_secret' => 'secret'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/admin/ifood')->post('/admin/ifood/configure', ['client_id' => '', 'client_secret' => 'NEVER-FLASH'])->assertSessionHasErrors('client_id');
        $this->assertNull(session()->getOldInput('client_secret'));
        $this->post('/admin/ifood/configure', ['client_id' => 'test-client', 'client_secret' => 'SECRET-ONLY-SERVER'])->assertRedirect()->assertSessionHasNoErrors();
        $app = DB::table('ifood_apps')->first();
        $this->assertNotSame('SECRET-ONLY-SERVER', $app->client_secret);
        $this->assertSame('SECRET-ONLY-SERVER', Crypt::decryptString($app->client_secret));
        $this->get('/admin/ifood')->assertOk()->assertDontSee('SECRET-ONLY-SERVER');
        $this->post('/admin/ifood/configure', ['client_id' => 'test-client', 'client_secret' => ''])->assertSessionHasNoErrors();
        $this->assertSame('SECRET-ONLY-SERVER', Crypt::decryptString(DB::table('ifood_apps')->value('client_secret')));
        $this->postJson('/admin/ifood/configure', ['client_id' => 'other-client'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_authentication_uses_form_credentials_and_encrypted_cached_token(): void
    {
        $app = $this->application();
        $this->fakeApi();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/ifood/test')->assertSessionHasNoErrors();
        $this->post('/admin/ifood/test')->assertSessionHasNoErrors();
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request['grantType'] === 'client_credentials' && $request['clientId'] === 'test-client' && $request['clientSecret'] === 'SECRET-ONLY-SERVER');
        $cached = Cache::get('ifood:token:'.$app->id.':'.$app->revision);
        $this->assertNotSame('private-token', $cached);
        $this->assertSame('private-token', Crypt::decryptString($cached));
        $this->get('/admin/ifood')->assertDontSee('private-token')->assertDontSee('SECRET-ONLY-SERVER');
    }

    public function test_admin_links_verified_store_and_owner_cannot_access_other_tenants(): void
    {
        $this->application();
        $this->fakeApi();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $otherTenant = $this->tenant('other');
        $otherStore = $this->store($otherTenant, 'other');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['store_id' => $store, 'merchant_id' => self::MERCHANT, 'test_merchant_confirmed' => 1];
        $this->post('/admin/ifood/connect', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $connection = DB::table('ifood_connections')->first();
        $this->assertDatabaseHas('ifood_connections', ['tenant_id' => $tenant, 'store_id' => $store, 'merchant_id' => self::MERCHANT, 'status' => 'verified']);
        $this->assertStringContainsString('CLOSED', $connection->operations);
        $payload['store_id'] = $otherStore;
        $this->postJson('/admin/ifood/connect', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('ifood_connections', 1);
        $this->actingAs($this->owner($tenant))->get('/integrations/ifood')->assertOk()->assertSee('Loja Teste iFood')->assertSee('Operação fechada')->assertDontSee('SECRET-ONLY-SERVER')->assertDontSee('private-token');
        $this->post('/integrations/ifood/'.$connection->id.'/check')->assertSessionHasNoErrors();
        $this->post('/admin/ifood/connect', $payload)->assertForbidden();
        $this->post('/admin/ifood/'.$connection->id.'/disconnect')->assertForbidden();
        $before = Http::recorded()->count();
        $this->actingAs($this->owner($otherTenant))->get('/integrations/ifood')->assertOk()->assertDontSee('Loja Teste iFood')->assertDontSee(self::MERCHANT);
        $this->post('/integrations/ifood/'.$connection->id.'/check')->assertNotFound();
        $this->assertCount($before, Http::recorded());
    }

    public function test_bad_api_response_does_not_bind_a_store_or_leak_external_errors(): void
    {
        $this->application();
        $store = $this->store($this->tenant());
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
            '*/merchants/*' => Http::response(['message' => 'SECRET-ONLY-SERVER private-token'], 403),
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/admin/ifood/connect', ['store_id' => $store, 'merchant_id' => self::MERCHANT, 'test_merchant_confirmed' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('ifood')->assertDontSee('SECRET-ONLY-SERVER')->assertDontSee('private-token');
        $this->assertDatabaseCount('ifood_connections', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_expired_token_is_refreshed_once(): void
    {
        $app = $this->application();
        Http::fake([
            '*/oauth/token' => Http::sequence()->push(['accessToken' => 'expired-token', 'expiresIn' => 21600])->push(['accessToken' => 'new-token', 'expiresIn' => 21600]),
            '*/merchants/'.self::MERCHANT => Http::sequence()->push([], 401)->push(['id' => self::MERCHANT, 'name' => 'Loja Teste iFood']),
            '*/merchants/'.self::MERCHANT.'/status' => Http::response([['operation' => 'DELIVERY', 'salesChannel' => 'MARKETPLACE', 'available' => true, 'state' => 'OK']]),
        ]);
        $snapshot = app(IfoodClient::class)->inspect($app, self::MERCHANT);
        $this->assertSame('Loja Teste iFood', $snapshot['merchant_name']);
        Http::assertSentCount(5);
        Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer new-token'));
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $this->application();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
            '*/merchants/*' => Http::response(['id' => ['invalid'], 'name' => 'Invalid']),
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/ifood/connect', ['store_id' => $this->store($this->tenant()), 'merchant_id' => self::MERCHANT, 'test_merchant_confirmed' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('ifood_connections', 0);
    }

    public function test_rotating_credentials_requires_reverification_and_local_unlink_is_audited(): void
    {
        $this->application();
        $this->fakeApi();
        $store = $this->store($this->tenant());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/admin/ifood/connect', ['store_id' => $store, 'merchant_id' => self::MERCHANT, 'test_merchant_confirmed' => 1])->assertSessionHasNoErrors();
        $id = DB::table('ifood_connections')->value('id');
        $this->post('/admin/ifood/configure', ['client_id' => 'test-client', 'client_secret' => 'ROTATED-SECRET'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ifood_connections', ['id' => $id, 'status' => 'pending', 'checked_at' => null, 'operations' => null]);
        $this->post('/admin/ifood/'.$id.'/disconnect')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ifood_connections', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'ifood.store_unlinked']);
        $this->assertStringNotContainsString('ROTATED-SECRET', DB::table('audit_events')->pluck('details')->implode(' '));
    }

    public function test_failed_recheck_clears_stale_status_and_retains_safe_diagnostics(): void
    {
        $this->application();
        $store = $this->store($this->tenant());
        $denied = false;
        Http::fake(function (ClientRequest $request) use (&$denied) {
            if (str_ends_with($request->url(), '/oauth/token')) {
                return Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]);
            }
            if ($denied) {
                return Http::response(['message' => 'private-token SECRET-ONLY-SERVER'], 403);
            }

            return str_ends_with($request->url(), '/status')
                ? Http::response([['operation' => 'DELIVERY', 'salesChannel' => 'MARKETPLACE', 'available' => true, 'state' => 'OK']])
                : Http::response(['id' => self::MERCHANT, 'name' => 'Loja Teste iFood']);
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/ifood/connect', ['store_id' => $store, 'merchant_id' => self::MERCHANT, 'test_merchant_confirmed' => 1])->assertSessionHasNoErrors();
        $id = DB::table('ifood_connections')->value('id');
        $denied = true;
        $this->postJson('/admin/ifood/'.$id.'/check')->assertUnprocessable()->assertDontSee('private-token');
        $this->assertDatabaseHas('ifood_connections', ['id' => $id, 'status' => 'error', 'operations' => null, 'last_error' => 'Aplicativo sem autorização para esta loja no iFood.']);
        $this->get('/admin/ifood')->assertOk()->assertSee('Falha na consulta')->assertDontSee('private-token')->assertDontSee('SECRET-ONLY-SERVER');
    }

    public function test_configuration_changed_during_verification_cannot_create_a_stale_binding(): void
    {
        $app = $this->application();
        $store = $this->store($this->tenant());
        Http::fake(function (ClientRequest $request) use ($app) {
            if (str_ends_with($request->url(), '/oauth/token')) {
                return Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]);
            }
            if (str_ends_with($request->url(), '/status')) {
                DB::table('ifood_apps')->where('id', $app->id)->update(['revision' => (string) Str::uuid()]);

                return Http::response([['operation' => 'DELIVERY', 'salesChannel' => 'MARKETPLACE', 'available' => true, 'state' => 'OK']]);
            }

            return Http::response(['id' => self::MERCHANT, 'name' => 'Loja Teste iFood']);
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/ifood/connect', ['store_id' => $store, 'merchant_id' => self::MERCHANT, 'test_merchant_confirmed' => 1])->assertUnprocessable()->assertJsonValidationErrors('ifood');
        $this->assertDatabaseCount('ifood_connections', 0);
    }
}
