<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StaffOperationController extends Controller
{
    private function member(Request $request, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $request->user()->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($user->role === 'staff' && $user->access_enabled && $user->tenant_id && in_array($user->staff_profile, ['operator', 'kitchen'], true), 403);

        return $user;
    }

    private function stores(object $user): Collection
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->whereIn('id', DB::table('user_store_access')->where('user_id', $user->id)->where('tenant_id', $user->tenant_id)->select('store_id'))->orderBy('name')->get();
    }

    private function data(Request $request): array
    {
        $user = $this->member($request);
        $stores = $this->stores($user);
        $filters = $request->validate(['store_id' => 'nullable|integer', 'status' => 'nullable|in:active,all,pending,accepted,preparing,ready,completed,cancelled', 'page' => 'nullable|integer|min:1|max:100000']);
        if (! empty($filters['store_id'])) {
            abort_unless($stores->contains('id', (int) $filters['store_id']), 404);
        }
        $query = DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $stores->pluck('id'));
        if (! empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }
        $status = $filters['status'] ?? 'active';
        if ($status === 'active') {
            $query->whereIn('status', ['pending', 'accepted', 'preparing', 'ready']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $orders = $query->orderBy('id')->paginate(30)->withPath(url('/operation'))->withQueryString();

        return ['staff' => $user, 'stores' => $stores, 'filters' => $filters, 'orders' => $orders,
            'items' => DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('id')->get()->groupBy('order_id')];
    }

    public function index(Request $request): View
    {
        return view('staff.operation', $this->data($request));
    }

    public function live(Request $request): JsonResponse
    {
        $html = view('staff.orders', $this->data($request))->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'no-store');
    }

    private function order(object $user, int $id): object
    {
        return DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $this->stores($user)->pluck('id'))->where('id', $id)->firstOrFail();
    }

    public function show(Request $request, int $id): View
    {
        $user = $this->member($request);
        $order = $this->order($user, $id);

        return view('staff.order', ['store' => DB::table('stores')->where('id', $order->store_id)->firstOrFail(), 'staff' => $user, 'order' => $order, 'items' => DB::table('order_items')->where('order_id', $order->id)->get()]);
    }

    public function status(Request $request, int $id): RedirectResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $user = $this->member($request, true);
            $this->order($user, $id);
            if ($user->staff_profile === 'kitchen') {
                abort_unless(in_array($request->input('status'), ['preparing', 'ready'], true), 403);
            }

            return app(PlatformController::class)->orderStatus($request, $id);
        });
    }

    public function paid(Request $request, int $id): RedirectResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $user = $this->member($request, true);
            abort_unless($user->staff_profile === 'operator', 403);
            $this->order($user, $id);

            return app(PlatformController::class)->markPaid($id);
        });
    }
}
