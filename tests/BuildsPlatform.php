<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BuildsPlatform
{
    protected function tenant(string $slug = 'demo'): int
    {
        return DB::table('tenants')->insertGetId(['name' => ucfirst($slug), 'slug' => $slug, 'courier_module' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function owner(int $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant, 'role' => 'owner']);
    }

    protected function store(int $tenant, string $slug = 'loja'): int
    {
        return DB::table('stores')->insertGetId(['tenant_id' => $tenant, 'name' => 'Loja '.$slug, 'slug' => $slug, 'address' => 'Rua da Loja, 100', 'latitude' => -23.55, 'longitude' => -46.63, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function order(int $tenant, int $store): int
    {
        return DB::table('orders')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'public_id' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'customer_name' => 'Cliente', 'phone' => '11999999999', 'address' => 'Rua do Cliente, 200', 'mode' => 'delivery', 'status' => 'ready', 'subtotal_cents' => 3000, 'fee_cents' => 500, 'total_cents' => 3500, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function driver(?int $store = null, int $age = 0): int
    {
        $u = User::factory()->create(['role' => 'courier']);

        return DB::table('drivers')->insertGetId(['user_id' => $u->id, 'phone' => '11999999999', 'vehicle' => 'Moto', 'status' => 'approved', 'available' => true, 'latitude' => -23.55, 'longitude' => -46.63, 'accuracy' => 10, 'heartbeat_at' => now()->subSeconds($age), 'checkin_store_id' => $store, 'checked_in_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function ride(int $tenant, int $store): int
    {
        return DB::table('rides')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'order_id' => $this->order($tenant, $store), 'status' => 'searching', 'rate_mode' => 'fixed', 'fare_cents' => 800, 'created_at' => now(), 'updated_at' => now()]);
    }
}
