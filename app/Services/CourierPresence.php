<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Presence, queue and board rules shared by the courier web panel and the courier API.
 */
class CourierPresence
{
    public const HEARTBEAT_SECONDS = 30;

    public const PRESENCE_TTL_SECONDS = 90;

    public const CHECKIN_RADIUS_METERS = 150;

    public const MAX_ACCURACY_METERS = 50;

    public function driver(int $userId): object
    {
        return DB::table('drivers')->where('user_id', $userId)->firstOrFail();
    }

    public function stores(): Collection
    {
        return $this->activeStores()->select('stores.id', 'stores.name', 'stores.address', 'stores.latitude', 'stores.longitude')->orderBy('stores.name')->get();
    }

    private function activeStores(): Builder
    {
        return DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->where('tenants.courier_module', 'active')->where('tenants.status', 'active')->whereNull('tenants.deleted_at');
    }

    private function activeStore(?int $storeId): ?object
    {
        return $storeId ? $this->activeStores()->where('stores.id', $storeId)->select('stores.*')->first() : null;
    }

    private function presenceValid(object $driver): bool
    {
        return (bool) $driver->heartbeat_at && now()->subSeconds(self::PRESENCE_TTL_SECONDS)->lte($driver->heartbeat_at);
    }

    private function busy(int $driverId): bool
    {
        return DB::table('rides')->where('driver_id', $driverId)->whereIn('status', ['assigned', 'collected', 'returning'])->exists();
    }

    public function expiredExclusiveOffer(int $driverId): bool
    {
        return DB::table('offers')->join('rides', 'rides.id', '=', 'offers.ride_id')->where('offers.driver_id', $driverId)->whereColumn('offers.dispatch_round', 'rides.dispatch_round')->where('offers.status', 'open')->where('offers.expires_at', '<=', now())->where('rides.status', 'offered')->exists();
    }

    /**
     * @return array{offers: Collection, rides: Collection, attempts: Collection}
     */
    public function board(object $driver): array
    {
        $offers = DB::table('offers')->join('rides', 'rides.id', '=', 'offers.ride_id')->join('orders', 'orders.id', '=', 'rides.order_id')->join('stores', 'stores.id', '=', 'rides.store_id')->join('tenants', 'tenants.id', '=', 'rides.tenant_id')
            ->where('offers.driver_id', $driver->id)->whereColumn('offers.dispatch_round', 'rides.dispatch_round')->where('offers.status', 'open')->where('offers.expires_at', '>', now())->whereIn('rides.status', ['offered', 'broadcast'])->where('tenants.courier_module', 'active')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')
            ->select('offers.id', 'offers.expires_at', 'rides.id as ride_id', 'rides.status as ride_status', 'rides.fare_cents', 'rides.route_meters', 'rides.cash_cents', 'stores.name', 'stores.address', 'orders.payment_method')->orderBy('offers.id')->get();
        if ($driver->status !== 'approved' || ! $driver->available || ! $this->presenceValid($driver)) {
            $offers = collect();
        }
        $rides = DB::table('rides')->join('orders', 'orders.id', '=', 'rides.order_id')->join('stores', 'stores.id', '=', 'rides.store_id')->where('rides.driver_id', $driver->id)->select('rides.*', 'stores.name as store_name', 'orders.address', 'orders.phone', 'orders.customer_name', 'orders.payment_method', 'orders.payment_status', 'orders.change_for_cents', 'orders.total_cents')->orderByDesc('rides.id')->limit(30)->get();
        $attempts = DB::table('delivery_attempts')->join('rides', 'rides.id', '=', 'delivery_attempts.ride_id')->join('stores', 'stores.id', '=', 'rides.store_id')->where('delivery_attempts.driver_id', $driver->id)->select('delivery_attempts.*', 'stores.name as store_name')->orderByDesc('delivery_attempts.id')->limit(30)->get();

        return compact('offers', 'rides', 'attempts');
    }

