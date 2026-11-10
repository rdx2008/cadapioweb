<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliveryStatement
{
    public const KINDS = ['completed' => 'Entrega concluída', 'returned' => 'Devolvida à loja', 'released_before_pickup' => 'Liberada antes da coleta'];

    public function query(?int $tenantId, ?int $driverId, ?int $storeId, Carbon $start, Carbon $until, string $settlement): Builder
    {
        $completed = DB::table('rides')->join('stores', 'stores.id', '=', 'rides.store_id')->join('drivers', 'drivers.id', '=', 'rides.driver_id')->join('users', 'users.id', '=', 'drivers.user_id')
            ->where('rides.status', 'completed')->when($tenantId !== null, fn ($q) => $q->where('rides.tenant_id', $tenantId))->when($driverId !== null, fn ($q) => $q->where('rides.driver_id', $driverId))
            ->selectRaw("rides.id AS entry_id, rides.id AS ride_id, rides.order_id, rides.store_id, rides.driver_id, stores.name AS store_name, users.name AS driver_name, 'completed' AS kind, COALESCE(rides.completed_at, rides.updated_at, rides.created_at) AS occurred_at, rides.fare_cents AS quoted_cents, rides.fare_cents AS compensation_cents, rides.cash_cents AS customer_cash_cents, rides.settled");
        $attempts = DB::table('delivery_attempts')->join('rides', 'rides.id', '=', 'delivery_attempts.ride_id')->join('stores', 'stores.id', '=', 'rides.store_id')->join('drivers', 'drivers.id', '=', 'delivery_attempts.driver_id')->join('users', 'users.id', '=', 'drivers.user_id')
            ->when($tenantId !== null, fn ($q) => $q->where('delivery_attempts.tenant_id', $tenantId))->when($driverId !== null, fn ($q) => $q->where('delivery_attempts.driver_id', $driverId))
            ->selectRaw('delivery_attempts.id AS entry_id, rides.id AS ride_id, rides.order_id, rides.store_id, delivery_attempts.driver_id, stores.name AS store_name, users.name AS driver_name, delivery_attempts.outcome AS kind, delivery_attempts.closed_at AS occurred_at, delivery_attempts.quoted_fare_cents AS quoted_cents, delivery_attempts.compensation_cents, delivery_attempts.customer_cash_cents, delivery_attempts.settled');

        return DB::query()->fromSub($completed->unionAll($attempts), 'entries')
            ->where('occurred_at', '>=', $start->copy()->utc()->format('Y-m-d H:i:s'))->where('occurred_at', '<', $until->copy()->utc()->format('Y-m-d H:i:s'))
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->when($settlement !== 'all', fn ($q) => $q->where('settled', $settlement === 'settled'));
    }

    public function summary(Builder $query): object
    {
        return (clone $query)->selectRaw("COUNT(*) AS entries_count, COALESCE(SUM(compensation_cents),0) AS compensation_cents, COALESCE(SUM(CASE WHEN settled THEN 0 ELSE compensation_cents END),0) AS pending_compensation_cents, COALESCE(SUM(CASE WHEN kind = 'completed' THEN customer_cash_cents ELSE 0 END),0) AS completed_cash_cents, COALESCE(SUM(CASE WHEN kind <> 'completed' THEN customer_cash_cents ELSE 0 END),0) AS attempt_cash_cents")->first();
    }

    public function ordered(Builder $query): Builder
    {
        return (clone $query)->orderByDesc('occurred_at')->orderBy('kind')->orderByDesc('entry_id');
    }
}
