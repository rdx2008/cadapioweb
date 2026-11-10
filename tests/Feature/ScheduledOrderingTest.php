<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ScheduledOrderingTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $hours = array_fill(0, 7, ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '23:00']);
        DB::table('stores')->where('id', $store)->update(['timezone' => 'America/Sao_Paulo', 'scheduling_enabled' => true, 'scheduling_lead_minutes' => 60, 'scheduling_prep_minutes' => 30, 'scheduling_days' => 2, 'weekly_hours' => json_encode($hours)]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Lanche', 'category' => 'Lanches', 'price_cents' => 1000]);
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'scheduled_for' => '2026-10-27T11:00', 'items' => [$product => 1], 'idempotency_key' => (string) Str::uuid()];

        return compact('tenant', 'store', 'cart');
    }

    public function test_schedule_works_when_currently_closed_and_snapshots_utc_and_timezone(): void
    {
        $f = $this->fixture();
        $this->get('/cardapio/loja')->assertOk()->assertSee('Quando deseja receber?')->assertSee('27/10 11:00');
        $review = $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('Agendado: 27/10 11:00')->assertSee('10:30');
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['scheduled_at' => '2026-10-27 14:00:00', 'prepare_at' => '2026-10-27 13:30:00', 'scheduled_timezone' => 'America/Sao_Paulo']);
        $this->get('/pedido/'.DB::table('orders')->value('public_id'))->assertOk()->assertSee('Agendado: 27/10 11:00');
        $this->actingAs($this->owner($f['tenant']))->get('/orders/'.DB::table('orders')->value('id'))->assertOk()->assertSee('Agendado: 27/10 11:00');
    }

    public function test_invalid_times_closed_preparation_horizon_and_manual_pause_are_rejected(): void
    {
        $f = $this->fixture();
        foreach (['2026-10-27T09:30', '2026-10-27T10:00', '2026-10-27T10:15', '2026-10-30T12:00', '2026-10-27T11:01', '2026-02-30T11:00', 'invalid'] as $value) {
            $this->postJson('/cardapio/loja/review', array_replace($f['cart'], ['scheduled_for' => $value]))->assertUnprocessable();
        }
        DB::table('stores')->where('id', $f['store'])->update(['open' => false]);
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_disabled_scheduling_and_schedule_changes_after_review_cannot_be_bypassed(): void
    {
        $f = $this->fixture();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('stores')->where('id', $f['store'])->update(['scheduling_prep_minutes' => 45]);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        DB::table('stores')->where('id', $f['store'])->update(['scheduling_enabled' => false]);
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_preparation_is_blocked_until_due_but_acceptance_and_cancellation_are_allowed(): void
    {
        $f = $this->fixture();
        $this->post('/cardapio/loja/review', $f['cart']);
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']]);
        $id = DB::table('orders')->value('id');
        $this->actingAs($this->owner($f['tenant']));
        $this->post('/orders/'.$id.'/status', ['status' => 'accepted'])->assertRedirect();
        $this->post('/orders/'.$id.'/status', ['status' => 'preparing'])->assertStatus(409);
        $this->travelTo(Carbon::parse('2026-10-27 13:30:00', 'UTC'));
        $this->post('/orders/'.$id.'/status', ['status' => 'preparing'])->assertRedirect();
        $this->post('/orders/'.$id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Cliente desistiu'])->assertRedirect();
    }

    public function test_special_closure_and_breaks_block_slots_but_overnight_hours_work(): void
    {
        $f = $this->fixture();
        DB::table('stores')->where('id', $f['store'])->update(['special_hours' => json_encode(['2026-10-27' => ['enabled' => false]])]);
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        $hours = array_fill(0, 7, ['enabled' => true, 'opens_at' => '18:00', 'closes_at' => '02:00']);
        DB::table('stores')->where('id', $f['store'])->update(['special_hours' => null, 'weekly_hours' => json_encode($hours)]);
        $cart = array_replace($f['cart'], ['scheduled_for' => '2026-10-28T01:00']);
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $hours = array_fill(0, 7, ['enabled' => true, 'opens_at' => '10:00', 'closes_at' => '12:00', 'second_opens_at' => '12:10', 'second_closes_at' => '23:00']);
        DB::table('stores')->where('id', $f['store'])->update(['weekly_hours' => json_encode($hours)]);
        $this->postJson('/cardapio/loja/review', array_replace($cart, ['scheduled_for' => '2026-10-27T12:30']))->assertUnprocessable();
    }

    public function test_expired_lead_time_is_revalidated_at_confirmation(): void
    {
        $f = $this->fixture();
        $this->travelTo(Carbon::parse('2026-10-27 12:25:00', 'UTC'));
        $f['cart']['scheduled_for'] = '2026-10-27T10:30';
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->travelTo(Carbon::parse('2026-10-27 12:35:00', 'UTC'));
        $this->postJson('/cardapio/loja',['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        $this->assertDatabaseCount('orders',0);
    }
}
