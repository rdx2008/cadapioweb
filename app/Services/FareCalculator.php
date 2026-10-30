<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class FareCalculator
{
    public function quote(object $store, ?int $meters = null, ?int $individual = null): int
    {
        $fare = match ($store->rate_mode) {
            'fixed' => (int) $store->base_cents,
            'per_km' => $meters !== null ? (int) $store->base_cents + intdiv($meters * (int) $store->per_km_cents + 500, 1000) : throw ValidationException::withMessages(['route_meters' => 'Informe a distância de rota confirmada. Não usamos distância em linha reta para cobrar.']),
            'per_ride' => $individual ?? throw ValidationException::withMessages(['fare_cents' => 'Informe a cotação desta corrida.']),
            default => throw ValidationException::withMessages(['rate_mode' => 'Regra inválida.']),
        };

        return max((int) $store->minimum_cents, $fare);
    }
}
