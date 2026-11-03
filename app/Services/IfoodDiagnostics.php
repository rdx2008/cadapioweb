<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class IfoodDiagnostics
{
    private const BODY_LIMIT = 65536;

    public function __construct(private IfoodClient $client) {}

    public function collect(object $app, string $merchantId): array
    {
        $secrets = [Crypt::decryptString($app->client_secret)];
        $records = [];
        foreach ([false, true] as $status) {
            $record = ['at_utc' => now()->utc()->toIso8601String(), 'request' => ['method' => 'GET', 'url' => null, 'headers' => []], 'response' => null];
            try {
                $response = $this->client->merchantProbe($app, $merchantId, $status, function (Request $request) use (&$record, &$secrets): void {
                    $authorization = $request->header('Authorization')[0] ?? '';
                    if (str_starts_with($authorization, 'Bearer ')) {
                        $secrets[] = substr($authorization, 7);
                    }
                    $record['request'] = ['method' => $request->method(), 'url' => $request->url(), 'headers' => $this->headers($request->headers(), $secrets)];
                    $record['curl'] = $this->curl($record['request']);
                });
                $body = $response->body();
                $clean = $this->redact($body, $secrets);
                $record['response'] = [
                    'status' => $response->status(),
                    'headers' => $this->headers($response->headers(), $secrets),
                    'body' => mb_convert_encoding(mb_strcut($clean, 0, self::BODY_LIMIT, 'UTF-8'), 'UTF-8', 'UTF-8'),
                    'body_bytes_original' => strlen($body),
                    'body_truncated' => strlen($clean) > self::BODY_LIMIT,
                ];
                $stats = $response->handlerStats();
                $record['transport'] = array_intersect_key($stats, array_flip(['primary_ip', 'primary_port', 'http_version', 'total_time', 'ssl_verify_result']));
            } catch (ConnectionException) {
                $record['error'] = 'Falha de transporte: nenhuma resposta HTTP recebida. O servidor pode ter atingido o timeout de conexão/leitura.';
            } catch (ValidationException $exception) {
                $record['error'] = $this->redact($exception->errors()['ifood'][0] ?? 'Falha ao autenticar o aplicativo.', $secrets);
            }
            $records[] = $record;
        }

        return [
            'schema_version' => 1, 'generated_at_utc' => now()->utc()->toIso8601String(),
            'environment' => 'test', 'client_id' => $app->client_id, 'merchant_id' => strtolower($merchantId),
            'notes' => [
                'Two independent GET requests, one per endpoint, using the same HTTP settings as the app. No redirects or automatic HTTP retries in this diagnostic.',
                'Authorization, cookies and known credentials are redacted. Set IFOOD_ACCESS_TOKEN privately to replay the cURL. No token is included in this file.',
                'Bodies are bounded at 65536 bytes. Check body_truncated. An HTTP 403 alone does not identify whether the refusal came from authorization or an edge block.',
                'No polling, acknowledgment, store binding or order action is executed.',
            ],
            'requests' => $records,
        ];
    }

    private function headers(array $headers, array $secrets): array
    {
        $result = [];
        foreach ($headers as $name => $values) {
            $sensitive = preg_match('/authorization|cookie|token|secret|api[-_]?key/i', $name);
            $result[$name] = $sensitive ? ['[REDACTED]'] : array_map(fn ($value) => $this->redact((string) $value, $secrets), (array) $values);
        }

        return $result;
    }

    private function redact(string $value, array $secrets): string
    {
        $secrets = array_values(array_unique(array_filter($secrets, fn ($secret) => is_string($secret) && $secret !== '')));
        usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));
        $value = str_replace($secrets, '[REDACTED]', $value);
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value);

        return preg_replace('/("(?:access_?token|refresh_?token|client_?secret|password)"\s*:\s*")[^"]*(")/i', '$1[REDACTED]$2', $value);
    }

    private function curl(array $request): string
    {
        $parts = ['curl --request GET --connect-timeout 5 --max-time 10 --url '.$this->quote($request['url'])];
        foreach ($request['headers'] as $name => $values) {
            if (strtolower($name) === 'authorization') {
                $parts[] = '--header "Authorization: Bearer ${IFOOD_ACCESS_TOKEN}"';
            } elseif ($values !== ['[REDACTED]']) {
                foreach ($values as $value) {
                    $parts[] = '--header '.$this->quote($name.': '.$value);
                }
            }
        }

        return implode(" \\\n  ", $parts);
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
