<?php

namespace App\Http\Controllers;

use App\Services\CheckoutPayment;
use App\Services\DiningRoom;
use App\Services\OnlinePayment;
use App\Support\Audit;
use App\Support\MoneyInput;
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
        $tabs = DB::table('dining_tabs')->whereIn('active_table_id', $tables->pluck('id'))->get()->keyBy('current_table_id');
        $owners = $tabs->pluck('id', 'id')->all();
        $parents = array_keys($owners);
        while ($parents !== []) {
            $children = DB::table('dining_tabs')->where('store_id', $store->id)->whereIn('merged_into_tab_id', $parents)->get(['id', 'merged_into_tab_id']);
            $parents = [];
            foreach ($children as $child) {
                if (! isset($owners[$child->id])) {
                    $owners[$child->id] = $owners[$child->merged_into_tab_id];
                    $parents[] = $child->id;
                }
            }
        }
        $orders = DB::table('orders')->whereIn('dining_tab_id', array_keys($owners))->where('status', '!=', 'cancelled')->get(['dining_tab_id', 'total_cents', 'payment_status'])->groupBy(fn ($order) => $owners[$order->dining_tab_id]);
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
        $request->validate(['split_people' => 'sometimes|required|integer|min:1|max:100']);
        $user = $room->member($request->user());
        $tab = $room->tab($user, $id);
        $summary = $room->summary($tab);
        $ids = $room->groupIds($tab);
        $orders = DB::table('orders')->whereIn('dining_tab_id', $ids)->orderByDesc('id')->paginate(20);
        $store = DB::table('stores')->find($tab->store_id);

        return compact('tab', 'summary', 'orders', 'store') + ['operator' => DB::table('users')->find($tab->opened_by),
            'settlements' => DB::table('dining_settlements')->join('users', 'users.id', '=', 'dining_settlements.user_id')->whereIn('dining_tab_id', $ids)->select('dining_settlements.*', 'users.name as operator_name')->orderBy('dining_settlements.id')->get(),
            'mergeTargets' => $tab->closed_at ? collect() : DB::table('dining_tabs')->where('tenant_id', $tab->tenant_id)->where('store_id', $tab->store_id)->whereNull('closed_at')->where('id', '!=', $id)->orderBy('table_name')->get(),
            'merges' => DB::table('dining_merges')->join('users', 'users.id', '=', 'dining_merges.user_id')->where(fn ($q) => $q->whereIn('source_tab_id', $ids)->orWhereIn('target_tab_id', $ids))->select('dining_merges.*', 'users.name as operator_name')->orderBy('dining_merges.id')->get(),
            'destinations' => $tab->closed_at ? collect() : DB::table('dining_tables')->where('tenant_id', $tab->tenant_id)->where('store_id', $tab->store_id)->where('enabled', true)
                ->where('id', '!=', $tab->current_table_id)->whereNotIn('id', DB::table('dining_tabs')->whereNotNull('active_table_id')->select('active_table_id'))->orderBy('name')->get(['id', 'name', 'seats']),
            'transfers' => DB::table('dining_transfers')->join('users', 'users.id', '=', 'dining_transfers.user_id')->where('dining_tab_id', $tab->id)->select('dining_transfers.*', 'users.name as operator_name')->orderByDesc('dining_transfers.id')->get(),
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
        $request->validate(['amount' => 'sometimes|required|string']);
        MoneyInput::normalize($request, ['amount' => 'amount_cents']);
        $data = $request->validate(['amount_cents' => 'sometimes|required|integer|min:1|max:100000000', 'payment_method' => 'required|in:cash,pix,card', 'review_hash' => 'required|string|size:64', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id, $room, $data): void {
            $user = $room->member($request->user(), true);
            $tab = $room->tab($user, $id, true);
            $room->pay($tab, $user, $data['payment_method'], $data['review_hash'], $data['request_key'], isset($data['amount_cents']) ? (int) $data['amount_cents'] : null);
        }, 3);

        return redirect()->route('dining.show', $id)->with('success', 'Recebimento registrado no caixa.');
    }

    public function serve(Request $request, int $id, int $orderId, DiningRoom $room): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $orderId, $room): void {
            $user = $room->member($request->user(), true);
            $tab = $room->tab($user, $id, true);
            abort_if($tab->closed_at !== null, 409);
            $order = DB::table('orders')->whereIn('dining_tab_id', $room->groupIds($tab))->where('tenant_id', $user->tenant_id)->where('id', $orderId)->lockForUpdate()->firstOrFail();
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

    public function mergeReview(Request $request, int $id, DiningRoom $room): Response
    {
        $data = $request->validate(['target_tab_id' => 'required|integer|min:1']);
        $user = $room->member($request->user());
        $source = $room->tab($user, $id);
        $target = $room->tab($user, (int) $data['target_tab_id']);
        abort_unless($source->store_id === $target->store_id, 404);
        abort_if($source->id === $target->id || $source->closed_at || $target->closed_at, 409, 'Escolha duas comandas abertas diferentes.');
        $store = DB::table('stores')->find($source->store_id);
        $sourceSummary = $room->summary($source);
        $targetSummary = $room->summary($target);

        return response()->view('dining.merge', compact('source', 'target', 'store', 'sourceSummary', 'targetSummary') + ['sourceHash' => $room->mergeHash($source, $sourceSummary), 'targetHash' => $room->mergeHash($target, $targetSummary)])->header('Cache-Control', 'private, no-store');
    }

    public function merge(Request $request, int $id, DiningRoom $room): RedirectResponse
    {
        $data = $request->validate(['target_tab_id' => 'required|integer|min:1', 'source_hash' => 'required|string|size:64', 'target_hash' => 'required|string|size:64', 'reason' => 'required|string|max:500', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id, $room, $data): void {
            $user = $room->member($request->user(), true);
            $source = $room->tab($user, $id, true);
            $target = $room->tab($user, (int) $data['target_tab_id'], true);
            $room->merge($source, $target, $user, $data['source_hash'], $data['target_hash'], trim($data['reason']), $data['request_key']);
        }, 3);

        return redirect()->route('dining.show', $data['target_tab_id'])->with('success', 'Comandas unidas. A mesa de origem foi liberada. Avise a equipe e confira as vias já impressas.');
    }

    public function transfer(Request $request, int $id, DiningRoom $room): RedirectResponse
    {
        $data = $request->validate(['expected_review_hash' => 'sometimes|required|string|size:64', 'from_table_id' => 'required|integer|min:1', 'to_table_id' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'confirmed' => 'accepted', 'request_key' => 'required|uuid']);
        DB::transaction(function () use ($request, $id, $room, $data): void {
            $user = $room->member($request->user(), true);
            $tab = $room->tab($user, $id, true);
            if (isset($data['expected_review_hash']) && ! DB::table('dining_transfers')->where('request_key', $data['request_key'])->exists()) {
                abort_unless(hash_equals($room->summary($tab)['review_hash'], $data['expected_review_hash']), 409, 'A comanda mudou. Atualize a tela antes de transferir.');
            }
            $room->transfer($tab, $user, (int) $data['from_table_id'], (int) $data['to_table_id'], trim($data['reason']), $data['request_key']);
        }, 3);

        return redirect()->route('dining.show', $id)->with('success', 'Comanda transferida. Avise a equipe sobre a nova mesa e confira as vias já impressas.');
    }

    public function close(Request $request, int $id, DiningRoom $room): RedirectResponse
    {
        $data = $request->validate(['expected_review_hash' => 'sometimes|required|string|size:64', 'request_key' => 'required|uuid', 'confirmed' => 'accepted', 'expected_table_id' => 'sometimes|required|integer|min:1']);
        DB::transaction(function () use ($request, $id, $room, $data): void {
            $user = $room->member($request->user(), true);
            $tab = $room->tab($user, $id, true);
            if ($tab->closed_at === null && isset($data['expected_table_id'])) {
                abort_unless($tab->current_table_id === (int) $data['expected_table_id'], 409, 'A comanda foi transferida. Atualize a tela antes de encerrar.');
            }
            if ($tab->closed_at === null && isset($data['expected_review_hash'])) {
                abort_unless(hash_equals($room->summary($tab)['review_hash'], $data['expected_review_hash']), 409, 'A comanda mudou. Atualize a tela antes de encerrar.');
            }
            $room->close($tab, $user, $data['request_key']);
        }, 3);

        return redirect()->route('dining.show', $id)->with('success', 'Comanda encerrada. Mesa liberada para novo atendimento.');
    }
}
