<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\InventoryCosts;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function settings(Request $request, Inventory $inventory): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'inventory_auto' => 'required|boolean', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $inventory, $data): void {
            $user = $inventory->member($request->user(), true);
            DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
            DB::table('stores')->where('id', $data['store_id'])->update(['inventory_auto' => $data['inventory_auto'], 'updated_at' => now()]);
            Audit::record('inventory.automation_changed', ['store_id' => (int) $data['store_id'], 'enabled' => (bool) $data['inventory_auto']], $user->tenant_id);
        }, 3);

        return redirect()->route('inventory.index', ['store_id' => $data['store_id']])->with('success', 'Configuração salva para novos pedidos. Pedidos já registrados mantêm sua composição.');
    }

    public function index(Request $request, Inventory $inventory): Response
    {
        $user = $inventory->member($request->user());
        $data = $request->validate(['store_id' => 'nullable|integer|min:1', 'item' => 'nullable|integer|min:1', 'items_page' => 'nullable|integer|min:1|max:100000', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($data['store_id']) ? $stores->firstWhere('id', (int) $data['store_id']) : $stores->first();
        abort_if(isset($data['store_id']) && ! $store, 404);
        $query = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0);
        abort_if(isset($data['item']) && ! (clone $query)->where('id', $data['item'])->exists(), 404);
        $lowCount = (clone $query)->where('active', true)->whereColumn('balance', '<=', 'minimum')->count();
        $items = $query->orderBy('name')->paginate(25, ['*'], 'items_page')->withQueryString();
        $lastMovements = DB::table('inventory_movements')->whereIn('item_id', $items->pluck('id'))->selectRaw('item_id, MAX(id) as last_id')->groupBy('item_id')->pluck('last_id', 'item_id');
        $costReviews = $items->mapWithKeys(fn ($item) => [$item->id => InventoryCosts::reviewHash($item, (int) ($lastMovements[$item->id] ?? 0))]);
        $history = DB::table('inventory_movements')->join('users', 'users.id', '=', 'inventory_movements.user_id')->where('inventory_movements.tenant_id', $user->tenant_id)->where('inventory_movements.store_id', $store?->id ?? 0)
            ->when(isset($data['item']), fn ($q) => $q->where('item_id', $data['item']))->select('inventory_movements.*', 'users.name as operator_name')->orderByDesc('inventory_movements.id')->paginate(30)->withQueryString();

        return response()->view('inventory.index', compact('stores', 'store', 'items', 'history', 'lowCount', 'costReviews'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Inventory $inventory, ?int $id = null): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'name' => 'required|string|max:120', 'unit' => $id ? 'prohibited' : 'required|in:un,kg,g,l,ml', 'minimum' => 'required|string|max:15', 'active' => 'required|boolean']);
        $minimum = $inventory->quantity($data['minimum'], 'minimum');
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        abort_if($name === '', 422);
        DB::transaction(function () use ($request, $inventory, $data, $minimum, $name, $id): void {
            $user = $inventory->member($request->user(), true);
            DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
            if ($id) {
                DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $data['store_id'])->where('id', $id)->lockForUpdate()->firstOrFail();
            }
            if (DB::table('inventory_items')->where('store_id', $data['store_id'])->where('name_key', mb_strtolower($name))->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Já existe um insumo com este nome nesta loja.']);
            }
            $values = ['name' => $name, 'name_key' => mb_strtolower($name), 'minimum' => $minimum, 'active' => $data['active'], 'updated_at' => now()];
            if ($id) {
                DB::table('inventory_items')->where('id', $id)->update($values);
            } else {
                abort_if(DB::table('inventory_items')->where('store_id', $data['store_id'])->count() >= 2000, 422, 'Limite de 2.000 insumos por loja.');
                $id = DB::table('inventory_items')->insertGetId($values + ['tenant_id' => $user->tenant_id, 'store_id' => $data['store_id'], 'unit' => $data['unit'], 'balance' => 0, 'stock_value_cents' => 0, 'created_at' => now()]);
            }
            Audit::record('inventory.saved', ['item_id' => $id, 'minimum' => $minimum, 'active' => (bool) $data['active']], $user->tenant_id);
        }, 3);

        return redirect()->route('inventory.index', ['store_id' => $data['store_id']])->with('success', 'Insumo salvo. Registre as quantidades nas movimentações.');
    }

    public function move(Request $request, int $id, Inventory $inventory): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|in:in,out', 'quantity' => 'required|string|max:15', 'entry_value' => 'nullable|string|max:16', 'reason' => 'required|string|max:500', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        abort_if($data['kind'] === 'out' && isset($data['entry_value']), 422, 'Saídas utilizam o custo médio do estoque.');
        $entryValue = isset($data['entry_value']) ? InventoryCosts::money($data['entry_value'], 'entry_value') : null;
        $quantity = $inventory->quantity($data['quantity']);
        $reason = trim($data['reason']);
        abort_if($reason === '', 422);
        $storeId = DB::transaction(function () use ($request, $inventory, $id, $data, $quantity, $reason, $entryValue): int {
            $user = $inventory->member($request->user(), true);
            $item = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('id', $id)->lockForUpdate()->firstOrFail();
            $inventory->move($item, $user, $data['kind'], $quantity, $reason, $data['request_key'], entryValue: $entryValue);

            return $item->store_id;
        }, 3);

        return redirect()->route('inventory.index', ['store_id' => $storeId, 'item' => $id])->with('success', 'Movimentação registrada. O saldo já foi atualizado.');
    }
}
