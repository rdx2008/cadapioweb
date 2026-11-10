<?php

namespace App\Http\Controllers;

use App\Services\DeliveryCoverage;
use App\Services\DeliveryPolygon;
use App\Services\PostcodeLookup;
use App\Services\TenantState;
use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryAreaController extends Controller
{
    private function store(Request $request, int $id, bool $lock = false): object
    {
        $actor = DB::table('users')->where('id', $request->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($actor->role === 'owner' && $actor->access_enabled && $actor->tenant_id === $request->user()->tenant_id, 403);
        if ($lock) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->firstOrFail();
        }
        abort_unless(app(TenantState::class)->active($actor->tenant_id), 403);

        return DB::table('stores')->where('tenant_id', $actor->tenant_id)->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function index(Request $request, int $id): Response
    {
        $store = $this->store($request, $id);
        $areas = DB::table('delivery_areas')->where('store_id', $id)->whereNull('deleted_at')->orderBy('id')->get();

        return response()->view('catalog.delivery-map', compact('store', 'areas'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function save(Request $request, int $id, DeliveryPolygon $geometry): RedirectResponse
    {
        MoneyInput::normalize($request, ['fee' => 'fee_cents']);
        $data = $request->validate(['area_id' => 'nullable|integer|min:1', 'revision' => 'required|integer|min:0|max:1000000', 'request_key' => 'required|uuid', 'name' => 'required|string|max:120', 'fee_cents' => 'required|integer|min:0|max:100000', 'enabled' => 'required|boolean', 'color' => 'required|regex:/^#[a-fA-F0-9]{6}$/', 'polygon' => 'required|json|max:12000']);
        DB::transaction(function () use ($request, $id, $data, $geometry): void {
            $store = $this->store($request, $id, true);
            $name = trim($data['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Informe um nome para a área.']);
            }
            $raw = json_decode($data['polygon'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($raw) || ! array_is_list($raw)) {
                throw ValidationException::withMessages(['polygon' => 'Desenhe os limites no mapa.']);
            }
            $points = $geometry->validate($raw, $store);
            $values = ['name' => $name, 'fee_cents' => (int) $data['fee_cents'], 'enabled' => (bool) $data['enabled'], 'color' => strtolower($data['color']), 'polygon' => json_encode($points, JSON_THROW_ON_ERROR)];
            $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            if (empty($data['area_id'])) {
                $existing = DB::table('delivery_areas')->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    abort_unless($existing->store_id === $id && hash_equals($existing->request_hash, $hash), 409);

                    return;
                }
                abort_unless((int) $data['revision'] === 0, 409);
                if (DB::table('delivery_areas')->where('store_id', $id)->whereNull('deleted_at')->count() >= 50) {
                    throw ValidationException::withMessages(['polygon' => 'Limite de 50 áreas por loja.']);
                }
                $areaId = DB::table('delivery_areas')->insertGetId($values + ['store_id' => $id, 'revision' => 1, 'request_key' => $data['request_key'], 'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $area = DB::table('delivery_areas')->where('store_id', $id)->where('id', $data['area_id'])->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
                abort_unless($area->revision === (int) $data['revision'], 409, 'Esta área mudou. Reabra o mapa antes de editar.');
                $areaId = $area->id;
                DB::table('delivery_areas')->where('id', $areaId)->update($values + ['revision' => $area->revision + 1, 'updated_at' => now()]);
            }
            Audit::record('delivery.area_saved', ['store_id' => $id, 'area_id' => $areaId] + $values, $store->tenant_id);
        }, 3);

        return redirect()->route('delivery-areas.index', $id)->with('success', 'Área salva. Confira o atendimento testando um endereço.');
    }

    public function remove(Request $request, int $id, int $areaId): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id, $areaId, $data): void {
            $store = $this->store($request, $id, true);
            $area = DB::table('delivery_areas')->where('store_id', $id)->where('id', $areaId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            abort_unless($area->revision === (int) $data['revision'], 409, 'A área mudou. Confira a versão atual.');
            DB::table('delivery_areas')->where('id', $areaId)->update(['deleted_at' => now(), 'enabled' => false, 'revision' => $area->revision + 1, 'updated_at' => now()]);
            Audit::record('delivery.area_removed', ['store_id' => $id, 'area_id' => $areaId, 'name' => $area->name, 'polygon' => $area->polygon, 'fee_cents' => $area->fee_cents], $store->tenant_id);
        }, 3);

        return redirect()->route('delivery-areas.index', $id)->with('success', 'Área removida do atendimento. Pedidos anteriores mantêm o frete registrado.');
    }

    public function test(Request $request, int $id, DeliveryCoverage $coverage, PostcodeLookup $lookup): JsonResponse
    {
        $store = $this->store($request, $id);
        $data = $request->validate(['postcode' => 'required|string|regex:/^[0-9]{5}-?[0-9]{3}$/', 'number' => 'required|string|max:15|regex:/^[0-9]+[A-Za-z0-9 \/-]*$/']);
        $fields = $lookup->find($data['postcode']) + $data;
        if (! $fields['street']) {
            throw ValidationException::withMessages(['postcode' => 'Este CEP é genérico. Use um CEP com rua para testar o endereço.']);
        }
        $address = $fields['street'].', '.$fields['number'].' - '.$fields['neighborhood'].', '.$fields['city'].' - '.$fields['state'].', '.$fields['postcode'];
        $results = [];
        foreach ($coverage->search($address, $fields) as $place) {
            $area = $coverage->polygonZone($store, $place);
            $results[] = ['label' => $place['label'], 'latitude' => $place['latitude'], 'longitude' => $place['longitude'], 'available' => $area !== null, 'area' => $area?->name, 'fee_cents' => $area?->fee_cents];
        }

        return response()->json(['results' => $results, 'preview' => true])->header('Cache-Control', 'private, no-store');
    }
}
