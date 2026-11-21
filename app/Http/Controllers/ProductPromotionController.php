<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\InventoryCosts;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProductPromotionController extends Controller
{
    public function index(Request $request, int $storeId, Inventory $access): Response
    {
        $user = $access->member($request->user());
        $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $storeId)->firstOrFail();
        $products = DB::table('products')->where('tenant_id', $user->tenant_id)->where('store_id', $storeId)->orderBy('name')->get(['id', 'name']);
        $promotions = DB::table('product_promotions as p')->join('products', 'products.id', '=', 'p.product_id')->where('p.tenant_id', $user->tenant_id)->where('p.store_id', $storeId)->where('products.tenant_id', $user->tenant_id)->where('products.store_id', $storeId)->select('p.*', 'products.name as product_name')->orderByDesc('p.id')->paginate(20)->withQueryString();
        $today = Carbon::now($store->timezone)->toDateString();

        return response()->view('catalog.promotions', compact('store', 'products', 'promotions', 'today'))->header('Cache-Control', 'private, no-store');
    }

    private function overlap(int $product, string $start, string $end, ?int $except = null): void
    {
        abort_if(DB::table('product_promotions')->where('product_id', $product)->where('enabled', true)->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)->when($except, fn ($query) => $query->where('id', '!=', $except))->exists(), 409, 'Este produto já tem uma promoção habilitada nesse período. Pause a anterior ou escolha outro período.');
    }

    public function create(Request $request, int $storeId, Inventory $access): RedirectResponse
    {
        $data = $request->validate(['product_id' => 'required|integer|min:1', 'name' => 'required|string|max:100', 'percent' => 'required|string|max:6', 'starts_on' => 'required|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on|before_or_equal:2100-12-31', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $storeId, $access, $data): void {
            $user = $access->member($request->user(), true);
            $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $storeId)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$user->id, $storeId, $data], JSON_THROW_ON_ERROR));
            $existing = DB::table('product_promotions')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $user->id && hash_equals($existing->request_hash, $hash), 409, 'Esta confirmação já foi utilizada.');

                return;
            }
            DB::table('products')->where('tenant_id', $user->tenant_id)->where('store_id', $storeId)->where('id', $data['product_id'])->firstOrFail();
            $basis = InventoryCosts::money($data['percent'], 'percent', 9000);
            abort_if($basis < 100, 422, 'Informe um desconto entre 1% e 90%, com até duas casas decimais.');
            abort_if(DB::table('product_promotions')->where('store_id', $storeId)->where('enabled', true)->where('ends_on', '>=', Carbon::now($store->timezone)->toDateString())->count() >= 500, 422, 'Limite de 500 promoções habilitadas atuais ou futuras nesta loja.');
            $this->overlap((int) $data['product_id'], $data['starts_on'], $data['ends_on']);
            $id = DB::table('product_promotions')->insertGetId(['tenant_id' => $user->tenant_id, 'store_id' => $storeId, 'product_id' => $data['product_id'], 'name' => trim($data['name']), 'basis_points' => $basis, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'created_by' => $user->id, 'request_key' => $data['request_key'], 'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('catalog.promotion_created', ['promotion_id' => $id, 'product_id' => (int) $data['product_id'], 'basis_points' => $basis, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on']], $user->tenant_id);
        }, 3);

        return redirect()->route('promotions.index', $storeId)->with('success', 'Promoção cadastrada. O período será aplicado na confirmação do pedido.');
    }

    public function availability(Request $request, int $storeId, int $id, Inventory $access): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'enabled' => 'required|boolean', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $storeId, $id, $access, $data): void {
            $user = $access->member($request->user(), true);
            $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $storeId)->lockForUpdate()->firstOrFail();
            $promotion = DB::table('product_promotions')->where('tenant_id', $user->tenant_id)->where('store_id', $storeId)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($promotion->revision === (int) $data['revision'], 409, 'A promoção mudou. Atualize a página.');
            if ($data['enabled']) {
                abort_if(DB::table('product_promotions')->where('store_id', $storeId)->where('enabled', true)->where('ends_on', '>=', Carbon::now($store->timezone)->toDateString())->count() >= 500, 422, 'Limite de promoções habilitadas atingido.');
                $this->overlap($promotion->product_id, $promotion->starts_on, $promotion->ends_on, $id);
            }
            DB::table('product_promotions')->where('id', $id)->update(['enabled' => (bool) $data['enabled'], 'revision' => $promotion->revision + 1, 'updated_at' => now()]);
            Audit::record('catalog.promotion_availability', ['promotion_id' => $id, 'enabled' => (bool) $data['enabled']], $user->tenant_id);
        }, 3);

        return redirect()->route('promotions.index', $storeId)->with('success', 'Promoção atualizada. Pedidos confirmados permanecem iguais.');
    }
}
