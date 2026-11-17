<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\InventoryCosts;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogBatchController extends Controller
{
    private function store(object $user, int $id, bool $lock = false): object
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public static function version(object $product): string
    {
        return hash('sha256', json_encode([
            $product->name, $product->price_cents, $product->available, $product->category_id,
            $product->category, $product->product_type, $product->uses_variants, $product->updated_at,
        ], JSON_THROW_ON_ERROR));
    }

    public static function allowsPrice(object $product): bool
    {
        return $product->product_type === 'common' && ! $product->uses_variants;
    }

    private function draftKey(int $storeId): string
    {
        return 'catalog_batch.'.$storeId;
    }

    public function index(Request $request, int $storeId, Inventory $access): Response
    {
        $user = $access->member($request->user());
        $store = $this->store($user, $storeId);
        $filters = $request->validate(['q' => 'nullable|string|max:120']);
        $products = DB::table('products')->where('tenant_id', $user->tenant_id)->where('store_id', $storeId)
            ->when(! empty($filters['q']), fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->orderBy('name')->orderBy('id')->paginate(20)->appends($filters);
        $categories = DB::table('catalog_categories')->where('store_id', $storeId)->orderBy('name')->get();
        $draft = $request->session()->get($this->draftKey($storeId));
        if (($draft['actor'] ?? null) !== $user->id) {
            $draft = null;
        }

        return response()->view('catalog.batch', compact('store', 'products', 'categories', 'draft'))->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, int $storeId, Inventory $access): RedirectResponse
    {
        $user = $access->member($request->user());
        $this->store($user, $storeId);
        $data = $request->validate([
            'rows' => 'required|array|max:20',
            'rows.*' => 'required|array:id,version,price,available,category_id',
            'rows.*.id' => 'required|integer|min:1|distinct',
            'rows.*.version' => 'required|string|size:64',
            'rows.*.price' => 'nullable|string|max:12',
            'rows.*.available' => 'nullable|boolean',
            'rows.*.category_id' => 'nullable|integer|min:1',
        ]);
        $products = DB::table('products')->where('tenant_id', $user->tenant_id)->where('store_id', $storeId)
            ->whereIn('id', array_column($data['rows'], 'id'))->get()->keyBy('id');
        $categories = DB::table('catalog_categories')->where('store_id', $storeId)->pluck('name', 'id');
        $changes = [];
        foreach ($data['rows'] as $row) {
            if (! isset($row['price']) && ! isset($row['available']) && ! isset($row['category_id'])) {
                continue;
            }
            $product = $products->get((int) $row['id']);
            abort_unless($product, 404);
            abort_unless(hash_equals(self::version($product), $row['version']), 409, 'O produto mudou. Atualize a página antes de preparar o lote.');
            $before = [];
            $after = [];
            if (isset($row['price'])) {
                abort_unless(self::allowsPrice($product), 422, 'Use o editor do produto para preços de pizzas, combos ou variações.');
                $price = InventoryCosts::money($row['price'], 'rows', 1000000);
                if ($price < 1) {
                    throw ValidationException::withMessages(['rows' => 'O preço deve estar entre R$ 0,01 e R$ 10.000,00.']);
                }
                if ($price !== (int) $product->price_cents) {
                    $before['price_cents'] = $product->price_cents;
                    $after['price_cents'] = $price;
                }
            }
            if (isset($row['available']) && (bool) $row['available'] !== (bool) $product->available) {
                $before['available'] = (bool) $product->available;
                $after['available'] = (bool) $row['available'];
            }
            if (isset($row['category_id'])) {
                $categoryId = (int) $row['category_id'];
                $name = $categories->get($categoryId);
                abort_unless($name !== null, 404);
                if ($categoryId !== (int) $product->category_id) {
                    $before['category'] = $product->category;
                    $after['category_id'] = $categoryId;
                    $after['category'] = $name;
                }
            }
            if ($after) {
                $changes[$product->id] = ['name' => $product->name, 'version' => $row['version'], 'before' => $before, 'after' => $after];
            }
        }
        if (! $changes) {
            throw ValidationException::withMessages(['rows' => 'Informe pelo menos uma alteração para revisar.']);
        }
        $request->session()->put($this->draftKey($storeId), [
            'actor' => $user->id, 'key' => (string) Str::uuid(), 'changes' => $changes,
        ]);

        return redirect()->route('catalog.batch', $storeId);
    }

    public function publish(Request $request, int $storeId, Inventory $access): RedirectResponse
    {
        $data = $request->validate(['key' => 'required|uuid', 'confirmed' => 'accepted']);
        $draft = $request->session()->get($this->draftKey($storeId));
        DB::transaction(function () use ($request, $storeId, $access, $data, $draft): void {
            $user = $access->member($request->user(), true);
            $this->store($user, $storeId, true);
            abort_unless($draft && $draft['actor'] === $user->id && $draft['key'] === $data['key'], 409, 'Prepare uma nova prévia para publicar.');
            $products = DB::table('products')->where('tenant_id', $user->tenant_id)->where('store_id', $storeId)
                ->whereIn('id', array_keys($draft['changes']))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($draft['changes'] as $id => $change) {
                $product = $products->get($id);
                abort_unless($product && hash_equals($change['version'], self::version($product)), 409, 'O cardápio mudou desde a prévia. Revise um novo lote.');
                if (isset($change['after']['category_id'])) {
                    $name = DB::table('catalog_categories')->where('store_id', $storeId)->where('id', $change['after']['category_id'])->value('name');
                    abort_unless($name === $change['after']['category'], 409, 'Uma categoria mudou. Revise um novo lote.');
                }
                DB::table('products')->where('id', $id)->update($change['after'] + ['updated_at' => now()]);
            }
            Audit::record('catalog.batch_published', [
                'store_id' => $storeId, 'batch_key' => $draft['key'], 'changes' => $draft['changes'],
            ], $user->tenant_id);
        }, 3);
        $request->session()->forget($this->draftKey($storeId));

        return redirect()->route('catalog.batch', $storeId)->with('success', 'Alterações publicadas no cardápio.');
    }

    public function discard(Request $request, int $storeId, Inventory $access): RedirectResponse
    {
        $user = $access->member($request->user());
        $this->store($user, $storeId);
        $request->session()->forget($this->draftKey($storeId));

        return redirect()->route('catalog.batch', $storeId)->with('success', 'Prévia descartada.');
    }
}
