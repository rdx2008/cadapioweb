<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\InventoryPurchases;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryPurchaseController extends Controller
{
    public function index(Request $request, Inventory $inventory): Response
    {
        $user = $inventory->member($request->user());
        $data = $request->validate(['store_id' => 'nullable|integer|min:1', 'status' => 'nullable|in:draft,received,cancelled', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1|max:100000']);
        if (! empty($data['from']) && ! empty($data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages(['to' => 'A data final deve ser posterior ou igual ao início.']);
        }
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($data['store_id']) ? $stores->firstWhere('id', (int) $data['store_id']) : $stores->first();
        abort_if(isset($data['store_id']) && ! $store, 404);
        $query = DB::table('inventory_purchases')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0);
        $counts = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $purchases = $query->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))->when(! empty($data['from']), fn ($q) => $q->where('purchased_on', '>=', $data['from']))->when(! empty($data['to']), fn ($q) => $q->where('purchased_on', '<=', $data['to']))->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->view('inventory.purchases', compact('stores', 'store', 'purchases', 'counts'))->header('Cache-Control', 'private, no-store');
    }

    public function form(Request $request, Inventory $inventory, ?int $id = null): Response
    {
        $user = $inventory->member($request->user());
        $purchase = $id ? DB::table('inventory_purchases')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail() : null;
        abort_if($purchase && $purchase->status !== 'draft', 409, 'Somente rascunhos podem ser editados.');
        $data = $request->validate(['store_id' => $id ? 'nullable|integer|min:1' : 'required|integer|min:1']);
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $purchase?->store_id ?? $data['store_id'])->firstOrFail();
        $suppliers = DB::table('inventory_suppliers')->where('tenant_id', $user->tenant_id)->where('store_id', $store->id)->orderBy('name')->get();
        $items = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $store->id)->orderBy('name')->get();
        $lines = $purchase ? DB::table('inventory_purchase_lines')->where('purchase_id', $id)->orderBy('id')->get() : collect();

        return response()->view('inventory.purchase-form', compact('purchase', 'store', 'suppliers', 'items', 'lines'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Inventory $inventory, InventoryPurchases $purchases, ?int $id = null): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'supplier_id' => 'required|integer|min:1', 'purchased_on' => 'required|date_format:Y-m-d', 'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:500',
            'freight' => 'nullable|string|max:16', 'discount' => 'nullable|string|max:16', 'revision' => $id ? 'required|integer|min:1|max:1000000' : 'nullable|integer|min:0|max:0', 'request_key' => 'required|uuid',
            'lines' => 'required|array|min:1|max:50', 'lines.*' => 'array:item_id,quantity,unit_price', 'lines.*.item_id' => 'nullable|integer|min:1|distinct', 'lines.*.quantity' => 'nullable|string|max:15', 'lines.*.unit_price' => 'nullable|string|max:16']);
        $purchaseId = DB::transaction(function () use ($request, $inventory, $purchases, $data, $id): int {
            $user = $inventory->member($request->user(), true);

            return $purchases->save($user, $data, $id);
        }, 3);

        return redirect()->route('purchases.show', $purchaseId)->with('success', 'Rascunho salvo. Confira os itens e confirme o recebimento para atualizar o estoque.');
    }

    public function show(Request $request, int $id, Inventory $inventory, InventoryPurchases $service): Response
    {
        $user = $inventory->member($request->user());
        $purchase = DB::table('inventory_purchases')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $purchase->store_id)->firstOrFail();
        $lines = DB::table('inventory_purchase_lines')->where('purchase_id', $id)->orderBy('id')->get();
        $events = DB::table('inventory_purchase_events')->join('users', 'users.id', '=', 'inventory_purchase_events.user_id')->where('purchase_id', $id)->select('inventory_purchase_events.*', 'users.name as operator_name')->orderByDesc('inventory_purchase_events.id')->paginate(10)->withQueryString();

        return response()->view('inventory.purchase-show', compact('purchase', 'store', 'lines', 'events') + ['reviewHash' => $service->reviewHash($purchase, $lines)])->header('Cache-Control', 'private, no-store');
    }

    public function receive(Request $request, int $id, Inventory $inventory, InventoryPurchases $purchases): RedirectResponse
    {
        $data = $request->validate(['review_hash' => 'required|string|size:64', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $inventory, $purchases, $id, $data): void {
            $user = $inventory->member($request->user(), true);
            $purchases->receive($user, $id, $data['request_key'], $data['review_hash']);
        }, 3);

        return redirect()->route('purchases.show', $id)->with('success', 'Recebimento integral registrado no estoque e nos custos. Nenhum pagamento foi lançado no caixa.');
    }

    public function cancel(Request $request, int $id, Inventory $inventory, InventoryPurchases $purchases): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $inventory, $purchases, $id, $data): void {
            $user = $inventory->member($request->user(), true);
            $purchases->cancel($user, $id, (int) $data['revision'], $data['reason'], $data['request_key']);
        }, 3);

        return redirect()->route('purchases.show', $id)->with('success', 'Rascunho cancelado, sem alterar estoque ou caixa.');
    }
}