    /**
     * @return array{code: string, label: string, renew: bool, queue_position: int|null, queue_total: int|null}
     */
    public function state(object $driver): array
    {
        $base = ['renew' => false, 'queue_position' => null, 'queue_total' => null];
        if ($driver->status !== 'approved') {
            return $base + ['code' => 'not_approved', 'label' => 'Cadastro pendente ou bloqueado'];
        }
        if ($this->busy($driver->id)) {
            return array_replace($base, ['code' => 'on_ride', 'label' => 'Em corrida · novas ofertas suspensas', 'renew' => true]);
        }
        if ($this->expiredExclusiveOffer($driver->id)) {
            return $base + ['code' => 'offer_expired', 'label' => 'Oferta sem resposta · confirme para voltar após a reconciliação'];
        }
        if (! $driver->available) {
            return $base + ['code' => 'offline', 'label' => 'Offline · confirme para ficar disponível'];
        }
        if (! $this->presenceValid($driver)) {
            return $base + ['code' => 'presence_expired', 'label' => 'Presença expirada · confirme para voltar'];
        }
        $base['renew'] = true;
        if (! $driver->checkin_store_id) {
            return $base + ['code' => 'region', 'label' => 'Disponível para ofertas da região'];
        }
        $store = $this->activeStore($driver->checkin_store_id);
        if (! $store || $driver->accuracy === null || $driver->accuracy > self::MAX_ACCURACY_METERS || $driver->latitude === null || $driver->longitude === null || DispatchService::distance($driver->latitude, $driver->longitude, $store->latitude, $store->longitude) > self::CHECKIN_RADIUS_METERS) {
            return array_replace($base, ['code' => 'out_of_radius', 'label' => 'Fora da fila · confirme a localização na loja', 'renew' => false]);
        }
        $claims = DB::table('driver_claims')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('driver_id');
        if ($claims->contains($driver->id)) {
            return $base + ['code' => 'offer_reserved', 'label' => 'Oferta reservada · responda antes do prazo'];
        }
        $queue = DB::table('drivers')->where('checkin_store_id', $store->id)->where('status', 'approved')->where('available', true)->where('heartbeat_at', '>=', now()->subSeconds(self::PRESENCE_TTL_SECONDS))->where('accuracy', '<=', self::MAX_ACCURACY_METERS)->whereNotIn('id', $claims)->orderBy('checked_in_at')->orderBy('id')->get()
            ->filter(fn ($candidate) => $candidate->latitude !== null && $candidate->longitude !== null && DispatchService::distance($candidate->latitude, $candidate->longitude, $store->latitude, $store->longitude) <= self::CHECKIN_RADIUS_METERS)->values();
        $position = $queue->search(fn ($candidate) => $candidate->id === $driver->id);

        return array_replace($base, ['code' => 'queued', 'label' => $store->name.' · fila de espera', 'queue_position' => $position === false ? null : $position + 1, 'queue_total' => $queue->count()]);
    }

    /**
     * Renews location while the courier is on a ride or validly present. Returns false when availability was suspended.
     *
     * @param  array{latitude: float|string, longitude: float|string, accuracy: float|string}  $location
     */
    public function heartbeat(object $driver, array $location): bool
    {
        return DB::transaction(function () use ($driver, $location): bool {
            $locked = DB::table('drivers')->where('id', $driver->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved', 403, 'Cadastro não aprovado.');
            $busy = $this->busy($driver->id);
            if (! $busy && ($this->expiredExclusiveOffer($driver->id) || ! $locked->available || ! $this->presenceValid($locked))) {
                $this->suspend($driver->id);

                return false;
            }
            if (! $busy && $locked->checkin_store_id) {
                $store = $this->activeStore($locked->checkin_store_id);
                if (! $store || DispatchService::distance($location['latitude'], $location['longitude'], $store->latitude, $store->longitude) > self::CHECKIN_RADIUS_METERS) {
                    $this->suspend($driver->id);

                    return false;
                }
            }
            DB::table('drivers')->where('id', $driver->id)->update(['latitude' => $location['latitude'], 'longitude' => $location['longitude'], 'accuracy' => $location['accuracy'], 'heartbeat_at' => now()]);

            return true;
        });
    }

    /**
     * Explicit availability confirmation, optionally checking in to a store queue.
     *
     * @param  array{latitude: float|string, longitude: float|string, accuracy: float|string, store_id?: int|string|null}  $location
     */
    public function confirm(object $driver, array $location): void
    {
        DB::transaction(function () use ($driver, $location): void {
            $locked = DB::table('drivers')->where('id', $driver->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved', 403, 'Cadastro aguardando aprovação.');
            abort_if($this->expiredExclusiveOffer($driver->id), 409, 'Aguarde a reconciliação da oferta expirada e confirme novamente.');
            abort_if(DB::table('driver_claims')->where('driver_id', $driver->id)->whereNull('expires_at')->exists(), 409, 'Finalize sua corrida antes de ficar disponível.');
            $storeId = null;
            $checkin = null;
            if (! empty($location['store_id'])) {
                $store = $this->activeStore((int) $location['store_id']);
                abort_unless($store, 404);
                abort_unless(DispatchService::distance($location['latitude'], $location['longitude'], $store->latitude, $store->longitude) <= self::CHECKIN_RADIUS_METERS, 422, 'Check-in permitido a até 150 m da loja.');
                $storeId = $store->id;
                $checkin = $locked->checkin_store_id === $storeId && $locked->available && $this->presenceValid($locked) ? $locked->checked_in_at : now();
            }
            DB::table('drivers')->where('id', $driver->id)->update(['latitude' => $location['latitude'], 'longitude' => $location['longitude'], 'accuracy' => $location['accuracy'], 'available' => true, 'heartbeat_at' => now(), 'checkin_store_id' => $storeId, 'checked_in_at' => $checkin]);
        });
    }

    public function offline(object $driver): void
    {
        DB::transaction(function () use ($driver): void {
            DB::table('drivers')->where('id', $driver->id)->lockForUpdate()->firstOrFail();
            $this->suspend($driver->id);
        });
    }

    private function suspend(int $driverId): void
    {
        DB::table('drivers')->where('id', $driverId)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);
    }
}
