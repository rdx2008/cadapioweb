<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BusinessIntelligence
{
    private function base(?int $tenantId, ?int $storeId, Carbon $from, Carbon $until): Builder
    {
        return DB::table('orders')->when($tenantId !== null, fn ($q) => $q->where('orders.tenant_id', $tenantId))->when($storeId !== null, fn ($q) => $q->where('orders.store_id', $storeId))->where('orders.created_at', '>=', $from->copy()->utc()->format('Y-m-d H:i:s'))->where('orders.created_at', '<', $until->copy()->utc()->format('Y-m-d H:i:s'));
    }

    private function paid(Builder $query): Builder
    {
        return (clone $query)->where('orders.payment_status', 'paid')->where('orders.status', '!=', 'cancelled');
    }

    private function summary(Builder $query): array
    {
        $row = (clone $query)->selectRaw("COUNT(*) AS orders_count, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN total_cents ELSE 0 END),0) AS paid_cents, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN subtotal_cents ELSE 0 END),0) AS product_cents, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN fee_cents ELSE 0 END),0) AS freight_cents, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN 1 ELSE 0 END),0) AS paid_orders, COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END),0) AS completed_orders, COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END),0) AS cancelled_orders, COALESCE(SUM(CASE WHEN payment_status <> 'paid' AND status <> 'cancelled' THEN total_cents ELSE 0 END),0) AS pending_cents")->first();
        $result = array_map('intval', (array) $row);
        $result['average_cents'] = $result['paid_orders'] ? (int) round($result['paid_cents'] / $result['paid_orders']) : 0;
        $result['cancellation_rate'] = $result['orders_count'] ? round(100 * $result['cancelled_orders'] / $result['orders_count'], 1) : 0;

        return $result;
    }

    private function customerExpression(): string
    {
        $digits = 'orders.phone';
        foreach ([' ', '+', '(', ')', '-', '.', "\t", "\r", "\n", "\u{00A0}"] as $character) {
            $digits = "REPLACE($digits, '$character', '')";
        }
        $phone = "CASE WHEN LENGTH($digits) IN (12,13) AND SUBSTR($digits,1,2) = '55' THEN SUBSTR($digits,3) ELSE $digits END";
        $numeric = DB::connection()->getDriverName() === 'pgsql' ? "$digits ~ '^[0-9]+$'" : "$digits NOT GLOB '*[^0-9]*'";

        return "CASE WHEN LENGTH($phone) BETWEEN 8 AND 15 AND ($numeric) THEN CAST(orders.tenant_id AS TEXT) || ':' || ($phone) ELSE NULL END";
    }

    public function report(?int $tenantId, ?int $storeId, Carbon $start, Carbon $end, string $timezone, bool $platform): array
    {
        if ($storeId !== null) {
            DB::table('stores')->where('id', $storeId)->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->firstOrFail();
        }
        $days = (int) $start->diffInDays($end) + 1;
        $until = $end->copy()->addDay();
        $previousStart = $start->copy()->subDays($days);
        $query = $this->base($tenantId, $storeId, $start, $until);
        $previousQuery = $this->base($tenantId, $storeId, $previousStart, $start);
        $summary = $this->summary($query);
        $previous = $this->summary($previousQuery);
        $customer = $this->customerExpression();
        $buyers = (clone $query)->where('orders.status', 'completed')->selectRaw("$customer AS customer_key, COUNT(*) AS visits")->groupByRaw($customer);
        $summary['customers'] = DB::query()->fromSub(clone $buyers, 'buyers')->whereNotNull('customer_key')->count();
        $summary['returning_customers'] = DB::query()->fromSub(clone $buyers, 'buyers')->whereNotNull('customer_key')->where('visits', '>', 1)->count();
        $identifiedOrders = (int) DB::query()->fromSub(clone $buyers, 'buyers')->whereNotNull('customer_key')->sum('visits');
        $summary['frequency'] = $summary['customers'] ? round($identifiedOrders / $summary['customers'], 2) : 0;
        $growth = $previous['paid_cents'] > 0 ? round(100 * ($summary['paid_cents'] - $previous['paid_cents']) / $previous['paid_cents'], 1) : ($summary['paid_cents'] === 0 ? 0 : null);
        $daily = [];
        $weekday = array_fill(0, 7, ['days' => 0, 'paid_cents' => 0, 'paid_orders' => 0, 'average_cents' => 0]);
        $hours = array_fill(0, 24, ['paid_orders' => 0, 'paid_cents' => 0]);
        $methods = $modes = $statuses = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $daily[$date->toDateString()] = ['date' => $date->toDateString(), 'orders' => 0, 'paid_cents' => 0, 'paid_orders' => 0, 'previous_cents' => 0, 'new_tenants' => 0];
            $weekday[$date->dayOfWeek]['days']++;
        }
        foreach ((clone $query)->select('orders.id', 'orders.created_at', 'orders.status', 'orders.payment_status', 'orders.total_cents', 'orders.mode', 'orders.payment_method')->lazyById(1000, 'orders.id', 'id') as $order) {
            $at = Carbon::parse($order->created_at, 'UTC')->timezone($timezone);
            $day = $at->toDateString();
            if (! isset($daily[$day])) {
                continue;
            }
            $daily[$day]['orders']++;
            $statuses[$order->status] = ($statuses[$order->status] ?? 0) + 1;
            if ($order->payment_status === 'paid' && $order->status !== 'cancelled') {
                $amount = (int) $order->total_cents;
                $daily[$day]['paid_cents'] += $amount;
                $daily[$day]['paid_orders']++;
                $weekday[$at->dayOfWeek]['paid_cents'] += $amount;
                $weekday[$at->dayOfWeek]['paid_orders']++;
                $hours[$at->hour]['paid_cents'] += $amount;
                $hours[$at->hour]['paid_orders']++;
                foreach (['methods' => $order->payment_method, 'modes' => $order->mode] as $group => $key) {
                    ${$group}[$key] ??= ['count' => 0, 'paid_cents' => 0];
                    ${$group}[$key]['count']++;
                    ${$group}[$key]['paid_cents'] += $amount;
                }
            }
        }
        foreach ($this->paid($previousQuery)->select('orders.id', 'orders.created_at', 'orders.total_cents')->lazyById(1000, 'orders.id', 'id') as $order) {
            $aligned = Carbon::parse($order->created_at, 'UTC')->timezone($timezone)->addDays($days)->toDateString();
            if (isset($daily[$aligned])) {
                $daily[$aligned]['previous_cents'] += (int) $order->total_cents;
            }
        }
        foreach ($weekday as &$day) {
            $day['average_cents'] = $day['days'] ? (int) round($day['paid_cents'] / $day['days']) : 0;
        }
        unset($day);
        $paidQuery = $this->paid($query);
        $products = DB::table('order_items')->whereIn('order_id', (clone $paidQuery)->select('orders.id'))->selectRaw('name, SUM(quantity) AS units, SUM(quantity * unit_cents) AS revenue_cents')->groupBy('name')->orderByDesc('revenue_cents')->orderBy('name')->limit(12)->get();
        $storeSales = (clone $paidQuery)->selectRaw('orders.store_id, COUNT(*) AS paid_orders, SUM(total_cents) AS paid_cents')->groupBy('orders.store_id');
        $storeRanking = DB::query()->fromSub($storeSales, 'sales')->join('stores', 'stores.id', '=', 'sales.store_id')->select('sales.*', 'stores.name')->orderByDesc('paid_cents')->orderBy('stores.id')->limit(20)->get();
        $companyRanking = collect();
        $platformStats = null;
        if ($platform) {
            $sales = (clone $paidQuery)->selectRaw('orders.tenant_id, COUNT(*) AS paid_orders, SUM(total_cents) AS paid_cents')->groupBy('orders.tenant_id');
            $companyRanking = DB::query()->fromSub($sales, 'sales')->join('tenants', 'tenants.id', '=', 'sales.tenant_id')->select('sales.*', 'tenants.name', 'tenants.deleted_at')->orderByDesc('paid_cents')->orderBy('tenants.id')->limit(20)->get();
            $companies = DB::table('tenants')->when($tenantId !== null, fn ($q) => $q->where('id', $tenantId));
            $platformStats = ['active' => (clone $companies)->whereNull('deleted_at')->where('status', 'active')->count(), 'suspended' => (clone $companies)->whereNull('deleted_at')->where('status', 'suspended')->count(), 'deleted' => (clone $companies)->whereNotNull('deleted_at')->count(), 'new' => (clone $companies)->where('created_at', '>=', $start->copy()->utc())->where('created_at', '<', $until->copy()->utc())->count(), 'removed' => (clone $companies)->where('deleted_at', '>=', $start->copy()->utc())->where('deleted_at', '<', $until->copy()->utc())->count(), 'selling' => (clone $paidQuery)->distinct()->count('orders.tenant_id'), 'stores' => DB::table('stores')->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->count()];
            foreach ((clone $companies)->where('created_at', '>=', $start->copy()->utc())->where('created_at', '<', $until->copy()->utc())->select('id', 'created_at')->lazyById(1000) as $company) {
                $key = Carbon::parse($company->created_at, 'UTC')->timezone($timezone)->toDateString();
                if (isset($daily[$key])) {
                    $daily[$key]['new_tenants']++;
                }
            }
        }

        return compact('summary', 'previous', 'growth', 'daily', 'weekday', 'hours', 'methods', 'modes', 'statuses', 'products', 'storeRanking', 'companyRanking', 'platformStats', 'days', 'previousStart', 'start', 'end', 'timezone');
    }
}
