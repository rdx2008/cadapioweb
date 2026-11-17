<?php

namespace Tests\Feature;

use App\Services\StoreFinance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class FinanceInstallmentsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function draft(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));

        return ['store_id' => $store, 'direction' => 'payable', 'description' => 'Equipamento', 'counterparty' => 'Fornecedor', 'category' => 'other', 'due_on' => '2028-01-31', 'amount' => '100,01', 'installments' => 3];
    }

    public function test_review_creates_exact_month_end_installments_once_and_tracks_balances(): void
    {
        $data = $this->draft();
        $this->get('/finance')->assertOk()->assertSee('Nova conta parcelada');
        $this->post('/finance/installments/preview', $data)->assertOk()->assertSee('29/02/2028')->assertSee('31/03/2028');
        $this->assertDatabaseCount('financial_accounts', 0);
        $key = session('finance_installments.data.request_key');
        $confirmation = ['request_key' => $key, 'confirmed' => 1, 'amount' => '1,00'];
        $this->post('/finance/installments', $confirmation)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/finance/installments', $confirmation)->assertRedirect();
        $parts = DB::table('financial_accounts')->orderBy('installment_number')->get();
        $this->assertSame([3334, 3334, 3333], $parts->pluck('amount_cents')->all());
        $this->assertSame(10001, $parts->sum('amount_cents'));
        $this->assertDatabaseCount('financial_events', 3);
        $this->assertDatabaseCount('cash_entries', 0);
        $id = $parts[0]->id;
        $this->get('/finance/'.$id)->assertOk()->assertSee('Parcelamento')->assertSee('100,01');
        $this->post('/finance/'.$id, array_replace($data, ['revision' => 1, 'request_key' => (string) Str::uuid(), 'amount' => '35,00']))->assertConflict();
        $this->post('/finance/'.$id.'/settle', ['revision' => 1, 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'amount' => '10,00', 'settled_on' => now()->toDateString(), 'method' => 'pix', 'destination' => 'external', 'note' => 'Conferido'])->assertRedirect();
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'settled_cents' => 1000]);
        $this->assertDatabaseHas('financial_accounts', ['id' => $parts[1]->id, 'settled_cents' => 0]);
        $this->get('/finance/'.$id)->assertOk()->assertSee('90,01');
        $this->post('/finance/'.$parts[1]->id.'/cancel', ['revision' => 1, 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'reason' => 'Ajuste conferido'])->assertRedirect();
        $this->get('/finance/'.$id)->assertOk()->assertSee('56,67')->assertSee('Cancelada');
        $settlement = DB::table('financial_settlements')->where('account_id', $id)->value('id');
        $this->post('/finance/'.$id.'/settlements/'.$settlement.'/reverse', ['revision' => 2, 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'reversed_on' => now()->toDateString(), 'reason' => 'Baixa incorreta'])->assertRedirect();
        $this->get('/finance/'.$id)->assertOk()->assertSee('66,67');
        $this->assertDatabaseHas('financial_accounts', ['id' => $id, 'settled_cents' => 0, 'status' => 'open']);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_preview_confirmation_and_tenant_boundaries_are_enforced(): void
    {
        $data = $this->draft();
        $foreignTenant = $this->tenant('other');
        $foreign = $this->store($foreignTenant, 'foreign');
        $this->post('/finance/installments/preview', array_replace($data, ['store_id' => $foreign]))->assertNotFound();
        $this->post('/finance/installments/preview', array_replace($data, ['amount' => '0,02']))->assertStatus(422);
        $this->post('/finance/installments/preview', array_replace($data, ['installments' => 37]))->assertSessionHasErrors('installments');
        $this->post('/finance/installments/preview', $data)->assertOk();
        $old = session('finance_installments.data.request_key');
        $this->post('/finance/installments', ['request_key' => $old])->assertSessionHasErrors('confirmed');
        $this->post('/finance/installments/preview', array_replace($data, ['direction' => 'receivable']))->assertOk();
        $this->post('/finance/installments', ['request_key' => $old, 'confirmed' => 1])->assertConflict();
        $this->post('/finance/installments', ['request_key' => session('finance_installments.data.request_key'), 'confirmed' => 1])->assertRedirect();
        $this->assertSame(3, DB::table('financial_accounts')->where('direction', 'receivable')->count());
        $id = DB::table('financial_accounts')->value('id');
        $this->actingAs($this->owner($foreignTenant));
        $this->get('/finance/'.$id)->assertNotFound();
    }

    public function test_dates_do_not_drift_and_last_due_date_is_bounded(): void
    {
        $rows = app(StoreFinance::class)->installmentSchedule(['amount' => '3,00', 'installments' => 3, 'due_on' => '2027-01-31']);
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], array_column($rows, 'due_on'));
        $data = $this->draft();
        $this->post('/finance/installments/preview', array_replace($data, ['due_on' => '2100-12-31']))->assertStatus(422);
        $this->assertDatabaseCount('financial_accounts', 0);
    }
}
