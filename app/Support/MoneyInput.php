<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MoneyInput
{
    public static function normalize(Request $request, array $fields): void
    {
        foreach ($fields as $money => $cents) {
            if (! $request->exists($money) || $request->input($money) === null || $request->input($money) === '') {
                continue;
            }
            $value = $request->input($money);
            if (! is_scalar($value) || ! preg_match('/^\d{1,7}(?:[.,]\d{1,2})?$/D', (string) $value)) {
                throw ValidationException::withMessages([$money => 'Informe um valor em reais, como 29,90, sem separador de milhares.']);
            }
            $parts = preg_split('/[.,]/', (string) $value);
            $request->merge([$cents => (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0')]);
        }
    }
}
