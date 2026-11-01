<?php

namespace App\Http\Controllers;

use App\Services\CheckoutPayment;
use App\Services\CheckoutQuote;
use App\Services\DeliveryCoverage;
use App\Services\DispatchService;
use App\Services\FareCalculator;
use App\Services\MenuCatalog;
use App\Services\OnlinePayment;
use App\Services\TenantState;
use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    private function member(Request $request, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $request->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($user->access_enabled && $user->tenant_id && $user->tenant_id === $request->user()->tenant_id && ($user->role === 'owner' || ($user->role === 'staff' && $user->staff_profile === 'operator')), 403);
        if ($lock) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->firstOrFail();
        }
        abort_unless(app(TenantState::class)->active($user->tenant_id), 403);

        return $user;
    }

    private function stores(object $user): Builder
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->when($user->role === 'staff', fn ($q) => $q->whereIn('id', DB::table('user_store_access')->where('user_id', $user->id)->where('tenant_id', $user->tenant_id)->select('store_id')));
    }

    private function store(Request $request, int $id, bool $lock = false): object
    {
        return $this->stores($this->member($request, $lock))->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    private function detail(Request $request, int $id): string
    {
        return $request->user()->role === 'owner' ? url('/orders/'.$id) : url('/operation/orders/'.$id);
    }

    public function index(Request $request): Response
    {
        return response()->view('pos.index', ['stores' => $this->stores($this->member($request))->orderBy('name')->get()])->header('Cache-Control', 'private, no-store');
    }

    public function sale(Request $request, int $id): Response
    {
        $store = $this->store($request, $id);

        return response()->view('pos.sale', app(MenuCatalog::class)->data($store) + ['store' => $store, 'methods' => app(CheckoutPayment::class)->methods($store), 'drivers' => $this->drivers($id), 'stores' => $this->stores($this->member($request))->orderBy('name')->get()])->header('Cache-Control', 'private, no-store');
    }

    private function drivers(int $storeId): Collection
    {
        return DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')->where('drivers.checkin_store_id', $storeId)->where('drivers.status', 'approved')->where('drivers.available', true)->where('users.access_enabled', true)->where('users.role', 'courier')->select('drivers.id', 'users.name')->orderBy('users.name')->get();
    }

    private function preferred(object $store, ?int $driverId): void
    {
        if ($driverId) {
            if (! DB::table('tenants')->where('id', $store->tenant_id)->where('courier_module', 'active')->exists() || ! $this->drivers($store->id)->contains('id', $driverId)) {
                throw ValidationException::withMessages(['preferred_driver_id' => 'O motoboy selecionado não está disponível nesta loja. Escolha outro ou a fila automática.']);
            }
        }
    }

    public function postcode(Request $request, int $id): JsonResponse
    {
        $store = $this->store($request, $id);

        return app(MenuController::class)->postcode($request, $store->slug);
    }

    public function delivery(Request $request, int $id): JsonResponse
    {
        $store = $this->store($request, $id);

        return app(MenuController::class)->delivery($request, $store->slug, app(DeliveryCoverage::class));
    }

    public function review(Request $request, int $id, CheckoutQuote $calculator): RedirectResponse
    {
        $store = $this->store($request, $id);
        MoneyInput::normalize($request, ['change_for' => 'change_for_cents']);
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120', 'phone' => 'nullable|string|min:8|max:25',
            'payment_method' => 'required|in:cash,card,pix,online_infinitepay,online_pagbank', 'received' => 'required|in:yes,no',
            'mode' => 'sometimes|required|in:pickup,delivery', 'pos_channel' => 'sometimes|required|in:counter,phone,whatsapp',
            'preferred_driver_id' => 'nullable|integer|min:1',
            'address' => 'nullable|string|max:500', 'address_complement' => 'nullable|string|max:120', 'delivery_token' => 'nullable|string|max:5000',
            'address_fields' => 'nullable|array:street,number,neighborhood,city,state,postcode',
            'address_fields.street' => 'required_if:mode,delivery|string|max:120',
            'address_fields.number' => 'required_if:mode,delivery|string|max:15|regex:/^[0-9]+[A-Za-z0-9 \/-]*$/',
            'address_fields.neighborhood' => 'required_if:mode,delivery|string|max:100',
            'address_fields.city' => 'required_if:mode,delivery|string|max:100',
            'address_fields.state' => 'required_if:mode,delivery|string|size:2|regex:/^[A-Za-z]{2}$/',
            'address_fields.postcode' => 'required_if:mode,delivery|string|regex:/^[0-9]{5}-?[0-9]{3}$/',
            'change_for_cents' => 'nullable|integer|min:1|max:100000000', 'idempotency_key' => 'required|uuid',
            'cart_lines' => 'required|array|min:1|max:50',
            'cart_lines.*' => 'array:product_id,quantity,variant_id,choices,flavors,combo_choices',
            'cart_lines.*.product_id' => 'required|integer|min:1', 'cart_lines.*.quantity' => 'required|integer|min:1|max:50',
            'cart_lines.*.variant_id' => 'nullable|integer|min:1',
            'cart_lines.*.flavors' => 'sometimes|array|max:4', 'cart_lines.*.flavors.*' => 'integer|min:1',
            'cart_lines.*.combo_choices' => 'sometimes|array|max:10', 'cart_lines.*.combo_choices.*' => 'array|max:10', 'cart_lines.*.combo_choices.*.*' => 'integer|min:1',
            'cart_lines.*.choices' => 'sometimes|array|max:20', 'cart_lines.*.choices.*' => 'array|max:99', 'cart_lines.*.choices.*.*' => 'integer|min:1',
        ]);
        $data['customer_name'] = trim($data['customer_name'] ?? '') ?: 'Cliente de balcão';
        $data['phone'] = $data['phone'] ?? '';
        $data['mode'] ??= 'pickup';
        $data['pos_channel'] ??= 'counter';
        if ($data['mode'] === 'delivery') {
            $request->validate(['customer_name' => 'required|string|max:120', 'phone' => 'required|string|min:8|max:25']);
            $fields = $data['address_fields'];
            $data['address'] = $fields['street'].', '.$fields['number'].' - '.$fields['neighborhood'].', '.$fields['city'].' - '.strtoupper($fields['state']).', '.preg_replace('/\D/', '', $fields['postcode']);
            $this->preferred($store, $data['preferred_driver_id'] ?? null);
        } else {
            $data['preferred_driver_id'] = null;
        }
        if (str_starts_with($data['payment_method'], 'online_')) {
            $data['received'] = 'no';
        }
        $quote = $calculator->calculate($store, $data, true);
        $request->session()->put('pos.'.$data['idempotency_key'], ['store_id' => $id, 'user_id' => $request->user()->id, 'data' => $data, 'quote' => $quote, 'created_at' => now()->timestamp]);

        return redirect()->route('pos.review', [$id, $data['idempotency_key']]);
    }

    private function draft(Request $request, int $id, string $key): array
    {
        $draft = $request->session()->get('pos.'.$key);
        abort_unless($draft && $draft['store_id'] === $id && $draft['user_id'] === $request->user()->id && $draft['created_at'] >= now()->subMinutes(30)->timestamp, 419);

        return $draft;
    }

    public function preview(Request $request, int $id, string $key): Response
    {
        $store = $this->store($request, $id);

        return response()->view('pos.review', ['store' => $store, 'key' => $key, 'draft' => $this->draft($request, $id, $key)])->header('Cache-Control', 'private, no-store');
    }

    public function edit(Request $request, int $id, string $key): RedirectResponse
    {
        $this->store($request, $id);
        $request->session()->flashInput($this->draft($request, $id, $key)['data']);

        return redirect()->route('pos.sale', $id);
    }

    public function confirm(Request $request, int $id, string $key, CheckoutQuote $calculator): RedirectResponse
    {
        $orderId = DB::transaction(function () use ($request, $id, $key, $calculator) {
            $store = $this->store($request, $id, true);
            $existing = DB::table('orders')->where('store_id', $id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless($existing->source === 'pos' && $existing->created_by === $request->user()->id, 409);

                return $existing->id;
            }
            $draft = $this->draft($request, $id, $key);
            $request->validate(['review_hash' => 'required|string|size:64']);
            abort_unless(hash_equals(hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR)), $request->input('review_hash')), 409, 'A revisão mudou. Abra a revisão atual antes de confirmar.');
            $quote = $calculator->calculate($store, $draft['data'], true);
            $this->preferred($store, $draft['data']['preferred_driver_id'] ?? null);
            if (! hash_equals($calculator->fingerprint($draft['quote']), $calculator->fingerprint($quote))) {
                $request->session()->flashInput($draft['data']);
                throw ValidationException::withMessages(['cart' => 'O catálogo ou pagamento mudou. Revise os valores antes de confirmar.'])->redirectTo(route('pos.sale', $id));
            }
            $orderId = DB::table('orders')->insertGetId([
                'public_id' => (string) Str::uuid(), 'tenant_id' => $store->tenant_id, 'store_id' => $id,
                'idempotency_key' => $key, 'source' => 'pos', 'created_by' => $request->user()->id,
                'customer_name' => $draft['data']['customer_name'], 'phone' => $draft['data']['phone'], 'mode' => $draft['data']['mode'],
                'pos_channel' => $draft['data']['pos_channel'], 'preferred_driver_id' => $draft['data']['preferred_driver_id'] ?? null,
                'address' => $quote['delivery_address'], 'delivery_zone_name' => $quote['delivery_zone_name'],
                'status' => 'accepted', 'accepted_at' => now(), 'payment_status' => $draft['data']['received'] === 'yes' ? 'paid' : 'pending',
                'payment_method' => $quote['payment_method'], 'change_for_cents' => $quote['change_for_cents'],
                'subtotal_cents' => $quote['subtotal_cents'], 'fee_cents' => $quote['fee_cents'], 'total_cents' => $quote['total_cents'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($quote['items'] as $item) {
                $item['options'] = json_encode($item['options'], JSON_THROW_ON_ERROR);
                DB::table('order_items')->insert($item + ['order_id' => $orderId]);
            }
            app(OnlinePayment::class)->reserve(DB::table('orders')->find($orderId));
            Audit::record('pos.sale', ['order_id' => $orderId, 'total_cents' => $quote['total_cents'], 'payment_method' => $quote['payment_method'], 'received' => $draft['data']['received'] === 'yes'], $store->tenant_id);

            return $orderId;
        }, 3);

        return redirect()->route('pos.result', $orderId)->with('success', 'Pedido registrado. Pagamentos por link precisam ser confirmados antes do preparo.');
    }

    private function order(Request $request, int $id): object
    {
        $user = $this->member($request);

        return DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $this->stores($user)->select('id'))->where('source', 'pos')->where('id', $id)->firstOrFail();
    }

    public function result(Request $request, int $orderId): Response
    {
        $order = $this->order($request, $orderId);

        return response()->view('pos.result', ['order' => $order, 'store' => $this->store($request, $order->store_id), 'detailUrl' => $this->detail($request, $orderId), 'drivers' => $this->drivers($order->store_id), 'ride' => DB::table('rides')->where('order_id', $orderId)->first(), 'courierEnabled' => DB::table('tenants')->where('id', $order->tenant_id)->value('courier_module') === 'active'])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function dispatch(Request $request, int $orderId): RedirectResponse
    {
        if ($request->input('preferred_driver_id') === 'auto') {
            $request->merge(['preferred_driver_id' => null]);
        }

        return DB::transaction(function () use ($request, $orderId) {
            $this->member($request, true);
            $initial = $this->order($request, $orderId);
            $order = DB::table('orders')->where('id', $initial->id)->lockForUpdate()->firstOrFail();
            $data = $request->validate(['preferred_driver_id' => 'nullable|integer|min:1']);
            $ride = DB::table('rides')->where('order_id', $orderId)->first();
            abort_if($ride && $ride->status !== 'unassigned', 409, 'Este pedido já tem uma corrida. Acompanhe ou trate a tentativa existente.');
            $store = $this->store($request, $order->store_id);
            $this->preferred($store, $data['preferred_driver_id'] ?? null);
            DB::table('orders')->where('id', $orderId)->update(['preferred_driver_id' => $data['preferred_driver_id'] ?? null]);

            return app(PlatformController::class)->dispatch($request, $orderId, app(FareCalculator::class), app(DispatchService::class));
        }, 3);
    }
}
