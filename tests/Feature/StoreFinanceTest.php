<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class StoreFinanceTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(15, 0));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);

        return compact('tenant', 'store', 'owner');
    }

    private function draft(array $f, string $direction = 'payable'): array
    {
        return ['store_id' => $f['store'], 'direction' => $direction, 'description' => 'Conta de teste', 'counterparty' => 'Fornecedor exemplo', 'category' => 'other', 'due_on' => '2026-11-09', 'amount' => '100,01', 'notes' => 'Conferir', 'request_key' => (string) Str::uuid()];
    }

    private function create(array $f, string $direction = 'payable'): int
    {
        $this->post('/finance', $this->draft($f, $direction))->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('financial_accounts')->max('id');
    }

    private function settlement(int $id, string $amount = '40,01'): array
    {
        return ['revision' => DB::table('financial_accounts')->find($id)->revision, 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'amount' => $amount, 'settled_on' => '2026-11-10', 'method' => 'pix', 'destination' => 'external', 'note' => 'Transferencia conferida'];
    }

    private function reversal(int $id): array
    {
        return ['revision' => DB::table('financial_accounts')->find($id)->revision, 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'reversed_on' => '2026-11-10', 'reason' => 'Baixa incorreta conferida'];
    }

    public function test_create_is_idempotent_edit_is_reviewed_and_does_not_move_money(): void
    {
        $f = $this->fixture();
        $data = $this->draft($f);
        $this->post('/finance', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/finance', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('financial_accounts', 1);
        $id = DB::table('financial_accounts')->value('id');
        $this->get('/finance/'.$id)->assertOk()->assertSee('Registrar pagamento');
        $data['request_key'] = (string) Str::uuid();
        $data['revision'] = 1;
        $data['amount'] = '120,02';
        $this->post('/finance/'.$id, $data)->assertRedirect();
        $this->post('/finance/'.$id, array_replace($data, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'amount_cents' => 12002, 'revision' => 2]);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('financial_settlements', 0);
    }

    public function test_partial_and_full_settlements_reconcile_exact_cents_once(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $data = $this->settlement($id);
        $this->post('/finance/'.$id.'/settle', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/finance/'.$id.'/settle', $data)->assertRedirect();
        $this->post('/finance/'.$id.'/settle', array_replace($data, ['amount' => '41,00']))->assertConflict();
        $this->assertDatabaseCount('financial_settlements', 1);
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'settled_cents' => 4001, 'status' => 'open']);
        $this->post('/finance/'.$id.'/settle', $this->settlement($id, '60,01'))->assertConflict();
        $this->post('/finance/'.$id.'/settle', $this->settlement($id, '60,00'))->assertRedirect();
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'settled_cents' => 10001, 'status' => 'settled']);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertEquals(10001, DB::table('financial_settlements')->sum('amount_cents'));
        Http::assertNothingSent();
    }

    public function test_stale_settlement_and_missing_confirmation_are_blocked(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $stale = $this->settlement($id);
        $data = $stale;
        unset($data['confirmed']);
        $this->post('/finance/'.$id.'/settle', $data)->assertSessionHasErrors('confirmed');
        $this->post('/finance/'.$id.'/settle', $stale)->assertRedirect();
        $stale['request_key'] = (string) Str::uuid();
        $this->post('/finance/'.$id.'/settle', $stale)->assertConflict();
        $this->assertDatabaseCount('financial_settlements', 1);
    }

    public function test_cash_payment_uses_current_turn_and_insufficient_cash_rolls_everything_back(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $cash = app(CashRegister::class);
        $sessionId = $cash->open(DB::table('stores')->find($f['store']), $f['owner']->id, 3000, (string) Str::uuid());
        $data = array_replace($this->settlement($id), ['method' => 'cash', 'destination' => 'cash', 'cash_session_id' => $sessionId]);
        $this->post('/finance/'.$id.'/settle', $data)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('financial_settlements', 0);
        $data['amount'] = '20,01';
        $this->post('/finance/'.$id.'/settle', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/finance/'.$id.'/settle', $data)->assertRedirect();
        $entry = DB::table('cash_entries')->first();
        $this->assertSame('withdrawal', $entry->kind);
        $this->assertDatabaseHas('financial_settlements', ['account_id' => $id, 'cash_entry_id' => $entry->id]);
        $this->assertEquals(999, $cash->totals(DB::table('cash_sessions')->find($sessionId))['expected']);
    }

    public function test_receivable_cash_is_a_single_supply_and_reversal_uses_new_turn(): void
    {
        $f = $this->fixture();
        $id = $this->create($f, 'receivable');
        $cash = app(CashRegister::class);
        $store = DB::table('stores')->find($f['store']);
        $first = $cash->open($store, $f['owner']->id, 0, (string) Str::uuid());
        $this->post('/finance/'.$id.'/settle', array_replace($this->settlement($id), ['method' => 'cash', 'destination' => 'cash', 'cash_session_id' => $first]))->assertRedirect();
        $session = DB::table('cash_sessions')->find($first);
        $cash->close($session, $f['owner']->id, 4001, '', (string) Str::uuid());
        $closed = DB::table('cash_sessions')->find($first)->closing_totals;
        $settlement = DB::table('financial_settlements')->value('id');
        $reverse = $this->reversal($id);
        $this->post('/finance/'.$id.'/settlements/'.$settlement.'/reverse', $reverse)->assertConflict();
        $second = $cash->open($store, $f['owner']->id, 5000, (string) Str::uuid());
        $reverse['cash_session_id'] = $second;
        $this->post('/finance/'.$id.'/settlements/'.$settlement.'/reverse', $reverse)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/finance/'.$id.'/settlements/'.$settlement.'/reverse', $reverse)->assertRedirect();
        $this->assertEquals($closed, DB::table('cash_sessions')->find($first)->closing_totals);
        $this->assertEquals(999, $cash->totals(DB::table('cash_sessions')->find($second))['expected']);
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'settled_cents' => 0, 'status' => 'open']);
        $this->assertDatabaseCount('cash_entries', 2);
    }

    public function test_closed_or_changed_cash_session_and_pix_in_drawer_are_rejected(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $data = array_replace($this->settlement($id), ['destination' => 'cash', 'cash_session_id' => 999]);
        $this->post('/finance/'.$id.'/settle', $data)->assertStatus(422);
        $data['method'] = 'cash';
        $this->post('/finance/'.$id.'/settle', $data)->assertConflict();
        $this->assertDatabaseCount('financial_settlements', 0);
    }

    public function test_reversal_preserves_original_and_reports_on_reversal_date(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $data = $this->settlement($id);
        $data['settled_on'] = '2026-11-09';
        $this->post('/finance/'.$id.'/settle', $data)->assertRedirect();
        $settlement = DB::table('financial_settlements')->first();
        $reverse = $this->reversal($id);
        $this->post('/finance/'.$id.'/settlements/'.$settlement->id.'/reverse', $reverse)->assertRedirect();
        $this->assertDatabaseHas('financial_settlements', ['id' => $settlement->id, 'amount_cents' => 4001, 'settled_on' => '2026-11-09', 'reversed_on' => '2026-11-10']);
        $yesterday = $this->get('/finance?from=2026-11-09&to=2026-11-09')->assertOk();
        $today = $this->get('/finance?from=2026-11-10&to=2026-11-10')->assertOk();
        $this->assertEquals(4001, $yesterday->viewData('realized')['paid']);
        $this->assertEquals(-4001, $today->viewData('realized')['paid']);
        $this->assertEquals(0, $this->get('/finance')->assertOk()->viewData('realized')['paid']);
        $this->post('/finance/'.$id.'/settlements/'.$settlement->id.'/reverse', array_replace($this->reversal($id), ['request_key' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_cancellation_requires_no_valid_settlements_and_never_deletes_history(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $this->post('/finance/'.$id.'/settle', $this->settlement($id))->assertRedirect();
        $this->post('/finance/'.$id.'/cancel', $this->reversal($id))->assertConflict();
        $settlement = DB::table('financial_settlements')->value('id');
        $this->post('/finance/'.$id.'/settlements/'.$settlement.'/reverse', $this->reversal($id))->assertRedirect();
        $cancel = $this->reversal($id);
        $this->post('/finance/'.$id.'/cancel', $cancel)->assertRedirect();
        $this->post('/finance/'.$id.'/cancel', $cancel)->assertRedirect();
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('financial_settlements', 1);
        $this->assertEquals(0, $this->get('/finance')->assertOk()->viewData('balances')['payable']);
    }

    public function test_purchase_link_is_explicit_unique_and_uses_frozen_total(): void
    {
        $f = $this->fixture();
        $supplier = DB::table('inventory_suppliers')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Fornecedor', 'name_key' => 'fornecedor']);
        $purchase = DB::table('inventory_purchases')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'supplier_id' => $supplier, 'supplier_name' => 'Fornecedor', 'status' => 'draft', 'purchased_on' => '2026-11-10', 'subtotal_cents' => 1234, 'freight_cents' => 56, 'discount_cents' => 1, 'total_cents' => 1289, 'created_by' => $f['owner']->id]);
        $data = ['request_key' => (string) Str::uuid(), 'revision' => 1, 'due_on' => '2026-11-20', 'confirmed' => 1];
        $this->post('/inventory/purchases/'.$purchase.'/finance', $data)->assertConflict();
        DB::table('inventory_purchases')->where('id', $purchase)->update(['status' => 'received', 'received_at' => now()]);
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->post('/inventory/purchases/'.$purchase.'/finance', $data + ['amount' => '0,01'])->assertRedirect();
        $this->post('/inventory/purchases/'.$purchase.'/finance', $data)->assertRedirect();
        $this->post('/inventory/purchases/'.$purchase.'/finance', array_replace($data, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseCount('financial_accounts', 1);
        $account = DB::table('financial_accounts')->first();
        $this->assertSame(1289, $account->amount_cents);
        $this->get('/inventory/purchases/'.$purchase)->assertOk()->assertSee('Abrir conta a pagar');
        $edit = $this->draft($f) + ['revision' => 1];
        $this->post('/finance/'.$account->id, $edit)->assertConflict();
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_tenant_role_store_and_fresh_access_are_checked_on_reads_and_mutations(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $settle = $this->settlement($id);
        $foreignTenant = $this->tenant('other');
        $foreignStore = $this->store($foreignTenant, 'other');
        $this->get('/finance?store_id='.$foreignStore)->assertNotFound();
        $this->post('/finance', array_replace($this->draft($f), ['store_id' => $foreignStore]))->assertNotFound();
        $this->actingAs($this->owner($foreignTenant))->get('/finance/'.$id)->assertNotFound();
        $this->post('/finance/'.$id.'/settle', $settle)->assertNotFound();
        $staff = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'operator']);
        $this->actingAs($staff)->get('/finance')->assertForbidden();
        $this->post('/finance/'.$id.'/settle', $settle)->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->post('/finance/'.$id.'/settle', $settle)->assertForbidden();
        $this->assertDatabaseCount('financial_settlements', 0);
    }

    public function test_invalid_money_future_dates_and_suspension_are_rejected(): void
    {
        $f = $this->fixture();
        foreach (['0', '-1', '1.000,00', '1e2', '1,001'] as $value) {
            $this->post('/finance', array_replace($this->draft($f), ['amount' => $value]))->assertStatus($value === '0' ? 422 : 302);
        }
        $id = $this->create($f);
        $this->post('/finance/'.$id.'/settle', array_replace($this->settlement($id), ['settled_on' => '2026-11-11']))->assertStatus(422);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/finance')->assertForbidden();
        $this->post('/finance/'.$id.'/settle', $this->settlement($id))->assertForbidden();
        $this->assertDatabaseCount('financial_settlements', 0);
    }

    public function test_due_date_can_change_after_payment_but_original_direction_and_amount_remain_frozen_after_reversal(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $this->post('/finance/'.$id.'/settle', $this->settlement($id))->assertRedirect();
        $edit = $this->draft($f) + ['revision' => 2];
        $edit['due_on'] = '2026-12-10';
        $this->post('/finance/'.$id, $edit)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'due_on' => '2026-12-10', 'amount_cents' => 10001, 'settled_cents' => 4001]);
        $settlement = DB::table('financial_settlements')->value('id');
        $this->post('/finance/'.$id.'/settlements/'.$settlement.'/reverse', $this->reversal($id))->assertRedirect();
        $edit['revision'] = DB::table('financial_accounts')->find($id)->revision;
        $edit['request_key'] = (string) Str::uuid();
        $edit['direction'] = 'receivable';
        $this->post('/finance/'.$id, $edit)->assertConflict();
        $edit['direction'] = 'payable';
        $edit['amount'] = '80,00';
        $this->post('/finance/'.$id, $edit)->assertConflict();
        $this->get('/finance/'.$id)->assertOk()->assertSee('readonly', false);
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'amount_cents' => 10001, 'direction' => 'payable']);
    }

    public function test_balance_cards_include_older_debts_while_list_and_movements_use_their_dates(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        DB::table('financial_accounts')->where('id', $id)->update(['due_on' => '2026-10-01']);
        $response = $this->get('/finance?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertEquals(10001, $response->viewData('balances')['payable']);
        $this->assertEquals(10001, $response->viewData('balances')['overdue']);
        $this->assertCount(0, $response->viewData('accounts'));
        $this->assertEquals(0, $response->viewData('realized')['paid']);
        $this->get('/finance?from=2026-11-30&to=2026-11-01')->assertSessionHasErrors('to');
    }
}
