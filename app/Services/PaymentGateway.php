<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymentGateway
{
    private function base(object $attempt): string
    {
        return $attempt->provider === 'infinitepay' ? 'https://api.checkout.infinitepay.io' : ($attempt->environment === 'sandbox' ? 'https://sandbox.api.pagseguro.com' : 'https://api.pagseguro.com');
    }

    private function request(object $attempt): PendingRequest
    {
        $http = Http::acceptJson()->asJson()->connectTimeout(4)->timeout(12)->withoutRedirecting();
        if ($attempt->provider === 'infinitepay') {
            return $http;
        }

        return $http->withToken(Crypt::decryptString($attempt->credential));
    }

    private function body(Response $response): array
    {
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Não foi possível confirmar a resposta do provedor.');
        }

        return $response->json();
    }

    public function validUrl(string $url, object $attempt): bool
    {
        $parts = parse_url($url);
        $hosts = $attempt->provider === 'infinitepay' ? ['checkout.infinitepay.io', 'buy.infinitepay.io'] : ($attempt->environment === 'sandbox' ? ['sandbox.pagamento.pagseguro.uol.com.br', 'sandbox.pagamento.pagbank.com.br'] : ['pagamento.pagbank.com.br', 'pagamento.pagseguro.uol.com.br']);

        return strlen($url) <= 2000 && ($parts['scheme'] ?? '') === 'https' && in_array($parts['host'] ?? '', $hosts, true) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    public function create(object $attempt, object $order, object $account): array
    {
        $return = rtrim(config('app.url'), '/').'/pr/'.$attempt->reference;
        if (parse_url($return, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Configure o endereço público HTTPS antes de criar cobranças.');
        }
        if ($attempt->provider === 'infinitepay') {
            $payload = ['handle' => $attempt->handle, 'order_nsu' => $attempt->reference, 'redirect_url' => $return, 'items' => [['quantity' => 1, 'price' => $attempt->amount_cents, 'description' => 'Pedido #'.$order->id]], 'webhook_url' => rtrim(config('app.url'), '/').'/wp/i/'.$account->webhook_secret];
            $data = $this->body($this->request($attempt)->post($this->base($attempt).'/links', $payload));
            $url = $data['url'] ?? $data['link'] ?? $data['checkout_url'] ?? $data['checkouturl'] ?? null;
            $external = $data['slug'] ?? null;
        } else {
            $callback = rtrim(config('app.url'), '/').'/wp/p';
            if (strlen($callback) > 100) {
                throw new RuntimeException('A URL de notificação do PagBank excede 100 caracteres.');
            }
            $payload = ['reference_id' => $attempt->reference, 'expiration_date' => Carbon::parse($attempt->expires_at, 'UTC')->toIso8601String(), 'customer_modifiable' => true, 'items' => [['reference_id' => $attempt->reference, 'name' => 'Pedido #'.$order->id, 'quantity' => 1, 'unit_amount' => $attempt->amount_cents]], 'payment_methods' => [['type' => 'CREDIT_CARD'], ['type' => 'PIX']], 'payment_methods_configs' => [['type' => 'CREDIT_CARD', 'config_options' => [['option' => 'INSTALLMENTS_LIMIT', 'value' => '1']]]], 'redirect_url' => $return, 'return_url' => $return, 'notification_urls' => [$callback], 'payment_notification_urls' => [$callback]];
            $data = $this->body($this->request($attempt)->post($this->base($attempt).'/checkouts', $payload));
            $external = $data['id'] ?? null;
            if (! is_string($external) || ! preg_match('/^CHEC_[A-Za-z0-9-]{5,100}$/D', $external) || ($data['reference_id'] ?? null) !== $attempt->reference) {
                throw new RuntimeException('Identidade do checkout não confirmada.');
            }
            $url = collect($data['links'] ?? [])->firstWhere('rel', 'PAY')['href'] ?? null;
        }
        if (! is_string($url) || ! $this->validUrl($url, $attempt) || ($external !== null && (! is_string($external) || ! preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $external)))) {
            throw new RuntimeException('Link de pagamento inválido.');
        }

        return ['checkout_url' => $url, 'external_id' => $external];
    }

    public function check(object $attempt): array
    {
        $hints = $attempt->hints ? json_decode(Crypt::decryptString($attempt->hints), true, flags: JSON_THROW_ON_ERROR) : [];
        if ($attempt->provider === 'infinitepay') {
            if (empty($hints['transaction_nsu']) || empty($hints['slug'])) {
                return ['paid' => false];
            }
            $data = $this->body($this->request($attempt)->post($this->base($attempt).'/payment_check', ['handle' => $attempt->handle, 'order_nsu' => $attempt->reference, 'transaction_nsu' => $hints['transaction_nsu'], 'slug' => $hints['slug']]));
            if (($data['success'] ?? false) !== true) {
                throw new RuntimeException('Consulta não confirmada.');
            }
            $paid = ($data['paid'] ?? false) === true;
            if ($paid && (($data['amount'] ?? null) !== $attempt->amount_cents || ! is_int($data['paid_amount'] ?? null) || $data['paid_amount'] < $attempt->amount_cents || ! in_array($data['capture_method'] ?? '', ['pix', 'credit_card'], true))) {
                throw new \DomainException('Valor ou moeda do pagamento não confirmado.');
            }

            return ['paid' => $paid, 'transaction_id' => $hints['transaction_nsu'], 'review' => (bool) ($hints['adverse'] ?? false)];
        }
        if (empty($hints['order_id'])) {
            return ['paid' => false];
        }
        $data = $this->body($this->request($attempt)->get($this->base($attempt).'/orders/'.$hints['order_id']));
        if (($data['id'] ?? null) !== $hints['order_id'] || ($data['reference_id'] ?? null) !== $attempt->reference) {
            throw new \DomainException('Pedido do provedor não corresponde à venda.');
        }
        $paid = collect($data['charges'] ?? [])->filter(fn ($charge) => ($charge['status'] ?? '') === 'PAID')->values();
        if ($paid->count() !== 1) {
            return ['paid' => false, 'review' => $paid->count() > 1];
        }
        $charge = $paid->first();
        $amount = $charge['amount'] ?? [];
        if (($amount['value'] ?? null) !== $attempt->amount_cents || ($amount['currency'] ?? '') !== 'BRL' || ($amount['summary']['paid'] ?? null) !== $attempt->amount_cents || ! is_string($charge['id'] ?? null) || ! preg_match('/^CHAR_[A-Za-z0-9-]{5,100}$/D', $charge['id'])) {
            throw new \DomainException('Valor ou moeda do pagamento não confirmado.');
        }

        return ['paid' => true, 'transaction_id' => $charge['id'], 'review' => ($amount['summary']['refunded'] ?? 0) > 0];
    }

    public function authenticPagbank(object $attempt, string $raw, array $headers): bool
    {
        $signature = $headers['x-payload-signature'] ?? '';
        if ($signature !== '') {
            try {
                $key = Cache::remember('pagbank.webhook-key:'.hash('sha256', $attempt->credential), 3600, function () use ($attempt) {
                    $data = $this->body($this->request($attempt)->get($this->base($attempt).'/public-keys', ['type' => 'webhook']));

                    return $data['public_key'] ?? '';
                });
                if (! is_string($key) || strlen($key) > 4096 || base64_decode($key, true) === false) {
                    return false;
                }
                $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n")."-----END PUBLIC KEY-----\n";
                foreach (explode(',', $signature) as $value) {
                    $decoded = base64_decode(trim($value), true);
                    if ($decoded !== false && openssl_verify($raw, $decoded, $pem, OPENSSL_ALGO_SHA256) === 1) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                return false;
            }

            return false;
        }
        $legacy = $headers['x-authenticity-token'] ?? '';

        return preg_match('/^[a-f0-9]{64}$/Di', $legacy) === 1 && hash_equals(hash('sha256', Crypt::decryptString($attempt->credential).'-'.$raw), strtolower($legacy));
    }

    public function closeCheckout(object $attempt): bool
    {
        if ($attempt->provider !== 'pagbank' || ! is_string($attempt->external_id) || ! preg_match('/^CHEC_[A-Za-z0-9-]{5,100}$/D', $attempt->external_id)) {
            return true;
        }
        try {
            $response = $this->request($attempt)->post($this->base($attempt).'/checkouts/'.$attempt->external_id.'/inactivate', []);
            if ($response->successful()) {
                return true;
            }
            $data = $this->body($this->request($attempt)->get($this->base($attempt).'/checkouts/'.$attempt->external_id));

            return ($data['id'] ?? null) === $attempt->external_id && ($data['status'] ?? null) === 'INACTIVE';
        } catch (\Throwable) {
            return false;
        }
    }
}
