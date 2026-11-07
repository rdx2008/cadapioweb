<?php

namespace App\Http\Controllers;

use App\Services\TenantState;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class KitchenController extends Controller
{
    private function member(Request $request, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $request->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($user->access_enabled && $user->tenant_id && $user->tenant_id === $request->user()->tenant_id && ($user->role === 'owner' || ($user->role === 'staff' && in_array($user->staff_profile, ['kitchen', 'operator'], true))), 403);
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

    private function data(Request $request): array
    {
        $user = $this->member($request);
        $filters = $request->validate(['store_id' => 'nullable|integer', 'stage' => 'nullable|in:accepted,preparing,ready', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = $this->stores($user)->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['store_id'])) {
            abort_unless($stores->contains('id', (int) $filters['store_id']), 404);
        }
        $query = DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $stores->pluck('id'))->when(! empty($filters['store_id']), fn ($q) => $q->where('store_id', $filters['store_id']));
        $counts = (clone $query)->whereIn('status', ['accepted', 'preparing', 'ready'])->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $stage = $filters['stage'] ?? 'accepted';
        $orders = $query->where('status', $stage)->orderByRaw('COALESCE(prepare_at, accepted_at, created_at) ASC')->orderBy('id')->paginate(30)->withQueryString();
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->select('id', 'order_id', 'quantity', 'name', 'options')->orderBy('id')->get()->groupBy('order_id');

        return compact('user', 'filters', 'stores', 'counts', 'stage', 'orders', 'items');
    }

    public function index(Request $request): View
    {
        return view('kitchen.index', $this->data($request));
    }

    public function live(Request $request): JsonResponse
    {
        $html = view('kitchen.orders', $this->data($request))->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, int $id): RedirectResponse
    {
        $request->validate(['status' => 'required|in:preparing,ready']);

        return DB::transaction(function () use ($request, $id) {
            $user = $this->member($request, true);
            DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $this->stores($user)->select('id'))->where('id', $id)->firstOrFail();

            return app(PlatformController::class)->orderStatus($request, $id);
        });
    }
}
