<?php

namespace App\Http\Controllers;

use App\Services\CheckoutQuote;
use App\Services\ComboCatalog;
use App\Services\DeliveryCoverage;
use App\Services\OnlinePayment;
use App\Services\PizzaPricing;
use App\Services\StoreAvailability;
use App\Services\TenantState;
use App\Support\MoneyInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MenuController extends Controller
{
    private function store(string $slug): object
    {
        $store = DB::table('stores')->where('slug', $slug)->firstOrFail();
        abort_unless(app(TenantState::class)->active($store->tenant_id), 404, 'Este cardápio está indisponível.');

        return $store;
    }

    public function menu(Request $request, string $slug, StoreAvailability $availability): View
    {
        $store = $this->store($slug);
        if ($request->filled('edit')) {
            $draft = $this->draft($request, $store, $request->string('edit')->toString());
            $request->session()->flashInput($draft['data']);
        }
        $products = DB::table('products')->leftJoin('catalog_categories', function ($join) {
            $join->on('catalog_categories.id', '=', 'products.category_id')->on('catalog_categories.store_id', '=', 'products.store_id');
        })->where('products.tenant_id', $store->tenant_id)->where('products.store_id', $store->id)->where('products.available', true)->select('products.*')->orderByRaw('COALESCE(catalog_categories.sort_order, 9999)')->orderBy('products.category')->orderBy('products.sort_order')->orderBy('products.name')->get();
        $variants = DB::table('product_variants')->whereIn('product_id', $products->pluck('id'))->where('available', true)->orderBy('price_cents')->orderBy('id')->get()->groupBy('product_id');
        $flavors = DB::table('pizza_flavors')->whereIn('product_id', $products->pluck('id'))->where('available', true)->orderBy('name')->get()->groupBy('product_id');
        $combos = [];
        $products = $products->filter(function ($product) use ($variants, $flavors, &$combos): bool {
            if ($product->product_type === 'combo') {
                $combos[$product->id] = app(ComboCatalog::class)->data($product);

                return ! $product->uses_variants && ! $product->pizza_enabled && $combos[$product->id]['ready'];
            }
            if (! $product->uses_variants) {
                return true;
            }
            $available = $variants->get($product->id, collect());
            if ($product->pizza_enabled) {
                $available = app(PizzaPricing::class)->availableVariants($available, $flavors->get($product->id, collect()));
                $variants->put($product->id, $available);
            }
            if ($available->isEmpty()) {
                return false;
            }
            $product->price_cents = $available->min('price_cents');

            return true;
        });
        $groups = DB::table('option_groups')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get();
        $groups = $groups->filter(fn ($group) => $group->kind !== 'border' || $products->firstWhere('id', $group->product_id)?->product_type === 'pizza');
        $options = DB::table('product_options')->whereIn('option_group_id', $groups->pluck('id'))->where('available', true)->orderBy('id')->get()->groupBy('option_group_id');
        $zones = DB::table('delivery_zones')->where('store_id', $store->id)->where('enabled', true)->orderBy('name')->get();

        $deliveryMode = app(DeliveryCoverage::class)->mode($store);
        $hasDelivery = $deliveryMode === 'radius'
            ? ($store->delivery_pricing !== 'bands' || $zones->contains(fn ($zone) => $zone->radius_meters !== null))
            : $zones->contains(fn ($zone) => $zone->radius_meters === null && $zone->city && $zone->state_code);

        return view('menu', ['store' => $store, 'products' => $products, 'groups' => $groups->groupBy('product_id'), 'options' => $options, 'variants' => $variants, 'flavors' => $flavors, 'combos' => $combos, 'zones' => $zones, 'hasDelivery' => $hasDelivery, 'isOpen' => $availability->isOpen($store)]);
    }

    public function review(Request $request, string $slug, CheckoutQuote $calculator): RedirectResponse
    {
        MoneyInput::normalize($request, ['change_for' => 'change_for_cents']);
        $store = $this->store($slug);
        if ($request->has('cart_lines') && ($request->has('items') || $request->has('choices') || $request->has('variants'))) {
            throw ValidationException::withMessages(['cart' => 'Formato de sacola inválido. Recarregue o cardápio e revise os itens.']);
        }
        if ($request->input('mode') === 'delivery' && $request->has('address_fields')) {
            $fields = $this->addressFields($request);
            $request->merge(['address' => $this->formatAddress($fields)]);
        }
        $data = $request->validate([
            'customer_name' => 'required|string|max:120', 'phone' => 'required|string|min:8|max:25',
            'scheduled_for' => 'nullable|string|date_format:Y-m-d\TH:i',
            'payment_method' => 'sometimes|required|in:cash,card,pix,online_infinitepay,online_pagbank',
            'change_for_cents' => 'nullable|integer|min:1|max:100000000',
            'mode' => 'required|in:pickup,delivery', 'address' => 'required_if:mode,delivery|nullable|string|max:500',
            'delivery_zone_id' => 'nullable|integer',
            'delivery_token' => 'nullable|string|max:5000',
            'address_complement' => 'nullable|string|max:120',
            'address_fields' => 'sometimes|array:street,number,neighborhood,city,state,postcode',
            'items' => 'nullable|required_without:cart_lines|array|min:1|max:500', 'items.*' => 'required|integer|min:0|max:50',
            'cart_lines' => 'nullable|required_without:items|array|min:1|max:50',
            'cart_lines.*' => 'array:product_id,quantity,variant_id,choices,flavors,combo_choices',
            'cart_lines.*.product_id' => 'required|integer|min:1',
            'cart_lines.*.quantity' => 'required|integer|min:1|max:50',
            'cart_lines.*.variant_id' => 'nullable|integer|min:1',
            'cart_lines.*.flavors' => 'sometimes|array|max:4',
            'cart_lines.*.flavors.*' => 'integer|min:1',
            'cart_lines.*.combo_choices' => 'sometimes|array|max:10',
            'cart_lines.*.combo_choices.*' => 'array|max:10',
            'cart_lines.*.combo_choices.*.*' => 'integer|min:1',
            'cart_lines.*.choices' => 'sometimes|array|max:20',
            'cart_lines.*.choices.*' => 'array|max:20',
            'cart_lines.*.choices.*.*' => 'integer|min:1',
            'choices' => 'nullable|array|max:50', 'choices.*' => 'array|max:20', 'choices.*.*' => 'array|max:20', 'choices.*.*.*' => 'integer|min:1',
            'variants' => 'nullable|array|max:500', 'variants.*' => 'nullable|integer|min:1',
            'idempotency_key' => 'required|uuid',
        ]);
        $existing = DB::table('orders')->where('store_id', $store->id)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return redirect('/pedido/'.$existing->public_id);
        }
        $quote = $calculator->calculate($store, $data);
        $request->session()->put('checkout.'.$data['idempotency_key'], ['store_id' => $store->id, 'data' => $data, 'quote' => $quote, 'created_at' => now()->timestamp]);

        return redirect('/cardapio/'.$slug.'/review/'.$data['idempotency_key']);
    }

    public function reviewPage(Request $request, string $slug, string $draftId): View
    {
        $store = $this->store($slug);
        $draft = $this->draft($request, $store, $draftId);

        return view('checkout-review', ['store' => $store, 'draft' => $draft, 'draftId' => $draftId]);
    }

    public function delivery(Request $request, string $slug, DeliveryCoverage $coverage): JsonResponse
    {
        $store = $this->store($slug);
        $fields = $request->has('address_fields') ? $this->addressFields($request) : null;
        $address = $fields ? $this->formatAddress($fields) : $request->validate(['address' => 'required|string|min:12|max:300'])['address'];

        return response()->json(['choices' => $coverage->choices($store, $address, $fields)])->header('Cache-Control', 'no-store');
    }

    private function addressFields(Request $request): array
    {
        $data = $request->validate([
            'address_fields' => 'required|array:street,number,neighborhood,city,state,postcode',
            'address_fields.street' => 'required|string|max:120',
            'address_fields.number' => 'required|string|max:15|regex:/^[0-9]+[A-Za-z0-9 \/-]*$/',
            'address_fields.neighborhood' => 'required|string|max:100',
            'address_fields.city' => 'required|string|max:100',
            'address_fields.state' => 'required|string|size:2|regex:/^[A-Za-z]{2}$/',
            'address_fields.postcode' => 'required|string|regex:/^[0-9]{5}-?[0-9]{3}$/',
        ]);

        return $data['address_fields'];
    }

    public function postcode(Request $request, string $slug): JsonResponse
    {
        $this->store($slug);
        $data = $request->validate(['postcode' => 'required|string|regex:/^[0-9]{5}-?[0-9]{3}$/']);
        $postcode = str_replace('-', '', $data['postcode']);
        $address = Cache::remember('postcode:br:'.$postcode, now()->addDays(7), function () use ($postcode): array {
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(6)->withoutRedirecting()->get('https://viacep.com.br/ws/'.$postcode.'/json/');
            } catch (ConnectionException) {
                throw ValidationException::withMessages(['postcode' => 'A busca de CEP está indisponível. Tente novamente ou preencha o endereço manualmente.']);
            }
            if (! $response->successful() || ! is_array($response->json())) {
                throw ValidationException::withMessages(['postcode' => 'Não foi possível consultar o CEP. Tente novamente ou preencha o endereço manualmente.']);
            }
            $body = $response->json();
            if (! empty($body['erro'])) {
                throw ValidationException::withMessages(['postcode' => 'CEP não encontrado. Confira os números ou preencha o endereço manualmente.']);
            }
            if (! is_string($body['cep'] ?? null) || ! is_string($body['localidade'] ?? null) || ! is_string($body['uf'] ?? null)
                || ! is_string($body['logradouro'] ?? '') || ! is_string($body['bairro'] ?? '')
                || preg_replace('/\D/', '', $body['cep']) !== $postcode || $body['localidade'] === '' || ! preg_match('/^[A-Z]{2}$/', $body['uf'])) {
                throw ValidationException::withMessages(['postcode' => 'O serviço retornou um endereço incompleto. Tente novamente ou preencha manualmente.']);
            }

            return ['street' => mb_substr((string) ($body['logradouro'] ?? ''), 0, 120), 'neighborhood' => mb_substr((string) ($body['bairro'] ?? ''), 0, 100), 'city' => mb_substr((string) $body['localidade'], 0, 100), 'state' => $body['uf']];
        });

        return response()->json(['address' => $address])->header('Cache-Control', 'no-store');
    }

    private function formatAddress(array $fields): string
    {
        return $fields['street'].', '.$fields['number'].' - '.$fields['neighborhood'].', '.$fields['city'].' - '.strtoupper($fields['state'])
            .(! empty($fields['postcode']) ? ', '.preg_replace('/\D/', '', $fields['postcode']) : '');
    }

    private function draft(Request $request, object $store, string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $draft = $request->session()->get('checkout.'.$id);
        abort_unless(is_array($draft) && $draft['store_id'] === $store->id, 404);
        if ($draft['created_at'] < now()->subMinutes(15)->timestamp) {
            throw ValidationException::withMessages(['cart' => 'A revisão expirou. Revise seu pedido novamente.'])->redirectTo('/cardapio/'.$store->slug);
        }

        return $draft;
    }

    public function checkout(Request $request, string $slug, CheckoutQuote $calculator): RedirectResponse
    {
        $store = $this->store($slug);
        $data = $request->validate(['draft_id' => 'required|uuid']);
        $draft = $this->draft($request, $store, $data['draft_id']);
        try {
            $public = DB::transaction(function () use ($store, $data, $draft, $calculator) {
                DB::table('tenants')->where('id', $store->tenant_id)->lockForUpdate()->firstOrFail();
                $current = DB::table('stores')->where('id', $store->id)->lockForUpdate()->first();
                $existing = DB::table('orders')->where('store_id', $store->id)->where('idempotency_key', $data['draft_id'])->first();
                if ($existing) {
                    return $existing->public_id;
                }
                $quote = $calculator->calculate($current, $draft['data']);
                if (! hash_equals($calculator->fingerprint($draft['quote']), $calculator->fingerprint($quote))) {
                    throw ValidationException::withMessages(['cart' => 'O cardápio ou frete mudou. Revise os novos valores antes de confirmar.']);
                }
                $public = (string) Str::uuid();
                $id = DB::table('orders')->insertGetId([
                    'public_id' => $public, 'tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'idempotency_key' => $data['draft_id'],
                    'scheduled_at' => $quote['scheduled_at'], 'prepare_at' => $quote['prepare_at'], 'scheduled_timezone' => $quote['scheduled_timezone'],
                    'payment_method' => $quote['payment_method'], 'change_for_cents' => $quote['change_for_cents'],
                    'customer_name' => $draft['data']['customer_name'], 'phone' => $draft['data']['phone'], 'mode' => $draft['data']['mode'],
                    'address' => $quote['delivery_address'],
                    'delivery_zone_name' => $quote['delivery_zone_name'], 'subtotal_cents' => $quote['subtotal_cents'],
                    'fee_cents' => $quote['fee_cents'], 'total_cents' => $quote['total_cents'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($quote['items'] as $item) {
                    $item['options'] = json_encode($item['options'], JSON_THROW_ON_ERROR);
                    DB::table('order_items')->insert($item + ['order_id' => $id]);
                }
                app(OnlinePayment::class)->reserve(DB::table('orders')->where('id', $id)->firstOrFail());

                return $public;
            }, 3);
        } catch (ValidationException $exception) {
            $request->session()->flashInput($draft['data']);
            throw $exception->redirectTo('/cardapio/'.$slug);
        }

        $order = DB::table('orders')->where('public_id', $public)->firstOrFail();
        if (app(OnlinePayment::class)->isOnline($order)) {
            $url = app(OnlinePayment::class)->start($order);
            if ($url) {
                return redirect()->away($url);
            }
        }

        return redirect('/pedido/'.$public);
    }

    public function tracking(string $id): View
    {
        $order = DB::table('orders')->where('public_id', $id)->firstOrFail();

        return view('tracking', ['store' => DB::table('stores')->find($order->store_id), 'order' => $order, 'items' => DB::table('order_items')->where('order_id', $order->id)->get(), 'ride' => DB::table('rides')->where('order_id', $order->id)->first()]);
    }

    public function status(string $id): JsonResponse
    {
        $order = DB::table('orders')->where('public_id', $id)->firstOrFail();
        $ride = DB::table('rides')->where('order_id', $order->id)->value('status');

        return response()->json(['status' => $order->status, 'payment_status' => $order->payment_status, 'payment_online' => app(OnlinePayment::class)->isOnline($order), 'ride_status' => $ride, 'cancellation_reason' => $order->cancellation_reason])->header('Cache-Control', 'no-store');
    }
}
