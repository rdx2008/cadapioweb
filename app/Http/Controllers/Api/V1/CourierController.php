<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AttemptResource;
use App\Http\Resources\V1\IncidentResource;
use App\Http\Resources\V1\OfferResource;
use App\Http\Resources\V1\RideResource;
use App\Http\Resources\V1\StatementEntryResource;
use App\Http\Resources\V1\StoreResource;
use App\Services\CourierPresence;
use App\Services\DeliveryExceptions;
use App\Services\DeliveryRecords;
use App\Services\DeliveryStatement;
use App\Services\DispatchService;
use App\Services\RideProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourierController extends Controller
{
    private const LOCATION_RULES = ['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:50'];

    public function __construct(private CourierPresence $presence) {}

    private function driver(Request $request): object
    {
        return $this->presence->driver($request->user()->id);
    }

    private function rideRow(object $driver, int $id): object
    {
        return DB::table('rides')->join('orders', 'orders.id', '=', 'rides.order_id')->join('stores', 'stores.id', '=', 'rides.store_id')->where('rides.id', $id)->where('rides.driver_id', $driver->id)
            ->select('rides.*', 'stores.name as store_name', 'orders.address', 'orders.phone', 'orders.customer_name', 'orders.payment_method', 'orders.payment_status', 'orders.change_for_cents', 'orders.total_cents')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(object $driver): array
    {
        $board = $this->presence->board($driver);

        return ['presence' => $this->presence->state($driver), 'offers' => OfferResource::collection($board['offers']), 'rides' => RideResource::collection($board['rides']), 'attempts' => AttemptResource::collection($board['attempts']), 'server_time' => now()->toIso8601String()];
    }

    public function stores(): JsonResponse
    {
        return response()->json(['stores' => StoreResource::collection($this->presence->stores())]);
    }

    public function board(Request $request): JsonResponse
    {
        return response()->json($this->snapshot($this->driver($request)));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(self::LOCATION_RULES + ['captured_at' => ['required', 'numeric', 'min:'.now()->subSeconds(60)->timestamp, 'max:'.now()->addSeconds(5)->timestamp]]);
        $accepted = $this->presence->heartbeat($this->driver($request), $data);

        return response()->json(['accepted' => $accepted, 'message' => $accepted ? 'Localização atualizada.' : 'Disponibilidade suspensa. Confirme para voltar à fila.'] + $this->snapshot($this->driver($request)), $accepted ? 200 : 409);
    }

    public function presence(Request $request): JsonResponse
    {
        $data = $request->validate(self::LOCATION_RULES + ['store_id' => 'nullable|integer|min:1']);
        $this->presence->confirm($this->driver($request), $data);

        return response()->json(['message' => 'Presença confirmada. Mantenha o aplicativo ativo para renovar a localização.'] + $this->snapshot($this->driver($request)));
    }

    public function offline(Request $request): JsonResponse
    {
        $this->presence->offline($this->driver($request));

        return response()->json(['message' => 'Você está offline.'] + $this->snapshot($this->driver($request)));
    }

    public function accept(Request $request, int $id, DispatchService $dispatch): JsonResponse
    {
        $driver = $this->driver($request);
        $rideId = $dispatch->accept($id, $driver->id);

        return response()->json(['message' => 'Corrida atribuída a você.', 'ride' => new RideResource($this->rideRow($driver, $rideId))] + $this->snapshot($driver));
    }

    public function decline(Request $request, int $id, DispatchService $dispatch): JsonResponse
    {
        $driver = $this->driver($request);
        $dispatch->decline($id, $driver->id);

        return response()->json(['message' => 'Oferta recusada.'] + $this->snapshot($driver));
    }

    public function ride(Request $request, int $id): JsonResponse
    {
        $driver = $this->driver($request);
        $ride = $this->rideRow($driver, $id);
        $incidents = DB::table('delivery_incidents')->join('users as authors', 'authors.id', '=', 'delivery_incidents.author_id')->where('ride_id', $ride->id)->where('delivery_incidents.tenant_id', $ride->tenant_id)->select('delivery_incidents.*', 'authors.name as author_name')->orderByDesc('delivery_incidents.id')->get();
        $attempts = DB::table('delivery_attempts')->join('rides', 'rides.id', '=', 'delivery_attempts.ride_id')->join('stores', 'stores.id', '=', 'rides.store_id')->where('delivery_attempts.ride_id', $ride->id)->where('delivery_attempts.driver_id', $driver->id)->select('delivery_attempts.*', 'stores.name as store_name')->orderByDesc('delivery_attempts.id')->get();

        return response()->json(['ride' => new RideResource($ride), 'incidents' => IncidentResource::collection($incidents), 'attempts' => AttemptResource::collection($attempts)]);
    }

    public function progress(Request $request, int $id, RideProgress $progress): JsonResponse
    {
        $data = $request->validate(RideProgress::RULES);
        $driver = $this->driver($request);
        $progress->advance($driver, $id, $data, $request->file('photo'), true);

        return response()->json(['message' => 'Etapa registrada.', 'ride' => new RideResource($this->rideRow($driver, $id))] + $this->snapshot($driver));
    }

    public function returnToStore(Request $request, int $id, DeliveryExceptions $exceptions): JsonResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'expected_round' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:1000']);
        $driver = $this->driver($request);
        $this->rideRow($driver, $id);
        $exceptions->act($id, $request->user(), 'return', $data);

        return response()->json(['message' => 'Retorno à loja registrado. Entregue o pedido na loja para encerrar a tentativa.', 'ride' => new RideResource($this->rideRow($driver, $id))] + $this->snapshot($driver));
    }

    public function incident(Request $request, int $id, DeliveryRecords $records): JsonResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'kind' => ['required', Rule::in(array_keys(DeliveryRecords::KINDS))], 'description' => 'required|string|min:5|max:1000', 'photo' => DeliveryRecords::PHOTO_RULES]);
        $driver = $this->driver($request);
        $records->recordIncident($request->user(), $this->rideRow($driver, $id), $data, $request->file('photo'));
        $incident = DB::table('delivery_incidents')->join('users as authors', 'authors.id', '=', 'delivery_incidents.author_id')->where('ride_id', $id)->where('request_key', $data['request_key'])->select('delivery_incidents.*', 'authors.name as author_name')->firstOrFail();

        return response()->json(['message' => 'Ocorrência registrada. A situação da corrida e os valores foram preservados.', 'incident' => new IncidentResource($incident)], 201);
    }

    public function statement(Request $request, DeliveryStatement $service): JsonResponse
    {
        $filters = $request->validate(['start' => 'nullable|date_format:Y-m-d', 'end' => 'nullable|date_format:Y-m-d', 'timezone' => ['nullable', Rule::in(timezone_identifiers_list())], 'store_id' => 'nullable|integer|min:1', 'settlement' => 'nullable|in:all,pending,settled', 'page' => 'nullable|integer|min:1|max:100000']);
        $driver = $this->driver($request);
        $stores = DB::table('stores')->where(fn ($activity) => $activity->whereExists(fn ($r) => $r->selectRaw('1')->from('rides')->whereColumn('rides.store_id', 'stores.id')->where('rides.driver_id', $driver->id))->orWhereExists(fn ($a) => $a->selectRaw('1')->from('delivery_attempts')->join('rides', 'rides.id', '=', 'delivery_attempts.ride_id')->whereColumn('rides.store_id', 'stores.id')->where('delivery_attempts.driver_id', $driver->id)))
            ->orderBy('name')->get(['id', 'name', 'timezone']);
        $storeId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        $store = $storeId ? $stores->firstWhere('id', $storeId) : null;
        abort_if($storeId && ! $store, 404);
        $timezone = $filters['timezone'] ?? $store?->timezone ?? 'America/Sao_Paulo';
        $today = now()->timezone($timezone)->startOfDay();
        $start = isset($filters['start']) ? Carbon::createFromFormat('!Y-m-d', $filters['start'], $timezone) : $today->copy()->subDays(29);
        $end = isset($filters['end']) ? Carbon::createFromFormat('!Y-m-d', $filters['end'], $timezone) : $today->copy();
        if ($start->gt($end) || $end->gt($today) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['start' => 'Escolha até 366 dias, com término até hoje.']);
        }
        $settlement = $filters['settlement'] ?? 'all';
        $query = $service->query(null, $driver->id, $storeId, $start, $end->copy()->addDay(), $settlement);
        $entries = $service->ordered($query)->paginate(30)->withQueryString();

        return response()->json([
            'filters' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'timezone' => $timezone, 'store_id' => $storeId, 'settlement' => $settlement],
            'stores' => $stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'totals' => array_map('intval', (array) $service->summary($query)),
            'entries' => StatementEntryResource::collection($entries->items()),
            'pagination' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'per_page' => $entries->perPage(), 'total' => $entries->total()],
        ]);
    }
}
