<?php

namespace App\Http\Controllers;

use App\Services\DispatchService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourierController extends Controller
{
    private function driver(): object
    {
        return DB::table('drivers')->where('user_id', auth()->id())->firstOrFail();
    }

    public function index()
    {
        $d = $this->driver();

        return view('courier', ['driver' => $d, 'stores' => DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('tenants.courier_module', 'active')->select('stores.id', 'stores.name', 'stores.address')->get(), 'offers' => DB::table('offers')->join('rides', 'rides.id', '=', 'offers.ride_id')->join('stores', 'stores.id', '=', 'rides.store_id')->where('offers.driver_id', $d->id)->where('offers.status', 'open')->where('offers.expires_at', '>', now())->whereIn('rides.status', ['offered', 'broadcast'])->select('offers.id', 'offers.expires_at', 'rides.fare_cents', 'rides.route_meters', 'rides.cash_cents', 'stores.name', 'stores.address')->get(), 'rides' => DB::table('rides')->join('orders', 'orders.id', '=', 'rides.order_id')->where('rides.driver_id', $d->id)->select('rides.*', 'orders.address', 'orders.phone', 'orders.customer_name')->orderByDesc('rides.id')->limit(30)->get()]);
    }

    public function presence(Request $r)
    {
        $d = $r->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:50', 'store_id' => 'nullable|integer']);
        $driver = $this->driver();
        DB::transaction(function () use ($driver, $d) {
            $locked = DB::table('drivers')->where('id', $driver->id)->lockForUpdate()->first();
            abort_unless($locked->status === 'approved', 403, 'Cadastro aguardando aprovação.');
            abort_if(DB::table('driver_claims')->where('driver_id', $driver->id)->whereNull('expires_at')->exists(), 409, 'Finalize sua corrida antes de ficar disponível.');
            $storeId = null;
            $checkin = null;
            if (! empty($d['store_id'])) {
                $s = DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('stores.id', $d['store_id'])->where('tenants.courier_module', 'active')->select('stores.*')->firstOrFail();
                abort_unless(DispatchService::distance($d['latitude'], $d['longitude'], $s->latitude, $s->longitude) <= 150, 422, 'Check-in permitido a até 150 m da loja.');
                $storeId = $s->id;
                $checkin = $locked->checkin_store_id === $storeId && $locked->available && $locked->heartbeat_at && now()->subSeconds(90)->lte($locked->heartbeat_at) ? $locked->checked_in_at : now();
            }DB::table('drivers')->where('id', $driver->id)->update(['latitude' => $d['latitude'], 'longitude' => $d['longitude'], 'accuracy' => $d['accuracy'], 'available' => true, 'heartbeat_at' => now(), 'checkin_store_id' => $storeId, 'checked_in_at' => $checkin]);
        });

        return back()->with('success', 'Presença atualizada. Nesta versão web, renove a localização antes de 90 segundos.');
    }

    public function offline()
    {
        DB::table('drivers')->where('id', $this->driver()->id)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);

        return back();
    }

    public function accept(int $id, DispatchService $s)
    {
        $s->accept($id, $this->driver()->id);

        return back()->with('success', 'Corrida atribuída a você.');
    }

    public function decline(int $id, DispatchService $s)
    {
        $s->decline($id, $this->driver()->id);

        return back();
    }

    public function progress(Request $r, int $id)
    {
        $r->validate(['status' => 'required|in:collected,completed']);
        $driver = $this->driver();
        DB::transaction(function () use ($r, $id, $driver) {
            $tenantId = DB::table('rides')->where('id', $id)->where('driver_id', $driver->id)->value('tenant_id');
            abort_unless($tenantId, 404);
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
            $ride = DB::table('rides')->where('id', $id)->where('driver_id', $driver->id)->lockForUpdate()->firstOrFail();
            abort_unless(($ride->status === 'assigned' && $r->status === 'collected') || ($ride->status === 'collected' && $r->status === 'completed'), 409);
            DB::table('rides')->where('id', $id)->update(['status' => $r->status, 'completed_at' => $r->status === 'completed' ? now() : null, 'updated_at' => now()]);
            if ($r->status === 'completed') {
                DB::table('orders')->where('id', $ride->order_id)->update(['status' => 'completed', 'payment_status' => 'paid', 'updated_at' => now()]);
                DB::table('driver_claims')->where('driver_id', $driver->id)->where('ride_id', $id)->delete();
                $other = DB::table('rides')->where('tenant_id', $ride->tenant_id)->whereIn('status', ['assigned', 'collected'])->where('id', '!=', $id)->exists();
                if (! $other) {
                    DB::table('tenants')->where('id', $ride->tenant_id)->where('courier_module', 'closing')->update(['courier_module' => 'disabled']);
                }
            }Audit::record('ride.progress', ['id' => $id, 'status' => $r->status], $ride->tenant_id);
        });

        return back()->with('success', 'Etapa registrada.');
    }
}
