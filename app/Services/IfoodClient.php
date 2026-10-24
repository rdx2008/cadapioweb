<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IfoodClient
{
    private const BASE = 'https://merchant-api.ifood.com.br';

    public function token(object $app): string
    {
        if ($app->environment !== 'test') {
            $this->fail('Produção iFood ainda não está liberada nesta versão.');
        }
        $key = $this->tokenKey($app);
        try {
            return Cache::lock($key.':lock', 30)->block(3, function () use ($app, $key): string {
                $cached = Cache::get($key);
                if (is_string($cached)) {
                    return Crypt::decryptString($cached);
                }
                try {
                    $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(10)->withoutRedirecting()
                        ->post(self::BASE.'/authentication/v1.0/oauth/token', [
                            'grantType' => 'client_credentials', 'clientId' => $app->client_id,
                            'clientSecret' => Crypt::decryptString($app->client_secret),
                        ]);
                } catch (ConnectionException) {
                    $this->fail('Não foi possível conectar ao iFood. Tente novamente.');
                }
                $this->check($response);
                $token = $response->json('accessToken');
                $expires = $response->json('expiresIn');
                if (! is_string($token) || $token === '' || strlen($token) > 8000 || preg_match('/[\x00-\x20\x7f]/', $token) || ! is_numeric($expires) || (int) $expires < 1) {
                    $this->fail('O iFood retornou uma autenticação inválida.');
                }
                if ((int) $expires > 60) {
                    Cache::put($key, Crypt::encryptString($token), min((int) $expires - 60, 21600));
                }

                return $token;
            });
        } catch (LockTimeoutException) {
            $this->fail('Autenticação iFood em andamento. Tente novamente em alguns segundos.');
        }
    }

    public function inspect(object $app, string $merchantId): array
    {
        if (! Str::isUuid($merchantId)) {
            $this->fail('Merchant ID inválido.');
        }
        $merchant = $this->read($app, '/merchant/v1.0/merchants/'.$merchantId)->json();
        if (! is_array($merchant) || ! is_string($merchant['id'] ?? null) || strtolower($merchant['id']) !== strtolower($merchantId) || ! is_string($merchant['name'] ?? null) || mb_strlen($merchant['name']) > 255 || trim($merchant['name']) === '') {
            $this->fail('O iFood retornou dados de loja inválidos.');
        }
        $statuses = $this->read($app, '/merchant/v1.0/merchants/'.$merchantId.'/status')->json();
        if (! is_array($statuses) || ! array_is_list($statuses) || $statuses === []) {
            $this->fail('O iFood não retornou a situação das operações da loja.');
        }
        $operations = [];
        foreach ($statuses as $status) {
            if (! is_array($status) || ! in_array($status['state'] ?? null, ['OK', 'WARNING', 'CLOSED', 'ERROR'], true) || ! is_bool($status['available'] ?? null) || ! is_string($status['operation'] ?? null) || ! is_string($status['salesChannel'] ?? null)) {
                $this->fail('O iFood retornou uma situação de loja inválida.');
            }
            $operations[] = [
                'operation' => mb_substr((string) ($status['operation'] ?? ''), 0, 80),
                'channel' => mb_substr((string) ($status['salesChannel'] ?? ''), 0, 80),
                'state' => $status['state'], 'available' => $status['available'],
            ];
        }

        return ['merchant_name' => $merchant['name'], 'operations' => $operations];
    }

    private function read(object $app, string $path): Response
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withToken($this->token($app))->acceptJson()->connectTimeout(5)->timeout(10)->withoutRedirecting()->get(self::BASE.$path);
            } catch (ConnectionException) {
                $this->fail('Não foi possível conectar ao iFood. Tente novamente.');
            }
            if ($response->status() === 401 && $attempt === 0) {
                Cache::forget($this->tokenKey($app));

                continue;
            }
            $this->check($response);

            return $response;
        }
        $this->fail('Autenticação iFood expirada. Verifique as credenciais.');
    }

    private function tokenKey(object $app): string
    {
        return 'ifood:token:'.$app->id.':'.$app->revision;
    }

    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $this->fail(match ($response->status()) {
            400, 401 => 'Credenciais ou solicitação iFood inválidas. Revise a configuração do aplicativo.',
            403 => 'Aplicativo sem autorização para esta loja no iFood.',
            404 => 'Loja não encontrada no iFood. Confira o Merchant ID.',
            429 => 'Limite de consultas iFood atingido. Aguarde antes de tentar novamente.',
            default => 'iFood indisponível ou resposta inesperada. Tente novamente mais tarde.',
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ifood' => $message]);
    }
}
