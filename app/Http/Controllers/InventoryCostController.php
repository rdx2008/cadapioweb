<?php

namespace App\Http\Controllers;

use App\Services\CheckoutQuote;
use App\Services\Inventory;
use App\Services\InventoryCosts;
use App\Services\MenuCatalog;
use App\Services\OrderInventory;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryCostController extends Controller
{
    public function index(Request $request, Inventory $inventory, InventoryCosts $costs): Response
    {
        $user = $inventory->member($request->user());
        $data = $request->validate(['store_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($data['store_id']) ? $stores->firstWhere('id', (int) $data['store_id']) : $stores->first();
        abort_if(isset($data['store_id']) && ! $store, 404);
        $products = DB::table('products')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->orderBy('name')->paginate(25)->withQueryString();
        $recipes = DB::table('product_recipes')->whereIn('product_id', $products->pluck('id'))->where('target_key', 'base')->get()->keyBy('product_id');
        $required = DB::table('option_groups')->whereIn('product_id', $products->pluck('id'))->where('minimum', '>', 0)->pluck('product_id')->all();
        $estimates = [];
        foreach ($products as $product) {
            $simple = $product->product_type === 'common' && ! $product->uses_variants && ! $product->pizza_enabled && ! in_array($product->id, $required, true);
            $estimates[$product->id] = $costs->estimate(json_decode($recipes->get($product->id)?->lines ?? '[]', true), $user->tenant_id, $store->id, $simple ? $product->price_cents : null);
        }
        $stock = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0);
        $pendingCount = (clone $stock)->where('balance', '>', 0)->whereNull('stock_value_cents')->count();
        $knownValue = (int) (clone $stock)->sum('stock_value_cents');

        return response()->view('inventory.costs', compact('stores', 'store', 'products', 'estimates', 'pendingCount', 'knownValue'))->header('Cache-Control', 'private, no-store');
    }

    private function simulationData(object $user, int $id): array
    {
        $product = DB::table('products')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $product->store_id)->firstOrFail();
        $catalog = app(MenuCatalog::class)->data($store);

        return compact('product', 'store') + ['available' => $catalog['products']->contains('id', $id), 'variants' => $catalog['variants']->get($id, collect()), 'flavors' => $catalog['flavors']->get($id, collect()),
            'groups' => $catalog['groups']->get($id, collect()), 'options' => $catalog['options'], 'combo' => $catalog['combos'][$id] ?? ['groups' => collect(), 'options' => collect()]];
    }

    public function simulator(Request $request, int $id, Inventory $inventory): Response
    {
        $user = $inventory->member($request->user());

        return response()->view('inventory.cost-simulator', $this->simulationData($user, $id) + ['result' => null])->header('Cache-Control', 'private, no-store');
    }

    public function simulate(Request $request, int $id, Inventory $inventory, InventoryCosts $costs): Response
    {
        $user = $inventory->member($request->user());
        $data = $request->validate(['entry' => 'required|array:quantity,variant_id,flavors,choices,combo_choices', 'entry.quantity' => 'required|integer|min:1|max:50', 'entry.variant_id' => 'nullable|integer|min:1',
            'entry.flavors' => 'nullable|array|max:4', 'entry.flavors.*' => 'integer|min:1', 'entry.choices' => 'nullable|array|max:20', 'entry.choices.*' => 'array|max:99', 'entry.choices.*.*' => 'integer|min:1',
            'entry.combo_choices' => 'nullable|array|max:10', 'entry.combo_choices.*' => 'array|max:10', 'entry.combo_choices.*.*' => 'integer|min:1']);
        $view = $this->simulationData($user, $id);
        $entry = $data['entry'] + ['product_id' => $id];
        $priceStore = clone $view['store'];
        $priceStore->inventory_auto = false;
        $priceStore->accepts_cash = true;
        $quote = app(CheckoutQuote::class)->calculate($priceStore, ['cart_lines' => [$entry], 'payment_method' => 'cash', 'mode' => 'pickup'], true);
        $stockStore = clone $view['store'];
        $stockStore->inventory_auto = true;
        $plan = app(OrderInventory::class)->snapshot($stockStore, [['product' => $view['product'], 'entry' => $entry]]);
        $result = $costs->estimate($plan['items'], $user->tenant_id, $stockStore->id, $quote['subtotal_cents']);

        return response()->view('inventory.cost-simulator', $view + ['result' => $result, 'selection' => $data['entry']])->header('Cache-Control', 'private, no-store');
    }

    public function value(Request $request, int $id, Inventory $inventory): RedirectResponse
    {
        $data = $request->validate(['value' => 'required|string|max:16', 'reason' => 'required|string|max:500', 'review_hash' => 'required|string|size:64', 'request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        $value = InventoryCosts::money($data['value'], 'value', InventoryCosts::MAX_VALUE);
        $reason = trim($data['reason']);
        abort_if($reason === '', 422);
        $storeId = DB::transaction(function () use ($request, $id, $inventory, $data, $value, $reason): int {
            $user = $inventory->member($request->user(), true);
            $item = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('id', $id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('inventory_valuations')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->item_id === $id && $existing->user_id === $user->id && $existing->value_cents === $value && $existing->reason === $reason && hash_equals($existing->review_hash, $data['review_hash']), 409);

                return $item->store_id;
            }
            $lastMovement = (int) DB::table('inventory_movements')->where('item_id', $id)->max('id');
            abort_unless(hash_equals(InventoryCosts::reviewHash($item, $lastMovement), $data['review_hash']), 409, 'O estoque ou seu valor mudou. Confira o saldo atual antes de avaliar.');
            if ($item->balance < 1) {
                throw ValidationException::withMessages(['value' => 'Não há saldo para avaliar. Registre uma entrada ou compra com valor conhecido.']);
            }
            $average = InventoryCosts::ratio($value, 1000, $item->balance, 10000);
            DB::table('inventory_items')->where('id', $id)->update(['stock_value_cents' => $value, 'average_cost_micros' => $average, 'valuation_revision' => $item->valuation_revision + 1, 'updated_at' => now()]);
            DB::table('inventory_valuations')->insert(['item_id' => $id, 'user_id' => $user->id, 'item_name' => $item->name, 'unit' => $item->unit, 'quantity' => $item->balance, 'previous_value_cents' => $item->stock_value_cents, 'value_cents' => $value, 'average_cost_micros' => $average,
                'reason' => $reason, 'review_hash' => $data['review_hash'], 'request_key' => $data['request_key'], 'created_at' => now()]);
            Audit::record('inventory.valued', ['item_id' => $id, 'value_cents' => $value, 'quantity' => $item->balance], $user->tenant_id);

            return $item->store_id;
        }, 3);

        return redirect()->route('inventory.index', ['store_id' => $storeId, 'item' => $id])->with('success', 'Valor do estoque registrado, sem alterar quantidades nem movimentos antigos.');
    }

    public function history(Request $request, int $id, Inventory $inventory): Response
    {
        $user = $inventory->member($request->user());
        $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $item = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        $store = DB::table('stores')->where('id', $item->store_id)->where('tenant_id', $user->tenant_id)->firstOrFail();
        $valuations = DB::table('inventory_valuations')->join('users', 'users.id', '=', 'inventory_valuations.user_id')->where('item_id', $id)->select('inventory_valuations.*', 'users.name as operator_name')->orderByDesc('inventory_valuations.id')->paginate(20);

        return response()->view('inventory.cost-history', compact('item', 'store', 'valuations'))->header('Cache-Control', 'private, no-store');
    }
}
