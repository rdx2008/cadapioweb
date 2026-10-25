<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class DeliveryCoverage
{
    public function search(string $address): array
    {
        if (! config('services.geoapify.key')) {
            $this->fail('A consulta de endereços ainda não foi configurada pela plataforma.');
        }
        $key = 'geocode:'.hash('sha256', $this->normalize($address));
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return json_decode(Crypt::decryptString($cached), true, flags: JSON_THROW_ON_ERROR);
        }
        $budget = 'geocode:budget:'.now()->utc()->format('Y-m-d');
        if (! RateLimiter::attempt($budget, max(1, (int) config('services.geoapify.daily_limit')), fn () => true, 86400)) {
            $this->fail('O limite diário de consultas foi atingido. Tente novamente mais tarde.');
        }
        if (! RateLimiter::attempt('geocode:second', 4, fn () => true, 1)) {
            $this->fail('A consulta está ocupada. Aguarde um instante e tente novamente.');
        }
        try {
            $response = Http::acceptJson()->connectTimeout(4)->timeout(8)->withoutRedirecting()
                ->get('https://api.geoapify.com/v1/geocode/search', [
                    'text' => $address, 'filter' => 'countrycode:br', 'lang' => 'pt',
                    'limit' => 3, 'format' => 'json', 'apiKey' => config('services.geoapify.key'),
                ]);
        } catch (ConnectionException) {
            $this->fail('Não foi possível consultar o endereço. Tente novamente.');
        }
        if (! $response->successful()) {
            $this->fail('A consulta de endereços está indisponível. Tente novamente mais tarde.');
        }
        $results = [];
        foreach (array_slice((array) $response->json('results', []), 0, 3) as $place) {
            if (! is_array($place) || ($place['country_code'] ?? '') !== 'br'
                || empty($place['housenumber']) || empty($place['street'])
                || ! in_array($place['result_type'] ?? '', ['building', 'amenity'], true)
                || ($place['rank']['confidence'] ?? 0) < 0.8
                || ! is_numeric($place['lat'] ?? null) || ! is_numeric($place['lon'] ?? null)
                || abs((float) $place['lat']) > 90 || abs((float) $place['lon']) > 180) {
                continue;
            }
            $results[] = ['label' => mb_substr((string) ($place['formatted'] ?? ''), 0, 500), 'latitude' => (float) $place['lat'], 'longitude' => (float) $place['lon']];
        }
        if ($results === []) {
            $this->fail('Não localizamos o número com precisão. Informe rua, número, bairro, cidade e estado.');
        }
        Cache::put($key, Crypt::encryptString(json_encode($results, JSON_THROW_ON_ERROR)), now()->addDay());

        return $results;
    }

    public function choices(object $store, string $address): array
    {
        $choices = [];
        foreach ($this->search($address) as $place) {
            $zone = $this->zone($store, $place['latitude'], $place['longitude']);
            $choices[] = ['label' => $place['label'], 'available' => $zone !== null,
                'fee_cents' => $zone ? (int) $zone->fee_cents : null,
                'token' => $zone ? Crypt::encryptString(json_encode($place + ['purpose' => 'delivery', 'store_id' => $store->id, 'address_hash' => hash('sha256', $this->normalize($address)), 'expires' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR)) : null];
        }

        return $choices;
    }

    public function verify(object $store, string $address, ?string $token): object
    {
        try {
            $place = json_decode(Crypt::decryptString($token ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $this->fail('Consulte e confirme o endereço para calcular o frete.');
        }
        if (! is_array($place) || ($place['purpose'] ?? null) !== 'delivery' || ($place['store_id'] ?? null) !== $store->id
            || ($place['expires'] ?? 0) < now()->timestamp
            || ! hash_equals($place['address_hash'] ?? '', hash('sha256', $this->normalize($address)))) {
            $this->fail('O endereço mudou ou a consulta expirou. Consulte o frete novamente.');
        }
        $zone = $this->zone($store, $place['latitude'], $place['longitude']);
        if (! $zone) {
            $this->fail('Este endereço está fora da área de entrega da loja.');
        }
        $zone->resolved_address = $place['label'];

        return $zone;
    }

    private function zone(object $store, float $latitude, float $longitude): ?object
    {
        $lat = deg2rad($latitude - (float) $store->latitude);
        $lon = deg2rad($longitude - (float) $store->longitude);
        $a = sin($lat / 2) ** 2 + cos(deg2rad((float) $store->latitude)) * cos(deg2rad($latitude)) * sin($lon / 2) ** 2;
        $meters = 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));

        return DB::table('delivery_zones')->where('store_id', $store->id)->where('enabled', true)
            ->where('radius_meters', '>=', $meters)->orderBy('radius_meters')->orderBy('id')->first();
    }

    private function normalize(string $address): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($address)));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['address' => $message]);
    }
}
