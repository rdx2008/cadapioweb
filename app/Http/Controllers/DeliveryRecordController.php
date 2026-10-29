<?php

namespace App\Http\Controllers;

use App\Services\DeliveryRecords;
use App\Services\TenantState;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryRecordController extends Controller
{
    private function ride(int $id): object
    {
        $user = auth()->user();
        abort_unless($user && ($user->access_enabled ?? true), 403);
        $query = DB::table('rides')->where('id', $id);
        if ($user->role === 'owner') {
            abort_unless(app(TenantState::class)->active($user->tenant_id), 403);
            $query->where('tenant_id', $user->tenant_id);
        } elseif ($user->role === 'courier') {
            $driver = DB::table('drivers')->where('user_id', $user->id)->firstOrFail();
            $query->where('driver_id', $driver->id);
        } else {
            abort(403);
        }

        return $query->firstOrFail();
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'status' => ['nullable', Rule::in(array_keys(DeliveryRecords::STATUSES))], 'incidents' => 'nullable|in:open']);
        $tenantId = $request->user()->tenant_id;
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->orderBy('name')->get();
        if (! empty($filters['store_id'])) {
            abort_unless($stores->contains('id', (int) $filters['store_id']), 404);
        }
        $query = DB::table('rides')->where('rides.tenant_id', $tenantId)->when(! empty($filters['store_id']), fn ($q) => $q->where('rides.store_id', $filters['store_id']))->when(! empty($filters['status']), fn ($q) => $q->where('rides.status', $filters['status']));
        $open = DB::table('delivery_incidents')->where('tenant_id', $tenantId)->where('status', 'open')->whereIn('ride_id', (clone $query)->select('rides.id'))->count();
        if (($filters['incidents'] ?? '') === 'open') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('delivery_incidents')->whereColumn('ride_id', 'rides.id')->where('status', 'open'));
        }
        $rides = $query->join('stores', 'stores.id', '=', 'rides.store_id')->leftJoin('drivers', 'drivers.id', '=', 'rides.driver_id')->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->select('rides.*', 'stores.name as store_name', 'users.name as driver_name')
            ->selectSub(DB::table('delivery_incidents')->selectRaw('count(*)')->whereColumn('ride_id', 'rides.id')->where('status', 'open'), 'open_incidents')
            ->orderByDesc('rides.id')->paginate(25)->withQueryString();

        return view('deliveries.index', compact('rides', 'stores', 'filters', 'open'));
    }

    public function show(int $id): View
    {
        $ride = $this->ride($id);
        $order = DB::table('orders')->where('id', $ride->order_id)->where('tenant_id', $ride->tenant_id)->firstOrFail();
        $store = DB::table('stores')->where('id', $ride->store_id)->firstOrFail();
        $incidents = DB::table('delivery_incidents')->join('users as authors', 'authors.id', '=', 'delivery_incidents.author_id')->leftJoin('users as resolvers', 'resolvers.id', '=', 'delivery_incidents.resolved_by')->where('ride_id', $ride->id)->where('delivery_incidents.tenant_id', $ride->tenant_id)->select('delivery_incidents.*', 'authors.name as author_name', 'resolvers.name as resolver_name')->orderByDesc('delivery_incidents.id')->get();

        return view('deliveries.show', compact('ride', 'order', 'store', 'incidents'));
    }

    public function create(Request $request, int $id, DeliveryRecords $records): RedirectResponse
    {
        $this->ride($id);
        $data = $request->validate(['request_key' => 'required|uuid', 'kind' => ['required', Rule::in(array_keys(DeliveryRecords::KINDS))], 'description' => 'required|string|min:5|max:1000', 'photo' => DeliveryRecords::PHOTO_RULES]);
        $path = null;
        try {
            DB::transaction(function () use ($request, $id, $records, $data, &$path): void {
                $initial = $this->ride($id);
                DB::table('tenants')->where('id', $initial->tenant_id)->lockForUpdate()->firstOrFail();
                $ride = $this->ride($id);
                DB::table('rides')->where('id', $ride->id)->lockForUpdate()->firstOrFail();
                $existing = DB::table('delivery_incidents')->where('ride_id', $id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    abort_unless($existing->author_id === $request->user()->id, 409);

                    return;
                }
                $allowed = $request->user()->role === 'courier' ? ['assigned', 'collected'] : ['searching', 'offered', 'broadcast', 'unassigned', 'assigned', 'collected'];
                abort_unless(in_array($ride->status, $allowed, true), 409, 'A corrida já foi encerrada.');
                abort_if($request->user()->role === 'courier' && $data['kind'] === 'no_courier', 422);
                $path = $records->photo($request->file('photo'), $ride->tenant_id);
                $incidentId = DB::table('delivery_incidents')->insertGetId(['tenant_id' => $ride->tenant_id, 'ride_id' => $id, 'author_id' => $request->user()->id, 'request_key' => $data['request_key'], 'kind' => $data['kind'], 'description' => $data['description'], 'photo_path' => $path, 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('delivery.incident.created', ['incident_id' => $incidentId, 'ride_id' => $id, 'kind' => $data['kind']], $ride->tenant_id);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }

        return back()->with('success', 'Ocorrência registrada. A situação da corrida e os valores foram preservados.');
    }

    public function resolve(Request $request, int $incidentId): RedirectResponse
    {
        $data = $request->validate(['resolution' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($request, $incidentId, $data): void {
            DB::table('tenants')->where('id', $request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless(app(TenantState::class)->active($request->user()->tenant_id), 403);
            $incident = DB::table('delivery_incidents')->where('id', $incidentId)->where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            if ($incident->status === 'resolved') {
                return;
            }
            DB::table('delivery_incidents')->where('id', $incidentId)->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'updated_at' => now()]);
            Audit::record('delivery.incident.resolved', ['incident_id' => $incidentId, 'ride_id' => $incident->ride_id], $incident->tenant_id);
        });

        return back()->with('success', 'Ocorrência resolvida e registrada no histórico.');
    }

    public function proof(int $id): StreamedResponse
    {
        $ride = $this->ride($id);

        return $this->image($ride->proof_photo_path);
    }

    public function incidentPhoto(int $incidentId): StreamedResponse
    {
        $incident = DB::table('delivery_incidents')->where('id', $incidentId)->firstOrFail();
        $this->ride($incident->ride_id);

        return $this->image($incident->photo_path);
    }

    private function image(?string $path): StreamedResponse
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'image/webp']);
    }
}
