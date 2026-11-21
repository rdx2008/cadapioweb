<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventorySupplierController extends Controller
{
    public function index(Request $request, Inventory $inventory): Response
    {
        $user = $inventory->member($request->user());
        $data = $request->validate(['store_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($data['store_id']) ? $stores->firstWhere('id', (int) $data['store_id']) : $stores->first();
        abort_if(isset($data['store_id']) && ! $store, 404);
        $suppliers = DB::table('inventory_suppliers')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->orderBy('name')->paginate(25)->withQueryString();

        return response()->view('inventory.suppliers', compact('stores', 'store', 'suppliers'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Inventory $inventory, ?int $id = null): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'name' => 'required|string|max:120', 'document' => 'nullable|string|max:30', 'phone' => 'nullable|string|max:25', 'email' => 'nullable|email|max:190', 'notes' => 'nullable|string|max:500', 'active' => 'required|boolean']);
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        abort_if($name === '', 422);
        DB::transaction(function () use ($request, $inventory, $data, $id, $name): void {
            $user = $inventory->member($request->user(), true);
            DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
            if ($id) {
                DB::table('inventory_suppliers')->where('tenant_id', $user->tenant_id)->where('store_id', $data['store_id'])->where('id', $id)->lockForUpdate()->firstOrFail();
            }
            if (DB::table('inventory_suppliers')->where('store_id', $data['store_id'])->where('name_key', mb_strtolower($name))->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Já existe um fornecedor com este nome nesta loja.']);
            }
            $values = ['name' => $name, 'name_key' => mb_strtolower($name), 'document' => $data['document'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'notes' => $data['notes'] ?? null, 'active' => $data['active'], 'updated_at' => now()];
            if ($id) {
                DB::table('inventory_suppliers')->where('id', $id)->update($values);
            } else {
                abort_if(DB::table('inventory_suppliers')->where('store_id', $data['store_id'])->count() >= 1000, 422, 'Limite de 1.000 fornecedores por loja.');
                $id = DB::table('inventory_suppliers')->insertGetId($values + ['tenant_id' => $user->tenant_id, 'store_id' => $data['store_id'], 'created_at' => now()]);
            }
            Audit::record('inventory.supplier_saved', ['supplier_id' => $id, 'active' => (bool) $data['active']], $user->tenant_id);
        }, 3);

        return redirect()->route('suppliers.index', ['store_id' => $data['store_id']])->with('success', 'Fornecedor salvo. Compras anteriores preservam os dados registrados.');
    }
}
