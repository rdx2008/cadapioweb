<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrderProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class OrderProductionTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(Carbon::parse('2026-10-30 15:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $order = $this->order($tenant, $store);
        DB::table('orders')->where('id', $order)->update(['status' => 'pending']);
        $this->actingAs($owner);

        return compact('tenant', 'store', 'owner', 'order');
    }

    public function test_stage_times_target_snapshot_and_lateness_are_preserved_without_auto_progress(): void
    {
        $f = $this->fixture();
        DB::table('stores')->where('id', $f['store'])->update(['preparation_target_minutes' => 15]);
        $url = '/orders/'.$f['order'].'/status';
        $this->post($url, ['status' => 'accepted'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'accepted_at' => '2026-10-30 15:00:00', 'preparation_started_at' => null]);
        $this->travel(2)->minutes();
        $this->post($url, ['status' => 'preparing'])->assertRedirect()->assertSessionHasNoErrors();
        DB::table('stores')->where('id', $f['store'])->update(['preparation_target_minutes' => 90]);
        $this->travel(16)->minutes();
        $order = DB::table('orders')->find($f['order']);
        $timer = app(OrderProduction::class)->timer($order);
        $this->assertTrue((bool) $timer['late']);
        $this->assertStringContainsString('Atrasado 1 min', $timer['text']);
        $this->assertEquals(15, $order->preparation_target_minutes);
        $this->assertSame('preparing', $order->status);
        $this->get('/orders/'.$f['order'])->assertOk()->assertSee('is-late')->assertSee('30/10/2026 12:02:00');
        $this->post($url, ['status' => 'ready'])->assertRedirect()->assertSessionHasNoErrors();
        $this->travel(30)->minutes();
        $timer = app(OrderProduction::class)->timer(DB::table('orders')->find($f['order']));
        $this->assertFalse((bool) $timer['late']);
        $this->assertSame('Preparo concluído em 16 min', $timer['text']);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'ready_at' => '2026-10-30 15:18:00', 'payment_status' => 'pending']);
    }

    public function test_scheduled_and_unpaid_online_guards_do_not_record_a_start_time(): void
    {
        $f = $this->fixture();
        DB::table('orders')->where('id', $f['order'])->update(['status' => 'accepted', 'prepare_at' => now()->addHour(), 'scheduled_at' => now()->addHours(2), 'scheduled_timezone' => 'America/Sao_Paulo']);
        $this->post('/orders/'.$f['order'].'/status', ['status' => 'preparing'])->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'preparation_started_at' => null]);
        $this->get('/orders/'.$f['order'])->assertSee('aguarde o horário');
        DB::table('orders')->where('id', $f['order'])->update(['prepare_at' => null, 'payment_method' => 'online_pagbank']);
        $this->post('/orders/'.$f['order'].'/status', ['status' => 'preparing'])->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'preparation_started_at' => null, 'preparation_target_minutes' => null]);
    }

    public function test_kitchen_records_production_only_in_assigned_store_and_never_sees_customer_data(): void
    {
        $f = $this->fixture();
        $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $f['tenant'], 'staff_profile' => 'kitchen', 'access_enabled' => true]);
        DB::table('user_store_access')->insert(['user_id' => $staff->id, 'tenant_id' => $f['tenant'], 'store_id' => $f['store']]);
        DB::table('orders')->where('id', $f['order'])->update(['status' => 'accepted']);
        $this->actingAs($staff)->post('/operation/orders/'.$f['order'].'/status', ['status' => 'preparing'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('orders')->where('id', $f['order'])->value('preparation_started_at'));
        $this->get('/operation')->assertOk()->assertSee('Meta 30 min')->assertDontSee('11999999999');
        $this->get('/operation/orders/'.$f['order'])->assertOk()->assertSee('Andamento da produção')->assertDontSee('Rua do Cliente');
        DB::table('user_store_access')->where('user_id', $staff->id)->delete();
        $this->post('/operation/orders/'.$f['order'].'/status', ['status' => 'ready'])->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'ready_at' => null]);
    }

    public function test_legacy_records_do_not_invent_times_and_cancelled_preparation_does_not_keep_aging(): void
    {
        $f = $this->fixture();
        DB::table('orders')->where('id', $f['order'])->update(['status' => 'preparing']);
        $this->get('/orders/'.$f['order'])->assertOk()->assertSee('tempo não registrado');
        $this->post('/orders/'.$f['order'].'/status', ['status' => 'ready'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'preparation_started_at' => null, 'accepted_at' => null]);
        DB::table('orders')->where('id', $f['order'])->update(['status' => 'preparing', 'preparation_started_at' => now()->subHour(), 'preparation_target_minutes' => 15]);
        $this->post('/orders/'.$f['order'].'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Cliente desistiu.'])->assertRedirect();
        $timer = app(OrderProduction::class)->timer(DB::table('orders')->find($f['order']));
        $this->assertFalse((bool) $timer['late']);
        $this->assertSame('Produção interrompida', $timer['text']);
    }

    public function test_target_configuration_is_bounded_tenant_scoped_and_independent_of_scheduling(): void
    {
        $f = $this->fixture();
        $payload = ['settings_section' => 'dados', 'name' => 'Loja', 'slug' => 'loja', 'open' => 1, 'minimum_order' => '0', 'preparation_target_minutes' => 0];
        $this->post('/stores/'.$f['store'].'/edit', $payload)->assertSessionHasErrors('preparation_target_minutes');
        $payload['preparation_target_minutes'] = 45;
        $this->post('/stores/'.$f['store'].'/edit', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stores', ['id' => $f['store'], 'preparation_target_minutes' => 45]);
        $this->actingAs($this->owner($this->tenant('foreign')))->post('/stores/'.$f['store'].'/edit', $payload)->assertNotFound();
    }

    public function test_failed_status_audit_rolls_back_production_timestamps_and_target(): void
    {
        $f = $this->fixture();
        DB::table('orders')->where('id', $f['order'])->update(['status' => 'accepted']);
        DB::statement("CREATE TRIGGER fail_production_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'order.status' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post('/orders/'.$f['order'].'/status', ['status' => 'preparing'])->assertStatus(500);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'status' => 'accepted', 'preparation_started_at' => null, 'preparation_target_minutes' => null]);
    }
}
