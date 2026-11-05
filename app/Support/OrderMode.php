<?php

namespace App\Support;

class OrderMode
{
    public static function label(string $mode, ?string $diningLabel = null): string
    {
        return match ($mode) {
            'delivery' => 'Entrega',
            'dine_in' => $diningLabel ?: 'Consumo no local',
            default => 'Retirada',
        };
    }
}
