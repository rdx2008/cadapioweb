<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IfoodInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class IfoodInboxTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(18, 0));
        Http::preventStrayRequests();
    }

    private function application(): object
    {
        $id = DB::table('ifood_apps')->insertGetId(['environment' => 'test', 'client_id' => 'test-client', 'client_secret' => Crypt::encryptString('server-secret'), 'revision' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('ifood_apps')->find($id);
    }

    private function connection(object $app, int $tenant, int $store, bool $enabled = true): object
    {
        $id = DB::table('ifood_connections')->insertGetId(['app_id' => $app->id, 'tenant_id' => $tenant, 'store_id' => $store, 'merchant_id' => (string) Str::uuid(), 'merchant_name' => 'Loja de teste', 'status' => 'verified', 'receiving_enabled' => $enabled, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('ifood_connections')->find($id);
    }

    private function event(object $connection, string $orderId, string $code = 'PLC', ?string $at = null): array
    {
        return ['id' => (string) Str::uuid(), 'merchantId' => $connection->merchant_id, 'orderId' => $orderId, 'code' => $code, 'fullCode' => 'PLACED', 'createdAt' => $at ?? now()->toIso8601String(), 'metadata' => ['private' => 'PRIVATE_EVENT']];
    }

    private function orderPayload(object $connection, string $id): array
    {
        return [
            'id' => $id, 'merchant' => ['id' => $connection->merchant_id], 'displayId' => 'TEST-100', 'category' => 'FOOD',
            'orderType' => 'DELIVERY', 'orderTiming' => 'SCHEDULED', 'isTest' => true,
            'createdAt' => now()->subMinute()->toIso8601String(), 'preparationStartDateTime' => now()->addHour()->toIso8601String(),
            'customer' => ['name' => 'Cliente de teste', 'documentNumber' => '12345678901', 'segmentation' => 'PRIVATE_SEGMENT'],
            'total' => ['subTotal' => 50, 'deliveryFee' => 5, 'additionalFees' => 0, 'benefits' => 2, 'orderAmount' => 53],
            'payments' => ['prepaid' => 30, 'pending' => 23],
            'items' => [['name' => 'Prato da casa', 'quantity' => 2.5, 'unitPrice' => 20, 'totalPrice' => 50, 'observations' => '<script>observação</script>', 'options' => [['name' => 'Molho', 'quantity' => 1, 'customization' => [['name' => 'Sem pimenta', 'quantity' => 1]]]]]],
        ];
    }

    public function test_events_are_durable_before_ack_and_duplicate_delivery_never_duplicates_orders(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant));
        $orderId = (string) Str::uuid();
        $event = $this->event($connection, $orderId);
        $payload = $this->orderPayload($connection, $orderId);
        $acks = 0;
        $fetches = 0;
        Http::fake(function (ClientRequest $request) use ($event, $payload, $connection, &$acks, &$fetches) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/oauth/token')) {
                return Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]);
            }
            if (str_ends_with($path, '/events:polling')) {
                $this->assertTrue($request->hasHeader('x-polling-merchants', $connection->merchant_id));

                return Http::response([$event]);
            }
            if (str_ends_with($path, '/acknowledgment')) {
                $this->assertDatabaseHas('ifood_events', ['external_id' => $event['id']]);
                $this->assertSame([['id' => $event['id']]], $request->data());
                $acks++;

                return Http::response([], 202);
            }
            $fetches++;

            return Http::response($payload);
        });
        $this->assertSame(['received' => 1, 'processed' => 1, 'failed' => 0], app(IfoodInbox::class)->sync($app->id));
        $order = DB::table('ifood_orders')->first();
        $this->assertSame(5300, $order->total_cents);
        $this->assertSame(3000, $order->prepaid_cents);
        $this->assertSame(2300, $order->pending_cents);
        $snapshot = Crypt::decryptString($order->snapshot);
        $this->assertStringNotContainsString('12345678901', $snapshot);
        $this->assertStringNotContainsString('PRIVATE_SEGMENT', $snapshot);
        $this->assertSame('2.5', json_decode($snapshot, true)['items'][0]['quantity']);
        $this->assertStringNotContainsString('PRIVATE_EVENT', DB::table('ifood_events')->value('payload'));
        $this->travel(31)->seconds();
        $this->assertSame(['received' => 0, 'processed' => 0, 'failed' => 0], app(IfoodInbox::class)->sync($app->id));
        $this->assertSame(2, $acks);
        $this->assertSame(1, $fetches);
        $this->assertDatabaseCount('ifood_events', 1);
        $this->assertDatabaseCount('ifood_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('rides', 0);
        $this->actingAs($this->owner($tenant))->get('/integrations/ifood/orders/'.$order->id)->assertOk()->assertSee('2.5')->assertSee('53,00')->assertSee('Sem pimenta')->assertSee('&lt;script&gt;observação&lt;/script&gt;', false)->assertDontSee('12345678901');
    }

    public function test_tenant_mapping_is_checked_for_events_details_and_private_views(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $other = $this->tenant('other');
        $one = $this->connection($app, $tenant, $this->store($tenant));
        $two = $this->connection($app, $other, $this->store($other, 'other'));
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();
        $foreign = $this->event((object) ['merchant_id' => (string) Str::uuid()], (string) Str::uuid());
        $batch = [$this->event($one, $firstId), $this->event($two, $secondId), $foreign];
        $firstPayload = $this->orderPayload($one, $firstId);
        $secondPayload = $this->orderPayload($two, $secondId);
        $secondPayload['customer']['name'] = 'Segredo de outro tenant';
        Http::fake(function (ClientRequest $request) use ($batch, $firstPayload, $secondPayload, $firstId, $foreign) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/oauth/token')) {
                return Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]);
            }
            if (str_ends_with($path, '/events:polling')) {
                return Http::response($batch);
            }
            if (str_ends_with($path, '/acknowledgment')) {
                $this->assertNotContains(['id' => $foreign['id']], $request->data());

                return Http::response([], 202);
            }

            return Http::response(str_ends_with($path, $firstId) ? $firstPayload : $secondPayload);
        });
        $result = app(IfoodInbox::class)->sync($app->id);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseCount('ifood_orders', 2);
        $this->assertDatabaseCount('ifood_events', 2);
        $private = DB::table('ifood_orders')->where('tenant_id', $other)->first();
        $this->actingAs($this->owner($tenant))->get('/integrations/ifood')->assertOk()->assertDontSee('Segredo de outro tenant');
        $response = $this->getJson('/integrations/ifood/orders/live')->assertOk();
        $this->assertStringNotContainsString('Segredo de outro tenant', $response->json('html'));
        $this->get('/integrations/ifood/orders/'.$private->id)->assertNotFound();
    }

    public function test_out_of_order_events_and_late_confirmations_do_not_reopen_cancelled_orders(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant));
        $id = (string) Str::uuid();
        $batch = [$this->event($connection, $id, 'CAN', now()->toIso8601String()), $this->event($connection, $id, 'PLC', now()->subSeconds(5)->toIso8601String())];
        Http::fake(function (ClientRequest $request) use (&$batch, $connection, $id) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/oauth/token') => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
                str_ends_with($path, '/events:polling') => Http::response($batch),
                str_ends_with($path, '/acknowledgment') => Http::response([], 202),
                default => Http::response($this->orderPayload($connection, $id)),
            };
        });
        app(IfoodInbox::class)->sync($app->id);
        $this->assertDatabaseHas('ifood_orders', ['external_order_id' => $id, 'status' => 'cancelled']);
        $this->travel(31)->seconds();
        $batch = [$this->event($connection, $id, 'CFM')];
        app(IfoodInbox::class)->sync($app->id);
        $this->assertDatabaseHas('ifood_orders', ['external_order_id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('ifood_orders', 1);
    }

    public function test_failed_ack_is_retried_without_reimporting_and_polling_obeys_thirty_seconds(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant));
        $id = (string) Str::uuid();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
            '*/events:polling*' => Http::response([$this->event($connection, $id)]),
            '*/acknowledgment' => Http::sequence()->push(['error' => 'private-token'], 500)->push([], 202),
            '*/orders/'.$id => Http::response($this->orderPayload($connection, $id)),
        ]);
        $this->assertSame(1, app(IfoodInbox::class)->sync($app->id)['failed']);
        $this->assertNull(DB::table('ifood_events')->value('acknowledged_at'));
        $this->assertDatabaseCount('ifood_orders', 1);
        $this->assertSame(0, app(IfoodInbox::class)->sync($app->id)['failed']);
        $this->assertNotNull(DB::table('ifood_events')->value('acknowledged_at'));
        $this->assertDatabaseCount('ifood_orders', 1);
        $this->assertCount(1, Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), '/events:polling')));
    }

    public function test_order_detail_failure_is_durable_and_recovers_after_receiving_is_paused(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant));
        $id = (string) Str::uuid();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
            '*/events:polling*' => Http::response([$this->event($connection, $id)]),
            '*/acknowledgment' => Http::response([], 202),
            '*/orders/'.$id => Http::sequence()->push(['message' => 'private-token server-secret'], 500)->push($this->orderPayload($connection, $id)),
        ]);
        $this->artisan('ifood:sync')->assertExitCode(1);
        $this->assertDatabaseHas('ifood_events', ['status' => 'error', 'attempts' => 1]);
        $this->assertNotNull(DB::table('ifood_events')->value('acknowledged_at'));
        $this->assertDatabaseCount('ifood_orders', 0);
        $this->assertStringNotContainsString('private-token', DB::table('ifood_events')->value('last_error'));
        DB::table('ifood_connections')->where('id', $connection->id)->update(['receiving_enabled' => false]);
        $this->travel(31)->seconds();
        $this->artisan('ifood:sync')->assertExitCode(0);
        $this->assertDatabaseHas('ifood_events', ['status' => 'processed', 'attempts' => 2, 'last_error' => null]);
        $this->assertDatabaseCount('ifood_orders', 1);
    }

    public function test_live_orders_and_mismatched_merchants_are_not_imported_or_exposed(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant));
        $realId = (string) Str::uuid();
        $wrongId = (string) Str::uuid();
        $real = $this->orderPayload($connection, $realId);
        $real['isTest'] = false;
        $wrong = $this->orderPayload($connection, $wrongId);
        $wrong['merchant']['id'] = (string) Str::uuid();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
            '*/events:polling*' => Http::response([$this->event($connection, $realId), $this->event($connection, $wrongId)]),
            '*/acknowledgment' => Http::response([], 202),
            '*/orders/'.$realId => Http::response($real), '*/orders/'.$wrongId => Http::response($wrong),
        ]);
        $this->assertSame(2, app(IfoodInbox::class)->sync($app->id)['failed']);
        $this->assertDatabaseCount('ifood_orders', 0);
        $this->assertDatabaseCount('ifood_events', 2);
        $response = $this->actingAs($this->owner($tenant))->getJson('/integrations/ifood/orders/live')->assertOk();
        $this->assertStringContainsString('Importação de pedidos reais bloqueada', $response->json('html'));
    }

    public function test_receiving_is_disabled_by_default_requires_admin_and_does_not_allow_unlinking_pending_events(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant), false);
        $this->artisan('ifood:sync')->assertExitCode(0);
        Http::assertNothingSent();
        $this->actingAs($this->owner($tenant))->post('/admin/ifood/'.$connection->id.'/receiving', ['enabled' => 1, 'test_only_confirmed' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/ifood/'.$connection->id.'/receiving', ['enabled' => 1])->assertUnprocessable();
        $this->post('/admin/ifood/'.$connection->id.'/receiving', ['enabled' => 1, 'test_only_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ifood_connections', ['id' => $connection->id, 'receiving_enabled' => true]);
        $event = $this->event($connection, (string) Str::uuid());
        DB::table('ifood_events')->insert(['app_id' => $app->id, 'connection_id' => $connection->id, 'tenant_id' => $tenant, 'store_id' => $connection->store_id, 'external_id' => $event['id'], 'external_order_id' => $event['orderId'], 'merchant_id' => $connection->merchant_id, 'code' => 'PLC', 'occurred_at' => now(), 'payload' => Crypt::encryptString(json_encode($event))]);
        $this->post('/admin/ifood/'.$connection->id.'/disconnect')->assertConflict();
        $this->post('/admin/ifood/'.$connection->id.'/receiving', ['enabled' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ifood_connections', ['id' => $connection->id, 'receiving_enabled' => false]);
    }

    public function test_unknown_events_are_preserved_and_acknowledged_without_creating_fake_orders(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant));
        $event = $this->event($connection, (string) Str::uuid(), 'FUTURE_EVENT');
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
            '*/events:polling*' => Http::response([$event]), '*/acknowledgment' => Http::response([], 202),
        ]);
        $this->assertSame(0, app(IfoodInbox::class)->sync($app->id)['failed']);
        $this->assertDatabaseHas('ifood_events', ['code' => 'FUTURE_EVENT', 'status' => 'ignored']);
        $this->assertNotNull(DB::table('ifood_events')->value('acknowledged_at'));
        $this->assertDatabaseCount('ifood_orders', 0);
        Http::assertSentCount(3);
    }

    public function test_conflicting_event_identity_is_not_acknowledged_or_reassigned(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $other = $this->tenant('other');
        $first = $this->connection($app, $tenant, $this->store($tenant));
        $second = $this->connection($app, $other, $this->store($other, 'other'));
        $id = (string) Str::uuid();
        $event = $this->event($first, $id);
        $acks = 0;
        Http::fake(function (ClientRequest $request) use (&$event, $first, $id, &$acks) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/acknowledgment')) {
                $acks++;

                return Http::response([], 202);
            }

            return match (true) {
                str_ends_with($path, '/oauth/token') => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
                str_ends_with($path, '/events:polling') => Http::response([$event]),
                default => Http::response($this->orderPayload($first, $id)),
            };
        });
        app(IfoodInbox::class)->sync($app->id);
        $event['merchantId'] = $second->merchant_id;
        $this->travel(31)->seconds();
        $this->assertSame(1, app(IfoodInbox::class)->sync($app->id)['failed']);
        $this->assertSame(1, $acks);
        $this->assertDatabaseHas('ifood_orders', ['external_order_id' => $id, 'tenant_id' => $tenant]);
        $this->assertDatabaseCount('ifood_orders', 1);
        $this->assertDatabaseHas('ifood_events', ['external_id' => $event['id'], 'merchant_id' => $first->merchant_id]);
    }

    public function test_later_confirmation_from_another_device_cannot_reverse_preparation(): void
    {
        $app = $this->application();
        $tenant = $this->tenant();
        $connection = $this->connection($app, $tenant, $this->store($tenant));
        $id = (string) Str::uuid();
        $batch = [$this->event($connection, $id, 'SPS')];
        Http::fake(function (ClientRequest $request) use (&$batch, $connection, $id) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/oauth/token') => Http::response(['accessToken' => 'private-token', 'expiresIn' => 21600]),
                str_ends_with($path, '/events:polling') => Http::response($batch),
                str_ends_with($path, '/acknowledgment') => Http::response([], 202),
                default => Http::response($this->orderPayload($connection, $id)),
            };
        });
        app(IfoodInbox::class)->sync($app->id);
        $this->travel(31)->seconds();
        $batch = [$this->event($connection, $id, 'CFM')];
        app(IfoodInbox::class)->sync($app->id);
        $this->assertDatabaseHas('ifood_orders', ['external_order_id' => $id, 'status' => 'preparing']);
    }
}
