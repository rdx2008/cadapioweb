<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductPromotions
{
    public static function price(int $base, int $basisPoints): int
    {
        if ($basisPoints < 1 || $basisPoints > 9000 || $base < 1) {
            return $base;
        }

        return $base - min($base - 1, intdiv($base * $basisPoints + 5000, 10000));
    }

    public function active(object $store): Collection
    {
        $today = Carbon::now($store->timezone)->toDateString();

        return DB::table('product_promotions')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('enabled', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->orderBy('id')->get()->keyBy('product_id');
    }
}
