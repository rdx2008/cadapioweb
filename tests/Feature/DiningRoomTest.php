<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashRegister;
use App\Services\DiningRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DiningRoomTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(bool $openCash = true): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        DB::table('stores')->where('id', $store)->update(['accepts_pix' => true, 'accepts_card' => true]);
        $this->post('/dining/tables', ['store_id' => $store, 'name' => 'Mesa 1', 'seats' => 4, 'enabled' => '1'])->assertSessionHasNoErrors();
        $table = DB::table('dining_tables')->first();
        $key = (string) Str::uuid();
        $this->post('/dining/tables/'.$table->id.'/open', ['guests' => 2, 'guest_label' => 'Ana', 'request_key' => $key])->assertSessionHasNoErrors();
        $tab = DB::table('dining_tabs')->first();
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Prato da casa', 'category' => 'Pratos', 'price_cents' => 3000]);
        if ($openCash) {
            app(CashRegister::class)->open(DB::table('stores')->find($store), $owner->id, 0, (string) Str::uuid());
        }

        return compact('tenant', 'store', 'owner', 'table', 'tab', 'product', 'key');
    }

    private function draft(array $f, array $replace = []): array
    {
        $key = (string) Str::uuid();
        $data = array_replace(['idempotency_key' => $key, 'dining_tab_id' => $f['tab']->id, 'mode' => 'dine_in', 'received' => 'no', 'payment_method' => 'cash', 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1]]], $replace);
        $this->post('/pos/'.$f['store'].'/review', $data)->assertSessionHasNoErrors();
        $response = $this->get('/pos/'.$f['store'].'/review/'.$key)->assertOk();

        return ['key' => $key, 'review_hash' => hash('sha256', json_encode($response->viewData('draft'), JSON_THROW_ON_ERROR))];
    }

    private function orderForTab(array $f): object
    {
        $draft = $this->draft($f);
        $this->post('/pos/'.$f['store'].'/review/'.$draft['key'].'/confirm', ['review_hash' => $draft['review_hash']])->assertSessionHasNoErrors();
        $this->post('/pos/'.$f['store'].'/review/'.$draft['key'].'/confirm', ['review_hash' => $draft['review_hash']])->assertSessionHasNoErrors();

        return DB::table('orders')->where('idempotency_key', $draft['key'])->firstOrFail();
    }

    private function payment(array $f, string $method = 'cash'): array
    {
        $summary = app(DiningRoom::class)->summary($f['tab']);

        return ['request_key' => (string) Str::uuid(), 'review_hash' => $summary['review_hash'], 'payment_method' => $method, 'confirmed' => '1'];
    }

    public function test_table_orders_reach_kitchen_with_table_label_and_can_be_served_paid_and_closed(): void
    {
        $f = $this->fixture();
        $this->get('/dining')->assertOk()->assertSee('Mesa 1')->assertSee('Ocupada');
        $this->get('/pos/'.$f['store'].'?tab='.$f['tab']->id)->assertOk()->assertSee('Consumo no local')->assertSee('dining_tab_id')->assertDontSee('value="delivery"', false);
        $order = $this->orderForTab($f);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'mode' => 'dine_in', 'dining_tab_id' => $f['tab']->id, 'fee_cents' => 0, 'status' => 'accepted', 'payment_status' => 'pending']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->get('/kitchen')->assertOk()->assertSee('Mesa 1')->assertSee('Comanda #'.$f['tab']->id)->assertDontSee('Ana');
        $print = $this->post('/orders/'.$order->id.'/print-jobs', ['kind' => 'production', 'paper' => '80', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get($print->headers->get('Location'))->assertOk()->assertSee('MESA 1')->assertSee('COMANDA #'.$f['tab']->id)->assertDontSee('Ana')->assertDontSee('R$');
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('Mesa 1');
        $this->get('/pos/orders/'.$order->id)->assertOk()->assertSee('Voltar à comanda')->assertDontSee('Compartilhar no WhatsApp');
        $close = ['confirmed' => '1', 'request_key' => (string) Str::uuid()];
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', $close)->assertSessionHasErrors('tab');
        $this->post('/dining/tabs/'.$f['tab']->id.'/orders/'.$order->id.'/serve')->assertConflict();
        $this->post('/orders/'.$order->id.'/status', ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/status', ['status' => 'ready'])->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/orders/'.$order->id.'/serve')->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/orders/'.$order->id.'/serve')->assertSessionHasNoErrors();
        $pay = $this->payment($f, 'pix');
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay)->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_entries', ['order_id' => $order->id, 'kind' => 'sale', 'amount_cents' => 3000, 'payment_method' => 'pix']);
        $this->assertDatabaseCount('cash_entries', 1);
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', $close)->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', $close)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_tabs', ['id' => $f['tab']->id, 'active_table_id' => null, 'closing_total_cents' => 3000]);
        $this->get('/dining/tabs/'.$f['tab']->id.'/print')->assertOk()->assertSee('Conta encerrada')->assertSee('30,00');
        $this->get('/pos/'.$f['store'].'?tab='.$f['tab']->id)->assertConflict();
        $this->post('/dining/tables/'.$f['table']->id.'/open', ['guests' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('dining_tabs', 2);
        $this->assertDatabaseHas('dining_tabs', ['id' => $f['tab']->id, 'active_table_id' => null, 'closing_total_cents' => 3000]);
    }

    public function test_stale_balance_is_rejected_and_payment_retry_does_not_pay_new_orders(): void
    {
        $f = $this->fixture();
        $first = $this->orderForTab($f);
        $stale = $this->payment($f);
        $second = $this->orderForTab($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $stale)->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('cash_entries', 0);
        $fresh = $this->payment($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $fresh)->assertSessionHasNoErrors();
        $third = $this->orderForTab($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $fresh)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $third->id, 'payment_status' => 'pending']);
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertDatabaseHas('dining_settlements', ['dining_tab_id' => $f['tab']->id, 'amount_cents' => 6000]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', array_replace($fresh, ['payment_method' => 'pix']))->assertConflict();
        $this->assertEquals(3000, app(DiningRoom::class)->summary($f['tab'])['pending']);
    }

    public function test_opening_is_unique_idempotent_and_table_name_cannot_drift_during_attendance(): void
    {
        $f = $this->fixture();
        $open = ['guests' => 2, 'guest_label' => 'Ana', 'request_key' => $f['key']];
        $this->post('/dining/tables/'.$f['table']->id.'/open', $open)->assertSessionHasNoErrors();
        $this->post('/dining/tables/'.$f['table']->id.'/open', array_replace($open, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->post('/dining/tables/'.$f['table']->id.'/open', array_replace($open, ['guests' => 3]))->assertConflict();
        $this->assertDatabaseCount('dining_tabs', 1);
        $this->post('/dining/tables/'.$f['table']->id, ['store_id' => $f['store'], 'name' => 'Mesa 2', 'seats' => 4, 'enabled' => 0])->assertConflict();
        $this->post('/dining/tables', ['store_id' => $f['store'], 'name' => 'mesa 1', 'seats' => 4, 'enabled' => 1])->assertSessionHasErrors('name');
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/dining/tables/'.$f['table']->id, ['store_id' => $f['store'], 'name' => 'Mesa 1', 'seats' => 6, 'enabled' => 0])->assertSessionHasNoErrors();
        $this->post('/dining/tables/'.$f['table']->id.'/open', array_replace($open, ['request_key' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_closed_tab_blocks_confirmation_of_old_draft_and_public_delivery_cannot_forge_a_table(): void
    {
        $f = $this->fixture();
        $draft = $this->draft($f);
        $this->post('/pos/'.$f['store'].'/review/'.$draft['key'].'/edit')->assertRedirect('/pos/'.$f['store'].'?tab='.$f['tab']->id);
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/pos/'.$f['store'].'/review/'.$draft['key'].'/confirm', ['review_hash' => $draft['review_hash']])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->post('/pos/'.$f['store'].'/review', ['mode' => 'dine_in', 'idempotency_key' => (string) Str::uuid(), 'received' => 'no', 'payment_method' => 'cash', 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1]]])->assertUnprocessable();
        $this->post('/cardapio/loja/review', ['mode' => 'dine_in'])->assertSessionHasErrors('mode');
    }

    public function test_operator_store_scope_and_revoked_access_apply_to_every_tab_action(): void
    {
        $f = $this->fixture();
        $other = $this->store($f['tenant'], 'other');
        $operator = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'operator']);
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'user_id' => $operator->id, 'store_id' => $other]);
        $this->actingAs($operator)->get('/dining/tabs/'.$f['tab']->id)->assertNotFound();
        $this->get('/dining?store_id='.$f['store'])->assertNotFound();
        $this->get('/pos/'.$other.'?tab='.$f['tab']->id)->assertNotFound();
        $this->post('/dining/tables/'.$f['table']->id.'/open', ['guests' => 1, 'request_key' => (string) Str::uuid()])->assertNotFound();
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'user_id' => $operator->id, 'store_id' => $f['store']]);
        $this->get('/dining/tabs/'.$f['tab']->id)->assertOk();
        $this->post('/dining/tables', ['store_id' => $f['store'], 'name' => 'Mesa 2', 'seats' => 4, 'enabled' => 1])->assertForbidden();
        $order = $this->orderForTab($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $this->payment($f, 'card'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_entries', ['order_id' => $order->id, 'user_id' => $operator->id]);
        DB::table('user_store_access')->where('user_id', $operator->id)->delete();
        $this->get('/dining/tabs/'.$f['tab']->id.'/print')->assertNotFound();
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $this->payment($f))->assertNotFound();
        $this->actingAs($this->owner($this->tenant('foreign')))->get('/dining/tabs/'.$f['tab']->id)->assertNotFound();
        foreach (['admin', 'courier', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['tenant_id' => $f['tenant'], 'role' => $role, 'staff_profile' => $role === 'staff' ? 'kitchen' : null]))->get('/dining')->assertForbidden();
        }
    }

    public function test_cash_closed_or_audit_failure_cannot_partially_settle_the_account(): void
    {
        $f = $this->fixture(false);
        $order = $this->orderForTab($f);
        $pay = $this->payment($f);
        DB::table('stores')->where('id', $f['store'])->update(['accepts_card' => false]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', array_replace($pay, ['payment_method' => 'card']))->assertUnprocessable();
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay)->assertSessionHasErrors('cash');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'pending']);
        $this->assertDatabaseCount('dining_settlements', 0);
        app(CashRegister::class)->open(DB::table('stores')->find($f['store']), $f['owner']->id, 0, (string) Str::uuid());
        DB::statement("CREATE TRIGGER fail_dining_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'dining.paid' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay)->assertStatus(500);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('dining_settlements', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'pending']);
    }

    public function test_cancelled_paid_order_requires_refund_before_tab_can_close(): void
    {
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $this->payment($f))->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Customer requested'])->assertSessionHasNoErrors();
        $close = ['confirmed' => 1, 'request_key' => (string) Str::uuid()];
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', $close)->assertSessionHasErrors('tab');
        $this->assertEquals(0, app(DiningRoom::class)->summary($f['tab'])['total']);
        $session = DB::table('cash_sessions')->where('open_store_id', $f['store'])->first();
        $this->post('/cash/'.$session->id.'/refunds', ['order_id' => $order->id, 'note' => 'Devolvido', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', $close)->assertSessionHasNoErrors();
    }

    public function test_partial_receipts_split_methods_preserve_exact_balance_and_close_only_when_paid(): void
    {
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $url = '/dining/tabs/'.$f['tab']->id;
        $first = $this->payment($f, 'pix') + ['amount' => '10,01'];
        $this->post($url.'/pay', $first)->assertSessionHasNoErrors();
        $this->post($url.'/pay', $first)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'partial']);
        $this->assertSame(1001, app(DiningRoom::class)->summary($f['tab'])['paid']);
        $this->assertSame(1999, app(DiningRoom::class)->summary($f['tab'])['pending']);
        $this->post($url.'/pay', array_replace($first, ['amount' => '10,02']))->assertConflict();
        $this->post($url.'/pay', array_replace($first, ['request_key' => (string) Str::uuid()]))->assertSessionHasErrors('payment');
        DB::table('orders')->where('id', $order->id)->update(['status' => 'completed']);
        $this->post($url.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('tab');
        $this->post('/orders/'.$order->id.'/paid')->assertConflict();
        $this->post($url.'/pay', $this->payment($f, 'card') + ['amount' => '9.99'])->assertSessionHasNoErrors();
        $this->post($url.'/pay', $this->payment($f, 'cash') + ['amount' => '10'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'paid', 'payment_method' => 'mixed', 'total_cents' => 3000]);
        $this->assertDatabaseCount('cash_entries', 3);
        $this->assertDatabaseCount('dining_settlements', 3);
        $totals = app(CashRegister::class)->totals(DB::table('cash_sessions')->first());
        $this->assertSame(1000, $totals['expected']);
        $this->assertSame(1001, $totals['pix']['sales']);
        $this->assertSame(999, $totals['card']['sales']);
        $this->get($url)->assertOk()->assertSee('Histórico de recebimentos')->assertSee('Pix conferido');
        $this->get($url.'/print')->assertOk()->assertSee('10,01')->assertSee('9,99');
        $this->post($url.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    public function test_partial_allocation_spans_orders_and_does_not_pay_new_consumption_on_retry(): void
    {
        $f = $this->fixture();
        $first = $this->orderForTab($f);
        $second = $this->orderForTab($f);
        $pay = $this->payment($f) + ['amount' => '40'];
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $first->id, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $second->id, 'payment_status' => 'partial']);
        $this->assertDatabaseHas('cash_entries', ['order_id' => $second->id, 'amount_cents' => 1000]);
        $third = $this->orderForTab($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay)->assertSessionHasNoErrors();
        $this->assertSame(5000, app(DiningRoom::class)->summary($f['tab'])['pending']);
        $this->assertDatabaseHas('orders', ['id' => $third->id, 'payment_status' => 'pending']);
        $this->assertDatabaseCount('cash_entries', 2);
        $this->get('/dining/tabs/'.$f['tab']->id.'?split_people=3')->assertOk()->assertSee('16,67')->assertSee('16,66');
        $this->get('/dining/tabs/'.$f['tab']->id.'?split_people=0')->assertSessionHasErrors('split_people');
    }

    public function test_cancelled_partial_receipt_refunds_only_received_amounts_in_original_methods_once(): void
    {
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $url = '/dining/tabs/'.$f['tab']->id;
        $this->post($url.'/pay', $this->payment($f) + ['amount' => '5'])->assertSessionHasNoErrors();
        $this->post($url.'/pay', $this->payment($f, 'pix') + ['amount' => '7'])->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();
        $summary = app(DiningRoom::class)->summary($f['tab']);
        $this->assertSame(0, $summary['pending']);
        $this->assertSame(1, $summary['refunds_pending']);
        $cash = DB::table('cash_sessions')->first();
        $this->get('/cash/'.$cash->id)->assertOk()->assertSee('12,00')->assertSee('Formas divididas');
        $refund = ['order_id' => $order->id, 'note' => 'Devolvido nas formas originais', 'confirmed' => 1, 'request_key' => (string) Str::uuid()];
        $this->post('/cash/'.$cash->id.'/refunds', $refund)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/cash/'.$cash->id.'/refunds', $refund)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_entries', ['order_id' => $order->id, 'kind' => 'refund', 'payment_method' => 'cash', 'amount_cents' => 500]);
        $this->assertDatabaseHas('cash_entries', ['order_id' => $order->id, 'kind' => 'refund', 'payment_method' => 'pix', 'amount_cents' => 700]);
        $this->assertDatabaseCount('cash_entries', 4);
        $this->assertSame(0, app(CashRegister::class)->totals($cash)['expected']);
        $this->post($url.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    public function test_partial_receipts_require_cash_valid_amount_method_and_fresh_authorization(): void
    {
        $f = $this->fixture(false);
        $this->orderForTab($f);
        $url = '/dining/tabs/'.$f['tab']->id.'/pay';
        $this->post($url, $this->payment($f) + ['amount' => '10'])->assertSessionHasErrors('cash');
        $this->assertDatabaseCount('dining_settlements', 0);
        app(CashRegister::class)->open(DB::table('stores')->find($f['store']), $f['owner']->id, 0, (string) Str::uuid());
        foreach (['', '0', '-1', '30,01', '1,001', 'abc'] as $amount) {
            $this->post($url, $this->payment($f) + ['amount' => $amount])->assertSessionHasErrors();
        }
        DB::table('stores')->where('id', $f['store'])->update(['accepts_pix' => false]);
        $this->post($url, $this->payment($f, 'pix') + ['amount' => '10'])->assertUnprocessable();
        $this->get('/cardapio/loja')->assertDontSee('value="mixed"', false);
        $foreign = $this->owner($this->tenant('foreign'));
        $this->actingAs($foreign)->post($url, $this->payment($f) + ['amount' => '10'])->assertNotFound();
        $this->actingAs($f['owner']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->post($url, $this->payment($f) + ['amount' => '10'])->assertForbidden();
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_partial_receipts_across_shifts_and_refund_failure_preserve_all_ledger_entries(): void
    {
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $url = '/dining/tabs/'.$f['tab']->id.'/pay';
        $this->post($url, $this->payment($f) + ['amount' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $first = DB::table('cash_sessions')->first();
        app(CashRegister::class)->close($first, $f['owner']->id, 500, '', (string) Str::uuid());
        $second = app(CashRegister::class)->open(DB::table('stores')->find($f['store']), $f['owner']->id, 500, (string) Str::uuid());
        $this->post($url, $this->payment($f, 'pix') + ['amount' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $this->payment($f, 'pix') + ['amount' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1500, app(DiningRoom::class)->summary($f['tab'])['pending']);
        $this->assertSame(500, json_decode(DB::table('cash_sessions')->find($first->id)->closing_totals, true)['cash']['sales']);
        $this->post('/orders/'.$order->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Desistiu'])->assertRedirect()->assertSessionHasNoErrors();
        $refund = ['order_id' => $order->id, 'note' => 'Conferido', 'confirmed' => 1, 'request_key' => (string) Str::uuid()];
        DB::unprepared("CREATE TRIGGER fail_pix_refund BEFORE INSERT ON cash_entries WHEN NEW.kind = 'refund' AND NEW.payment_method = 'pix' BEGIN SELECT RAISE(ABORT, 'refund failed'); END");
        $this->post('/cash/'.$second.'/refunds', $refund)->assertStatus(500);
        $this->assertSame(0, DB::table('cash_entries')->where('kind', 'refund')->count());
        DB::unprepared('DROP TRIGGER fail_pix_refund');
        $this->post('/cash/'.$second.'/refunds', $refund)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1500, (int) DB::table('cash_entries')->where('kind', 'refund')->sum('amount_cents'));
        $this->assertSame(0, app(CashRegister::class)->totals(DB::table('cash_sessions')->find($second))['expected']);
        $this->assertSame(500, json_decode(DB::table('cash_sessions')->find($first->id)->closing_totals, true)['cash']['sales']);
    }

    public function test_live_map_refreshes_occupancy_consumption_and_ready_orders_without_writes(): void
    {
        $this->travelTo(now()->startOfMinute());
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $url = '/dining/live?store_id='.$f['store'];
        $first = $this->getJson($url)->assertOk()->assertJsonPath('audience', 'owner');
        $second = $this->getJson($url)->assertOk();
        $this->assertSame($first->json('signature'), $second->json('signature'));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        DB::table('orders')->where('id', $order->id)->update(['status' => 'ready']);
        $ready = $this->getJson($url)->assertOk();
        $this->assertNotSame($first->json('signature'), $ready->json('signature'));
        $this->assertStringContainsString('pronto(s) para servir', $ready->json('html'));
        $this->assertStringContainsString('30,00', $ready->json('html'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cash_entries', 0);
        $other = $this->store($f['tenant'], 'denied');
        $operator = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'operator']);
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'store_id' => $other, 'user_id' => $operator->id]);
        $this->actingAs($operator)->getJson($url)->assertNotFound();
        $this->getJson('/dining/live?store_id='.$other)->assertOk()->assertDontSee('Mesa 1');
        DB::table('users')->where('id', $operator->id)->update(['access_enabled' => false]);
        $this->getJson('/dining/live?store_id='.$other)->assertForbidden();
    }

    private function anotherTab(array $f, string $name = 'Mesa 2'): array
    {
        $table = $this->spareTable($f, $name);
        $this->post('/dining/tables/'.$table.'/open', ['guests' => 2, 'guest_label' => 'Outro grupo', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return array_replace($f, ['table' => DB::table('dining_tables')->find($table), 'tab' => DB::table('dining_tabs')->where('active_table_id', $table)->firstOrFail()]);
    }

    private function mergePayload(array $source, array $target): array
    {
        $preview = $this->get('/dining/tabs/'.$source['tab']->id.'/merge?target_tab_id='.$target['tab']->id)->assertOk();

        return ['target_tab_id' => $target['tab']->id, 'source_hash' => $preview->viewData('sourceHash'), 'target_hash' => $preview->viewData('targetHash'), 'reason' => 'Grupos vao pagar juntos', 'confirmed' => 1, 'request_key' => (string) Str::uuid()];
    }

    public function test_merging_preserves_original_orders_and_partial_receipts_then_pays_and_serves_combined_bill(): void
    {
        $source = $this->fixture();
        $target = $this->anotherTab($source);
        $first = $this->orderForTab($source);
        $second = $this->orderForTab($target);
        $this->post('/dining/tabs/'.$source['tab']->id.'/pay', $this->payment($source, 'pix') + ['amount' => '10'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$target['tab']->id.'/pay', $this->payment($target, 'card') + ['amount' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $cashBefore = DB::table('cash_entries')->get();
        $settlementsBefore = DB::table('dining_settlements')->get();
        $draft = $this->draft($source);
        $merge = $this->mergePayload($source, $target);
        $url = '/dining/tabs/'.$source['tab']->id.'/merge';
        $this->post($url, $merge)->assertRedirect('/dining/tabs/'.$target['tab']->id)->assertSessionHasNoErrors();
        $this->post($url, $merge)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($cashBefore, DB::table('cash_entries')->get());
        $this->assertEquals($settlementsBefore, DB::table('dining_settlements')->get());
        $this->assertDatabaseHas('orders', ['id' => $first->id, 'dining_tab_id' => $source['tab']->id, 'dining_label' => 'Mesa 2 · Comanda #'.$target['tab']->id, 'total_cents' => 3000, 'payment_status' => 'partial']);
        $this->assertDatabaseHas('dining_tabs', ['id' => $source['tab']->id, 'active_table_id' => null, 'merged_into_tab_id' => $target['tab']->id, 'closing_total_cents' => null]);
        $this->assertDatabaseHas('dining_tabs', ['id' => $target['tab']->id, 'guests' => 4, 'closed_at' => null]);
        $summary = app(DiningRoom::class)->summary($target['tab']);
        $this->assertSame(6000, $summary['total']);
        $this->assertSame(1500, $summary['paid']);
        $this->assertSame(4500, $summary['pending']);
        $this->get('/dining')->assertOk()->assertSee('60,00')->assertSee('Unida à #'.$target['tab']->id);
        $this->get('/dining/tabs/'.$source['tab']->id)->assertOk()->assertSee('Abrir comanda de destino')->assertDontSee('Confirmar valor recebido');
        $this->get('/dining/tabs/'.$target['tab']->id.'/print')->assertOk()->assertSee('60,00')->assertSee('Histórico de uniões')->assertSee('10,00')->assertSee('5,00');
        $this->post('/pos/'.$source['store'].'/review/'.$draft['key'].'/confirm', ['review_hash' => $draft['review_hash']])->assertConflict();
        $this->post('/dining/tabs/'.$target['tab']->id.'/pay', $this->payment($target))->assertRedirect()->assertSessionHasNoErrors();
        foreach ([$first, $second] as $order) {
            DB::table('orders')->where('id', $order->id)->update(['status' => 'ready']);
            $this->post('/dining/tabs/'.$target['tab']->id.'/orders/'.$order->id.'/serve')->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post('/dining/tabs/'.$target['tab']->id.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_tabs', ['id' => $target['tab']->id, 'closing_total_cents' => 6000]);
        $this->assertSame(6000, (int) DB::table('cash_entries')->where('kind', 'sale')->sum('amount_cents'));
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('dining_merges', 1);
    }

    public function test_chained_merges_and_later_transfer_include_each_original_order_and_receipt_once(): void
    {
        $first = $this->fixture();
        $second = $this->anotherTab($first);
        $third = $this->anotherTab($first, 'Mesa 3');
        foreach ([$first, $second, $third] as $f) {
            $this->orderForTab($f);
            $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $this->payment($f) + ['amount' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $merge = $this->mergePayload($first, $second);
        $this->post('/dining/tabs/'.$first['tab']->id.'/merge', $merge)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$second['tab']->id.'/merge', $this->mergePayload($second, $third))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$first['tab']->id.'/merge', $merge)->assertRedirect()->assertSessionHasNoErrors();
        $summary = app(DiningRoom::class)->summary($third['tab']);
        $this->assertSame(9000, $summary['total']);
        $this->assertSame(300, $summary['paid']);
        $this->assertCount(3, $summary['orders']);
        $page = $this->get('/dining/tabs/'.$third['tab']->id)->assertOk();
        $this->assertCount(3, $page->viewData('settlements'));
        $this->assertCount(2, $page->viewData('merges'));
        $free = $this->spareTable($first, 'Mesa 4');
        $this->post('/dining/tabs/'.$third['tab']->id.'/transfer', $this->transferPayload($third['table']->id, $free))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('orders')->where('dining_label', 'Mesa 4 · Comanda #'.$third['tab']->id)->count());
        $this->assertDatabaseCount('cash_entries', 3);
        $this->assertDatabaseCount('dining_merges', 2);
    }

    public function test_merge_rejects_stale_amounts_and_old_empty_bill_payment_transfer_and_closing_reviews(): void
    {
        $source = $this->fixture();
        $target = $this->anotherTab($source);
        $this->orderForTab($source);
        $merge = $this->mergePayload($source, $target);
        $this->post('/dining/tabs/'.$source['tab']->id.'/pay', $this->payment($source) + ['amount' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$source['tab']->id.'/merge', $merge)->assertSessionHasErrors('merge');
        $oldPayment = $this->payment($target);
        $oldHash = app(DiningRoom::class)->summary($target['tab'])['review_hash'];
        $merge = $this->mergePayload($source, $target);
        $this->post('/dining/tabs/'.$source['tab']->id.'/merge', $merge)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$source['tab']->id.'/merge', array_replace($merge, ['reason' => 'Changed']))->assertConflict();
        $this->post('/dining/tabs/'.$target['tab']->id.'/pay', $oldPayment)->assertSessionHasErrors('payment');
        $free = $this->spareTable($source, 'Mesa 3');
        $this->post('/dining/tabs/'.$target['tab']->id.'/transfer', $this->transferPayload($target['table']->id, $free) + ['expected_review_hash' => $oldHash])->assertConflict();
        $this->post('/dining/tabs/'.$target['tab']->id.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid(), 'expected_review_hash' => $oldHash])->assertConflict();
        $this->assertDatabaseCount('dining_merges', 1);
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_merge_requires_two_open_tables_in_same_store_and_fresh_operator_access(): void
    {
        $source = $this->fixture();
        $target = $this->anotherTab($source);
        $merge = $this->mergePayload($source, $target);
        $url = '/dining/tabs/'.$source['tab']->id.'/merge';
        $this->post($url, array_replace($merge, ['confirmed' => 0]))->assertSessionHasErrors('confirmed');
        $otherStore = $this->store($source['tenant'], 'other');
        DB::table('dining_tabs')->where('id', $target['tab']->id)->update(['store_id' => $otherStore]);
        $this->post($url, $merge)->assertNotFound();
        $this->get($url.'?target_tab_id='.$target['tab']->id)->assertNotFound();
        DB::table('dining_tabs')->where('id', $target['tab']->id)->update(['store_id' => $source['store']]);
        $operator = User::factory()->create(['tenant_id' => $source['tenant'], 'role' => 'staff', 'staff_profile' => 'operator']);
        $this->actingAs($operator)->post($url, $merge)->assertNotFound();
        DB::table('user_store_access')->insert(['tenant_id' => $source['tenant'], 'store_id' => $source['store'], 'user_id' => $operator->id]);
        $this->post($url, $merge)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_merges', ['source_tab_id' => $source['tab']->id, 'user_id' => $operator->id]);
        $this->actingAs($this->owner($this->tenant('foreign')))->post($url, $merge)->assertNotFound();
        $this->actingAs($operator);
        DB::table('users')->where('id', $operator->id)->update(['access_enabled' => false]);
        $this->post($url, $merge)->assertForbidden();
    }

    public function test_merged_cancelled_partial_receipt_still_requires_refund_before_combined_bill_can_close(): void
    {
        $source = $this->fixture();
        $target = $this->anotherTab($source);
        $cancelled = $this->orderForTab($source);
        $active = $this->orderForTab($target);
        $this->post('/dining/tabs/'.$source['tab']->id.'/pay', $this->payment($source) + ['amount' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/orders/'.$cancelled->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Desistiu'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$source['tab']->id.'/merge', $this->mergePayload($source, $target))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, app(DiningRoom::class)->summary($target['tab'])['refunds_pending']);
        $this->assertSame(3000, app(DiningRoom::class)->summary($target['tab'])['total']);
        $this->post('/dining/tabs/'.$target['tab']->id.'/pay', $this->payment($target))->assertRedirect()->assertSessionHasNoErrors();
        DB::table('orders')->where('id', $active->id)->update(['status' => 'completed']);
        $close = ['confirmed' => 1, 'request_key' => (string) Str::uuid()];
        $this->post('/dining/tabs/'.$target['tab']->id.'/close', $close)->assertSessionHasErrors('tab');
        $cash = DB::table('cash_sessions')->first();
        $this->post('/cash/'.$cash->id.'/refunds', ['order_id' => $cancelled->id, 'note' => 'Devolucao conferida', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$target['tab']->id.'/close', $close)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_tabs', ['id' => $target['tab']->id, 'closing_total_cents' => 3000]);
        $this->assertDatabaseHas('orders', ['id' => $cancelled->id, 'dining_tab_id' => $source['tab']->id, 'status' => 'cancelled']);
    }

    public function test_merge_rejects_same_closed_disabled_or_oversized_destination_and_changed_target_balance(): void
    {
        $source = $this->fixture();
        $target = $this->anotherTab($source);
        $url = '/dining/tabs/'.$source['tab']->id.'/merge';
        $merge = $this->mergePayload($source, $target);
        $this->post($url, array_replace($merge, ['target_tab_id' => $source['tab']->id]))->assertUnprocessable();
        $this->orderForTab($target);
        $this->post($url, $merge)->assertSessionHasErrors('merge');
        $merge = $this->mergePayload($source, $target);
        DB::table('dining_tables')->where('id', $target['table']->id)->update(['enabled' => false]);
        $this->post($url, $merge)->assertConflict();
        DB::table('dining_tables')->where('id', $target['table']->id)->update(['enabled' => true]);
        DB::table('dining_tabs')->where('id', $target['tab']->id)->update(['guests' => 100]);
        $this->post($url, $this->mergePayload($source, $target))->assertUnprocessable();
        DB::table('dining_tabs')->where('id', $target['tab']->id)->update(['closed_at' => now()]);
        $this->post($url, $merge)->assertConflict();
        $this->assertDatabaseCount('dining_merges', 0);
    }

    public function test_merge_audit_failure_rolls_back_group_occupancy_and_kitchen_label(): void
    {
        $source = $this->fixture();
        $target = $this->anotherTab($source);
        $order = $this->orderForTab($source);
        $merge = $this->mergePayload($source, $target);
        DB::unprepared("CREATE TRIGGER fail_merge BEFORE INSERT ON audit_events WHEN NEW.action = 'dining.merged' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->post('/dining/tabs/'.$source['tab']->id.'/merge', $merge)->assertStatus(500);
        $this->assertDatabaseCount('dining_merges', 0);
        $this->assertDatabaseHas('dining_tabs', ['id' => $source['tab']->id, 'active_table_id' => $source['table']->id, 'merged_into_tab_id' => null, 'closed_at' => null]);
        $this->assertDatabaseHas('dining_tabs', ['id' => $target['tab']->id, 'guests' => 2]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'dining_label' => 'Mesa 1 · Comanda #'.$source['tab']->id]);
    }

    private function spareTable(array $f, string $name = 'Mesa 2'): int
    {
        return DB::table('dining_tables')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => $name, 'name_key' => mb_strtolower($name), 'seats' => 6, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function transferPayload(int $from, int $to): array
    {
        return ['from_table_id' => $from, 'to_table_id' => $to, 'reason' => 'Grupo pediu mesa maior', 'confirmed' => 1, 'request_key' => (string) Str::uuid()];
    }

    public function test_transfer_preserves_bill_and_receipts_updates_live_kitchen_and_keeps_old_print_copy(): void
    {
        $f = $this->fixture();
        $destination = $this->spareTable($f);
        $order = $this->orderForTab($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $this->payment($f))->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/status', ['status' => 'preparing'])->assertSessionHasNoErrors();
        $print = $this->post('/orders/'.$order->id.'/print-jobs', ['kind' => 'production', 'paper' => '80', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $receipts = DB::table('cash_entries')->get();
        $settlement = DB::table('dining_settlements')->first();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $destination))->assertRedirect('/dining/tabs/'.$f['tab']->id)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_tabs', ['id' => $f['tab']->id, 'dining_table_id' => $f['table']->id, 'current_table_id' => $destination, 'active_table_id' => $destination, 'table_name' => 'Mesa 2', 'guest_label' => 'Ana']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'dining_tab_id' => $f['tab']->id, 'dining_label' => 'Mesa 2 · Comanda #'.$f['tab']->id, 'total_cents' => 3000, 'payment_status' => 'paid', 'status' => 'preparing']);
        $this->assertEquals($receipts, DB::table('cash_entries')->get());
        $this->assertEquals($settlement, DB::table('dining_settlements')->first());
        $this->get('/kitchen?stage=preparing')->assertOk()->assertSee('Mesa 2')->assertDontSee('Ana');
        $this->get($print->headers->get('Location'))->assertOk()->assertSee('MESA 1')->assertDontSee('MESA 2');
        $newPrint = $this->post('/orders/'.$order->id.'/print-jobs', ['kind' => 'production', 'paper' => '80', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get($newPrint->headers->get('Location'))->assertOk()->assertSee('MESA 2');
        $tabs = $this->get('/dining')->assertOk()->viewData('tabs');
        $this->assertFalse($tabs->has($f['table']->id));
        $this->assertEquals($f['tab']->id, $tabs->get($destination)->id);
        $this->get('/dining/tabs/'.$f['tab']->id)->assertOk()->assertSee('Histórico de transferências')->assertSee('Grupo pediu mesa maior');
        $this->get('/dining/tabs/'.$f['tab']->id.'/print')->assertOk()->assertSee('Histórico de mesas:')->assertSee('Mesa 2');
        $this->post('/dining/tables/'.$f['table']->id.'/open', ['guests' => 2, 'guest_label' => 'Ana', 'request_key' => $f['key']])->assertRedirect('/dining/tabs/'.$f['tab']->id);
        $this->assertDatabaseCount('dining_tabs', 1);
        $newOrder = $this->orderForTab($f);
        $this->assertSame('Mesa 2 · Comanda #'.$f['tab']->id, $newOrder->dining_label);
        $this->assertDatabaseHas('audit_events', ['action' => 'dining.transferred', 'tenant_id' => $f['tenant']]);
    }

    public function test_transfer_rejects_occupied_disabled_foreign_same_or_closed_table(): void
    {
        $f = $this->fixture();
        $target = $this->spareTable($f);
        $this->post('/dining/tables/'.$target.'/open', ['guests' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $target))->assertConflict();
        $otherTab = DB::table('dining_tabs')->where('active_table_id', $target)->first();
        $this->post('/dining/tabs/'.$otherTab->id.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        DB::table('dining_tables')->where('id', $target)->update(['enabled' => false]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $target))->assertConflict();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $f['table']->id))->assertUnprocessable();
        $otherStore = $this->store($f['tenant'], 'other');
        $foreign = $this->spareTable(['tenant' => $f['tenant'], 'store' => $otherStore]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $foreign))->assertNotFound();
        $foreignTenant = $this->tenant('foreign');
        $foreignTable = $this->spareTable(['tenant' => $foreignTenant, 'store' => $this->store($foreignTenant, 'foreign')]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $foreignTable))->assertNotFound();
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $target))->assertConflict();
        $this->assertDatabaseCount('dining_transfers', 0);
    }

    public function test_retried_transfer_never_moves_back_after_another_transfer_and_stale_source_is_blocked(): void
    {
        $f = $this->fixture();
        $second = $this->spareTable($f);
        $third = $this->spareTable($f, 'Mesa 3');
        $first = $this->transferPayload($f['table']->id, $second);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $first)->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($second, $third))->assertSessionHasNoErrors();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $first)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_tabs', ['id' => $f['tab']->id, 'current_table_id' => $third, 'active_table_id' => $third]);
        $this->assertDatabaseCount('dining_transfers', 2);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', array_replace($first, ['reason' => 'Changed reason']))->assertConflict();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $second))->assertConflict();
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', ['expected_table_id' => $f['table']->id, 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_transfer_invalidates_old_sale_review_and_payment_review_without_changing_the_balance(): void
    {
        $f = $this->fixture();
        $target = $this->spareTable($f);
        $this->orderForTab($f);
        $draft = $this->draft($f);
        $payment = $this->payment($f);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $target))->assertSessionHasNoErrors();
        $this->post('/pos/'.$f['store'].'/review/'.$draft['key'].'/confirm', ['review_hash' => $draft['review_hash']])->assertRedirect('/pos/'.$f['store'].'?tab='.$f['tab']->id)->assertSessionHasErrors('tab');
        $this->assertDatabaseCount('orders', 1);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $payment)->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('cash_entries', 0);
        $f['tab'] = DB::table('dining_tabs')->find($f['tab']->id);
        $this->assertSame(3000, app(DiningRoom::class)->summary($f['tab'])['pending']);
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $this->payment($f))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_transfer_is_authorized_per_store_and_records_the_actual_operator(): void
    {
        $f = $this->fixture();
        $target = $this->spareTable($f);
        $operator = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'operator']);
        $payload = $this->transferPayload($f['table']->id, $target);
        $this->actingAs($operator)->post('/dining/tabs/'.$f['tab']->id.'/transfer', $payload)->assertNotFound();
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'user_id' => $operator->id, 'store_id' => $f['store']]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_transfers', ['dining_tab_id' => $f['tab']->id, 'user_id' => $operator->id]);
        DB::table('user_store_access')->where('user_id', $operator->id)->delete();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $payload)->assertNotFound();
        $foreignTenant = $this->tenant('foreign');
        $this->actingAs($this->owner($foreignTenant))->post('/dining/tabs/'.$f['tab']->id.'/transfer', $payload)->assertNotFound();
        DB::table('users')->where('id', $operator->id)->update(['access_enabled' => false]);
        $this->actingAs($operator)->post('/dining/tabs/'.$f['tab']->id.'/transfer', $payload)->assertForbidden();
    }

    public function test_failed_transfer_audit_rolls_back_occupation_and_kitchen_label(): void
    {
        $f = $this->fixture();
        $target = $this->spareTable($f);
        $order = $this->orderForTab($f);
        DB::statement("CREATE TRIGGER fail_transfer_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'dining.transferred' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', $this->transferPayload($f['table']->id, $target))->assertStatus(500);
        $this->assertDatabaseHas('dining_tabs', ['id' => $f['tab']->id, 'current_table_id' => $f['table']->id, 'active_table_id' => $f['table']->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'dining_label' => 'Mesa 1 · Comanda #'.$f['tab']->id]);
        $this->assertDatabaseCount('dining_transfers', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_disabled_tenant_and_stale_credentials_cannot_read_or_mutate_dining_room(): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->get('/dining')->assertForbidden();
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => true]);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/dining/tabs/'.$f['tab']->id)->assertForbidden();

    }
}
