<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MenuCatalog
{
    public function data(object $store): array
    {
        $products = DB::table('products')->leftJoin('catalog_categories', function ($join) {
            $join->on('catalog_categories.id', '=', 'products.category_id')->on('catalog_categories.store_id', '=', 'products.store_id');
        })->where('products.tenant_id', $store->tenant_id)->where('products.store_id', $store->id)->where('products.available', true)->select('products.*')->orderByRaw('COALESCE(catalog_categories.sort_order, 9999)')->orderBy('products.category')->orderBy('products.sort_order')->orderBy('products.name')->get();
        $variants = DB::table('product_variants')->whereIn('product_id', $products->pluck('id'))->where('available', true)->orderBy('price_cents')->orderBy('id')->get()->groupBy('product_id');
        $flavors = DB::table('pizza_flavors')->whereIn('product_id', $products->pluck('id'))->where('available', true)->orderBy('name')->get()->groupBy('product_id');
        $combos = [];
        $products = $products->filter(function ($product) use ($variants, $flavors, &$combos): bool {
            if ($product->product_type === 'combo') {
                $combos[$product->id] = app(ComboCatalog::class)->data($product);

                return ! $product->uses_variants && ! $product->pizza_enabled && $combos[$product->id]['ready'];
            }
            if (! $product->uses_variants) {
                return true;
            }
            $available = $variants->get($product->id, collect());
            if ($product->pizza_enabled) {
                $available = app(PizzaPricing::class)->availableVariants($available, $flavors->get($product->id, collect()));
                $variants->put($product->id, $available);
            }
            if ($available->isEmpty()) {
                return false;
            }
            $product->price_cents = $available->min('price_cents');

            return true;
        });
        $groups = DB::table('option_groups')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get();
        $groups = $groups->filter(fn ($group) => $group->kind !== 'border' || $products->firstWhere('id', $group->product_id)?->product_type === 'pizza');
        $options = DB::table('product_options')->whereIn('option_group_id', $groups->pluck('id'))->where('available', true)->orderBy('id')->get()->groupBy('option_group_id');

        return ['products' => $products, 'groups' => $groups->groupBy('product_id'), 'options' => $options, 'variants' => $variants, 'flavors' => $flavors, 'combos' => $combos];
    }
}
