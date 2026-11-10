<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationsBoard
{
    public function member(object $actor, bool $kitchen = false, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $actor->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($user->access_enabled && $user->tenant_id && $user->tenant_id === $actor->tenant_id && ($user->role === 'owner' || ($user->role === 'staff' && in_array($user->staff_profile, $kitchen ? ['operator', 'kitchen'] : ['operator'], true))), 403);
        if ($lock) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->firstOrFail();
        }
        abort_unless(app(TenantState::class)->active($user->tenant_id), 403);

        return $user;
    }

    public function stores(object $user): Builder
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->when($user->role === 'staff', fn ($q) => $q->whereIn('id', DB::table('user_store_access')->where('user_id', $user->id)->where('tenant_id', $user->tenant_id)->select('store_id')));
    }

    public function production(object $user, ?int $storeId, array $filters = [], bool $compact = false): array
    {
        $db = DB::class;
        $base = $db::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $this->stores($user)->select('id'))->when($storeId, fn ($q) => $q->where('store_id', $storeId))->when(! empty($filters['mode']), fn ($q) => $q->where('mode', $filters['mode']));
        $lanes = [];
        $counts = [];
        $ids = [];
        foreach (['accepted', 'preparing', 'ready', 'scheduled'] as $stage) {
            $q = (clone $base)->where('status', $stage === 'scheduled' ? 'accepted' : $stage);
            if ($stage === 'scheduled') {
                $q->where('prepare_at', '>', now());
            }
            if ($stage === 'accepted') {
                $q->where(fn ($q) => $q->whereNull('prepare_at')->orWhere('prepare_at', '<=', now()));
            }
            $counts[$stage] = (clone $q)->count();
            $q->orderByRaw('COALESCE(prepare_at, accepted_at, created_at) ASC')->orderBy('id');
            $lanes[$stage] = $compact ? $q->limit(4)->get() : $q->paginate(24, ['*'], $stage.'_page')->withPath(route('kitchen.index'))->appends(request()->except($stage.'_page'));
            $ids = array_merge($ids, $lanes[$stage]->pluck('id')->all());
        }
        $items = $compact ? collect() : $db::table('order_items')->whereIn('order_id', $ids)->select('id', 'order_id', 'quantity', 'name', 'options')->orderBy('id')->get()->groupBy('order_id');

        return compact('lanes', 'counts', 'items');
    }

    public function drivers(object $store): Collection
    {
        if (! is_numeric($store->latitude) || ! is_numeric($store->longitude)) {
            return collect();
        }

        return DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')->where('checkin_store_id', $store->id)->where('drivers.status', 'approved')->where('drivers.available', true)->where('users.access_enabled', true)->where('users.role', 'courier')->where('heartbeat_at', '>=', now()->subSeconds(90))->where('accuracy', '<=', 50)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('driver_claims')->whereColumn('driver_id', 'drivers.id')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rides')->whereColumn('driver_id', 'drivers.id')->whereIn('status', ['assigned', 'collected', 'returning']))
            ->select('drivers.id', 'drivers.latitude', 'drivers.longitude', 'drivers.heartbeat_at', 'drivers.vehicle', 'users.name')->orderBy('users.name')->limit(200)->get()->filter(fn ($driver) => is_numeric($driver->latitude) && is_numeric($driver->longitude) && DispatchService::distance($driver->latitude, $driver->longitude, $store->latitude, $store->longitude) <= 150);
    }

    public function review(object $order, ?object $ride): string
    {
        return hash('sha256', json_encode([$order->id, $order->status, $order->mode, $order->payment_status, $order->payment_method, $order->total_cents, $order->preferred_driver_id, $order->updated_at, $ride?->id, $ride?->status, $ride?->dispatch_round], JSON_THROW_ON_ERROR));
    }
}
