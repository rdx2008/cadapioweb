<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $action, array $details = [], ?int $tenant = null): void
    {
        DB::table('audit_events')->insert(['tenant_id' => $tenant, 'user_id' => auth()->id(), 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
