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
        return $this->request($app, 'GET', $path);
    }

    public function poll(object $app, array $merchants): array
    {
        if ($merchants === [] || count($merchants) > 100 || collect($merchants)->contains(fn ($id) => ! is_string($id) || ! Str::isUuid($id))) {
            $this->fail('Selecione de 1 a 100 lojas válidas para o recebimento de teste.');
        }
        $response = $this->request($app, 'GET', '/events/v1.0/events:polling', ['categories' => 'FOOD'], ['x-polling-merchants' => implode(',', $merchants)]);
        if ($response->status() === 204) {
            return [];
        }
        $events = $response->json();
        if (! is_array($events) || ! array_is_list($events) || count($events) > 2000) {
            $this->fail('O iFood retornou uma lista de eventos inválida.');
        }

        return $events;
    }

    public function acknowledge(object $app, array $ids): void
    {
        if ($ids === [] || count($ids) > 2000 || collect($ids)->contains(fn ($id) => ! is_string($id) || ! Str::isUuid($id))) {
            $this->fail('Lista de confirmação de eventos inválida.');
        }
        $response = $this->request($app, 'POST', '/events/v1.0/events/acknowledgment', array_map(fn ($id) => ['id' => $id], array_values(array_unique($ids))));
        if ($response->status() !== 202) {
            $this->fail('O iFood não confirmou o recebimento da leitura dos eventos.');
        }
    }

    public function order(object $app, string $id): array
    {
        if (! Str::isUuid($id)) {
            $this->fail('Identificador de pedido iFood inválido.');
        }
        $order = $this->read($app, '/order/v1.0/orders/'.$id)->json();
        if (! is_array($order) || array_is_list($order)) {
            $this->fail('O iFood retornou detalhes de pedido inválidos.');
        }

        return $order;
    }

    public function cancellationReasons(object $app, string $id): array
    {
        if (! Str::isUuid($id)) {
            $this->fail('Identificador de pedido inválido.');
        }
        $payload = $this->read($app, '/order/v1.0/orders/'.$id.'/cancellationReasons')->json();
        $reasons = is_array($payload) ? ($payload['reasons'] ?? $payload) : null;
        if (! is_array($reasons) || ! array_is_list($reasons) || count($reasons) > 100) {
            $this->fail('O iFood retornou uma lista de motivos inválida.');
        }
        $result = [];
        foreach ($reasons as $reason) {
            if (! is_array($reason) || ! is_scalar($reason['code'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,40}$/D', (string) $reason['code']) || ! is_string($reason['description'] ?? null) || mb_strlen($reason['description']) > 500) {
                $this->fail('O iFood retornou um motivo de cancelamento inválido.');
            }
            $result[(string) $reason['code']] = $reason['description'];
        }

        return $result;
    }

    public function execute(object $app, string $id, string $action, array $payload): array
    {
        $endpoints = ['confirm' => 'confirm', 'prepare' => 'startPreparation', 'ready' => 'readyToPickup', 'dispatch' => 'dispatch', 'cancel' => 'requestCancellation'];
        if (! Str::isUuid($id) || ! isset($endpoints[$action])) {
            $this->fail('Ação de pedido inválida.');
        }
        try {
            $token = $this->token($app);
        } catch (ValidationException) {
            return ['status' => 'retry', 'message' => 'Autenticação indisponível antes do envio. Nova tentativa será feita.', 'retry_after' => 30];
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(10)->withoutRedirecting()
                    ->send('POST', self::BASE.'/order/v1.0/orders/'.$id.'/'.$endpoints[$action], $payload === [] ? ['body' => '{}'] : ['json' => $payload]);
            } catch (ConnectionException) {
                return ['status' => 'uncertain', 'message' => 'Conexão interrompida durante o envio. Aguarde o evento ou confira no iFood antes de tentar outra ação.'];
            }
            if ($response->status() === 401 && $attempt === 0) {
                Cache::forget($this->tokenKey($app));
                try {
                    $token = $this->token($app);
                } catch (ValidationException) {
                    return ['status' => 'rejected', 'message' => 'Autenticação recusada. Confira as credenciais antes de solicitar novamente.'];
                }

                continue;
            }
            if ($response->status() === 202) {
                return ['status' => 'awaiting_event', 'message' => null];
            }
            if ($response->status() === 429) {
                $delay = $response->header('Retry-After') ?? $response->header('X-RateLimit-Reset');

                return ['status' => 'retry', 'message' => 'Limite de envios atingido. A solicitação aguarda nova tentativa.', 'retry_after' => is_numeric($delay) ? max(30, min(3600, (int) $delay)) : 60];
            }
            if (in_array($response->status(), [400, 401, 403, 404, 422], true)) {
                return ['status' => 'rejected', 'message' => 'O iFood recusou a solicitação (HTTP '.$response->status().'). Revise o pedido e a autorização da loja.'];
            }

            return ['status' => 'uncertain', 'message' => 'Resposta sem confirmação do resultado. O comando não será reenviado automaticamente; confira o retorno do iFood.'];
        }

        return ['status' => 'rejected', 'message' => 'Autenticação recusada pelo iFood.'];
    }

    private function request(object $app, string $method, string $path, array $data = [], array $headers = []): Response
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $request = Http::withToken($this->token($app))->acceptJson()->withHeaders($headers)->connectTimeout(5)->timeout(10)->withoutRedirecting();
                $response = $method === 'GET' ? $request->get(self::BASE.$path, $data) : $request->post(self::BASE.$path, $data);
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
