<?php

namespace App\Http\Controllers;

use App\Services\DeliveryExceptions;
use App\Services\DispatchService;
use App\Services\TenantState;
use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeliveryExceptionController extends Controller
{
    public function act(Request $request, int $id, string $action, DeliveryExceptions $exceptions, DispatchService $dispatch): RedirectResponse
    {
        abort_unless(in_array($action, ['release', 'return', 'receive', 'retry'], true), 404);
        $rules = ['request_key' => 'required|uuid', 'expected_round' => 'required|integer|min:1'];
        if ($action !== 'retry') {
            $rules['reason'] = 'required|string|min:5|max:1000';
        }
        if (in_array($action, ['release', 'receive'], true)) {
            MoneyInput::normalize($request, ['compensation' => 'compensation_cents', 'customer_cash' => 'customer_cash_cents']);
            $rules['compensation_cents'] = 'required|integer|min:0|max:100000';
            $rules['physical_confirmation'] = 'accepted';
        }
        if ($action === 'receive') {
            $rules['customer_cash_cents'] = 'required|integer|min:0|max:10000000';
        }
        $data = $request->validate($rules);
        if ($exceptions->act($id, $request->user(), $action, $data)) {
            $dispatch->dispatch($id);
        }

        return redirect()->route('deliveries.show', $id)->with('success', 'Entrega atualizada. Confira a situação e o histórico das tentativas.');
    }

    public function show(Request $request, int $attemptId): View
    {
        $user = $request->user();
        abort_unless($user->access_enabled ?? true, 403);
        $query = DB::table('delivery_attempts')->where('delivery_attempts.id', $attemptId);
        if ($user->role === 'owner') {
            abort_unless(app(TenantState::class)->active($user->tenant_id), 403);
            $query->where('delivery_attempts.tenant_id', $user->tenant_id);
        } elseif ($user->role === 'courier') {
            $query->where('delivery_attempts.driver_id', DB::table('drivers')->where('user_id', $user->id)->value('id') ?? 0);
        } else {
            abort(403);
        }
        $attempt = $query->join('rides', 'rides.id', '=', 'delivery_attempts.ride_id')->join('stores', 'stores.id', '=', 'rides.store_id')->join('drivers', 'drivers.id', '=', 'delivery_attempts.driver_id')->join('users', 'users.id', '=', 'drivers.user_id')->select('delivery_attempts.*', 'stores.name as store_name', 'users.name as driver_name', 'rides.order_id')->firstOrFail();

        return view('deliveries.attempt', compact('attempt'));
    }

    public function settle(Request $request, int $attemptId): RedirectResponse
    {
        $request->validate(['confirmation' => 'accepted']);
        DB::transaction(function () use ($request, $attemptId): void {
            $tenantId = $request->user()->tenant_id;
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
            abort_unless(app(TenantState::class)->active($tenantId), 403);
            $attempt = DB::table('delivery_attempts')->where('id', $attemptId)->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            if ($attempt->settled) {
                return;
            }
            DB::table('delivery_attempts')->where('id', $attemptId)->update(['settled' => true, 'settled_by' => $request->user()->id, 'settled_at' => now(), 'updated_at' => now()]);
            Audit::record('delivery.attempt.settled', ['attempt_id' => $attemptId, 'compensation_cents' => $attempt->compensation_cents], $tenantId);
        });

        return back()->with('success', 'Acerto externo da remuneração registrado.');
    }
}
