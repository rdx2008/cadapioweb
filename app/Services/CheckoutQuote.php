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
        $entries = $draft['cart_lines'] ?? collect($draft['items'])->map(fn ($quantity, $id) => ['product_id' => $id, 'quantity' => $quantity, 'variant_id' => $draft['variants'][$id] ?? null, 'choices' => $draft['choices'][$id] ?? []])->values()->all();
        $quantities = [];
        foreach ($entries as $entry) {
            $productId = $entry['product_id'];
            $quantity = $entry['quantity'];
            $this->require(ctype_digit((string) $productId) && (int) $productId > 0, 'Identificador de produto inválido.');
            if (! $quantity) {
                continue;
            }
            $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
            $this->require($quantities[$productId] <= 50, 'O limite é de 50 unidades do mesmo produto, somando suas combinações.');
            $product = DB::table('products')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('id', $productId)->where('available', true)->first();
            $this->require($product !== null, 'Um produto não está mais disponível. Revise o carrinho.');
            $groups = DB::table('option_groups')->where('product_id', $product->id)->when($product->product_type !== 'pizza', fn ($query) => $query->where('kind', '!=', 'border'))->orderBy('id')->get();
            $chosen = $entry['choices'] ?? [];
            $this->require(count(array_diff(array_map('strval', array_keys($chosen)), $groups->pluck('id')->map(fn ($id) => (string) $id)->all())) === 0, 'Grupo de adicionais inválido para este produto.');
            $options = [];
            $unit = (int) $product->price_cents;
            if ($product->uses_variants) {
                $variantId = $entry['variant_id'] ?? null;
                $variant = DB::table('product_variants')->where('product_id', $product->id)->where('id', $variantId)->where('available', true)->first();
                $this->require($variant !== null, 'Escolha uma variação disponível de '.$product->name.'.');
                $unit = (int) $variant->price_cents;
                if ($product->pizza_enabled) {
                    $pizza = app(PizzaPricing::class)->quote($product, $variant, $entry['flavors'] ?? []);
                    $unit = $pizza['price_cents'];
                }
                $options[] = ['group' => 'Variação', 'name' => $variant->name, 'price_cents' => $unit, 'kind' => 'variant'];
                if (isset($pizza)) {
                    $options[] = ['group' => 'Sabores (partes iguais)', 'name' => $pizza['description'], 'price_cents' => 0, 'kind' => 'description'];
                    $options[] = ['group' => 'Cálculo da pizza', 'name' => $pizza['rule'], 'price_cents' => 0, 'kind' => 'description'];
                    unset($pizza);
                }
            } else {
                $this->require(empty($entry['variant_id']), 'O modo de preço de '.$product->name.' mudou. Revise o carrinho.');
            }
            if (! $product->pizza_enabled) {
                $this->require(empty($entry['flavors']), 'O modo pizza mudou. Revise as escolhas deste produto.');
            }
            if ($product->product_type === 'combo') {
                $this->require(! $product->uses_variants && ! $product->pizza_enabled, 'O combo está com configuração incompatível.');
                foreach (app(ComboCatalog::class)->quote($product, $entry['combo_choices'] ?? []) as $component) {
                    $unit += $component['price_cents'];
                    $options[] = $component;
                }
            } else {
                $this->require(empty($entry['combo_choices']), 'O tipo do produto mudou. Revise o combo.');
            }
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
            $this->require(count($lines) <= 50 && $subtotal <= 100000000, 'O pedido excede o limite de 50 itens ou o valor máximo permitido.');
        }
        $this->require(count($lines) > 0, 'Selecione pelo menos um produto.');
        $this->require($subtotal >= $store->minimum_order_cents, 'O pedido mínimo em produtos é R$ '.number_format($store->minimum_order_cents / 100, 2, ',', '.').'.');
        $fee = 0;
        $zoneName = null;
        $deliveryAddress = null;
        if ($draft['mode'] === 'delivery') {
            $zone = app(DeliveryCoverage::class)->verify($store, $draft['address'], $draft['delivery_token'] ?? null);
            $this->require($zone !== null, 'Selecione uma região atendida pela loja.');
            $fee = (int) $zone->fee_cents;
            $zoneName = $zone->name;
            $deliveryAddress = $zone->resolved_address ?? $draft['address'];
            if (! empty($draft['address_complement'])) {
                $deliveryAddress .= ' · '.$draft['address_complement'];
            }
        }

        return ['items' => $lines, 'subtotal_cents' => $subtotal, 'fee_cents' => $fee, 'total_cents' => $subtotal + $fee, 'delivery_zone_name' => $zoneName, 'delivery_address' => $deliveryAddress];
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
