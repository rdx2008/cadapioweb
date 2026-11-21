<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StoreAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class StoreSpecialHoursTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_owner_can_save_replace_and_remove_dates_without_changing_weekly_hours_or_other_stores(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->store($tenant, 'outra');
        $this->actingAs($this->owner($tenant));
        $this->post(route('stores.special-hours', $store), ['special_date' => '2026-12-25', 'enabled' => 0, 'tenant_id' => 999])->assertRedirect(route('stores.edit', $store).'#special-hours');
        $this->post(route('stores.special-hours', $store), ['special_date' => '2026-12-31', 'enabled' => 1, 'opens_at' => '11:00', 'closes_at' => '14:00', 'second_opens_at' => '18:00', 'second_closes_at' => '02:00'])->assertRedirect();
        $dates = json_decode(DB::table('stores')->where('id', $store)->value('special_hours'), true);
        $this->assertFalse($dates['2026-12-25']['enabled']);
        $this->assertSame('02:00', $dates['2026-12-31']['second_closes_at']);
        $this->post(route('stores.special-hours', $store), ['special_date' => '2026-12-25', 'enabled' => 1, 'opens_at' => '12:00', 'closes_at' => '16:00'])->assertRedirect();
        $this->get(route('stores.edit', $store))->assertOk()->assertSee('Feriados e horários especiais')->assertSee('25/12/2026')->assertSee('31/12/2026');
        $this->post(route('stores.special-hours.remove', $store), ['special_date' => '2026-12-25'])->assertRedirect();
        $dates = json_decode(DB::table('stores')->where('id', $store)->value('special_hours'), true);
        $this->assertArrayNotHasKey('2026-12-25', $dates);
        $this->assertArrayHasKey('2026-12-31', $dates);
        $this->assertNull(DB::table('stores')->where('id', $store)->value('weekly_hours'));
        $this->assertNull(DB::table('stores')->where('id', $other)->value('special_hours'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant, 'action' => 'store.special_hours.saved']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant, 'action' => 'store.special_hours.removed']);
    }

    public function test_dates_are_validated_and_only_owners_can_manage_their_own_store(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $foreign = $this->store($this->tenant('foreign'), 'foreign');
        $data = ['special_date' => '2026-12-25', 'enabled' => 0];
        $this->actingAs($this->owner($tenant))->post(route('stores.special-hours', $foreign), $data)->assertNotFound();
        $this->post(route('stores.special-hours.remove', $foreign), $data)->assertNotFound();
        $this->postJson(route('stores.special-hours', $store), ['special_date' => '2026-02-30', 'enabled' => 1])->assertUnprocessable()->assertJsonValidationErrors(['special_date', 'opens_at', 'closes_at']);
        $this->postJson(route('stores.special-hours', $store), ['special_date' => '2026-12-25', 'enabled' => 1, 'opens_at' => '11:00', 'closes_at' => '14:00', 'second_opens_at' => '18:00'])->assertUnprocessable()->assertJsonValidationErrors('second_closes_at');
        foreach (['staff', 'courier', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'tenant_id' => $tenant]))->post(route('stores.special-hours', $store), $data)->assertForbidden();
            $this->post(route('stores.special-hours.remove', $store), $data)->assertForbidden();
        }
        $this->assertNull(DB::table('stores')->where('id', $store)->value('special_hours'));
    }

    public function test_special_hours_override_weekly_or_manual_schedule_use_local_date_and_respect_pause(): void
    {
        $availability = app(StoreAvailability::class);
        $store = (object) ['open' => true, 'timezone' => 'America/Manaus', 'weekly_hours' => null, 'special_hours' => json_encode(['2026-12-25' => ['enabled' => false], '2026-12-26' => ['enabled' => true, 'opens_at' => '11:00', 'closes_at' => '14:00', 'second_opens_at' => '18:00', 'second_closes_at' => '23:00']])];
        $this->assertTrue($availability->isOpen($store, Carbon::parse('2026-12-25 03:59:59', 'UTC')));
        $this->assertFalse($availability->isOpen($store, Carbon::parse('2026-12-25 04:00:00', 'UTC')));
        $this->travelTo(Carbon::parse('2026-12-25 12:00:00', 'America/Manaus'));
        $this->assertSame('Fechado nesta data especial', $availability->todayLabel($store));
        foreach (['10:59:59' => false, '11:00:00' => true, '14:00:00' => false, '17:59:59' => false, '18:00:00' => true, '23:00:00' => false] as $time => $expected) {
            $this->assertSame($expected, $availability->isOpen($store, Carbon::parse('2026-12-26 '.$time, 'America/Manaus')));
        }
        $this->travelTo(Carbon::parse('2026-12-26 12:00:00', 'America/Manaus'));
        $this->assertSame('Horário especial: 11:00 às 14:00 · 18:00 às 23:00', $availability->todayLabel($store));
        $store->open = false;
        $this->assertFalse($availability->isOpen($store));
    }

    public function test_midnight_override_cuts_previous_night_and_special_overnight_carries_only_without_next_override(): void
    {
        $availability = app(StoreAvailability::class);
        $hours = array_fill(0, 7, ['enabled' => true, 'opens_at' => '22:00', 'closes_at' => '02:00']);
        $store = (object) ['open' => true, 'timezone' => 'America/Sao_Paulo', 'weekly_hours' => json_encode($hours), 'special_hours' => json_encode(['2026-12-25' => ['enabled' => false], '2026-12-26' => ['enabled' => true, 'opens_at' => '18:00', 'closes_at' => '03:00']])];
        $this->assertTrue($availability->isOpen($store, Carbon::parse('2026-12-24 23:59:59', $store->timezone)));
        $this->assertFalse($availability->isOpen($store, Carbon::parse('2026-12-25 00:00:00', $store->timezone)));
        $this->assertFalse($availability->isOpen($store, Carbon::parse('2026-12-26 01:00:00', $store->timezone)));
        $this->assertTrue($availability->isOpen($store, Carbon::parse('2026-12-27 02:59:59', $store->timezone)));
        $this->assertFalse($availability->isOpen($store, Carbon::parse('2026-12-27 03:00:00', $store->timezone)));
        $dates = json_decode($store->special_hours, true);
        $dates['2026-12-27'] = ['enabled' => true, 'opens_at' => '12:00', 'closes_at' => '16:00'];
        $store->special_hours = json_encode($dates);
        $this->assertFalse($availability->isOpen($store, Carbon::parse('2026-12-27 01:00:00', $store->timezone)));
    }

    public function test_customer_cannot_review_or_confirm_after_special_closure_and_can_order_during_special_opening(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Lanche', 'category' => 'Lanches', 'price_cents' => 1000]);
        $this->travelTo(Carbon::parse('2026-12-25 12:00:00', 'America/Sao_Paulo'));
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'items' => [$product => 1], 'idempotency_key' => (string) Str::uuid()];
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        DB::table('stores')->where('id', $store)->update(['special_hours' => json_encode(['2026-12-25' => ['enabled' => false]])]);
        $this->get('/cardapio/loja')->assertOk()->assertSee('Fechado nesta data especial');
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        DB::table('stores')->where('id', $store)->update(['special_hours' => json_encode(['2026-12-25' => ['enabled' => true, 'opens_at' => '11:00', 'closes_at' => '14:00']]), 'weekly_hours' => json_encode(array_fill(0, 7, ['enabled' => false]))]);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['store_id' => $store, 'total_cents' => 1000]);
        $this->travelTo(Carbon::parse('2026-12-25 13:59:59', 'America/Sao_Paulo'));
        $cart['idempotency_key'] = (string) Str::uuid();
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->travelTo(Carbon::parse('2026-12-25 14:00:00', 'America/Sao_Paulo'));
        $this->postJson('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
    }
}
