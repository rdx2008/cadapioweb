<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DashboardSummary
{
    public function forTenant(int $tenantId, ?int $storeId = null): array
    {
        $store = $storeId ? DB::table('stores')->where('tenant_id', $tenantId)->where('id', $storeId)->firstOrFail() : null;
        $day = now()->timezone($store->timezone ?? 'America/Sao_Paulo')->startOfDay();
        $query = DB::table('orders')->where('tenant_id', $tenantId)->when($storeId, fn ($query) => $query->where('store_id', $storeId));
        $today = (clone $query)->where('created_at', '>=', $day->copy()->utc())->where('created_at', '<', $day->copy()->addDay()->utc());
        $paid = (clone $today)->where('payment_status', 'paid')->where('status', '!=', 'cancelled');
        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'today_orders' => (clone $today)->count(),
            'preparing' => (int) ($counts['preparing'] ?? 0),
            'paid_cents' => (int) (clone $paid)->sum('total_cents'),
            'average_cents' => (int) round((clone $paid)->avg('total_cents') ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'accepted' => (int) ($counts['accepted'] ?? 0),
            'ready' => (int) ($counts['ready'] ?? 0),
            'active' => (int) ($counts['pending'] ?? 0) + (int) ($counts['accepted'] ?? 0) + (int) ($counts['preparing'] ?? 0) + (int) ($counts['ready'] ?? 0),
        ];
    }
}
