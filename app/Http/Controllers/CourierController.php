<?php

namespace App\Http\Controllers;

use App\Services\DeliveryConfirmation;
use App\Services\DeliveryRecords;
use App\Services\DispatchService;
use App\Services\OnlinePayment;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CourierController extends Controller
{
    private function driver(): object
    {
        return DB::table('drivers')->where('user_id', auth()->id())->firstOrFail();
    }

    public function index(): View
    {
        $d = $this->driver();

        return view('courier', $this->board($d) + ['driver' => $d, 'presence' => $this->presenceState($d), 'stores' => DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('tenants.courier_module', 'active')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')->select('stores.id', 'stores.name', 'stores.address')->get()]);
    }

    public function live(): JsonResponse
    {
        $driver = $this->driver();
        $board = $this->board($driver);
        $html = view('partials.courier-board', $board)->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html), 'presence' => $this->presenceState($driver), 'server_time' => now()->timestamp * 1000])->header('Cache-Control', 'no-store');
    }

    private function board(object $driver): array
    {
        $offers = DB::table('offers')->join('rides', 'rides.id', '=', 'offers.ride_id')->join('orders', 'orders.id', '=', 'rides.order_id')->join('stores', 'stores.id', '=', 'rides.store_id')->join('tenants', 'tenants.id', '=', 'rides.tenant_id')
            ->where('offers.driver_id', $driver->id)->whereColumn('offers.dispatch_round', 'rides.dispatch_round')->where('offers.status', 'open')->where('offers.expires_at', '>', now())->whereIn('rides.status', ['offered', 'broadcast'])->where('tenants.courier_module', 'active')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')
            ->select('offers.id', 'offers.expires_at', 'rides.fare_cents', 'rides.route_meters', 'rides.cash_cents', 'stores.name', 'stores.address', 'orders.payment_method')->orderBy('offers.id')->get();
        if ($driver->status !== 'approved' || ! $driver->available || ! $driver->heartbeat_at || now()->subSeconds(90)->gt($driver->heartbeat_at)) {
            $offers = collect();
        }
        $rides = DB::table('rides')->join('orders', 'orders.id', '=', 'rides.order_id')->where('rides.driver_id', $driver->id)->select('rides.*', 'orders.address', 'orders.phone', 'orders.customer_name', 'orders.payment_method', 'orders.payment_status', 'orders.change_for_cents', 'orders.total_cents')->orderByDesc('rides.id')->limit(30)->get();

        $attempts = DB::table('delivery_attempts')->join('rides', 'rides.id', '=', 'delivery_attempts.ride_id')->join('stores', 'stores.id', '=', 'rides.store_id')->where('delivery_attempts.driver_id', $driver->id)->select('delivery_attempts.*', 'stores.name as store_name')->orderByDesc('delivery_attempts.id')->limit(30)->get();

        return compact('offers', 'rides', 'attempts');
    }

    private function presenceState(object $driver): array
    {
        $base = ['renew' => false, 'queue_position' => null, 'queue_total' => null];
        if ($driver->status !== 'approved') {
            return $base + ['label' => 'Cadastro pendente ou bloqueado'];
        }
        if (DB::table('rides')->where('driver_id', $driver->id)->whereIn('status', ['assigned', 'collected', 'returning'])->exists()) {
            return array_replace($base, ['label' => 'Em corrida · novas ofertas suspensas', 'renew' => true]);
        }
        if ($this->expiredExclusiveOffer($driver->id)) {
            return $base + ['label' => 'Oferta sem resposta · confirme para voltar após a reconciliação'];
        }
        if (! $driver->available) {
            return $base + ['label' => 'Offline · confirme para ficar disponível'];
        }
        if (! $driver->heartbeat_at || now()->subSeconds(90)->gt($driver->heartbeat_at)) {
            return $base + ['label' => 'Presença expirada · confirme para voltar'];
        }
        $base['renew'] = true;
        if (! $driver->checkin_store_id) {
            return $base + ['label' => 'Disponível para ofertas da região'];
        }
        $store = DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('stores.id', $driver->checkin_store_id)->where('tenants.courier_module', 'active')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')->select('stores.*')->first();
        if (! $store || $driver->accuracy === null || $driver->accuracy > 50 || $driver->latitude === null || $driver->longitude === null || DispatchService::distance($driver->latitude, $driver->longitude, $store->latitude, $store->longitude) > 150) {
            return array_replace($base, ['label' => 'Fora da fila · confirme a localização na loja', 'renew' => false]);
        }
        $claims = DB::table('driver_claims')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('driver_id');
        if ($claims->contains($driver->id)) {
            return $base + ['label' => 'Oferta reservada · responda antes do prazo'];
        }
        $queue = DB::table('drivers')->where('checkin_store_id', $store->id)->where('status', 'approved')->where('available', true)->where('heartbeat_at', '>=', now()->subSeconds(90))->where('accuracy', '<=', 50)->whereNotIn('id', $claims)->orderBy('checked_in_at')->orderBy('id')->get()
            ->filter(fn ($candidate) => $candidate->latitude !== null && $candidate->longitude !== null && DispatchService::distance($candidate->latitude, $candidate->longitude, $store->latitude, $store->longitude) <= 150)->values();
        $position = $queue->search(fn ($candidate) => $candidate->id === $driver->id);

        return array_replace($base, ['label' => $store->name.' · fila de espera', 'queue_position' => $position === false ? null : $position + 1, 'queue_total' => $queue->count()]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:50', 'captured_at' => ['required', 'numeric', 'min:'.now()->subSeconds(60)->timestamp, 'max:'.now()->addSeconds(5)->timestamp]]);
        $driver = $this->driver();
        $accepted = DB::transaction(function () use ($driver, $data): bool {
            $locked = DB::table('drivers')->where('id', $driver->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved', 403, 'Cadastro não aprovado.');
            $busy = DB::table('rides')->where('driver_id', $driver->id)->whereIn('status', ['assigned', 'collected', 'returning'])->exists();
            $expiredOffer = $this->expiredExclusiveOffer($driver->id);
            if (! $busy && ($expiredOffer || ! $locked->available || ! $locked->heartbeat_at || now()->subSeconds(90)->gt($locked->heartbeat_at))) {
                DB::table('drivers')->where('id', $driver->id)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);

                return false;
            }
            if (! $busy && $locked->checkin_store_id) {
                $store = DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('stores.id', $locked->checkin_store_id)->where('tenants.courier_module', 'active')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')->select('stores.*')->first();
                if (! $store || DispatchService::distance($data['latitude'], $data['longitude'], $store->latitude, $store->longitude) > 150) {
                    DB::table('drivers')->where('id', $driver->id)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);

                    return false;
                }
            }
            DB::table('drivers')->where('id', $driver->id)->update(['latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'accuracy' => $data['accuracy'], 'heartbeat_at' => now()]);

            return true;
        });

        return response()->json(['presence' => $this->presenceState($this->driver()), 'message' => $accepted ? 'Localização atualizada.' : 'Disponibilidade suspensa. Confirme para voltar à fila.'], $accepted ? 200 : 409)->header('Cache-Control', 'no-store');
    }

    private function expiredExclusiveOffer(int $driverId): bool
    {
        return DB::table('offers')->join('rides', 'rides.id', '=', 'offers.ride_id')->where('offers.driver_id', $driverId)->whereColumn('offers.dispatch_round', 'rides.dispatch_round')->where('offers.status', 'open')->where('offers.expires_at', '<=', now())->where('rides.status', 'offered')->exists();
    }

    public function presence(Request $r)
    {
        $d = $r->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:50', 'store_id' => 'nullable|integer']);
        $driver = $this->driver();
        DB::transaction(function () use ($driver, $d) {
            $locked = DB::table('drivers')->where('id', $driver->id)->lockForUpdate()->first();
            abort_unless($locked->status === 'approved', 403, 'Cadastro aguardando aprovação.');
            abort_if($this->expiredExclusiveOffer($driver->id), 409, 'Aguarde a reconciliação da oferta expirada e confirme novamente.');
            abort_if(DB::table('driver_claims')->where('driver_id', $driver->id)->whereNull('expires_at')->exists(), 409, 'Finalize sua corrida antes de ficar disponível.');
            $storeId = null;
            $checkin = null;
            if (! empty($d['store_id'])) {
                $s = DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('stores.id', $d['store_id'])->where('tenants.courier_module', 'active')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')->select('stores.*')->firstOrFail();
                abort_unless(DispatchService::distance($d['latitude'], $d['longitude'], $s->latitude, $s->longitude) <= 150, 422, 'Check-in permitido a até 150 m da loja.');
                $storeId = $s->id;
                $checkin = $locked->checkin_store_id === $storeId && $locked->available && $locked->heartbeat_at && now()->subSeconds(90)->lte($locked->heartbeat_at) ? $locked->checked_in_at : now();
            }DB::table('drivers')->where('id', $driver->id)->update(['latitude' => $d['latitude'], 'longitude' => $d['longitude'], 'accuracy' => $d['accuracy'], 'available' => true, 'heartbeat_at' => now(), 'checkin_store_id' => $storeId, 'checked_in_at' => $checkin]);
        });

        return back()->with('success', 'Presença confirmada. Mantenha esta página visível para renovar a localização automaticamente.');
    }

    public function offline()
    {
        DB::transaction(function (): void {
            $driver = DB::table('drivers')->where('id', $this->driver()->id)->lockForUpdate()->firstOrFail();
            DB::table('drivers')->where('id', $driver->id)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);
        });

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
        $r->validate(['status' => 'required|in:collected,completed', 'expected_round' => 'nullable|integer|min:1', 'delivery_code' => 'prohibited_unless:status,completed|nullable|string|regex:/^[0-9]{6}$/D', 'received_by' => 'prohibited_unless:status,completed|nullable|string|max:100', 'photo' => 'prohibited_unless:status,completed|'.DeliveryRecords::PHOTO_RULES]);
        $driver = $this->driver();
        $path = null;
        try {
            DB::transaction(function () use ($r, $id, $driver, &$path) {
                $tenantId = DB::table('rides')->where('id', $id)->where('driver_id', $driver->id)->value('tenant_id');
                abort_unless($tenantId, 404);
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
                $ride = DB::table('rides')->where('id', $id)->where('driver_id', $driver->id)->lockForUpdate()->firstOrFail();
                abort_if($r->filled('expected_round') && $ride->dispatch_round !== $r->integer('expected_round'), 409, 'A tentativa mudou. Atualize a página.');
                abort_unless(($ride->status === 'assigned' && $r->status === 'collected') || ($ride->status === 'collected' && $r->status === 'completed'), 409);
                $order = DB::table('orders')->where('id', $ride->order_id)->lockForUpdate()->firstOrFail();
                app(OnlinePayment::class)->requirePaid($order);
                abort_if($r->status === 'completed' && $order->payment_method !== 'cash' && $order->payment_status !== 'paid', 409, 'A loja precisa confirmar o recebimento do Pix ou cartão antes de concluir a entrega.');
                $evidence = [];
                if ($r->status === 'collected' && $ride->confirmation_required) {
                    $evidence['confirmation_cipher'] = app(DeliveryConfirmation::class)->generate();
                }
                if ($r->status === 'completed') {
                    app(DeliveryConfirmation::class)->verify($ride, $r->input('delivery_code'), $r->integer('expected_round'));
                    $path = app(DeliveryRecords::class)->photo($r->file('photo'), $ride->tenant_id);
                    $evidence = ['confirmation_verified_at' => $ride->confirmation_required ? now() : null, 'confirmation_cipher' => null, 'received_by' => $r->input('received_by'), 'proof_photo_path' => $path, 'proof_recorded_at' => $path || $r->filled('received_by') ? now() : null];
                }
                DB::table('rides')->where('id', $id)->update($evidence + ['status' => $r->status, 'completed_at' => $r->status === 'completed' ? now() : null, 'updated_at' => now()]);
                if ($r->status === 'completed') {
                    DB::table('orders')->where('id', $ride->order_id)->update(['status' => 'completed', 'payment_status' => 'paid', 'updated_at' => now()]);
                    DB::table('driver_claims')->where('driver_id', $driver->id)->where('ride_id', $id)->delete();
                    $other = DB::table('rides')->where('tenant_id', $ride->tenant_id)->whereIn('status', ['assigned', 'collected', 'returning'])->where('id', '!=', $id)->exists();
                    if (! $other) {
                        DB::table('tenants')->where('id', $ride->tenant_id)->where('courier_module', 'closing')->update(['courier_module' => 'disabled']);
                    }
                }Audit::record('ride.progress', ['id' => $id, 'status' => $r->status], $ride->tenant_id);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return back()->with('success', 'Etapa registrada.');
    }
}
