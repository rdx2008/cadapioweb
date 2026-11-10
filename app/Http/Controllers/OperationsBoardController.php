<?php

namespace App\Http\Controllers;

use App\Services\DispatchService;
use App\Services\FareCalculator;
use App\Services\OperationsBoard;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OperationsBoardController extends Controller
{
    public function summary(Request $request, int $id, OperationsBoard $board): JsonResponse
    {
        $user = $board->member($request->user());
        $store = $board->stores($user)->where('id', $id)->firstOrFail();
        $data = $board->production($user, $id, compact: true);
        $html = view('operations.pos-summary', $data + compact('user', 'store'))->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'private, no-store');
    }

    private function data(Request $request, OperationsBoard $board): array
    {
        $user = $board->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100', 'incidents' => 'nullable|in:open', 'waiting_page' => 'nullable|integer|min:1|max:100000', 'route_page' => 'nullable|integer|min:1|max:100000', 'done_page' => 'nullable|integer|min:1|max:100000']);
        $stores = $board->stores($user)->orderBy('name')->get();
        $store = ! empty($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(! empty($filters['store_id']) && ! $store, 404);
        $base = DB::table('orders')->leftJoin('rides', 'rides.order_id', '=', 'orders.id')->leftJoin('drivers', 'drivers.id', '=', 'rides.driver_id')->leftJoin('users as couriers', 'couriers.id', '=', 'drivers.user_id')->where('orders.tenant_id', $user->tenant_id)->where('orders.store_id', $store?->id ?? 0)->where('orders.mode', 'delivery');
        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $base->where(fn ($q) => $q->where('orders.customer_name', 'like', $term)->orWhere('couriers.name', 'like', $term)->orWhere('orders.id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0));
        }
        if (($filters['incidents'] ?? '') === 'open') {
            $base->whereExists(fn ($q) => $q->selectRaw('1')->from('delivery_incidents')->whereColumn('ride_id', 'rides.id')->where('status', 'open'));
        }
        $base->select('orders.*', 'rides.id as ride_id', 'rides.status as ride_status', 'rides.driver_id', 'rides.dispatch_round', 'rides.updated_at as ride_updated_at', 'rides.completed_at as ride_completed_at', 'rides.proof_recorded_at', 'couriers.name as driver_name', 'drivers.heartbeat_at as driver_heartbeat_at', 'drivers.vehicle as driver_vehicle')
            ->selectSub(DB::table('delivery_incidents')->selectRaw('COUNT(*)')->whereColumn('ride_id', 'rides.id')->where('status', 'open'), 'open_incidents');
        $waiting = (clone $base)->where('orders.status', 'ready')->where(fn ($q) => $q->whereNull('rides.id')->orWhereNotIn('rides.status', ['collected', 'returning', 'completed']));
        $route = (clone $base)->whereIn('rides.status', ['collected', 'returning']);
        $day = Carbon::now($store?->timezone ?? 'America/Sao_Paulo')->startOfDay();
        $start = $day->copy()->utc();
        $end = $day->copy()->addDay()->utc();
        $done = (clone $base)->where('rides.status', 'completed')->where('rides.completed_at', '>=', $start)->where('rides.completed_at', '<', $end);
        $counts = ['waiting' => (clone $waiting)->count(), 'route' => (clone $route)->count(), 'done' => (clone $done)->count()];
        $counts['incidents'] = DB::table('delivery_incidents')->where('tenant_id', $user->tenant_id)->whereIn('ride_id', DB::table('rides')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->select('id'))->where('status', 'open')->count();
        $lanes = ['waiting' => $waiting->orderBy('orders.ready_at')->orderBy('orders.id')->paginate(12, ['*'], 'waiting_page'), 'route' => $route->orderBy('rides.updated_at')->orderBy('rides.id')->paginate(12, ['*'], 'route_page'), 'done' => $done->orderByDesc('rides.completed_at')->paginate(12, ['*'], 'done_page')];
        foreach ($lanes as $rows) {
            $rows->withPath(route('expedition.index'))->appends($request->except($rows->getPageName()));
        }
        $rideIds = collect($lanes)->flatMap(fn ($rows) => $rows->pluck('ride_id'))->filter();
        $incidents = DB::table('delivery_incidents')->where('tenant_id', $user->tenant_id)->whereIn('ride_id', $rideIds)->where('status', 'open')->select('ride_id', 'kind', 'description')->orderByDesc('id')->get()->groupBy('ride_id');
        $drivers = $store ? $board->drivers($store) : collect();
        $busy = DB::table('rides')->join('drivers', 'drivers.id', '=', 'rides.driver_id')->join('users', 'users.id', '=', 'drivers.user_id')->where('rides.tenant_id', $user->tenant_id)->where('rides.store_id', $store?->id ?? 0)->whereIn('rides.status', ['assigned', 'collected', 'returning'])->select('drivers.id', 'users.name', 'drivers.vehicle', 'drivers.heartbeat_at', 'drivers.latitude', 'drivers.longitude', 'drivers.accuracy', 'rides.status')->get()->unique('id');
        $courierEnabled = DB::table('tenants')->where('id', $user->tenant_id)->value('courier_module') === 'active';

        return compact('user', 'stores', 'store', 'filters', 'counts', 'lanes', 'drivers', 'busy', 'incidents', 'courierEnabled', 'board');
    }

    public function index(Request $request, OperationsBoard $board): Response
    {
        return response()->view('operations.expedition', $this->data($request, $board))->header('Cache-Control', 'private, no-store');
    }

    public function live(Request $request, OperationsBoard $board): JsonResponse
    {
        $html = view('operations.expedition-board', $this->data($request, $board))->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'private, no-store');
    }

    public function dispatch(Request $request, int $id, OperationsBoard $board): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['review_hash' => 'required|string|size:64', 'preferred_driver_id' => 'nullable|integer|min:1']);
        DB::transaction(function () use ($request, $id, $board, $data): void {
            $user = $board->member($request->user(), lock: true);
            $order = DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $board->stores($user)->select('id'))->where('id', $id)->lockForUpdate()->firstOrFail();
            $ride = DB::table('rides')->where('order_id', $id)->lockForUpdate()->first();
            abort_unless(hash_equals($board->review($order, $ride), $data['review_hash']), 409, 'Este pedido mudou. Atualize a expedição antes de solicitar.');
            abort_if($ride && $ride->status !== 'unassigned', 409, 'Acompanhe a busca ou corrida já existente.');
            abort_if($ride && DB::table('delivery_attempts')->where('ride_id', $ride->id)->exists(), 409, 'Use Nova busca nos detalhes da entrega.');
            $store = $board->stores($user)->where('id', $order->store_id)->firstOrFail();
            if (! empty($data['preferred_driver_id'])) {
                abort_unless($board->drivers($store)->contains('id', (int) $data['preferred_driver_id']), 409, 'O motoboy não está mais disponível nesta loja.');
            }
            DB::table('orders')->where('id', $id)->update(['preferred_driver_id' => $data['preferred_driver_id'] ?? null]);
            Audit::record('dispatch.preferred_driver', ['order_id' => $id, 'driver_id' => $data['preferred_driver_id'] ?? null], $user->tenant_id);
            app(PlatformController::class)->dispatch($request, $id, app(FareCalculator::class), app(DispatchService::class));
        }, 3);

        return $request->expectsJson() ? response()->json(['message' => 'Solicitação registrada. Aguarde o aceite e a coleta pelo motoboy.']) : back()->with('success', 'Motoboy solicitado. Aguarde o aceite e a coleta.');
    }
}
