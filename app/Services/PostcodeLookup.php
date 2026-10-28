<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PostcodeLookup
{
    public function find(string $postcode): array
    {
        $postcode = str_replace('-', '', $postcode);
        $address = Cache::remember('postcode:br:'.$postcode, now()->addDays(7), function () use ($postcode): array {
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(6)->withoutRedirecting()->get('https://viacep.com.br/ws/'.$postcode.'/json/');
            } catch (ConnectionException) {
                throw ValidationException::withMessages(['postcode' => 'A busca de CEP está indisponível. Tente novamente ou preencha o endereço manualmente.']);
            }
            if (! $response->successful() || ! is_array($response->json())) {
                throw ValidationException::withMessages(['postcode' => 'Não foi possível consultar o CEP. Tente novamente ou preencha o endereço manualmente.']);
            }
            $body = $response->json();
            if (! empty($body['erro'])) {
                throw ValidationException::withMessages(['postcode' => 'CEP não encontrado. Confira os números ou preencha o endereço manualmente.']);
            }
            if (! is_string($body['cep'] ?? null) || ! is_string($body['localidade'] ?? null) || ! is_string($body['uf'] ?? null)
                || ! is_string($body['logradouro'] ?? '') || ! is_string($body['bairro'] ?? '')
                || preg_replace('/\D/', '', $body['cep']) !== $postcode || $body['localidade'] === '' || ! preg_match('/^[A-Z]{2}$/', $body['uf'])) {
                throw ValidationException::withMessages(['postcode' => 'O serviço retornou um endereço incompleto. Tente novamente ou preencha manualmente.']);
            }

            return ['street' => mb_substr((string) ($body['logradouro'] ?? ''), 0, 120), 'neighborhood' => mb_substr((string) ($body['bairro'] ?? ''), 0, 100), 'city' => mb_substr((string) $body['localidade'], 0, 100), 'state' => $body['uf']];
        });

        return $address;
    }
}
