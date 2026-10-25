<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeliveryCoverage
{
    public function search(string $address): array
    {
        if (! config('services.geoapify.key')) {
            $this->fail('A consulta de endereços ainda não foi configurada pela plataforma.');
        }
        $key = 'geocode:v2:'.hash('sha256', $this->normalize($address));
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
            $results[] = ['label' => mb_substr((string) ($place['formatted'] ?? ''), 0, 500), 'latitude' => (float) $place['lat'], 'longitude' => (float) $place['lon'],
                'neighborhood' => mb_substr((string) ($place['suburb'] ?? $place['quarter'] ?? $place['district'] ?? ''), 0, 120),
                'city' => mb_substr((string) ($place['city'] ?? $place['town'] ?? $place['village'] ?? ''), 0, 120),
                'state_code' => strtoupper(substr((string) ($place['state_code'] ?? ''), -2))];
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
            $zone = $this->zone($store, $place);
            $choices[] = ['label' => $place['label'], 'available' => $zone !== null,
                'message' => $this->mode($store) === 'neighborhood' && (empty($place['neighborhood']) || empty($place['city']) || empty($place['state_code']))
                    ? 'Não identificamos o bairro e a cidade com segurança. Confira o endereço completo.' : 'Fora da área de entrega',
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
        $zone = $this->zone($store, $place);
        if (! $zone) {
            $this->fail('Este endereço está fora da área de entrega da loja.');
        }
        $zone->resolved_address = $place['label'];

        return $zone;
    }

    public function mode(object $store): string
    {
        return $store->delivery_mode ?? ($store->delivery_by_radius ? 'radius' : 'neighborhood');
    }

    private function zone(object $store, array $place): ?object
    {
        if ($this->mode($store) === 'neighborhood') {
            if (empty($place['neighborhood']) || empty($place['city']) || empty($place['state_code'])) {
                return null;
            }

            $matches = DB::table('delivery_zones')->where('store_id', $store->id)->where('enabled', true)->whereNull('radius_meters')->orderBy('id')->get()
                ->filter(fn ($zone) => $this->normalize($zone->name) === $this->normalize($place['neighborhood'])
                    && $this->normalize($zone->city ?? '') === $this->normalize($place['city'])
                    && strtoupper($zone->state_code ?? '') === $place['state_code']);

            return $matches->count() === 1 ? $matches->first() : null;
        }
        $lat = deg2rad($place['latitude'] - (float) $store->latitude);
        $lon = deg2rad($place['longitude'] - (float) $store->longitude);
        $a = sin($lat / 2) ** 2 + cos(deg2rad((float) $store->latitude)) * cos(deg2rad($place['latitude'])) * sin($lon / 2) ** 2;
        $meters = 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
        $pricing = $store->delivery_pricing ?? 'bands';
        if ($pricing !== 'bands') {
            if ($meters > $store->delivery_max_meters) {
                return null;
            }
            $fee = $pricing === 'fixed' ? (int) $store->delivery_fixed_cents
                : max((int) $store->delivery_minimum_cents, (int) $store->delivery_base_cents + (int) round($meters * $store->delivery_per_km_cents / 1000));

            return (object) ['name' => 'Entrega por raio', 'fee_cents' => $fee];
        }

        return DB::table('delivery_zones')->where('store_id', $store->id)->where('enabled', true)
            ->where('radius_meters', '>=', $meters)->orderBy('radius_meters')->orderBy('id')->first();
    }

    private function normalize(string $address): string
    {
        return mb_strtolower(Str::ascii(preg_replace('/\s+/u', ' ', trim($address))));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['address' => $message]);
    }
}
