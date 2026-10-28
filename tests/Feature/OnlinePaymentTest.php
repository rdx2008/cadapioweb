<?php

namespace Tests\Feature;

use App\Services\OnlinePayment;
use App\Services\PaymentGateway;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://menu.example.test']);
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-27 15:00:00', 'UTC'));
    }

    private function fakeHttp(array|callable $responses): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function fixture(string $provider = 'infinitepay'): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $key = 'fake-checkout-key-not-real';
        $secret = Str::random(32);
        $account = DB::table('store_payment_accounts')->insertGetId(['store_id' => $store, 'provider' => $provider, 'environment' => 'production', 'credential' => Crypt::encryptString($provider === 'pagbank' ? $key : ''), 'handle' => 'loja-teste', 'webhook_secret' => $secret, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Lanche', 'category' => 'Lanches', 'price_cents' => 3000]);
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'items' => [$product => 1], 'payment_method' => 'online_'.$provider, 'idempotency_key' => (string) Str::uuid()];

        return compact('tenant', 'store', 'key', 'secret', 'account', 'cart');
    }

    private function create(array $f, string $provider = 'infinitepay'): object
    {
        $this->fakeHttp(function ($request) use ($provider) {
            $this->assertDatabaseHas('payment_attempts', ['state' => 'creating']);
            if ($provider === 'infinitepay') {
                $this->assertSame(3000, $request['items'][0]['price']);
                $this->assertSame('loja-teste', $request['handle']);
                $this->assertStringStartsWith('https://menu.example.test/wp/i/', $request['webhook_url']);
                $this->assertArrayNotHasKey('options', $request->data());
                $this->assertArrayNotHasKey('webhook', $request->data());
                $this->assertFalse($request->hasHeader('X-Api-Key'));
                $this->assertFalse($request->hasHeader('Authorization'));

                return Http::response(['link' => 'https://checkout.infinitepay.io/loja/invoice', 'slug' => 'invoice-test']);
            }

            $host = str_starts_with($request->url(), 'https://sandbox.') ? 'sandbox.pagamento.pagbank.com.br' : 'pagamento.pagbank.com.br';

            return Http::response(['id' => 'CHEC_TEST-12345', 'reference_id' => $request['reference_id'], 'links' => [['rel' => 'PAY', 'href' => 'https://'.$host.'/pagamento?code=test']]], 201);
        });
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();

        return DB::table('payment_attempts')->orderByDesc('id')->first();
    }

    private function infiniteNotice(array $f, object $attempt, string $transaction = 'transaction-test'): void
    {
        $this->postJson('/wp/i/'.$f['secret'], ['order_nsu' => $attempt->reference, 'invoice_slug' => 'invoice-test', 'transaction_nsu' => $transaction, 'paid' => true])->assertOk();
    }

    private function signedNotice(object $attempt, string $key, array $patch = []): void
    {
        $body = json_encode(array_replace(['id' => 'ORDE_TEST-12345', 'reference_id' => $attempt->reference, 'charges' => [['status' => 'PAID']]], $patch), JSON_THROW_ON_ERROR);
        $this->call('POST', '/wp/p', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_AUTHENTICITY_TOKEN' => hash('sha256', $key.'-'.$body)], $body)->assertOk();
    }

    public function test_infinitepay_link_is_created_once_and_webhook_needs_server_verification(): void
    {
        $f = $this->fixture();
        $attempt = $this->create($f);
        $this->assertSame('waiting', $attempt->state);
        $this->assertSame('', Crypt::decryptString($attempt->credential));
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect('https://checkout.infinitepay.io/loja/invoice');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('orders', 1);
        $this->infiniteNotice($f, $attempt);
        $this->assertDatabaseHas('orders', ['payment_status' => 'pending']);
        $this->travel(31)->seconds();
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 3000, 'paid_amount' => 3000, 'capture_method' => 'pix'])]);
        $this->artisan('payments:sync')->assertExitCode(0);
        $this->assertDatabaseHas('orders', ['payment_status' => 'paid', 'status' => 'pending']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payment_check') && $request['handle'] === 'loja-teste' && ! $request->hasHeader('X-Api-Key') && ! $request->hasHeader('Authorization'));
        $this->assertDatabaseHas('payment_attempts', ['id' => $attempt->id, 'transaction_id' => 'transaction-test', 'needs_review' => false]);
        $this->infiniteNotice($f, DB::table('payment_attempts')->first());
        $this->travel(31)->seconds();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'payment.confirmed')->count());
        $order = DB::table('orders')->first();
        $this->actingAs($this->owner($f['tenant']))->post('/orders/'.$order->id.'/status', ['status' => 'accepted'])->assertRedirect();
    }

    public function test_return_parameters_and_paid_webhook_alone_cannot_confirm_payment(): void
    {
        $f = $this->fixture();
        $attempt = $this->create($f);
        $this->get('/pr/'.$attempt->reference.'?'.http_build_query(['order_nsu' => $attempt->reference, 'slug' => 'invoice-test', 'transaction_nsu' => 'fake-transaction', 'paid' => 'true']))->assertRedirect();
        $this->assertDatabaseHas('orders', ['payment_status' => 'pending']);
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => false])]);
        $this->travel(31)->seconds();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['payment_status' => 'pending']);
        $order = DB::table('orders')->first();
        $this->actingAs($this->owner($f['tenant']));
        $this->post('/orders/'.$order->id.'/paid')->assertConflict();
        $this->post('/orders/'.$order->id.'/status', ['status' => 'accepted'])->assertConflict();
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('Consultar pagamento')->assertDontSee($f['key']);
    }

    public function test_timeout_never_creates_a_second_checkout(): void
    {
        $f = $this->fixture();
        $calls = 0;
        $this->fakeHttp(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('timeout');
        });
        $this->post('/cardapio/loja/review', $f['cart']);
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $attempt = DB::table('payment_attempts')->first();
        $this->assertSame('uncertain', $attempt->state);
        $public = DB::table('orders')->value('public_id');
        $this->post('/pedido/'.$public.'/pagar')->assertRedirect('/pedido/'.$public);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(1, $calls);
        $this->get('/pedido/'.$public)->assertOk()->assertSee('conferir no provedor')->assertDontSee('Ir para pagamento seguro');
    }

    public function test_wrong_amount_and_untrusted_redirect_never_release_a_sale(): void
    {
        $f = $this->fixture();
        $attempt = $this->create($f);
        $this->infiniteNotice($f, $attempt);
        $this->travel(31)->seconds();
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 100, 'paid_amount' => 100, 'capture_method' => 'pix'])]);
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['payment_status' => 'review']);
        $this->assertFalse(app(PaymentGateway::class)->validUrl('https://checkout.infinitepay.io.evil.test/pay', $attempt));
        $this->assertFalse(app(PaymentGateway::class)->validUrl('https://checkout.infinitepay.io@evil.test/pay', $attempt));
        $this->assertFalse(app(PaymentGateway::class)->validUrl('http://checkout.infinitepay.io/pay', $attempt));
        $this->assertTrue(app(PaymentGateway::class)->validUrl('https://buy.infinitepay.io/loja/invoice', $attempt));
    }

    public function test_pagbank_signed_webhook_is_verified_again_against_order_api(): void
    {
        $f = $this->fixture('pagbank');
        $attempt = $this->create($f, 'pagbank');
        $this->postJson('/wp/p', ['reference_id' => $attempt->reference, 'id' => 'ORDE_TEST-12345'])->assertUnauthorized();
        $this->signedNotice($attempt, $f['key']);
        $this->assertDatabaseHas('orders', ['payment_status' => 'pending']);
        $this->fakeHttp(['*/checkouts/*/inactivate' => Http::response([]), '*/orders/ORDE_TEST-12345' => Http::response(['id' => 'ORDE_TEST-12345', 'reference_id' => $attempt->reference, 'charges' => [['id' => 'CHAR_TEST-12345', 'status' => 'PAID', 'amount' => ['value' => 3000, 'currency' => 'BRL', 'summary' => ['paid' => 3000, 'refunded' => 0]]]]])]);
        $this->travel(31)->seconds();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['payment_status' => 'paid']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.pagseguro.com/orders/ORDE_TEST-12345' && $request->hasHeader('Authorization', 'Bearer '.$f['key']));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/checkouts/CHEC_TEST-12345/inactivate'));
    }

    public function test_pagbank_identity_currency_and_refunds_are_not_treated_as_success(): void
    {
        $f = $this->fixture('pagbank');
        $attempt = $this->create($f, 'pagbank');
        $this->signedNotice($attempt, $f['key']);
        $this->travel(31)->seconds();
        $data = ['id' => 'ORDE_TEST-12345', 'reference_id' => 'another-reference', 'charges' => [['id' => 'CHAR_TEST-12345', 'status' => 'PAID', 'amount' => ['value' => 3000, 'currency' => 'BRL', 'summary' => ['paid' => 3000, 'refunded' => 0]]]]];
        $this->fakeHttp(['*/orders/*' => Http::response($data)]);
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['payment_status' => 'review']);
        $data['reference_id'] = $attempt->reference;
        $data['charges'][0]['amount']['currency'] = 'USD';
        $this->fakeHttp(['*/orders/*' => Http::response($data)]);
        $this->travel(121)->seconds();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['payment_status' => 'review']);
        $data['charges'][0]['amount']['currency'] = 'BRL';
        $data['charges'][0]['amount']['summary']['refunded'] = 3000;
        $this->fakeHttp(['*/orders/*' => Http::response($data)]);
        $this->travel(121)->seconds();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['payment_status' => 'review']);
        $this->assertDatabaseHas('payment_attempts', ['needs_review' => true]);
    }

    public function test_late_payment_or_payment_for_cancelled_order_requires_manual_review(): void
    {
        $f = $this->fixture();
        $attempt = $this->create($f);
        $order = DB::table('orders')->first();
        $this->actingAs($this->owner($f['tenant']))->post('/orders/'.$order->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Teste'])->assertRedirect();
        $this->infiniteNotice($f, DB::table('payment_attempts')->first());
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 3000, 'paid_amount' => 3000, 'capture_method' => 'pix'])]);
        $this->travel(31)->minutes();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['status' => 'cancelled', 'payment_status' => 'review']);
        $this->assertDatabaseHas('payment_attempts', ['needs_review' => true]);
        $this->post('/pedido/'.$order->public_id.'/pagar')->assertRedirect('/pedido/'.$order->public_id);
    }

    public function test_credential_rotation_preserves_pending_attempt_and_webhook_authentication(): void
    {
        $f = $this->fixture('pagbank');
        $attempt = $this->create($f, 'pagbank');
        DB::table('store_payment_accounts')->where('id', $f['account'])->update(['credential' => Crypt::encryptString('new-fake-credential')]);
        $this->signedNotice($attempt, $f['key']);
        $this->assertSame($f['key'], Crypt::decryptString(DB::table('payment_attempts')->first()->credential));
    }

    public function test_account_configuration_is_encrypted_scoped_and_credentials_are_never_rendered(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $this->post('/stores/'.$store.'/payments/pagbank', ['enabled' => 1, 'environment' => 'sandbox', 'credential' => 'my-fake-secret-key'])->assertRedirect();
        $account = DB::table('store_payment_accounts')->first();
        $this->assertNotSame('my-fake-secret-key', $account->credential);
        $this->assertSame('my-fake-secret-key', Crypt::decryptString($account->credential));
        $this->get('/stores/'.$store.'/edit')->assertOk()->assertSee('Pagamento online')->assertDontSee('my-fake-secret-key');
        $this->assertStringNotContainsString('my-fake-secret-key', DB::table('audit_events')->value('details'));
        $this->actingAs($this->owner($this->tenant('other')))->post('/stores/'.$store.'/payments/pagbank', ['enabled' => 1])->assertNotFound();
    }

    public function test_changed_or_disabled_account_requires_new_review_and_cannot_be_forged(): void
    {
        $f = $this->fixture();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('store_payment_accounts')->where('id', $f['account'])->update(['handle' => 'outra-tag']);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        DB::table('store_payment_accounts')->where('id', $f['account'])->update(['enabled' => false]);
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_ecdsa_signature_validates_original_body_and_rejects_tampering(): void
    {
        $f = $this->fixture('pagbank');
        $attempt = $this->create($f, 'pagbank');
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        if (is_file(dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $options['config'] = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        }
        $private = openssl_pkey_new($options);
        $pem = openssl_pkey_get_details($private)['key'];
        $base64 = preg_replace('/-----[^-]+-----|\s/', '', $pem);
        $this->fakeHttp(['*/public-keys*' => Http::response(['public_key' => $base64])]);
        $raw = json_encode(['id' => 'ORDE_TEST-12345', 'reference_id' => $attempt->reference], JSON_THROW_ON_ERROR);
        openssl_sign($raw, $signature, $private, OPENSSL_ALGO_SHA256);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYLOAD_SIGNATURE' => base64_encode($signature)];
        $this->call('POST', '/wp/p', [], [], [], $headers, $raw.' ')->assertUnauthorized();
        $this->call('POST', '/wp/p', [], [], [], $headers, $raw)->assertOk();
    }

    public function test_sandbox_checkout_is_not_available_in_production_storefront(): void
    {
        $f = $this->fixture('pagbank');
        DB::table('store_payment_accounts')->where('id', $f['account'])->update(['environment' => 'sandbox']);
        $this->app->instance('env', 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->get('/cardapio/loja')->assertOk()->assertDontSee('value="online_pagbank"', false);
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_expired_checkout_is_not_recreated_and_late_payment_is_held_for_review(): void
    {
        $f = $this->fixture();
        $attempt = $this->create($f);
        $this->travel(31)->minutes();
        $public = DB::table('orders')->value('public_id');
        $this->post('/pedido/'.$public.'/pagar')->assertRedirect('/pedido/'.$public);
        Http::assertSentCount(1);
        $this->infiniteNotice($f, $attempt);
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 3000, 'paid_amount' => 3000, 'capture_method' => 'pix'])]);
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['status' => 'pending', 'payment_status' => 'review']);
    }

    public function test_one_provider_transaction_cannot_pay_two_orders(): void
    {
        $f = $this->fixture();
        $first = $this->create($f);
        $this->infiniteNotice($f, $first);
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 3000, 'paid_amount' => 3000, 'capture_method' => 'pix'])]);
        $this->travel(31)->seconds();
        app(OnlinePayment::class)->sync($first->id);
        $f['cart']['idempotency_key'] = (string) Str::uuid();
        $second = $this->create($f);
        $this->infiniteNotice($f, $second);
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 3000, 'paid_amount' => 3000, 'capture_method' => 'pix'])]);
        $this->travel(31)->seconds();
        app(OnlinePayment::class)->sync($second->id);
        $this->assertDatabaseHas('orders', ['id' => $first->order_id, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $second->order_id, 'payment_status' => 'review']);
        $this->assertSame(1, DB::table('payment_attempts')->whereNotNull('transaction_id')->count());
    }

    public function test_scheduled_checkout_expires_before_preparation_and_requires_time_to_pay(): void
    {
        $f = $this->fixture();
        DB::table('stores')->where('id', $f['store'])->update(['scheduling_enabled' => true, 'scheduling_lead_minutes' => 60, 'scheduling_prep_minutes' => 60]);
        $f['cart']['scheduled_for'] = '2026-10-27T13:00';
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        DB::table('stores')->where('id', $f['store'])->update(['scheduling_prep_minutes' => 45]);
        $attempt = $this->create($f);
        $this->assertSame('2026-10-27 15:15:00', $attempt->expires_at);
    }

    public function test_conflicting_authenticated_transaction_after_payment_requires_review(): void
    {
        $f = $this->fixture();
        $attempt = $this->create($f);
        $this->infiniteNotice($f, $attempt);
        $this->fakeHttp(['*/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 3000, 'paid_amount' => 3000, 'capture_method' => 'pix'])]);
        $this->travel(31)->seconds();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->infiniteNotice($f, DB::table('payment_attempts')->first(), 'different-transaction');
        $this->travel(31)->seconds();
        app(OnlinePayment::class)->sync($attempt->id);
        $this->assertDatabaseHas('orders', ['payment_status' => 'review']);
        $this->assertDatabaseHas('payment_attempts', ['transaction_id' => 'transaction-test', 'needs_review' => true]);
    }

    public function test_infinitepay_configuration_only_requires_tag_and_discards_obsolete_key(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $this->post('/stores/'.$store.'/payments/infinitepay', ['enabled' => 1, 'handle' => 'minha-tag'])->assertRedirect();
        $account = DB::table('store_payment_accounts')->first();
        $this->assertSame('minha-tag', $account->handle);
        $this->assertSame('', Crypt::decryptString($account->credential));
        DB::table('store_payment_accounts')->where('id', $account->id)->update(['credential' => Crypt::encryptString('obsolete-key')]);
        $this->post('/stores/'.$store.'/payments/infinitepay', ['enabled' => 1, 'handle' => 'minha-tag'])->assertRedirect();
        $this->assertSame('', Crypt::decryptString(DB::table('store_payment_accounts')->first()->credential));
        $this->get('/stores/'.$store.'/edit')->assertOk()->assertSee('InfiniteTag')->assertDontSee('API Key')->assertDontSee('Sandbox')->assertDontSee('homologação')->assertDontSee('name="environment"', false)->assertSee('Vendas → Integrações → Gerar Token')->assertDontSee('obsolete-key');
        Http::assertNothingSent();
    }

    public function test_existing_infinitepay_attempt_ignores_stored_key_and_uses_external_checkout(): void
    {
        $f = $this->fixture();
        DB::table('store_payment_accounts')->where('id', $f['account'])->update(['credential' => 'obsolete-non-decryptable-value']);
        $attempt = $this->create($f);
        $this->assertSame('waiting', $attempt->state);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/links') && $request['handle'] === 'loja-teste' && ! $request->hasHeader('X-Api-Key') && ! $request->hasHeader('Authorization'));
    }

    public function test_pagbank_configuration_uses_production_without_environment_selector(): void
    {
        $f = $this->fixture('pagbank');
        DB::table('store_payment_accounts')->where('id', $f['account'])->update(['environment' => 'sandbox']);
        $this->actingAs($this->owner($f['tenant']));
        $this->postJson('/stores/'.$f['store'].'/payments/pagbank', ['enabled' => 1])->assertUnprocessable();
        $this->post('/stores/'.$f['store'].'/payments/pagbank', ['enabled' => 0])->assertRedirect();
        $this->assertDatabaseHas('store_payment_accounts', ['id' => $f['account'], 'environment' => 'sandbox', 'enabled' => false]);
        $this->post('/stores/'.$f['store'].'/payments/pagbank', ['enabled' => 1, 'environment' => 'sandbox', 'credential' => 'production-fake-token'])->assertRedirect();
        $this->assertDatabaseHas('store_payment_accounts', ['id' => $f['account'], 'environment' => 'production', 'enabled' => true]);
        $attempt = $this->create($f, 'pagbank');
        $this->assertSame('production', $attempt->environment);
        $this->assertSame('waiting', $attempt->state);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.pagseguro.com/checkouts' && $request->hasHeader('Authorization', 'Bearer production-fake-token'));
    }
}
