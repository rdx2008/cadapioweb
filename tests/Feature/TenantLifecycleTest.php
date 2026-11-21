<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class TenantLifecycleTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_admin_can_edit_company_and_owner_without_changing_privileges(): void
    {
        $tenant = $this->tenant();
        $owner = $this->owner($tenant);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/tenants/'.$tenant.'/edit')->assertOk();
        $this->post('/admin/tenants/'.$tenant.'/edit', ['name' => 'New company', 'slug' => 'new-company', 'owner_name' => 'New owner', 'email' => 'new@example.test'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenants', ['id' => $tenant, 'name' => 'New company', 'slug' => 'new-company']);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => 'new@example.test', 'role' => 'owner', 'tenant_id' => $tenant]);
        $this->actingAs($owner)->post('/admin/tenants/'.$tenant.'/status', ['status' => 'suspended', 'reason' => 'Test'])->assertForbidden();
    }

    public function test_suspension_blocks_existing_sessions_public_menu_and_new_offers_but_can_be_reversed(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        app(DispatchService::class)->dispatch($ride);
        $offer = DB::table('offers')->where('ride_id', $ride)->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/tenants/'.$tenant.'/status', ['status' => 'suspended', 'reason' => 'Commercial review'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $ride, 'status' => 'cancelled']);
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'revoked']);
        $this->assertDatabaseMissing('driver_claims', ['ride_id' => $ride]);
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'status' => 'approved', 'checkin_store_id' => null]);
        $this->actingAs($owner)->get('/dashboard')->assertForbidden();
        $this->get('/cardapio/loja')->assertNotFound();
        $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant]);
        $this->actingAs($staff)->get('/operation')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/tenants/'.$tenant.'/status', ['status' => 'active'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get('/bi')->assertOk();
        $this->get('/cardapio/loja')->assertOk();
    }

    public function test_suspended_owner_cannot_log_in(): void
    {
        $tenant = $this->tenant();
        $owner = User::factory()->create(['role' => 'owner', 'tenant_id' => $tenant, 'password' => 'LongPassword123!']);
        DB::table('tenants')->where('id', $tenant)->update(['status' => 'suspended']);
        $this->post('/login', ['email' => $owner->email, 'password' => 'LongPassword123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_assigned_courier_can_finish_after_suspension_and_global_account_remains_available(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        app(DispatchService::class)->dispatch($ride);
        $offer = DB::table('offers')->where('ride_id', $ride)->firstOrFail();
        app(DispatchService::class)->accept($offer->id, $driver);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/tenants/'.$tenant.'/status', ['status' => 'suspended', 'reason' => 'Review'])->assertSessionHasNoErrors();
        $courier = User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
        $this->actingAs($courier)->get('/courier')->assertOk();
        $this->post('/courier/rides/'.$ride, ['status' => 'collected'])->assertSessionHasNoErrors();
        $this->post('/courier/rides/'.$ride, ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $ride, 'status' => 'completed']);
        $this->assertDatabaseHas('drivers', ['id' => $driver, 'status' => 'approved']);
    }

    public function test_deletion_requires_password_and_confirmation_and_preserves_history_and_can_restore(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $order = $this->order($tenant, $store);
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'LongPassword123!']);
        $url = '/admin/tenants/'.$tenant.'/delete';
        $this->actingAs($admin)->post($url, ['confirm_slug' => 'demo', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post($url, ['confirm_slug' => 'wrong', 'current_password' => 'LongPassword123!'])->assertSessionHasErrors('confirm_slug');
        $this->post($url, ['confirm_slug' => 'demo', 'current_password' => 'LongPassword123!'])->assertSessionHasErrors('confirm_slug');
        DB::table('orders')->where('id', $order)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $this->post($url, ['confirm_slug' => 'demo', 'current_password' => 'LongPassword123!'])->assertRedirect('/admin#empresas');
        $this->assertNotNull(DB::table('tenants')->where('id', $tenant)->value('deleted_at'));
        $this->assertDatabaseHas('orders', ['id' => $order, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('stores', ['id' => $store]);
        $this->get('/admin')->assertOk()->assertViewHas('tenants', fn ($tenants) => $tenants->isEmpty());
        $this->get('/admin?include_deleted=1')->assertOk()->assertViewHas('tenants', fn ($tenants) => $tenants->count() === 1);
        $this->post('/admin/tenants/'.$tenant.'/restore')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenants', ['id' => $tenant, 'deleted_at' => null, 'status' => 'suspended']);
    }
}
