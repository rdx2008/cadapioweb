<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryCosts
{
    public const MAX_VALUE = 99999999999;

    public static function reviewHash(object $item, int $lastMovement): string
    {
        return hash('sha256', json_encode([$item->id, $item->balance, $item->stock_value_cents, $item->average_cost_micros, $item->valuation_revision, $lastMovement], JSON_THROW_ON_ERROR));
    }

    public static function money(string $value, string $field, int $maximum = 999999999): int
    {
        $value = trim($value);
        if (! preg_match('/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Informe um valor em reais, como 29,90, sem separador de milhares.']);
        }
        $parts = preg_split('/[.,]/', $value);
        $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($cents > $maximum) {
            throw ValidationException::withMessages([$field => 'O valor excede o limite permitido.']);
        }

        return $cents;
    }

    /** Multiply/divide through quotient and remainder, without floats or overflowing intermediates. */
    public static function ratio(int $value, int $multiplier, int $denominator, int $scale = 1, bool $round = true): ?int
    {
        if ($denominator < 1 || $value < 0 || $multiplier < 0) {
            return null;
        }
        if ($multiplier === 0) {
            return 0;
        }
        $whole = intdiv($value, $denominator);
        $remainder = $value % $denominator;
        if ($whole > intdiv(PHP_INT_MAX, $multiplier) || $remainder > intdiv(PHP_INT_MAX, $multiplier)) {
            return null;
        }
        $product = $remainder * $multiplier;
        $extra = intdiv($product, $denominator);
        $whole *= $multiplier;
        if ($whole > PHP_INT_MAX - $extra) {
            return null;
        }
        $whole += $extra;
        $remainder = $product % $denominator;
        $half = $round ? intdiv($denominator, 2) : 0;
        if ($whole > intdiv(PHP_INT_MAX, $scale) || $remainder > intdiv(PHP_INT_MAX - $half, $scale)) {
            return null;
        }
        $fraction = intdiv($remainder * $scale + $half, $denominator);
        $whole *= $scale;

        return $whole <= PHP_INT_MAX - $fraction ? $whole + $fraction : null;
    }

    public static function transition(object $item, string $kind, int $quantity, ?int $entryValue): array
    {
        $previous = $item->stock_value_cents;
        $average = $item->average_cost_micros;
        if ($kind === 'in') {
            $value = $entryValue;
            $after = $entryValue === null || ($item->balance > 0 && $previous === null) ? null : ($item->balance === 0 ? 0 : $previous) + $entryValue;
        } else {
            $value = $previous === null ? null : self::ratio($previous, $quantity, $item->balance, round: false);
            $after = $item->balance === $quantity ? 0 : ($value === null ? null : $previous - $value);
        }
        if ($after !== null && $after > self::MAX_VALUE) {
            throw ValidationException::withMessages(['value' => 'O valor acumulado do estoque ultrapassaria o limite permitido.']);
        }
        $balance = $item->balance + ($kind === 'in' ? $quantity : -$quantity);
        if ($balance > 0) {
            $average = $after === null ? null : self::ratio($after, 1000, $balance, 10000);
        }

        return ['value_cents' => $value, 'stock_value_after_cents' => $after, 'average_cost_micros' => $average];
    }

    public static function micros(?int $value): string
    {
        return $value === null ? 'Custo pendente' : 'R$ '.number_format($value / 1000000, 4, ',', '.');
    }

    public function estimate(array $lines, int $tenant, int $store, ?int $priceCents = null): array
    {
        $items = DB::table('inventory_items')->where('tenant_id', $tenant)->where('store_id', $store)->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');
        $details = [];
        $total = 0;
        $complete = $lines !== [];
        foreach ($lines as $line) {
            $item = $items->get($line['item_id']);
            $cost = ! $item || $item->average_cost_micros === null ? null : ($item->balance > 0 && $item->stock_value_cents !== null
                ? self::ratio($item->stock_value_cents, $line['quantity'], $item->balance, 10000) : self::ratio($item->average_cost_micros, $line['quantity'], 1000));
            if ($cost === null || $total > PHP_INT_MAX - ($cost ?? 0)) {
                $complete = false;
            } else {
                $total += $cost;
            }
            $details[] = $line + ['cost_micros' => $cost, 'last_cost' => $item && $item->balance === 0];
        }
        $cost = $complete ? $total : null;
        $revenue = $priceCents === null ? null : $priceCents * 10000;
        $cmv = $cost !== null && $revenue > 0 ? self::ratio($cost, 10000, $revenue) : null;

        return ['lines' => $details, 'cost_micros' => $cost, 'price_cents' => $priceCents, 'margin_micros' => $cost !== null && $revenue !== null ? $revenue - $cost : null, 'cmv_basis_points' => $cmv];
    }
}
