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
