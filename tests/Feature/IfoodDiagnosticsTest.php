<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class IfoodDiagnosticsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private const MERCHANT = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function configure(): void
    {
        DB::table('ifood_apps')->insert(['environment' => 'test', 'client_id' => 'public-client', 'client_secret' => Crypt::encryptString('private-client-secret'), 'revision' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function payload(): array
    {
        return ['merchant_id' => self::MERCHANT, 'test_merchant_confirmed' => 1];
    }

    public function test_two_independent_requests_capture_edge_response_and_redact_secrets_without_binding_or_polling(): void
    {
        $this->configure();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-access-token', 'expiresIn' => 21600]),
            '*/merchants/'.self::MERCHANT => Http::response('<html>Access denied. Reference #edge-123 private-access-token private-client-secret</html>', 403, ['Content-Type' => 'text/html', 'CF-Ray' => 'ray-123-GRU', 'X-Request-Id' => 'req-456', 'Set-Cookie' => 'session=private-cookie', 'X-Echo' => 'private-access-token']),
            '*/merchants/'.self::MERCHANT.'/status' => Http::response([['available' => false, 'state' => 'CLOSED']], 200, ['X-Amzn-Trace-Id' => 'trace-789']),
        ]);
        $response = $this->post('/admin/ifood/diagnostics', $this->payload())->assertOk()->assertDownload()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $text = $response->streamedContent();
        $report = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $report['requests']);
        $this->assertSame(403, $report['requests'][0]['response']['status']);
        $this->assertSame(200, $report['requests'][1]['response']['status']);
        $this->assertStringContainsString('Reference #edge-123', $text);
        $this->assertStringContainsString('ray-123-GRU', $text);
        $this->assertStringContainsString('req-456', $text);
        $this->assertStringContainsString('trace-789', $text);
        $this->assertStringContainsString('IFOOD_ACCESS_TOKEN', $report['requests'][0]['curl']);
        foreach (['private-access-token', 'private-client-secret', 'private-cookie'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
            $this->assertStringNotContainsString($secret, json_encode(session()->all()));
            $this->assertStringNotContainsString($secret, (string) DB::table('audit_events')->value('details'));
        }
        Http::assertSentCount(3);
        $this->assertDatabaseCount('ifood_connections', 0);
        $this->assertDatabaseCount('ifood_events', 0);
        $this->assertDatabaseHas('ifood_apps', ['last_polled_at' => null]);
        $this->get('/admin/ifood')->assertOk()->assertSee('diagnostics', false)->assertDontSee('private-client-secret');
    }

    public function test_access_uuid_confirmation_and_dedicated_throttle_protect_the_diagnostic(): void
    {
        $this->post('/admin/ifood/diagnostics', $this->payload())->assertRedirect('/login');
        $this->actingAs($this->owner($this->tenant()))->post('/admin/ifood/diagnostics', $this->payload())->assertForbidden();
        $this->configure();
        $this->post('/admin/ifood/diagnostics', ['merchant_id' => 'https://example.com', 'test_merchant_confirmed' => 1])->assertSessionHasErrors('merchant_id');
        $this->post('/admin/ifood/diagnostics', ['merchant_id' => self::MERCHANT])->assertSessionHasErrors('test_merchant_confirmed');
        $this->post('/admin/ifood/diagnostics', $this->payload())->assertTooManyRequests();
        Http::assertNothingSent();
    }

    public function test_large_bodies_are_explicitly_truncated_and_redirect_is_never_followed(): void
    {
        $this->configure();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-access-token', 'expiresIn' => 21600]),
            '*/merchants/'.self::MERCHANT => Http::response(str_repeat('A', 70000), 403),
            '*/merchants/'.self::MERCHANT.'/status' => Http::response('', 302, ['Location' => 'https://untrusted.example/login']),
        ]);
        $report = json_decode($this->post('/admin/ifood/diagnostics', $this->payload())->assertOk()->streamedContent(), true);
        $this->assertTrue($report['requests'][0]['response']['body_truncated']);
        $this->assertSame(65536, strlen($report['requests'][0]['response']['body']));
        $this->assertSame(302, $report['requests'][1]['response']['status']);
        Http::assertSentCount(3);
    }

    public function test_transport_failure_still_probes_the_other_endpoint_without_logging_exception_secrets(): void
    {
        $this->configure();
        Http::fake([
            '*/oauth/token' => Http::response(['accessToken' => 'private-access-token', 'expiresIn' => 21600]),
            '*/merchants/'.self::MERCHANT => fn () => throw new ConnectionException('private-access-token private-client-secret'),
            '*/merchants/'.self::MERCHANT.'/status' => Http::response([], 200),
        ]);
        $text = $this->post('/admin/ifood/diagnostics', $this->payload())->assertOk()->streamedContent();
        $report = json_decode($text, true);
        $this->assertNull($report['requests'][0]['response']);
        $this->assertNotEmpty($report['requests'][0]['error']);
        $this->assertSame(200, $report['requests'][1]['response']['status']);
        $this->assertStringNotContainsString('private-access-token', $text);
        $this->assertStringNotContainsString('private-client-secret', $text);
    }
}
