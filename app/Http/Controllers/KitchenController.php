<?php

namespace App\Http\Controllers;

use App\Services\OperationsBoard;
use App\Services\TenantState;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
        $filters = $request->validate(['store_id' => 'nullable|integer', 'stage' => 'nullable|in:accepted,preparing,ready', 'view' => 'nullable|in:kanban,list', 'mode' => 'nullable|in:delivery,pickup,dine_in', 'accepted_page' => 'nullable|integer|min:1|max:100000', 'preparing_page' => 'nullable|integer|min:1|max:100000', 'ready_page' => 'nullable|integer|min:1|max:100000', 'scheduled_page' => 'nullable|integer|min:1|max:100000', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = $this->stores($user)->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['store_id'])) {
            abort_unless($stores->contains('id', (int) $filters['store_id']), 404);
        }
        $query = DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $stores->pluck('id'))->when(! empty($filters['store_id']), fn ($q) => $q->where('store_id', $filters['store_id']));
        $query->when(! empty($filters['mode']), fn ($q) => $q->where('mode', $filters['mode']));
        $counts = (clone $query)->whereIn('status', ['accepted', 'preparing', 'ready'])->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $stage = $filters['stage'] ?? 'accepted';
        $orders = $query->where('status', $stage)->orderByRaw('COALESCE(prepare_at, accepted_at, created_at) ASC')->orderBy('id')->paginate(30)->withQueryString();
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->select('id', 'order_id', 'quantity', 'name', 'options')->orderBy('id')->get()->groupBy('order_id');

        $kanban = ($filters['view'] ?? (isset($filters['stage']) ? 'list' : 'kanban')) === 'kanban';
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : ($stores->count() === 1 ? $stores->first() : null);
        $board = $kanban ? app(OperationsBoard::class)->production($user, isset($filters['store_id']) ? (int) $filters['store_id'] : null, $filters) : null;

        return compact('user', 'filters', 'stores', 'counts', 'stage', 'orders', 'items', 'kanban', 'board', 'store');
    }

    public function index(Request $request): Response
    {
        return response()->view('kitchen.index', $this->data($request))->header('Cache-Control', 'private, no-store');
    }

    public function live(Request $request): JsonResponse
    {
        $data = $this->data($request);
        $html = view($data['kanban'] ? 'operations.kitchen-board' : 'kitchen.orders', $data)->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $request->validate(['status' => 'required|in:preparing,ready']);

        return DB::transaction(function () use ($request, $id) {
            $user = $this->member($request, true);
            DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $this->stores($user)->select('id'))->where('id', $id)->firstOrFail();

            $response = app(PlatformController::class)->orderStatus($request, $id);

            return $request->expectsJson() ? response()->json(['message' => 'Pedido atualizado na cozinha.']) : $response;
        });
    }
}
