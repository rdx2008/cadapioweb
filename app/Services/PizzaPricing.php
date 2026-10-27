<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PizzaPricing
{
    public function price(object $flavor, int $variantId): ?int
    {
        $prices = json_decode($flavor->prices, true);
        $price = is_array($prices) ? ($prices[$variantId] ?? null) : null;

        return is_int($price) && $price > 0 && $price <= 1000000 ? $price : null;
    }

    public function availableVariants(Collection $variants, Collection $flavors): Collection
    {
        return $variants->map(function ($variant) use ($flavors) {
            $prices = $flavors->filter(fn ($flavor) => $flavor->available)->map(fn ($flavor) => $this->price($flavor, $variant->id))->filter(fn ($price) => $price !== null);
            if ($prices->isEmpty()) {
                return null;
            }
            $display = clone $variant;
            $display->price_cents = $prices->min();

            return $display;
        })->filter()->values();
    }

    public function quote(object $product, object $variant, array $ids): array
    {
        $ids = array_map('intval', $ids);
        if (! count($ids) || count($ids) > $variant->max_flavors || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['cart' => 'Escolha de 1 a '.$variant->max_flavors.' sabores diferentes para '.$variant->name.'.']);
        }
        $flavors = DB::table('pizza_flavors')->where('product_id', $product->id)->where('available', true)->whereIn('id', $ids)->orderBy('id')->get();
        $prices = $flavors->map(fn ($flavor) => $this->price($flavor, $variant->id));
        if ($flavors->count() !== count($ids) || $prices->contains(null)) {
            throw ValidationException::withMessages(['cart' => 'Um sabor não está disponível para o tamanho escolhido. Revise a pizza.']);
        }
        $cents = $product->pizza_pricing === 'average' ? intdiv($prices->sum() + intdiv(count($ids), 2), count($ids)) : $prices->max();

        return ['price_cents' => $cents, 'description' => $flavors->map(fn ($flavor) => '1/'.count($ids).' '.$flavor->name)->implode(' + '), 'rule' => $product->pizza_pricing === 'average' ? 'Média dos sabores em partes iguais' : 'Maior preço entre os sabores'];
    }
}
