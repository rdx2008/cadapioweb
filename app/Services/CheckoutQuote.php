<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutQuote
{
    public function __construct(private StoreAvailability $availability) {}

    public function calculate(object $store, array $draft): array
    {
        $this->require($this->availability->isOpen($store), 'Esta loja está fechada ou pausada.');
        $lines = [];
        $subtotal = 0;
        foreach ($draft['items'] as $productId => $quantity) {
            $this->require(ctype_digit((string) $productId) && (int) $productId > 0, 'Identificador de produto inválido.');
            if (! $quantity) {
                continue;
            }
            $product = DB::table('products')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('id', $productId)->where('available', true)->first();
            $this->require($product !== null, 'Um produto não está mais disponível. Revise o carrinho.');
            $groups = DB::table('option_groups')->where('product_id', $product->id)->orderBy('id')->get();
            $chosen = $draft['choices'][$product->id] ?? [];
            $this->require(count(array_diff(array_map('strval', array_keys($chosen)), $groups->pluck('id')->map(fn ($id) => (string) $id)->all())) === 0, 'Grupo de adicionais inválido para este produto.');
            $options = [];
            $unit = (int) $product->price_cents;
            foreach ($groups as $group) {
                $ids = array_map('intval', $chosen[$group->id] ?? []);
                $this->require(count($ids) === count(array_unique($ids)), 'Um adicional não pode ser selecionado duas vezes.');
                $this->require(count($ids) >= $group->minimum && count($ids) <= $group->maximum, 'Confira as escolhas de '.$product->name.': '.$group->name.' exige de '.$group->minimum.' a '.$group->maximum.' opções.');
                $available = DB::table('product_options')->where('option_group_id', $group->id)->where('available', true)->whereIn('id', $ids)->orderBy('id')->get();
                $this->require($available->count() === count($ids), 'Adicional indisponível ou pertencente a outro produto.');
                foreach ($available as $option) {
                    $unit += $option->price_cents;
                    $options[] = ['group' => $group->name, 'name' => $option->name, 'price_cents' => (int) $option->price_cents];
                }
            }
            $lines[] = ['name' => $product->name, 'quantity' => (int) $quantity, 'unit_cents' => $unit, 'options' => $options];
            $subtotal += $unit * $quantity;
            $this->require(count($lines) <= 50 && $subtotal <= 100000000, 'O pedido excede o limite de 50 produtos distintos ou o valor máximo permitido.');
        }
        $this->require(count($lines) > 0, 'Selecione pelo menos um produto.');
        $this->require($subtotal >= $store->minimum_order_cents, 'O pedido mínimo em produtos é R$ '.number_format($store->minimum_order_cents / 100, 2, ',', '.').'.');
        $fee = 0;
        $zoneName = null;
        if ($draft['mode'] === 'delivery') {
            $zone = DB::table('delivery_zones')->where('store_id', $store->id)->where('enabled', true)->where('id', $draft['delivery_zone_id'] ?? 0)->first();
            $this->require($zone !== null, 'Selecione uma região atendida pela loja.');
            $fee = (int) $zone->fee_cents;
            $zoneName = $zone->name;
        }

        return ['items' => $lines, 'subtotal_cents' => $subtotal, 'fee_cents' => $fee, 'total_cents' => $subtotal + $fee, 'delivery_zone_name' => $zoneName];
    }

    public function fingerprint(array $quote): string
    {
        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['cart' => $message]);
        }
    }
}
