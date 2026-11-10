<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TenantState
{
    public function active(?int $id): bool
    {
        return $id !== null && DB::table('tenants')->where('id', $id)->where('status', 'active')->whereNull('deleted_at')->exists();
    }

    public function stopNewOffers(int $id): void
    {
        $rides = DB::table('rides')->where('tenant_id', $id)->whereIn('status', ['searching', 'offered', 'broadcast', 'unassigned'])->pluck('id');
        DB::table('offers')->whereIn('ride_id', $rides)->where('status', 'open')->update(['status' => 'revoked']);
        DB::table('driver_claims')->whereIn('ride_id', $rides)->whereNotNull('expires_at')->delete();
        DB::table('rides')->whereIn('id', $rides)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('drivers')->whereIn('checkin_store_id', DB::table('stores')->where('tenant_id', $id)->select('id'))->update(['checkin_store_id' => null, 'checked_in_at' => null]);
    }
}
