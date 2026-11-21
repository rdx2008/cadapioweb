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
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    private function store(object $user, int $id, bool $lock = false): object
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function index(Request $request, int $storeId, Inventory $access): Response
    {
        $store = $this->store($access->member($request->user()), $storeId);
        $today = Carbon::now($store->timezone)->toDateString();
        $coupons = DB::table('store_coupons')->where('store_id', $storeId)
            ->select('store_coupons.*')
            ->selectSub(DB::table('orders')->whereColumn('coupon_id', 'store_coupons.id')
                ->where(fn ($query) => $query->where('status', '!=', 'cancelled')->orWhereIn('payment_status', ['paid', 'review']))
                ->selectRaw('COUNT(*)'), 'uses')
            ->orderByDesc('id')->paginate(20);

        return response()->view('catalog.coupons', compact('store', 'coupons', 'today'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, int $storeId, Inventory $access): RedirectResponse
    {
        $data = $request->validate([
            'code' => 'required|string|min:4|max:24|alpha_dash:ascii',
            'kind' => 'required|in:fixed,percent',
            'amount' => 'required|string|max:12',
            'minimum' => 'required|string|max:12',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'maximum_uses' => 'required|integer|min:1|max:100000',
            'per_customer' => 'required|integer|min:1|lte:maximum_uses',
        ]);
        $value = $data['kind'] === 'fixed' ? InventoryCosts::money($data['amount'], 'amount', 1000000) : $data['amount'];
        if (($data['kind'] === 'percent' && ! preg_match('/^(?:[1-9][0-9]?|100)$/D', $value)) || (int) $value < 1) {
            throw ValidationException::withMessages(['amount' => 'Use um valor positivo em reais ou uma porcentagem inteira entre 1 e 100.']);
        }
        $minimum = InventoryCosts::money($data['minimum'], 'minimum', 1000000);
        DB::transaction(function () use ($request, $storeId, $access, $data, $value, $minimum): void {
            $user = $access->member($request->user(), true);
            $this->store($user, $storeId, true);
            $code = strtoupper($data['code']);
            if (DB::table('store_coupons')->where('store_id', $storeId)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Já existe um cupom com este código na loja.']);
            }
            $id = DB::table('store_coupons')->insertGetId([
                'store_id' => $storeId, 'code' => $code, 'kind' => $data['kind'], 'value' => (int) $value,
                'minimum_cents' => $minimum, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                'maximum_uses' => $data['maximum_uses'], 'per_customer' => $data['per_customer'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('coupon.created', ['coupon_id' => $id, 'store_id' => $storeId], $user->tenant_id);
        }, 3);

        return redirect()->route('coupons.index', $storeId)->with('success', 'Cupom criado.');
    }

    public function availability(Request $request, int $storeId, int $id, Inventory $access): RedirectResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        DB::transaction(function () use ($request, $storeId, $id, $access, $data): void {
            $user = $access->member($request->user(), true);
            $this->store($user, $storeId, true);
            $coupon = DB::table('store_coupons')->where('store_id', $storeId)->where('id', $id)->firstOrFail();
            DB::table('store_coupons')->where('id', $coupon->id)->update(['enabled' => $data['enabled'], 'updated_at' => now()]);
            Audit::record('coupon.availability', ['coupon_id' => $id, 'store_id' => $storeId, 'enabled' => (bool) $data['enabled']], $user->tenant_id);
        }, 3);

        return redirect()->route('coupons.index', $storeId)->with('success', 'Disponibilidade atualizada.');
    }
}
