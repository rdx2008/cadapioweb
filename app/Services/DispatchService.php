<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class DispatchService
{
    public static function distance(float $a, float $b, float $c, float $d): float
    {
        $v = sin(deg2rad($c - $a) / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin(deg2rad($d - $b) / 2) ** 2;

        return 6371000 * 2 * asin(sqrt(min(1, $v)));
    }

    private function eligible(object $driver): bool
    {
        return $driver->status === 'approved' && $driver->available && $driver->heartbeat_at && now()->subSeconds(90)->lte($driver->heartbeat_at) && $driver->accuracy !== null && $driver->accuracy <= 50;
    }

    public function dispatch(int $id): void
    {
        DB::transaction(function () use ($id) {
            $tenantId = DB::table('rides')->where('id', $id)->value('tenant_id');
            if (! $tenantId) {
                return;
            }
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $ride = DB::table('rides')->where('id', $id)->lockForUpdate()->first();
            if (! $ride || ! in_array($ride->status, ['searching', 'offered', 'broadcast'])) {
                return;
            }
            if ($tenant->courier_module !== 'active' || ! app(TenantState::class)->active($tenant->id)) {
                return;
            }
            $order = DB::table('orders')->where('id', $ride->order_id)->first();
            if (! $order || $order->status !== 'ready' || $order->payment_status === 'review') {
                return;
            }
            $live = DB::table('offers')->where('ride_id', $id)->where('dispatch_round', $ride->dispatch_round)->where('status', 'open')->where('expires_at', '>', now())->exists();
            if ($live) {
                return;
            }
            $expired = DB::table('offers')->where('ride_id', $id)->where('dispatch_round', $ride->dispatch_round)->where('status', 'open')->get();
            foreach ($expired as $offer) {
                DB::table('offers')->where('id', $offer->id)->update(['status' => 'expired']);
                DB::table('driver_claims')->where('driver_id', $offer->driver_id)->where('ride_id', $id)->whereNotNull('expires_at')->delete();
                if ($ride->status === 'offered') {
                    DB::table('drivers')->where('id', $offer->driver_id)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);
                }
            }
            $store = DB::table('stores')->find($ride->store_id);
            $seen = DB::table('offers')->where('ride_id', $id)->where('dispatch_round', $ride->dispatch_round)->pluck('driver_id');
            $queue = DB::table('drivers')->where('checkin_store_id', $store->id)->whereNotIn('id', $seen)->orderBy('checked_in_at')->orderBy('id')->get();
            if ($ride->status !== 'broadcast') {
                foreach ($queue as $candidate) {
                    $d = DB::table('drivers')->where('id', $candidate->id)->lockForUpdate()->first();
                    if (! $this->eligible($d) || self::distance($d->latitude, $d->longitude, $store->latitude, $store->longitude) > 150) {
                        continue;
                    }
                    DB::table('driver_claims')->where('driver_id', $d->id)->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
                    if (DB::table('driver_claims')->where('driver_id', $d->id)->exists()) {
                        continue;
                    }
                    DB::table('driver_claims')->insert(['driver_id' => $d->id, 'ride_id' => $id, 'expires_at' => now()->addSeconds(30)]);
                    $this->offer($id, $d->id, 30, $ride->dispatch_round);
                    DB::table('rides')->where('id', $id)->update(['status' => 'offered', 'updated_at' => now()]);

                    return;
                }
            }
            $radius = $ride->status === 'broadcast' ? 5000 : 3000;
            $drivers = DB::table('drivers')->whereNotIn('id', $seen)->where('available', true)->get();
            $count = 0;
            foreach ($drivers as $d) {
                if ($this->eligible($d) && ! DB::table('driver_claims')->where('driver_id', $d->id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists() && self::distance($d->latitude, $d->longitude, $store->latitude, $store->longitude) <= $radius) {
                    $this->offer($id, $d->id, 60, $ride->dispatch_round);
                    $count++;
                }
            }
            if (! $count && $radius === 3000) {
                foreach ($drivers as $d) {
                    if ($this->eligible($d) && ! DB::table('driver_claims')->where('driver_id', $d->id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists() && self::distance($d->latitude, $d->longitude, $store->latitude, $store->longitude) <= 5000) {
                        $this->offer($id, $d->id, 60, $ride->dispatch_round);
                        $count++;
                    }
                }
            }
            DB::table('rides')->where('id', $id)->update(['status' => $count ? 'broadcast' : 'unassigned', 'updated_at' => now()]);
        }, 3);
    }

    private function offer(int $ride, int $driver, int $seconds, int $round): void
    {
        DB::table('offers')->insert(['dispatch_round' => $round, 'ride_id' => $ride, 'driver_id' => $driver, 'expires_at' => now()->addSeconds($seconds), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function accept(int $offerId, int $driverId): int
    {
        return DB::transaction(function () use ($offerId, $driverId) {
            $initial = DB::table('offers')->where('id', $offerId)->where('driver_id', $driverId)->first();
            abort_unless($initial, 404);
            $tenantId = DB::table('rides')->where('id', $initial->ride_id)->value('tenant_id');
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $ride = DB::table('rides')->where('id', $initial->ride_id)->lockForUpdate()->first();
            abort_unless($initial->dispatch_round === $ride->dispatch_round, 409, 'Oferta de uma tentativa anterior.');
            if ($ride->driver_id === $driverId && in_array($ride->status, ['assigned', 'collected', 'completed'])) {
                return $ride->id;
            }
            abort_unless($tenant->courier_module === 'active', 409, 'Módulo em encerramento.');
            abort_unless(app(TenantState::class)->active($tenant->id), 409, 'Empresa indisponível.');
            $offer = DB::table('offers')->find($offerId);
            $driver = DB::table('drivers')->where('id', $driverId)->lockForUpdate()->first();
            abort_unless($offer->status === 'open' && now()->lt($offer->expires_at) && ! $ride->driver_id && in_array($ride->status, ['offered', 'broadcast']) && $this->eligible($driver), 409, 'Oferta indisponível.');
            $store = DB::table('stores')->find($ride->store_id);
            abort_unless(self::distance($driver->latitude, $driver->longitude, $store->latitude, $store->longitude) <= ($ride->status === 'offered' ? 150 : 5000), 409, 'Localização fora da cobertura.');
            DB::table('driver_claims')->where('driver_id', $driverId)->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
            $claim = DB::table('driver_claims')->where('driver_id', $driverId)->first();
            abort_if($claim && $claim->ride_id !== $ride->id, 409, 'Você já possui outra oferta ou corrida.');
            DB::table('driver_claims')->updateOrInsert(['driver_id' => $driverId], ['ride_id' => $ride->id, 'expires_at' => null]);
            DB::table('rides')->where('id', $ride->id)->update(['driver_id' => $driverId, 'status' => 'assigned', 'updated_at' => now()]);
            DB::table('offers')->where('ride_id', $ride->id)->where('status', 'open')->update(['status' => 'lost']);
            DB::table('offers')->where('id', $offerId)->update(['status' => 'accepted']);
            DB::table('drivers')->where('id', $driverId)->update(['available' => false, 'checkin_store_id' => null, 'checked_in_at' => null]);
            Audit::record('ride.accepted', ['ride_id' => $ride->id, 'driver_id' => $driverId], $ride->tenant_id);

            return $ride->id;
        }, 3);
    }

    public function decline(int $offerId, int $driverId): void
    {
        DB::transaction(function () use ($offerId, $driverId) {
            $initial = DB::table('offers')->where('id', $offerId)->where('driver_id', $driverId)->first();
            abort_unless($initial, 404);
            $ride = DB::table('rides')->where('id', $initial->ride_id)->lockForUpdate()->first();
            abort_unless($initial->dispatch_round === $ride->dispatch_round, 409);
            $o = DB::table('offers')->find($offerId);
            abort_unless($o->status === 'open', 409);
            DB::table('offers')->where('id', $offerId)->update(['status' => 'declined']);
            DB::table('driver_claims')->where('driver_id', $driverId)->where('ride_id', $o->ride_id)->whereNotNull('expires_at')->delete();
            DB::table('drivers')->where('id', $driverId)->update(['checked_in_at' => now()]);
        });
    }
}
