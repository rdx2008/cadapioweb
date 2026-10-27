<?php

namespace App\Http\Controllers;

use App\Services\DeliveryCoverage;
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

        return view('catalog.product', compact('product', 'groups', 'options'));
    }

    public function updateProduct(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        MoneyInput::normalize($request, ['price' => 'price_cents']);
        $data = $request->validate(['name' => 'required|string|max:120', 'category' => 'required|string|max:80', 'description' => 'nullable|string|max:1000', 'price_cents' => 'required|integer|min:1|max:1000000', 'available' => 'required|boolean', 'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048']);
        unset($data['photo']);
        $path = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('tenants/'.$product->tenant_id.'/products', 'local');
            $data['photo_path'] = $path;
        }
        try {
            DB::transaction(function () use ($product, $data) {
                DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->first();
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
        $data = $request->validate(['group_id' => 'nullable|integer', 'name' => 'required|string|max:100', 'minimum' => 'required|integer|min:0|max:20', 'maximum' => 'required|integer|min:1|max:20|gte:minimum']);
        DB::transaction(function () use ($product, $data) {
            DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->first();
            $groupId = $data['group_id'] ?? null;
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
