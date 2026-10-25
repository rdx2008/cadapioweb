<?php

namespace App\Http\Controllers;

use App\Services\CheckoutQuote;
use App\Services\DeliveryCoverage;
use App\Services\StoreAvailability;
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
        return DB::table('stores')->where('slug', $slug)->firstOrFail();
    }

    public function menu(Request $request, string $slug, StoreAvailability $availability): View
    {
        $store = $this->store($slug);
        if ($request->filled('edit')) {
            $draft = $this->draft($request, $store, $request->string('edit')->toString());
            $request->session()->flashInput($draft['data']);
        }
        $products = DB::table('products')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('available', true)->orderBy('category')->orderBy('name')->get();
        $groups = DB::table('option_groups')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get();
        $options = DB::table('product_options')->whereIn('option_group_id', $groups->pluck('id'))->where('available', true)->orderBy('id')->get()->groupBy('option_group_id');
        $zones = DB::table('delivery_zones')->where('store_id', $store->id)->where('enabled', true)->orderBy('name')->get();

        $deliveryMode = app(DeliveryCoverage::class)->mode($store);
        $hasDelivery = $deliveryMode === 'radius'
            ? ($store->delivery_pricing !== 'bands' || $zones->contains(fn ($zone) => $zone->radius_meters !== null))
            : $zones->contains(fn ($zone) => $zone->radius_meters === null && $zone->city && $zone->state_code);

        return view('menu', ['store' => $store, 'products' => $products, 'groups' => $groups->groupBy('product_id'), 'options' => $options, 'zones' => $zones, 'hasDelivery' => $hasDelivery, 'isOpen' => $availability->isOpen($store)]);
    }

    public function review(Request $request, string $slug, CheckoutQuote $calculator): RedirectResponse
    {
        $store = $this->store($slug);
        if ($request->input('mode') === 'delivery' && $request->has('address_fields')) {
            $fields = $this->addressFields($request);
            $request->merge(['address' => $this->formatAddress($fields)]);
        }
        $data = $request->validate([
            'customer_name' => 'required|string|max:120', 'phone' => 'required|string|min:8|max:25',
            'mode' => 'required|in:pickup,delivery', 'address' => 'required_if:mode,delivery|nullable|string|max:500',
            'delivery_zone_id' => 'nullable|integer',
            'delivery_token' => 'nullable|string|max:5000',
            'address_complement' => 'nullable|string|max:120',
            'address_fields' => 'sometimes|array:street,number,neighborhood,city,state,postcode',
            'items' => 'required|array|min:1|max:500', 'items.*' => 'required|integer|min:0|max:50',
            'choices' => 'nullable|array|max:50', 'choices.*' => 'array|max:20', 'choices.*.*' => 'array|max:20', 'choices.*.*.*' => 'integer|min:1',
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
                    'customer_name' => $draft['data']['customer_name'], 'phone' => $draft['data']['phone'], 'mode' => $draft['data']['mode'],
                    'address' => $quote['delivery_address'],
                    'delivery_zone_name' => $quote['delivery_zone_name'], 'subtotal_cents' => $quote['subtotal_cents'],
                    'fee_cents' => $quote['fee_cents'], 'total_cents' => $quote['total_cents'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($quote['items'] as $item) {
                    $item['options'] = json_encode($item['options'], JSON_THROW_ON_ERROR);
                    DB::table('order_items')->insert($item + ['order_id' => $id]);
                }

                return $public;
            }, 3);
        } catch (ValidationException $exception) {
            $request->session()->flashInput($draft['data']);
            throw $exception->redirectTo('/cardapio/'.$slug);
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

        return response()->json(['status' => $order->status, 'payment_status' => $order->payment_status, 'ride_status' => $ride, 'cancellation_reason' => $order->cancellation_reason])->header('Cache-Control', 'no-store');
    }
}
