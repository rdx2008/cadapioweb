<?php

namespace App\Http\Controllers;

use App\Services\CheckoutPayment;
use App\Services\CheckoutQuote;
use App\Services\MenuCatalog;
use App\Services\TenantState;
use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        return response()->view('pos.sale', app(MenuCatalog::class)->data($store) + ['store' => $store, 'methods' => array_intersect_key(app(CheckoutPayment::class)->methods($store), CheckoutPayment::LABELS)])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, int $id, CheckoutQuote $calculator): RedirectResponse
    {
        $store = $this->store($request, $id);
        MoneyInput::normalize($request, ['change_for' => 'change_for_cents']);
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120', 'phone' => 'nullable|string|min:8|max:25',
            'payment_method' => 'required|in:cash,card,pix', 'received' => 'required|in:yes,no',
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
        $data['mode'] = 'pickup';
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
            if (! hash_equals($calculator->fingerprint($draft['quote']), $calculator->fingerprint($quote))) {
                $request->session()->flashInput($draft['data']);
                throw ValidationException::withMessages(['cart' => 'O catálogo ou pagamento mudou. Revise os valores antes de confirmar.'])->redirectTo(route('pos.sale', $id));
            }
            $orderId = DB::table('orders')->insertGetId([
                'public_id' => (string) Str::uuid(), 'tenant_id' => $store->tenant_id, 'store_id' => $id,
                'idempotency_key' => $key, 'source' => 'pos', 'created_by' => $request->user()->id,
                'customer_name' => $draft['data']['customer_name'], 'phone' => $draft['data']['phone'], 'mode' => 'pickup',
                'status' => 'accepted', 'accepted_at' => now(), 'payment_status' => $draft['data']['received'] === 'yes' ? 'paid' : 'pending',
                'payment_method' => $quote['payment_method'], 'change_for_cents' => $quote['change_for_cents'],
                'subtotal_cents' => $quote['subtotal_cents'], 'fee_cents' => 0, 'total_cents' => $quote['total_cents'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($quote['items'] as $item) {
                $item['options'] = json_encode($item['options'], JSON_THROW_ON_ERROR);
                DB::table('order_items')->insert($item + ['order_id' => $orderId]);
            }
            Audit::record('pos.sale', ['order_id' => $orderId, 'total_cents' => $quote['total_cents'], 'payment_method' => $quote['payment_method'], 'received' => $draft['data']['received'] === 'yes'], $store->tenant_id);

            return $orderId;
        }, 3);

        return redirect($this->detail($request, $orderId))->with('success', 'Venda registrada no PDV e enviada para a fila da cozinha.');
    }
}
