<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    public function menu(string $slug)
    {
        $store = DB::table('stores')->where('slug', $slug)->firstOrFail();

        return view('menu', ['store' => $store, 'products' => DB::table('products')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('available', true)->get()]);
    }

    public function checkout(Request $r, string $slug)
    {
        $s = DB::table('stores')->where('slug', $slug)->firstOrFail();
        $d = $r->validate(['customer_name' => 'required|string|max:120', 'phone' => 'required|string|max:25', 'mode' => 'required|in:pickup,delivery', 'address' => 'required_if:mode,delivery|nullable|string|max:500', 'items' => 'required|array|min:1|max:50', 'items.*' => 'required|integer|min:0|max:50', 'idempotency_key' => 'required|uuid']);
        $public = DB::transaction(function () use ($s, $d) {
            DB::table('stores')->where('id', $s->id)->lockForUpdate()->first();
            $old = DB::table('orders')->where('store_id', $s->id)->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                return $old->public_id;
            }abort_unless($s->open, 422, 'Loja fechada.');
            $items = [];
            $subtotal = 0;
            foreach ($d['items'] as $id => $qty) {
                if (! $qty) {
                    continue;
                }$p = DB::table('products')->where('id', $id)->where('tenant_id', $s->tenant_id)->where('store_id', $s->id)->where('available', true)->first();
                abort_unless($p, 422, 'Produto indisponível.');
                $subtotal += $p->price_cents * $qty;
                $items[] = ['name' => $p->name, 'quantity' => $qty, 'unit_cents' => $p->price_cents];
            }abort_unless(count($items), 422, 'Selecione pelo menos um produto.');
            $fee = $d['mode'] === 'delivery' ? $s->customer_fee_cents : 0;
            $public = (string) Str::uuid();
            $id = DB::table('orders')->insertGetId(['public_id' => $public, 'tenant_id' => $s->tenant_id, 'store_id' => $s->id, 'idempotency_key' => $d['idempotency_key'], 'customer_name' => $d['customer_name'], 'phone' => $d['phone'], 'mode' => $d['mode'], 'address' => $d['address'] ?? null, 'subtotal_cents' => $subtotal, 'fee_cents' => $fee, 'total_cents' => $subtotal + $fee, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $i) {
                DB::table('order_items')->insert($i + ['order_id' => $id]);
            }

            return $public;
        });

        return redirect('/pedido/'.$public);
    }

    public function tracking(string $id)
    {
        $o = DB::table('orders')->where('public_id', $id)->firstOrFail();

        return view('tracking', ['order' => $o, 'items' => DB::table('order_items')->where('order_id', $o->id)->get(), 'ride' => DB::table('rides')->where('order_id', $o->id)->first()]);
    }
}
