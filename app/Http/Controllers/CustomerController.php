<?php

namespace App\Http\Controllers;

use App\Services\BusinessIntelligence;
use App\Services\Inventory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    private function orders(int $tenant, int $store): Builder
    {
        return DB::table('orders')->where('tenant_id', $tenant)->where('store_id', $store)
            ->whereIn('source', ['menu', 'pos'])->where('status', 'completed')->where('payment_status', 'paid');
    }

    public function index(Request $request, Inventory $access, BusinessIntelligence $bi): Response
    {
        $user = $access->member($request->user());
        $filters = $request->validate([
            'store_id' => 'nullable|integer|min:1',
            'segment' => 'nullable|in:new,returning,inactive',
            'sort' => 'nullable|in:recent,frequency,value',
        ]);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $cutoff = Carbon::today($store?->timezone ?? 'America/Sao_Paulo')->subDays(60)->utc();
        $identity = $bi->customerExpression();
        $orders = $this->orders($user->tenant_id, $store?->id ?? 0);
        $buyers = (clone $orders)->whereRaw("($identity) IS NOT NULL")
            ->selectRaw("$identity AS customer_key, COUNT(*) AS visits, SUM(total_cents) AS total_cents, MAX(created_at) AS last_purchase")
            ->groupByRaw($identity);
        $customers = DB::query()->fromSub($buyers, 'customers');
        $metrics = [
            'identified' => (clone $customers)->count(),
            'returning' => (clone $customers)->where('visits', '>', 1)->where('last_purchase', '>', $cutoff)->count(),
            'inactive' => (clone $customers)->where('last_purchase', '<=', $cutoff)->count(),
            'unidentified' => $orders->whereRaw("($identity) IS NULL")->count(),
        ];
        match ($filters['segment'] ?? null) {
            'new' => $customers->where('visits', 1)->where('last_purchase', '>', $cutoff),
            'returning' => $customers->where('visits', '>', 1)->where('last_purchase', '>', $cutoff),
            'inactive' => $customers->where('last_purchase', '<=', $cutoff),
            default => null,
        };
        $sort = ['recent' => 'last_purchase', 'frequency' => 'visits', 'value' => 'total_cents'][$filters['sort'] ?? 'recent'];
        $customers = $customers->orderByDesc($sort)->orderBy('customer_key')->paginate(25)->appends($filters);

        return response()->view('customers.index', compact('store', 'stores', 'customers', 'metrics', 'cutoff'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $storeId, Inventory $access, BusinessIntelligence $bi): Response
    {
        $user = $access->member($request->user());
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $storeId)->firstOrFail();
        $token = $request->query('customer');
        abort_unless(is_string($token) && strlen($token) <= 512, 404);
        try {
            $identity = Crypt::decryptString($token);
        } catch (DecryptException) {
            abort(404);
        }
        $orders = $this->orders($user->tenant_id, $store->id)
            ->whereRaw('('.$bi->customerExpression().') = ?', [$identity]);
        $latest = (clone $orders)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $totals = (clone $orders)->selectRaw('COUNT(*) AS visits, SUM(total_cents) AS total_cents, MIN(created_at) AS first_purchase')->first();
        $history = $orders->select('id', 'source', 'pos_channel', 'total_cents', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->appends(['customer' => $token]);

        $referralsEnabled = DB::table('store_referrals')->where('store_id', $store->id)->where('enabled', true)->exists();

        return response()->view('customers.show', compact('store', 'latest', 'totals', 'history', 'referralsEnabled'))->header('Cache-Control', 'private, no-store');
    }
}
