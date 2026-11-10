<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(bool $open = true): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        $session = $open ? app(CashRegister::class)->open(DB::table('stores')->find($store), $owner->id, 10000, (string) Str::uuid()) : null;

        return compact('tenant', 'store', 'owner', 'session');
    }

    private function movement(int $session, string $kind, string $amount, ?string $key = null): array
    {
        $data = ['kind' => $kind, 'amount' => $amount, 'note' => 'Conferido pelo operador', 'request_key' => $key ?? (string) Str::uuid()];
        $this->post('/cash/'.$session.'/movements', $data)->assertRedirect('/cash/'.$session)->assertSessionHasNoErrors();

        return $data;
    }

    private function sale(array $f, string $method = 'cash', bool $received = true): object
    {
        DB::table('stores')->where('id', $f['store'])->update(['accepts_pix' => true, 'accepts_card' => true]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Prato', 'category' => 'Menu', 'price_cents' => 3000]);
        $key = (string) Str::uuid();
        $data = ['idempotency_key' => $key, 'payment_method' => $method, 'received' => $received ? 'yes' : 'no', 'change_for' => $method === 'cash' ? '50,00' : '', 'cart_lines' => [['product_id' => $product, 'quantity' => 1]]];
        $this->post('/pos/'.$f['store'].'/review', $data)->assertSessionHasNoErrors();
        $draft = $this->get('/pos/'.$f['store'].'/review/'.$key)->assertOk()->viewData('draft');
        $hash = ['review_hash' => hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR))];
        $this->post('/pos/'.$f['store'].'/review/'.$key.'/confirm', $hash)->assertSessionHasNoErrors();
        $this->post('/pos/'.$f['store'].'/review/'.$key.'/confirm', $hash)->assertSessionHasNoErrors();

        return DB::table('orders')->where('idempotency_key', $key)->firstOrFail();
    }

    public function test_opening_is_unique_per_store_idempotent_and_scoped_to_authorized_operator(): void
    {
        $f = $this->fixture(false);
        $data = ['store_id' => $f['store'], 'opening' => '100,00', 'request_key' => (string) Str::uuid()];
        $this->get('/cash')->assertOk()->assertSee('Caixa da loja');
        $this->post('/cash', $data)->assertRedirect();
        $this->post('/cash', $data)->assertRedirect();
        $id = DB::table('cash_sessions')->value('id');
        $this->assertDatabaseCount('cash_sessions', 1);
        $this->post('/cash', array_replace($data, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->post('/cash', array_replace($data, ['opening' => '99,00']))->assertConflict();
        $this->get('/cash/'.$id.'/print')->assertConflict();
        $this->actingAs($this->owner($this->tenant('other')))->get('/cash/'.$id)->assertNotFound();
        $this->post('/cash/'.$id.'/movements', ['kind' => 'supply', 'amount' => '10,00', 'note' => 'Foreign', 'request_key' => (string) Str::uuid()])->assertNotFound();
        foreach (['admin', 'courier', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['tenant_id' => $f['tenant'], 'role' => $role, 'staff_profile' => $role === 'staff' ? 'kitchen' : null]))->get('/cash')->assertForbidden();
        }
    }

    public function test_cash_pix_card_and_movements_produce_correct_physical_count_and_frozen_closing(): void
    {
        $f = $this->fixture();
        $this->sale($f);
        $this->sale($f, 'pix');
        $this->sale($f, 'card');
        $supply = $this->movement($f['session'], 'supply', '20,00');
        $this->post('/cash/'.$f['session'].'/movements', $supply)->assertRedirect();
        $this->post('/cash/'.$f['session'].'/movements', array_replace($supply, ['amount' => '21,00']))->assertConflict();
        $this->movement($f['session'], 'withdrawal', '10,00');
        $session = DB::table('cash_sessions')->find($f['session']);
        $totals = app(CashRegister::class)->totals($session);
        $this->assertSame(14000, $totals['expected']);
        $this->assertSame(3000, $totals['pix']['sales']);
        $this->assertSame(3000, $totals['card']['sales']);
        $this->assertDatabaseCount('cash_entries', 5);
        $this->get('/cash/'.$session->id)->assertOk()->assertSee('140,00')->assertSee('Maquininha');
        $close = ['counted' => '140,00', 'confirmed' => '1', 'request_key' => (string) Str::uuid()];
        $this->post('/cash/'.$session->id.'/close', $close)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/cash/'.$session->id.'/close', $close)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_sessions', ['id' => $session->id, 'open_store_id' => null, 'expected_cents' => 14000, 'counted_cents' => 14000, 'difference_cents' => 0]);
        $this->post('/cash/'.$session->id.'/movements', array_replace($supply, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->get('/cash/'.$session->id.'/print')->assertOk()->assertSee('140,00')->assertSee('Fechamento de caixa');
        $this->post('/cash', ['store_id' => $f['store'], 'opening' => '40,00', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->sale($f);
        $newSession = DB::table('cash_sessions')->where('open_store_id', $f['store'])->value('id');
        $this->post('/cash/'.$newSession.'/close', $close)->assertConflict();
        $this->get('/cash/'.$session->id)->assertOk()->assertSee('140,00');
        $this->assertSame(3000, json_decode(DB::table('cash_sessions')->find($session->id)->closing_totals, true)['cash']['sales']);
    }

    public function test_closed_register_blocks_immediate_receipt_but_pending_order_can_be_paid_in_next_turn_once(): void
    {
        $f = $this->fixture(false);
        $this->get('/pos/'.$f['store'])->assertOk()->assertSee('Caixa fechado.')->assertDontSee('@else');
        $product = DB::table('products')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Food', 'category' => 'Menu', 'price_cents' => 3000]);
        $key = (string) Str::uuid();
        $this->post('/pos/'.$f['store'].'/review', ['idempotency_key' => $key, 'payment_method' => 'cash', 'received' => 'yes', 'cart_lines' => [['product_id' => $product, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $draft = $this->get('/pos/'.$f['store'].'/review/'.$key)->viewData('draft');
        $this->post('/pos/'.$f['store'].'/review/'.$key.'/confirm', ['review_hash' => hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR))])->assertSessionHasErrors('cash');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $order = $this->sale($f, received: false);
        $this->post('/orders/'.$order->id.'/paid')->assertSessionHasErrors('cash');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'pending']);
        $this->post('/cash', ['store_id' => $f['store'], 'opening' => '0,00', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->post('/orders/'.$order->id.'/paid')->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/paid')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertDatabaseHas('cash_entries', ['order_id' => $order->id, 'kind' => 'sale', 'amount_cents' => 3000]);
    }

    public function test_discrepancy_requires_explanation_and_withdrawal_cannot_exceed_cash(): void
    {
        $f = $this->fixture();
        $this->post('/cash/'.$f['session'].'/movements', ['kind' => 'withdrawal', 'amount' => '100,01', 'note' => 'Too much', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('amount');
        $close = ['counted' => '99,00', 'confirmed' => '1', 'request_key' => (string) Str::uuid()];
        $this->post('/cash/'.$f['session'].'/close', $close)->assertSessionHasErrors('closing_note');
        $this->assertDatabaseHas('cash_sessions', ['id' => $f['session'], 'closed_at' => null]);
        $this->post('/cash/'.$f['session'].'/close', $close + ['closing_note' => 'Faltou um real na conferência'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_sessions', ['id' => $f['session'], 'difference_cents' => -100]);
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.close', 'tenant_id' => $f['tenant']]);
        $this->post('/cash/'.$f['session'].'/close', array_replace($close, ['counted' => '100,00']))->assertConflict();
    }

    public function test_cancelled_sale_keeps_cash_until_explicit_refund_including_from_an_earlier_turn(): void
    {
        $f = $this->fixture();
        $order = $this->sale($f);
        $this->post('/orders/'.$order->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Customer requested'])->assertSessionHasNoErrors();
        $this->assertSame(13000, app(CashRegister::class)->totals(DB::table('cash_sessions')->find($f['session']))['expected']);
        $this->post('/cash/'.$f['session'].'/close', ['counted' => '130,00', 'confirmed' => '1', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/cash', ['store_id' => $f['store'], 'opening' => '100,00', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $session = DB::table('cash_sessions')->where('open_store_id', $f['store'])->first();
        $this->get('/cash/'.$session->id)->assertOk()->assertSee('Registrar devolução');
        $refund = ['order_id' => $order->id, 'note' => 'Valor devolvido', 'request_key' => (string) Str::uuid()];
        $this->post('/cash/'.$session->id.'/refunds', $refund)->assertSessionHasErrors('confirmed');
        $refund['confirmed'] = '1';
        $this->post('/cash/'.$session->id.'/refunds', $refund)->assertSessionHasNoErrors();
        $this->post('/cash/'.$session->id.'/refunds', $refund)->assertSessionHasNoErrors();
        $this->post('/cash/'.$session->id.'/refunds', array_replace($refund, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame(7000, app(CashRegister::class)->totals($session)['expected']);
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertDatabaseHas('cash_sessions', ['id' => $f['session'], 'expected_cents' => 13000]);
    }

    public function test_store_scope_revocation_and_suspension_block_read_and_write(): void
    {
        $f = $this->fixture();
        $other = $this->store($f['tenant'], 'other');
        $operator = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'operator']);
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'user_id' => $operator->id, 'store_id' => $other]);
        $this->actingAs($operator)->get('/cash/'.$f['session'])->assertNotFound();
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'user_id' => $operator->id, 'store_id' => $f['store']]);
        $this->get('/cash/'.$f['session'])->assertOk();
        $this->movement($f['session'], 'supply', '10,00');
        $this->assertDatabaseHas('cash_entries', ['user_id' => $operator->id, 'kind' => 'supply']);
        DB::table('user_store_access')->where('user_id', $operator->id)->delete();
        $this->get('/cash/'.$f['session'])->assertNotFound();
        DB::table('users')->where('id', $operator->id)->update(['access_enabled' => false]);
        $this->get('/cash')->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/cash')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_movement_and_legacy_sales_are_not_backfilled(): void
    {
        $f = $this->fixture();
        $legacy = $this->order($f['tenant'], $f['store']);
        DB::table('orders')->where('id', $legacy)->update(['source' => 'pos', 'payment_status' => 'paid']);
        $this->post('/orders/'.$legacy.'/paid')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cash_entries', 0);
        DB::statement("CREATE TRIGGER fail_cash_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'cash.supply' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post('/cash/'.$f['session'].'/movements', ['kind' => 'supply', 'amount' => '10,00', 'note' => 'Test', 'request_key' => (string) Str::uuid()])->assertStatus(500);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_money_validation_rejects_negative_zero_movement_and_unknown_types(): void
    {
        $f = $this->fixture();
        foreach (['-1,00', '0,00', '1.000,00', 'abc'] as $amount) {
            $this->post('/cash/'.$f['session'].'/movements', ['kind' => 'supply', 'amount' => $amount, 'note' => 'Invalid', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors();
        }
        $this->post('/cash/'.$f['session'].'/movements', ['kind' => 'sale', 'amount' => '10,00', 'note' => 'Invalid', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('kind');
        $this->assertDatabaseCount('cash_entries', 0);

    }
}
