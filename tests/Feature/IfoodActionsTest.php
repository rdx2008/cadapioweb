<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IfoodInbox;
use App\Services\IfoodOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\BuildsPlatform;
use Tests\TestCase;

class IfoodActionsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(19, 0));
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $tenant = $this->tenant('t-'.Str::lower(Str::random(8)));
        $store = $this->store($tenant, 's-'.Str::lower(Str::random(8)));
        $app = DB::table('ifood_apps')->first();
        if (! $app) {
            $id = DB::table('ifood_apps')->insertGetId(['environment' => 'test', 'client_id' => 'client', 'client_secret' => Crypt::encryptString('secret-server'), 'revision' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
            $app = DB::table('ifood_apps')->find($id);
        }
        $merchant = (string) Str::uuid();
        $connection = DB::table('ifood_connections')->insertGetId(['app_id' => $app->id, 'tenant_id' => $tenant, 'store_id' => $store, 'merchant_id' => $merchant, 'merchant_name' => 'Teste', 'status' => 'verified', 'receiving_enabled' => true, 'actions_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = ['items' => [], 'totals' => ['subTotal' => 1000, 'deliveryFee' => 0, 'additionalFees' => 0, 'benefits' => 0, 'orderAmount' => 1000]];
        $id = DB::table('ifood_orders')->insertGetId(['app_id' => $app->id, 'tenant_id' => $tenant, 'store_id' => $store, 'merchant_id' => $merchant, 'external_order_id' => (string) Str::uuid(), 'display_id' => 'TEST-1', 'customer_name' => 'Cliente Teste', 'status' => 'pending', 'order_type' => 'DELIVERY', 'delivered_by' => 'MERCHANT', 'timing' => 'IMMEDIATE', 'ordered_at' => now(), 'last_event_at' => now(), 'total_cents' => 1000, 'prepaid_cents' => 1000, 'pending_cents' => 0, 'snapshot' => Crypt::encryptString(json_encode($snapshot)), 'created_at' => now(), 'updated_at' => now()]);

        return [$app, DB::table('ifood_connections')->find($connection), DB::table('ifood_orders')->find($id), $this->owner($tenant)];
    }

    private function detail(object $order): array
    {
        return ['id' => $order->external_order_id, 'merchant' => ['id' => $order->merchant_id], 'category' => 'FOOD', 'isTest' => true, 'orderType' => 'DELIVERY', 'orderTiming' => 'IMMEDIATE', 'delivery' => ['deliveredBy' => 'MERCHANT']];
    }

    private function event(object $order, string $code): array
    {
        return ['id' => (string) Str::uuid(), 'merchantId' => $order->merchant_id, 'orderId' => $order->external_order_id, 'code' => $code, 'createdAt' => now()->toIso8601String()];
    }

    private function submit(object $order, string $action, ?string $key = null, ?string $reason = null): TestResponse
    {
        return $this->post('/integrations/ifood/orders/'.$order->id.'/actions', ['action' => $action, 'request_key' => $key ?? (string) Str::uuid(), 'reason' => $reason]);
    }

    public function test_full_action_flow_is_durable_idempotent_and_only_events_confirm_status(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        $batch = [];
        $writes = [];
        Http::fake(function (ClientRequest $request) use ($order, &$batch, &$writes) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/oauth/token')) {
                return Http::response(['accessToken' => 'token', 'expiresIn' => 21600]);
            }
            if (str_ends_with($path, '/events:polling')) {
                return Http::response($batch);
            }
            if (str_ends_with($path, '/acknowledgment')) {
                return Http::response([], 202);
            }
            if ($request->method() === 'POST') {
                $this->assertDatabaseHas('ifood_commands', ['order_id' => $order->id, 'status' => 'sending']);
                $writes[] = basename($path);
                if (str_ends_with($path, '/dispatch')) {
                    $this->assertSame(['deliveredBy' => 'MERCHANT'], $request->data());
                }

                return Http::response([], 202);
            }

            return Http::response($this->detail($order));
        });
        $this->actingAs($owner);
        foreach ([['confirm', 'CFM', 'accepted'], ['prepare', 'PRS', 'preparing'], ['ready', 'RTP', 'ready'], ['dispatch', 'DSP', 'dispatched']] as [$action, $code, $state]) {
            $previous = DB::table('ifood_orders')->where('id', $order->id)->value('status');
            $key = (string) Str::uuid();
            $this->submit($order, $action, $key)->assertSessionHasNoErrors();
            $this->submit($order, $action, $key)->assertSessionHasNoErrors();
            $count = DB::table('ifood_commands')->count();
            $this->artisan('ifood:send-commands')->assertExitCode(0);
            $this->assertDatabaseHas('ifood_commands', ['action' => $action, 'status' => 'awaiting_event']);
            $this->assertDatabaseHas('ifood_orders', ['id' => $order->id, 'status' => $previous]);
            $this->assertSame($count, DB::table('ifood_commands')->count());
            $this->travel(31)->seconds();
            $batch = [$this->event($order, $code)];
            app(IfoodInbox::class)->sync($app->id);
            $this->assertDatabaseHas('ifood_commands', ['action' => $action, 'status' => 'confirmed']);
            $this->assertDatabaseHas('ifood_orders', ['id' => $order->id, 'status' => $state]);
        }
        $this->assertSame(['confirm', 'startPreparation', 'readyToPickup', 'dispatch'], $writes);
        $this->assertDatabaseCount('ifood_commands', 4);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('rides', 0);
    }

    public function test_cross_tenant_actions_and_disabled_connection_never_reach_the_api(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        $other = $this->owner($this->tenant('other'));
        $this->actingAs($other)->submit($order, 'confirm')->assertNotFound();
        $this->get('/integrations/ifood/orders/'.$order->id.'/live')->assertNotFound();
        DB::table('ifood_connections')->where('id', $connection->id)->update(['actions_enabled' => false]);
        $this->actingAs($owner)->submit($order, 'confirm')->assertSessionHasErrors('ifood');
        $this->get('/integrations/ifood/orders/'.$order->id)->assertOk()->assertDontSee('name="action"', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ifood_commands', 0);
    }

    public function test_timeout_is_uncertain_and_never_automatically_resent(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        $writes = 0;
        Http::fake(function (ClientRequest $request) use ($order, &$writes) {
            if (str_ends_with($request->url(), '/oauth/token')) {
                return Http::response(['accessToken' => 'token', 'expiresIn' => 21600]);
            }
            if ($request->method() === 'POST') {
                $writes++;
                throw new ConnectionException('private-token secret-server');
            }

            return Http::response($this->detail($order));
        });
        $this->actingAs($owner)->submit($order, 'confirm')->assertSessionHasNoErrors();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'uncertain', 'attempts' => 1]);
        $this->travel(61)->seconds();
        app(IfoodOutbox::class)->run();
        $this->assertSame(1, $writes);
        $this->submit($order, 'confirm')->assertSessionHasErrors('ifood');
        $this->get('/integrations/ifood/orders/'.$order->id)->assertOk()->assertSee('Resultado não confirmado')->assertDontSee('private-token')->assertDontSee('secret-server');
    }

    public function test_rate_limit_delays_a_known_unsent_request_and_preserves_pending_status(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'token', 'expiresIn' => 21600]),
            '*/orders/'.$order->external_order_id => Http::response($this->detail($order)),
            '*/confirm' => Http::sequence()->push([], 429, ['Retry-After' => '60'])->push([], 202),
        ]);
        $this->actingAs($owner)->submit($order, 'confirm')->assertSessionHasNoErrors();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'queued', 'attempts' => 1]);
        $this->travel(31)->seconds();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'queued', 'attempts' => 1]);
        $this->travel(30)->seconds();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'awaiting_event', 'attempts' => 2]);
        $this->assertDatabaseHas('ifood_orders', ['id' => $order->id, 'status' => 'pending']);
    }

    public function test_cancel_only_uses_current_provider_reasons_and_waits_for_cancelled_event(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        $batch = [];
        Http::fake(function (ClientRequest $request) use ($order, &$batch) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/oauth/token')) {
                return Http::response(['accessToken' => 'token', 'expiresIn' => 21600]);
            }
            if (str_ends_with($path, '/cancellationReasons')) {
                return Http::response(['reasons' => [['code' => '503', 'description' => 'Produto indisponível']]]);
            }
            if (str_ends_with($path, '/requestCancellation')) {
                $this->assertSame(['reason' => '503'], $request->data());

                return Http::response([], 202);
            }
            if (str_ends_with($path, '/events:polling')) {
                return Http::response($batch);
            }
            if (str_ends_with($path, '/acknowledgment')) {
                return Http::response([], 202);
            }

            return Http::response($this->detail($order));
        });
        $this->actingAs($owner)->submit($order, 'cancel', reason: '999')->assertSessionHasErrors('ifood');
        $this->post('/integrations/ifood/orders/'.$order->id.'/reasons')->assertSessionHasNoErrors();
        $this->submit($order, 'cancel', reason: '999')->assertSessionHasErrors('ifood');
        $this->submit($order, 'cancel', reason: '503')->assertSessionHasNoErrors();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['action' => 'cancel', 'status' => 'awaiting_event']);
        $this->assertDatabaseHas('ifood_orders', ['id' => $order->id, 'status' => 'pending']);
        $this->travel(31)->seconds();
        $batch = [$this->event($order, 'CAN')];
        app(IfoodInbox::class)->sync($app->id);
        $this->assertDatabaseHas('ifood_commands', ['action' => 'cancel', 'status' => 'confirmed']);
        $this->assertDatabaseHas('ifood_orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_ifood_delivery_scheduled_orders_and_changed_credentials_block_unsafe_commands(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        $this->actingAs($owner);
        DB::table('ifood_orders')->where('id', $order->id)->update(['timing' => 'SCHEDULED', 'preparation_at' => now()->addHour()]);
        $this->submit($order, 'confirm')->assertSessionHasErrors('ifood');
        DB::table('ifood_orders')->where('id', $order->id)->update(['timing' => 'IMMEDIATE', 'status' => 'ready', 'delivered_by' => 'IFOOD']);
        $this->submit($order, 'dispatch')->assertSessionHasErrors('ifood');
        DB::table('ifood_orders')->where('id', $order->id)->update(['status' => 'pending', 'delivered_by' => 'MERCHANT']);
        $this->submit($order, 'confirm')->assertSessionHasNoErrors();
        DB::table('ifood_apps')->where('id', $app->id)->update(['revision' => (string) Str::uuid()]);
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'rejected', 'attempts' => 0]);
        Http::assertNothingSent();
    }

    public function test_crash_after_send_does_not_replay_the_command(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        $this->actingAs($owner)->submit($order, 'confirm')->assertSessionHasNoErrors();
        DB::table('ifood_commands')->update(['status' => 'sending', 'sent_at' => now()->subMinutes(3), 'locked_at' => now()->subMinutes(3)]);
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'uncertain']);
        Http::assertNothingSent();
    }

    public function test_terminal_event_during_http_request_is_not_overwritten_by_late_202(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        Http::fake(function (ClientRequest $request) use ($order, $app) {
            if (str_ends_with($request->url(), '/oauth/token')) {
                return Http::response(['accessToken' => 'token', 'expiresIn' => 21600]);
            }
            if ($request->method() === 'POST') {
                DB::table('ifood_orders')->where('id', $order->id)->update(['status' => 'cancelled']);
                app(IfoodOutbox::class)->observe((object) ['app_id' => $app->id, 'code' => 'CAN', 'occurred_at' => now()->addSecond()->toDateTimeString()], $order->id);

                return Http::response([], 202);
            }

            return Http::response($this->detail($order));
        });
        $this->actingAs($owner)->submit($order, 'confirm')->assertSessionHasNoErrors();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'obsolete']);
        $this->assertDatabaseHas('ifood_orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_server_error_is_not_blindly_retried_and_unattributed_cancel_failure_remains_uncertain(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'token', 'expiresIn' => 21600]),
            '*/orders/'.$order->external_order_id => Http::response($this->detail($order)),
            '*/confirm' => Http::response(['message' => 'token secret-server'], 500),
        ]);
        $this->actingAs($owner)->submit($order, 'confirm')->assertSessionHasNoErrors();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['action' => 'confirm', 'status' => 'uncertain']);
        $this->travel(31)->seconds();
        app(IfoodOutbox::class)->run();
        $this->assertCount(1, Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), '/confirm')));
        $this->assertStringNotContainsString('secret-server', DB::table('ifood_commands')->value('last_error'));
        DB::table('ifood_commands')->update(['action' => 'cancel', 'status' => 'awaiting_event']);
        app(IfoodOutbox::class)->observe((object) ['app_id' => $app->id, 'code' => 'CARF', 'occurred_at' => now()->toDateTimeString()], $order->id);
        $this->assertDatabaseHas('ifood_commands', ['action' => 'cancel', 'status' => 'uncertain']);
        $this->assertDatabaseHas('ifood_orders', ['id' => $order->id, 'status' => 'pending']);
    }

    public function test_fresh_api_identity_and_logistics_are_checked_before_any_write(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        DB::table('ifood_orders')->where('id', $order->id)->update(['status' => 'ready']);
        $detail = $this->detail($order);
        $detail['delivery']['deliveredBy'] = 'IFOOD';
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'token', 'expiresIn' => 21600]),
            '*/orders/'.$order->external_order_id => Http::response($detail),
        ]);
        $this->actingAs($owner)->submit($order, 'dispatch')->assertSessionHasNoErrors();
        app(IfoodOutbox::class)->run();
        $this->assertDatabaseHas('ifood_commands', ['status' => 'rejected', 'attempts' => 0]);
        $this->assertCount(0, Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), '/dispatch')));
    }

    public function test_actions_require_explicit_admin_activation_and_stable_form_keys(): void
    {
        [$app, $connection, $order, $owner] = $this->fixture();
        DB::table('ifood_connections')->where('id', $connection->id)->update(['actions_enabled' => false]);
        $this->actingAs($owner)->post('/admin/ifood/'.$connection->id.'/actions', ['enabled' => 1, 'test_actions_confirmed' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/ifood/'.$connection->id.'/actions', ['enabled' => 1])->assertUnprocessable();
        $this->post('/admin/ifood/'.$connection->id.'/actions', ['enabled' => 1, 'test_actions_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ifood_connections', ['id' => $connection->id, 'actions_enabled' => true]);
        $this->actingAs($owner);
        $first = $this->getJson('/integrations/ifood/orders/'.$order->id.'/live')->assertOk();
        $second = $this->getJson('/integrations/ifood/orders/'.$order->id.'/live')->assertOk();
        $this->assertSame($first->json('signature'), $second->json('signature'));
        Http::assertNothingSent();
    }
}
