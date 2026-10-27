<?php

namespace App\Http\Controllers;

use App\Services\DashboardSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderBoardController extends Controller
{
    public function data(Request $request): array
    {
        $filters = $request->validate(['store_id' => 'nullable|integer', 'status' => 'nullable|in:active,all,pending,accepted,preparing,ready,completed,cancelled', 'q' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1|max:100000']);
        $query = DB::table('orders')->where('tenant_id', $request->user()->tenant_id);
        if (! empty($filters['store_id'])) {
            DB::table('stores')->where('tenant_id', $request->user()->tenant_id)->where('id', $filters['store_id'])->firstOrFail();
            $query->where('store_id', $filters['store_id']);
        }
        $latestId = (clone $query)->max('id') ?? 0;
        $status = $filters['status'] ?? 'active';
        if ($status === 'active') {
            $query->whereIn('status', ['pending', 'accepted', 'preparing', 'ready']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('customer_name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('id', is_numeric($filters['q']) ? (int) $filters['q'] : 0));
        }
        $orders = $query->orderByDesc('id')->paginate(30)->withPath(url('/dashboard'))->withQueryString();
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('id')->get()->groupBy('order_id');

        return ['boardOrders' => $orders, 'boardItems' => $items, 'latestId' => (int) $latestId, 'filters' => $filters];
    }

    public function live(Request $request): JsonResponse
    {
        $data = $this->data($request);
        $tenant = DB::table('tenants')->where('id', $request->user()->tenant_id)->firstOrFail();
        $html = view('partials.orders', $data + ['tenant' => $tenant])->render();

        return response()->json(['html' => $html, 'latest_id' => $data['latestId'], 'signature' => hash('sha256', $html), 'summary' => app(DashboardSummary::class)->forTenant((int) $request->user()->tenant_id, ! empty($data['filters']['store_id']) ? (int) $data['filters']['store_id'] : null)])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $id): View
    {
        $order = DB::table('orders')->where('tenant_id', $request->user()->tenant_id)->where('id', $id)->firstOrFail();

        return view('order-detail', ['store' => DB::table('stores')->where('id', $order->store_id)->firstOrFail(), 'order' => $order, 'items' => DB::table('order_items')->where('order_id', $id)->get()]);
    }
}
