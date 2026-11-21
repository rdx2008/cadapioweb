<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KitchenPrintQueue
{
    public function enqueue(object $order): void
    {
        $store = DB::table('stores')->where('id', $order->store_id)->firstOrFail();
        if (! $store->kitchen_printer_token_hash) {
            return;
        }
        $groups = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get()->groupBy('kitchen_station');
        $sequence = 0;
        foreach ($groups as $station => $items) {
            $lines = [$store->name, 'PEDIDO #'.$order->id, $station === '' ? 'Preparo geral' : (string) $station, ''];
            foreach ($items as $item) {
                $lines[] = $item->quantity.' x '.$item->name;
                foreach (json_decode($item->options ?? '[]', true) ?? [] as $option) {
                    $lines[] = '  '.($option['group'] ?? '').': '.($option['name'] ?? '');
                }
            }
            $ticket = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', implode("\n", $lines));
            DB::table('kitchen_print_jobs')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'store_id' => $store->id, 'order_id' => $order->id,
                'sequence' => ++$sequence, 'station' => $station === '' ? null : (string) $station,
                'ticket' => $ticket, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
