<?php

namespace App\Http\Controllers;

use App\Services\DeliveryCoverage;
use App\Services\PizzaPricing;
use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'category_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100', 'availability' => 'nullable|in:all,active,paused,featured']);
        $stores = DB::table('stores')->where('tenant_id', auth()->user()->tenant_id)->orderBy('name')->get();
        $selectedStore = ! empty($filters['store_id']) ? $this->store((int) $filters['store_id']) : $stores->first();
        $categories = DB::table('catalog_categories')->where('store_id', $selectedStore?->id ?? 0)->orderBy('sort_order')->orderBy('name')->get();
        if (! empty($filters['category_id'])) {
            abort_unless($categories->contains('id', (int) $filters['category_id']), 404);
        }
        $all = DB::table('products')->where('products.tenant_id', auth()->user()->tenant_id)->where('products.store_id', $selectedStore?->id ?? 0);
        $counts = ['total' => (clone $all)->count(), 'active' => (clone $all)->where('available', true)->count(), 'paused' => (clone $all)->where('available', false)->count()];
        $products = (clone $all)->leftJoin('catalog_categories', function ($join) {
            $join->on('catalog_categories.id', '=', 'products.category_id')->on('catalog_categories.store_id', '=', 'products.store_id');
        })->select('products.*')
            ->selectSub(DB::table('product_variants')->selectRaw('min(price_cents)')->whereColumn('product_id', 'products.id')->where('available', true), 'variant_price')
            ->selectSub(DB::table('option_groups')->selectRaw('count(*)')->whereColumn('product_id', 'products.id'), 'group_count')
            ->when(! empty($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when(! empty($filters['q']), fn ($query) => $query->where('products.name', 'like', '%'.$filters['q'].'%'))
            ->when(($filters['availability'] ?? '') === 'active', fn ($query) => $query->where('available', true))
            ->when(($filters['availability'] ?? '') === 'paused', fn ($query) => $query->where('available', false))
            ->when(($filters['availability'] ?? '') === 'featured', fn ($query) => $query->where('featured', true))
            ->orderByRaw('COALESCE(catalog_categories.sort_order, 9999)')->orderBy('products.category')->orderBy('products.sort_order')->orderBy('products.name')->paginate(24)->withQueryString();
        $pizzaIds = $products->getCollection()->where('pizza_enabled', true)->pluck('id');
        $pizzaSizes = DB::table('product_variants')->whereIn('product_id', $pizzaIds)->where('available', true)->get()->groupBy('product_id');
        $pizzaFlavors = DB::table('pizza_flavors')->whereIn('product_id', $pizzaIds)->where('available', true)->get()->groupBy('product_id');
        $products->getCollection()->each(function ($product) use ($pizzaSizes, $pizzaFlavors): void {
            if ($product->pizza_enabled) {
                $product->variant_price = app(PizzaPricing::class)->availableVariants($pizzaSizes->get($product->id, collect()), $pizzaFlavors->get($product->id, collect()))->min('price_cents');
            }
        });

        $tenant = DB::table('tenants')->find(auth()->user()->tenant_id);

        return view('catalog.index', compact('stores', 'selectedStore', 'categories', 'counts', 'products', 'filters', 'tenant'));
    }

    public function create(Request $request): View
    {
        $request->validate(['store_id' => 'nullable|integer|min:1']);
        $stores = DB::table('stores')->where('tenant_id', auth()->user()->tenant_id)->orderBy('name')->get();
        $selectedStore = $request->filled('store_id') ? $this->store((int) $request->store_id) : $stores->first();
        $categories = DB::table('catalog_categories')->where('store_id', $selectedStore?->id ?? 0)->orderBy('sort_order')->orderBy('name')->get();

        $tenant = DB::table('tenants')->find(auth()->user()->tenant_id);

        return view('catalog.create', compact('stores', 'selectedStore', 'categories', 'tenant'));
    }

    public function category(Request $request): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer', 'category_id' => 'nullable|integer|min:1', 'name' => 'required|string|max:80', 'sort_order' => 'required|integer|min:0|max:9999']);
        $store = $this->store($data['store_id']);
        DB::transaction(function () use ($store, $data): void {
            DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
            $id = $data['category_id'] ?? null;
            if ($id) {
                DB::table('catalog_categories')->where('store_id', $store->id)->where('id', $id)->firstOrFail();
            }
            if (DB::table('catalog_categories')->where('store_id', $store->id)->where('name', $data['name'])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Esta loja já possui uma categoria com esse nome.']);
            }
            $values = ['name' => $data['name'], 'sort_order' => $data['sort_order'], 'updated_at' => now()];
            if ($id) {
                DB::table('catalog_categories')->where('id', $id)->update($values);
                DB::table('products')->where('store_id', $store->id)->where('category_id', $id)->update(['category' => $data['name'], 'updated_at' => now()]);
            } else {
                $id = DB::table('catalog_categories')->insertGetId($values + ['store_id' => $store->id, 'created_at' => now()]);
            }
            Audit::record('catalog.category.saved', ['store_id' => $store->id, 'category_id' => $id], $store->tenant_id);
        });

        return redirect(route('catalog.index', ['store_id' => $store->id]).'#categories')->with('success', 'Categoria salva. A ordem vale também no cardápio do cliente.');
    }

    private function resolveCategory(int $storeId, array $data): object
    {
        if (! empty($data['category_id'])) {
            return DB::table('catalog_categories')->where('store_id', $storeId)->where('id', $data['category_id'])->firstOrFail();
        }
        $name = trim($data['category'] ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['category_id' => 'Selecione ou cadastre uma categoria.']);
        }
        $category = DB::table('catalog_categories')->where('store_id', $storeId)->where('name', $name)->first();
        if ($category) {
            return $category;
        }
        $id = DB::table('catalog_categories')->insertGetId(['store_id' => $storeId, 'name' => $name, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('catalog_categories')->find($id);
    }

    public function createProduct(Request $request): RedirectResponse
    {
        MoneyInput::normalize($request, ['price' => 'price_cents']);
        $data = $request->validate(['store_id' => 'required|integer', 'name' => 'required|string|max:120', 'category_id' => 'nullable|integer|min:1', 'category' => 'nullable|string|max:80', 'description' => 'nullable|string|max:1000', 'price_cents' => 'nullable|required_unless:uses_variants,1|integer|min:1|max:1000000', 'available' => 'sometimes|boolean', 'featured' => 'sometimes|boolean', 'sort_order' => 'sometimes|integer|min:0|max:9999', 'uses_variants' => 'sometimes|boolean', 'new_variants' => 'nullable|array|max:20', 'new_variants.*' => 'array:name,price', 'new_variants.*.name' => 'required|string|max:80', 'new_variants.*.price' => ['required', 'regex:/^\d{1,5}(?:[.,]\d{1,2})?$/D'], 'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048']);
        $store = $this->store($data['store_id']);
        $variants = $data['new_variants'] ?? [];
        if (($data['uses_variants'] ?? false) && ! count($variants)) {
            throw ValidationException::withMessages(['new_variants' => 'Adicione pelo menos um tamanho com preço.']);
        }
        foreach ($variants as &$variant) {
            $parts = preg_split('/[.,]/', $variant['price']);
            $variant['price_cents'] = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            if ($variant['price_cents'] < 1 || $variant['price_cents'] > 1000000) {
                throw ValidationException::withMessages(['new_variants' => 'O preço de cada tamanho deve ser maior que zero e até R$ 10.000.']);
            }
            unset($variant['price']);
        }
        unset($variant);
        if ($data['uses_variants'] ?? false) {
            $data['price_cents'] = min(array_column($variants, 'price_cents'));
        }
        $path = $request->hasFile('photo') ? $request->file('photo')->store('tenants/'.$store->tenant_id.'/products', 'local') : null;
        unset($data['new_variants'], $data['photo']);
        try {
            $id = DB::transaction(function () use ($store, $data, $variants, $path): int {
                DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
                $category = $this->resolveCategory($store->id, $data);
                $data['category_id'] = $category->id;
                $data['category'] = $category->name;
                $id = DB::table('products')->insertGetId($data + ['tenant_id' => $store->tenant_id, 'photo_path' => $path, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($variants as $variant) {
                    DB::table('product_variants')->insert($variant + ['product_id' => $id, 'available' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                Audit::record('product.created', ['product_id' => $id, 'store_id' => $store->id], $store->tenant_id);

                return $id;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return $request->boolean('editor_flow') ? redirect('/products/'.$id.'/edit')->with('success', 'Produto salvo. Confira tamanhos e configure os adicionais abaixo.') : back()->with('success', 'Produto cadastrado.');
    }

    private function store(int $id): object
    {
        return DB::table('stores')->where('tenant_id', auth()->user()->tenant_id)->where('id', $id)->firstOrFail();
    }

    private function product(int $id): object
    {
        return DB::table('products')->where('tenant_id', auth()->user()->tenant_id)->where('id', $id)->firstOrFail();
    }

    public function editStore(int $id): View
    {
        $store = $this->store($id);

        return view('catalog.store', ['store' => $store, 'hours' => json_decode($store->weekly_hours ?? 'null', true), 'zones' => DB::table('delivery_zones')->where('store_id', $id)->orderBy('id')->get()]);
    }

    public function updateStore(Request $request, int $id): RedirectResponse
    {
        $store = $this->store($id);
        MoneyInput::normalize($request, ['minimum_order' => 'minimum_order_cents', 'base_fare' => 'base_cents', 'per_km_fare' => 'per_km_cents', 'minimum_fare' => 'minimum_cents']);
        $data = $request->validate([
            'name' => 'required|string|max:100', 'slug' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('stores')->ignore($id)],
            'address' => 'required|string|max:240', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'open' => 'required|boolean', 'timezone' => ['required', Rule::in(timezone_identifiers_list())], 'minimum_order_cents' => 'required|integer|min:0|max:1000000',
            'use_hours' => 'required|boolean', 'hours' => 'required|array:0,1,2,3,4,5,6|size:7', 'hours.*.enabled' => 'required|boolean',
            'hours.*.opens_at' => 'required|date_format:H:i', 'hours.*.closes_at' => 'required|date_format:H:i',
            'hours.*.second_opens_at' => 'nullable|required_with:hours.*.second_closes_at|date_format:H:i',
            'hours.*.second_closes_at' => 'nullable|required_with:hours.*.second_opens_at|date_format:H:i',
            'delivery_by_radius' => 'sometimes|boolean',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'cover' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'rate_mode' => 'required|in:fixed,per_km,per_ride', 'base_cents' => 'required|integer|min:0|max:100000',
            'per_km_cents' => 'required|integer|min:0|max:100000', 'minimum_cents' => 'required|integer|min:0|max:100000',
        ]);
        ksort($data['hours']);
        $data['weekly_hours'] = $data['use_hours'] ? json_encode(array_values($data['hours']), JSON_THROW_ON_ERROR) : null;
        unset($data['hours'], $data['use_hours']);
        unset($data['logo'], $data['cover']);
        $uploads = [];
        foreach (['logo', 'cover'] as $kind) {
            if ($request->hasFile($kind)) {
                $uploads[] = $data[$kind.'_path'] = $request->file($kind)->store('tenants/'.$store->tenant_id.'/stores', 'local');
            }
        }
        try {
            DB::transaction(function () use ($store, $data) {
                DB::table('stores')->where('id', $store->id)->lockForUpdate()->first();
                DB::table('stores')->where('id', $store->id)->update($data + ['updated_at' => now()]);
                Audit::record('store.updated', ['store_id' => $store->id], $store->tenant_id);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($uploads);
            throw $exception;
        }

        return back()->with('success', 'Loja e horários atualizados.');
    }

    public function availability(Request $request, int $id): RedirectResponse
    {
        $store = $this->store($id);
        $data = $request->validate(['open' => 'required|boolean']);
        DB::transaction(function () use ($store, $data) {
            DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
            DB::table('stores')->where('id', $store->id)->update(['open' => $data['open'], 'updated_at' => now()]);
            Audit::record('store.availability', ['store_id' => $store->id, 'open' => (bool) $data['open']], $store->tenant_id);
        });

        return back()->with('success', $data['open'] ? 'Pedidos habilitados conforme os horários da loja.' : 'Loja pausada para novos pedidos.');
    }

    public function specialHours(Request $request, int $id): RedirectResponse
    {
        $store = $this->store($id);
        $data = $request->validate([
            'special_date' => 'required|date_format:Y-m-d',
            'enabled' => 'required|boolean',
            'opens_at' => 'nullable|required_if:enabled,1|date_format:H:i',
            'closes_at' => 'nullable|required_if:enabled,1|date_format:H:i',
            'second_opens_at' => 'nullable|required_with:second_closes_at|date_format:H:i',
            'second_closes_at' => 'nullable|required_with:second_opens_at|date_format:H:i',
        ]);
        DB::transaction(function () use ($store, $data): void {
            $locked = DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
            $exceptions = json_decode($locked->special_hours ?? '{}', true);
            $date = $data['special_date'];
            if (! isset($exceptions[$date]) && count($exceptions) >= 366) {
                throw ValidationException::withMessages(['special_date' => 'Limite de 366 datas especiais. Remova datas antigas para cadastrar outras.']);
            }
            $exceptions[$date] = $data['enabled'] ? [
                'enabled' => true, 'opens_at' => $data['opens_at'], 'closes_at' => $data['closes_at'],
                'second_opens_at' => $data['second_opens_at'] ?? null, 'second_closes_at' => $data['second_closes_at'] ?? null,
            ] : ['enabled' => false];
            ksort($exceptions);
            DB::table('stores')->where('id', $store->id)->update(['special_hours' => json_encode($exceptions, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            Audit::record('store.special_hours.saved', ['store_id' => $store->id, 'date' => $date, 'enabled' => (bool) $data['enabled']], $store->tenant_id);
        });

        return redirect(route('stores.edit', $id).'#special-hours')->with('success', 'Data especial salva. A pausa manual continua prevalecendo.');
    }

    public function removeSpecialHours(Request $request, int $id): RedirectResponse
    {
        $store = $this->store($id);
        $data = $request->validate(['special_date' => 'required|date_format:Y-m-d']);
        DB::transaction(function () use ($store, $data): void {
            $locked = DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
            $exceptions = json_decode($locked->special_hours ?? '{}', true);
            unset($exceptions[$data['special_date']]);
            DB::table('stores')->where('id', $store->id)->update(['special_hours' => $exceptions ? json_encode($exceptions, JSON_THROW_ON_ERROR) : null, 'updated_at' => now()]);
            Audit::record('store.special_hours.removed', ['store_id' => $store->id, 'date' => $data['special_date']], $store->tenant_id);
        });

        return redirect(route('stores.edit', $id).'#special-hours')->with('success', 'Exceção removida. A data volta a seguir a configuração semanal.');
    }

    public function zone(Request $request, int $storeId): RedirectResponse
    {
        $store = $this->store($storeId);
        MoneyInput::normalize($request, ['fee' => 'fee_cents']);
        $data = $request->validate(['zone_id' => 'nullable|integer', 'name' => 'required|string|max:120', 'fee_cents' => 'required|integer|min:0|max:100000', 'enabled' => 'required|boolean']);
        $radius = $request->validate(['radius_km' => 'nullable|numeric|min:0.1|max:100']);
        $area = $request->validate([
            'zone_kind' => 'sometimes|in:neighborhood,radius',
            'city' => [Rule::requiredIf($request->input('zone_kind') === 'neighborhood'), 'nullable', 'string', 'max:120'],
            'state_code' => [Rule::requiredIf($request->input('zone_kind') === 'neighborhood'), 'nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);
        if (($area['zone_kind'] ?? null) === 'radius') {
            $request->validate(['radius_km' => 'required|numeric|min:0.1|max:100']);
        }
        $data['radius_meters'] = isset($radius['radius_km']) ? (int) round($radius['radius_km'] * 1000) : null;
        if (($area['zone_kind'] ?? null) === 'neighborhood') {
            $data['radius_meters'] = null;
        }
        $data['city'] = $data['radius_meters'] ? null : ($area['city'] ?? null);
        $data['state_code'] = $data['radius_meters'] ? null : strtoupper($area['state_code'] ?? '');
        DB::transaction(function () use ($store, $data) {
            DB::table('stores')->where('id', $store->id)->lockForUpdate()->first();
            $zoneId = $data['zone_id'] ?? null;
            unset($data['zone_id']);
            if ($zoneId) {
                DB::table('delivery_zones')->where('store_id', $store->id)->where('id', $zoneId)->firstOrFail();
                DB::table('delivery_zones')->where('id', $zoneId)->update($data + ['updated_at' => now()]);
            } else {
                DB::table('delivery_zones')->insert($data + ['store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'Região de entrega salva.');
    }

    public function deliverySettings(Request $request, int $id): RedirectResponse
    {
        $store = $this->store($id);
        MoneyInput::normalize($request, ['delivery_fixed' => 'delivery_fixed_cents', 'delivery_base' => 'delivery_base_cents', 'delivery_per_km' => 'delivery_per_km_cents', 'delivery_minimum' => 'delivery_minimum_cents']);
        $pricedRadius = $request->input('delivery_mode') === 'radius' && $request->input('delivery_pricing') !== 'bands';
        $data = $request->validate([
            'delivery_mode' => 'required|in:neighborhood,radius',
            'delivery_pricing' => 'required_if:delivery_mode,radius|nullable|in:fixed,bands,per_km',
            'delivery_max_km' => [Rule::requiredIf($pricedRadius), 'nullable', 'numeric', 'min:0.1', 'max:100'],
            'delivery_fixed_cents' => [Rule::requiredIf($pricedRadius && $request->input('delivery_pricing') === 'fixed'), 'nullable', 'integer', 'min:0', 'max:100000'],
            'delivery_base_cents' => [Rule::requiredIf($pricedRadius && $request->input('delivery_pricing') === 'per_km'), 'nullable', 'integer', 'min:0', 'max:100000'],
            'delivery_per_km_cents' => [Rule::requiredIf($pricedRadius && $request->input('delivery_pricing') === 'per_km'), 'nullable', 'integer', 'min:0', 'max:100000'],
            'delivery_minimum_cents' => [Rule::requiredIf($pricedRadius && $request->input('delivery_pricing') === 'per_km'), 'nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        if (isset($data['delivery_max_km'])) {
            $data['delivery_max_meters'] = (int) round($data['delivery_max_km'] * 1000);
        }
        unset($data['delivery_max_km']);
        $data = array_filter($data, fn ($value) => $value !== null);
        $data['delivery_by_radius'] = $data['delivery_mode'] === 'radius';
        DB::transaction(function () use ($store, $data): void {
            DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
            DB::table('stores')->where('id', $store->id)->update($data + ['updated_at' => now()]);
            Audit::record('store.delivery_settings', ['store_id' => $store->id], $store->tenant_id);
        });

        return back()->with('success', 'Área de atendimento e cálculo de frete atualizados.');
    }

    public function editProduct(int $id): View
    {
        $product = $this->product($id);
        $groups = DB::table('option_groups')->where('product_id', $id)->orderBy('id')->get();
        $options = DB::table('product_options')->whereIn('option_group_id', $groups->pluck('id'))->orderBy('id')->get()->groupBy('option_group_id');

        $variants = DB::table('product_variants')->where('product_id', $id)->orderBy('id')->get();

        $categories = DB::table('catalog_categories')->where('store_id', $product->store_id)->orderBy('sort_order')->orderBy('name')->get();

        $tenant = DB::table('tenants')->find(auth()->user()->tenant_id);

        $flavors = DB::table('pizza_flavors')->where('product_id', $id)->orderBy('id')->get();

        return view('catalog.product', compact('product', 'groups', 'options', 'variants', 'categories', 'tenant', 'flavors'));
    }

    public function pizzaSettings(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        $data = $request->validate(['pizza_enabled' => 'required|boolean', 'pizza_pricing' => 'required|in:highest,average']);
        DB::transaction(function () use ($product, $data): void {
            DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->firstOrFail();
            $current = DB::table('products')->find($product->id);
            $variants = DB::table('product_variants')->where('product_id', $product->id)->where('available', true)->get();
            $flavors = DB::table('pizza_flavors')->where('product_id', $product->id)->where('available', true)->get();
            if ($data['pizza_enabled'] && (! $current->uses_variants || app(PizzaPricing::class)->availableVariants($variants, $flavors)->isEmpty())) {
                throw ValidationException::withMessages(['pizza_enabled' => 'Ative tamanhos e cadastre pelo menos um sabor com preço em um tamanho disponível.']);
            }
            DB::table('products')->where('id', $product->id)->update($data + ['updated_at' => now()]);
            Audit::record('product.pizza_settings', ['product_id' => $product->id], $product->tenant_id);
        });

        return back()->with('success', 'Regras da pizza atualizadas.');
    }

    public function flavor(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        $data = $request->validate(['flavor_id' => 'nullable|integer|min:1', 'name' => 'required|string|max:80', 'description' => 'nullable|string|max:240', 'available' => 'required|boolean', 'prices' => 'required|array|min:1|max:20', 'prices.*' => ['nullable', 'regex:/^\d{1,5}(?:[.,]\d{1,2})?$/D']]);
        DB::transaction(function () use ($product, $data): void {
            DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->firstOrFail();
            $variants = DB::table('product_variants')->where('product_id', $product->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (array_diff(array_map('strval', array_keys($data['prices'])), $variants)) {
                throw ValidationException::withMessages(['prices' => 'Tamanho inválido para este produto.']);
            }
            $prices = [];
            foreach ($data['prices'] as $variant => $price) {
                if ($price === null || $price === '') {
                    continue;
                }
                $parts = preg_split('/[.,]/', $price);
                $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
                if ($cents < 1 || $cents > 1000000) {
                    throw ValidationException::withMessages(['prices' => 'Informe preços maiores que zero e até R$ 10.000.']);
                }
                $prices[$variant] = $cents;
            }
            if (! $prices) {
                throw ValidationException::withMessages(['prices' => 'Informe preço para pelo menos um tamanho.']);
            }
            $flavorId = $data['flavor_id'] ?? null;
            unset($data['flavor_id']);
            $data['prices'] = json_encode($prices, JSON_THROW_ON_ERROR);
            if ($flavorId) {
                DB::table('pizza_flavors')->where('product_id', $product->id)->where('id', $flavorId)->firstOrFail();
                DB::table('pizza_flavors')->where('id', $flavorId)->update($data + ['updated_at' => now()]);
            } else {
                if (DB::table('pizza_flavors')->where('product_id', $product->id)->count() >= 100) {
                    throw ValidationException::withMessages(['name' => 'Limite de 100 sabores por produto. Edite um sabor existente.']);
                }
                $flavorId = DB::table('pizza_flavors')->insertGetId($data + ['product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('product.flavor.saved', ['product_id' => $product->id, 'flavor_id' => $flavorId], $product->tenant_id);
        });

        return back()->with('success', 'Sabor salvo. Preço vazio deixa o sabor indisponível naquele tamanho.');
    }

    public function pricingMode(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        $data = $request->validate(['uses_variants' => 'required|boolean']);
        DB::transaction(function () use ($product, $data): void {
            DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->firstOrFail();
            if (! $data['uses_variants'] && DB::table('products')->where('id', $product->id)->value('pizza_enabled')) {
                throw ValidationException::withMessages(['uses_variants' => 'Desative o modo pizza antes de voltar ao preço único.']);
            }
            if ($data['uses_variants'] && ! DB::table('product_variants')->where('product_id', $product->id)->where('available', true)->exists()) {
                throw ValidationException::withMessages(['uses_variants' => 'Cadastre pelo menos uma variação disponível antes de ativar este modo.']);
            }
            DB::table('products')->where('id', $product->id)->update($data + ['updated_at' => now()]);
            Audit::record('product.pricing_mode', ['product_id' => $product->id, 'uses_variants' => (bool) $data['uses_variants']], $product->tenant_id);
        });

        return back()->with('success', 'Modo de preço atualizado. Pedidos anteriores mantêm os valores.');
    }

    public function variant(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        MoneyInput::normalize($request, ['variant_price' => 'price_cents']);
        $data = $request->validate(['variant_id' => 'nullable|integer', 'name' => 'required|string|max:80', 'price_cents' => 'required|integer|min:1|max:1000000', 'available' => 'required|boolean', 'max_flavors' => 'sometimes|integer|min:1|max:4']);
        DB::transaction(function () use ($product, $data): void {
            DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->firstOrFail();
            $variantId = $data['variant_id'] ?? null;
            unset($data['variant_id']);
            if ($variantId) {
                DB::table('product_variants')->where('product_id', $product->id)->where('id', $variantId)->firstOrFail();
                DB::table('product_variants')->where('id', $variantId)->update($data + ['updated_at' => now()]);
            } else {
                if (DB::table('product_variants')->where('product_id', $product->id)->count() >= 20) {
                    throw ValidationException::withMessages(['name' => 'Limite de 20 variações por produto. Edite uma variação existente.']);
                }
                $variantId = DB::table('product_variants')->insertGetId($data + ['product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('products')->where('id', $product->id)->update(['updated_at' => now()]);
            Audit::record('product.variant.saved', ['product_id' => $product->id, 'variant_id' => $variantId], $product->tenant_id);
        });

        return back()->with('success', 'Variação salva. Se todas forem pausadas, o produto sai do cardápio enquanto usar preço por variação.');
    }

    public function updateProduct(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        MoneyInput::normalize($request, ['price' => 'price_cents']);
        $data = $request->validate(['name' => 'required|string|max:120', 'category' => 'nullable|string|max:80', 'category_id' => 'nullable|integer|min:1', 'featured' => 'sometimes|boolean', 'sort_order' => 'sometimes|integer|min:0|max:9999', 'description' => 'nullable|string|max:1000', 'price_cents' => 'required|integer|min:1|max:1000000', 'available' => 'required|boolean', 'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048']);
        unset($data['photo']);
        $path = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('tenants/'.$product->tenant_id.'/products', 'local');
            $data['photo_path'] = $path;
        }
        try {
            DB::transaction(function () use ($product, $data) {
                DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->first();
                $category = $this->resolveCategory($product->store_id, $data);
                $data['category_id'] = $category->id;
                $data['category'] = $category->name;
                DB::table('products')->where('id', $product->id)->update($data + ['updated_at' => now()]);
                Audit::record('product.updated', ['product_id' => $product->id], $product->tenant_id);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('success', 'Produto atualizado. Os pedidos anteriores mantêm seus valores.');
    }

    public function photo(int $id): StreamedResponse
    {
        $product = DB::table('products')->where('id', $id)->firstOrFail();
        abort_unless($product->photo_path && Storage::disk('local')->exists($product->photo_path), 404);

        return Storage::disk('local')->response($product->photo_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }

    public function branding(int $id, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['logo', 'cover'], true), 404);
        $store = DB::table('stores')->where('id', $id)->firstOrFail();
        $path = $store->{$kind.'_path'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }

    public function locate(Request $request, int $id, DeliveryCoverage $coverage): JsonResponse
    {
        $this->store($id);
        $data = $request->validate(['address' => 'required|string|min:12|max:300']);

        return response()->json(['choices' => $coverage->search($data['address'])])->header('Cache-Control', 'no-store');
    }

    public function group(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        $data = $request->validate(['group_id' => 'nullable|integer', 'name' => 'required|string|max:100', 'minimum' => 'required|integer|min:0|max:20', 'maximum' => 'required|integer|min:1|max:20|gte:minimum', 'kind' => 'sometimes|in:addon,border']);
        DB::transaction(function () use ($product, $data) {
            DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->first();
            $groupId = $data['group_id'] ?? null;
            $current = $groupId ? DB::table('option_groups')->where('product_id', $product->id)->where('id', $groupId)->firstOrFail() : null;
            $data['kind'] = $data['kind'] ?? $current?->kind ?? 'addon';
            if ($data['kind'] === 'border' && ($data['maximum'] != 1 || $data['minimum'] > 1)) {
                throw ValidationException::withMessages(['maximum' => 'Grupo de borda permite no máximo uma escolha. Use mínimo 0 (opcional) ou 1 (obrigatória).']);
            }
            if ($data['kind'] === 'border' && DB::table('option_groups')->where('product_id', $product->id)->where('kind', 'border')->when($groupId, fn ($query) => $query->where('id', '!=', $groupId))->exists()) {
                throw ValidationException::withMessages(['kind' => 'Este produto já possui um grupo de borda. Edite as opções desse grupo.']);
            }
            unset($data['group_id']);
            if ($groupId) {
                DB::table('option_groups')->where('product_id', $product->id)->where('id', $groupId)->firstOrFail();
                DB::table('option_groups')->where('id', $groupId)->update($data + ['updated_at' => now()]);
            } else {
                DB::table('option_groups')->insert($data + ['product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'Grupo salvo. Cadastre opções suficientes para atender ao mínimo.');
    }

    public function option(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        MoneyInput::normalize($request, ['price' => 'price_cents']);
        $data = $request->validate(['option_group_id' => 'required|integer', 'option_id' => 'nullable|integer', 'name' => 'required|string|max:120', 'price_cents' => 'required|integer|min:0|max:100000', 'available' => 'required|boolean']);
        DB::transaction(function () use ($product, $data) {
            DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->first();
            DB::table('option_groups')->where('product_id', $product->id)->where('id', $data['option_group_id'])->firstOrFail();
            $optionId = $data['option_id'] ?? null;
            unset($data['option_id']);
            if ($optionId) {
                DB::table('product_options')->where('option_group_id', $data['option_group_id'])->where('id', $optionId)->firstOrFail();
                DB::table('product_options')->where('id', $optionId)->update($data + ['updated_at' => now()]);
            } else {
                DB::table('product_options')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'Adicional salvo.');
    }
}
