<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DashboardDesignTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_metrics_use_the_full_day_and_ignore_unpaid_cancelled_and_foreign_sales(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(16, 0));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        for ($i = 0; $i < 32; $i++) {
            $id = $this->order($tenant, $store);
            DB::table('orders')->where('id', $id)->update(['payment_status' => 'paid', 'status' => 'completed', 'total_cents' => 1000]);
        }
        $unpaid = $this->order($tenant, $store);
        $cancelled = $this->order($tenant, $store);
        DB::table('orders')->where('id', $cancelled)->update(['payment_status' => 'paid', 'status' => 'cancelled']);
        $old = $this->order($tenant, $store);
        DB::table('orders')->where('id', $old)->update(['payment_status' => 'paid', 'status' => 'completed', 'created_at' => now()->subDays(2)]);
        $other = $this->tenant('other');
        $this->order($other, $this->store($other, 'other'));
        $this->actingAs($this->owner($tenant))->getJson('/orders/live')->assertOk()
            ->assertJsonPath('summary.today_orders', 34)->assertJsonPath('summary.paid_cents', 32000)
            ->assertJsonPath('summary.average_cents', 1000)->assertJsonPath('summary.ready', 1);
        $this->get('/dashboard')->assertOk()->assertSee('Sua operação, em tempo real.')->assertSee('320,00');
        $this->getJson('/orders/live?store_id='.DB::table('stores')->where('tenant_id', $other)->value('id'))->assertNotFound();
        $this->get('/dashboard?store_id='.DB::table('stores')->where('tenant_id', $other)->value('id'))->assertNotFound();
    }

    public function test_owner_can_pause_and_resume_only_own_store(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->tenant('other');
        $foreignStore = $this->store($other, 'other');
        $this->actingAs($this->owner($tenant));
        $this->post('/stores/'.$foreignStore.'/availability', ['open' => 0])->assertNotFound();
        $this->post('/stores/'.$store.'/availability', ['open' => 0])->assertRedirect();
        $this->assertDatabaseHas('stores', ['id' => $store, 'open' => false]);
        $this->get('/dashboard')->assertOk()->assertSee('Pedidos pausados');
        $this->post('/stores/'.$store.'/availability', ['open' => 1])->assertRedirect();
        $this->assertDatabaseHas('stores', ['id' => $store, 'open' => true]);
        $this->assertDatabaseHas('stores', ['id' => $foreignStore, 'open' => true]);
    }

    public function test_superadmin_dashboard_renders_owner_contacts_and_platform_counts(): void
    {
        $tenant = $this->tenant();
        $this->store($tenant);
        $owner = $this->owner($tenant);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Sua plataforma, sob controle.')
            ->assertSee($owner->email)->assertSee('Buscar empresa, identificador ou e-mail')->assertSee('Criar empresa e acesso');
        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }
}
