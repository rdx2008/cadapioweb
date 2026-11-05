<?php

namespace App\Http\Controllers;

use App\Services\CheckoutPayment;
use App\Services\DiningRoom;
use App\Services\OnlinePayment;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiningRoomController extends Controller
{
    public function index(Request $request, DiningRoom $room): Response
    {
        $user = $room->member($request->user());
        $data = $request->validate(['store_id' => 'nullable|integer|min:1']);
        $stores = $room->stores($user)->orderBy('name')->get();
        $store = ! empty($data['store_id']) ? $stores->firstWhere('id', (int) $data['store_id']) : $stores->first();
        abort_if(! empty($data['store_id']) && ! $store, 404);
        $tables = DB::table('dining_tables')->where('store_id', $store?->id ?? 0)->orderBy('name')->get();
        $tabs = DB::table('dining_tabs')->whereIn('active_table_id', $tables->pluck('id'))->get()->keyBy('dining_table_id');
        $orders = DB::table('orders')->whereIn('dining_tab_id', $tabs->pluck('id'))->where('status', '!=', 'cancelled')->get(['dining_tab_id', 'total_cents', 'payment_status'])->groupBy('dining_tab_id');
        $history = DB::table('dining_tabs')->where('store_id', $store?->id ?? 0)->orderByDesc('id')->paginate(15);

        return response()->view('dining.index', compact('stores', 'store', 'tables', 'tabs', 'orders', 'history'))->header('Cache-Control', 'private, no-store');
    }

    public function saveTable(Request $request, DiningRoom $room, ?int $id = null): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'name' => 'required|string|max:80', 'seats' => 'required|integer|min:1|max:100', 'enabled' => 'required|boolean']);
        DB::transaction(function () use ($request, $room, $data, $id): void {
            $user = $room->member($request->user(), true);
            abort_unless($user->role === 'owner', 403);
            $store = $room->stores($user)->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
            $name = trim($data['name']);
            $key = mb_strtolower($name);
            if (DB::table('dining_tables')->where('store_id', $store->id)->where('name_key', $key)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Já existe uma mesa com esse nome nesta loja.']);
            }
            $values = ['name' => $name, 'name_key' => $key, 'seats' => $data['seats'], 'enabled' => $data['enabled'], 'updated_at' => now()];
            if ($id) {
                DB::table('dining_tables')->where('tenant_id', $user->tenant_id)->where('store_id', $store->id)->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_if(DB::table('dining_tabs')->where('active_table_id', $id)->exists(), 409, 'Encerre a comanda desta mesa antes de editar ou desativar.');
                DB::table('dining_tables')->where('id', $id)->update($values);
            } else {
                abort_if(DB::table('dining_tables')->where('store_id', $store->id)->count() >= 200, 422, 'Limite de 200 mesas por loja.');
                $id = DB::table('dining_tables')->insertGetId($values + ['store_id' => $store->id, 'tenant_id' => $user->tenant_id, 'created_at' => now()]);
            }
            Audit::record('dining.table_saved', ['table_id' => $id], $user->tenant_id);
        }, 3);

        return redirect()->route('dining.index', ['store_id' => $data['store_id']])->with('success', 'Mesa salva.');
    }

    public function open(Request $request, int $id, DiningRoom $room): RedirectResponse
    {
        $data = $request->validate(['guest_label' => 'nullable|string|max:120', 'guests' => 'required|integer|min:1|max:100', 'request_key' => 'required|uuid']);
        $tabId = DB::transaction(function () use ($request, $room, $id, $data): int {
            $user = $room->member($request->user(), true);
            $table = DB::table('dining_tables')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $room->stores($user)->select('id'))->where('id', $id)->firstOrFail();

            return $room->open($table, $user, trim($data['guest_label'] ?? ''), (int) $data['guests'], $data['request_key']);
        }, 3);

        return redirect()->route('dining.show', $tabId)->with('success', 'Comanda aberta. Você já pode lançar o primeiro pedido.');
    }

    private function data(Request $request, int $id, DiningRoom $room): array
    {
        $user = $room->member($request->user());
        $tab = $room->tab($user, $id);
        $summary = $room->summary($tab);
        $orders = DB::table('orders')->where('dining_tab_id', $id)->orderByDesc('id')->paginate(20);
        $store = DB::table('stores')->find($tab->store_id);

        return compact('tab', 'summary', 'orders', 'store') + ['operator' => DB::table('users')->find($tab->opened_by),
            'methods' => array_intersect_key(app(CheckoutPayment::class)->methods($store), array_flip(['cash', 'pix', 'card'])),
            'items' => DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('id')->get()->groupBy('order_id')];
    }

    public function show(Request $request, int $id, DiningRoom $room): Response
    {
        return response()->view('dining.show', $this->data($request, $id, $room))->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $request, int $id, DiningRoom $room): Response
    {
        $data = $this->data($request, $id, $room);
        $data['orders'] = $data['summary']['orders'];
        $data['items'] = DB::table('order_items')->whereIn('order_id', $data['orders']->pluck('id'))->orderBy('id')->get()->groupBy('order_id');

        return response()->view('dining.print', $data)->header('Cache-Control', 'private, no-store');
    }

    public function pay(Request $request, int $id, DiningRoom $room): RedirectResponse
    {
        $data = $request->validate(['payment_method' => 'required|in:cash,pix,card', 'review_hash' => 'required|string|size:64', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id, $room, $data): void {
            $user = $room->member($request->user(), true);
            $tab = $room->tab($user, $id, true);
            $room->pay($tab, $user, $data['payment_method'], $data['review_hash'], $data['request_key']);
        }, 3);

        return redirect()->route('dining.show', $id)->with('success', 'Recebimento registrado no caixa.');
    }

    public function serve(Request $request, int $id, int $orderId, DiningRoom $room): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $orderId, $room): void {
            $user = $room->member($request->user(), true);
            $tab = $room->tab($user, $id, true);
            abort_if($tab->closed_at !== null, 409);
            $order = DB::table('orders')->where('dining_tab_id', $id)->where('tenant_id', $user->tenant_id)->where('id', $orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === 'completed') {
                return;
            }
            abort_unless($order->status === 'ready', 409, 'Aguarde o pedido ficar pronto antes de marcar como servido.');
            app(OnlinePayment::class)->requirePaid($order);
            DB::table('orders')->where('id', $orderId)->update(['status' => 'completed', 'updated_at' => now()]);
            Audit::record('dining.served', ['tab_id' => $id, 'order_id' => $orderId], $user->tenant_id);
        }, 3);

        return redirect()->route('dining.show', $id)->with('success', 'Pedido marcado como servido.');
    }

    public function close(Request $request, int $id, DiningRoom $room): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id, $room, $data): void {
            $user = $room->member($request->user(), true);
            $room->close($room->tab($user, $id, true), $user, $data['request_key']);
        }, 3);

        return redirect()->route('dining.show', $id)->with('success', 'Comanda encerrada. Mesa liberada para novo atendimento.');
    }
}
