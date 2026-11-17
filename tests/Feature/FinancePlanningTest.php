<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinancialRecurrences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class FinancePlanningTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2028, 1, 15)->setTime(15, 0));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);

        return compact('tenant', 'store', 'owner');
    }

    private function draft(array $f): array
    {
        return ['store_id' => $f['store'], 'direction' => 'payable', 'description' => 'Aluguel mensal', 'counterparty' => 'Locador', 'category' => 'rent', 'amount' => '1000,01', 'due_on' => '2028-01-31', 'notes' => 'Contrato conferido'];
    }

    private function create(array $f, array $changes = []): int
    {
        $this->post('/finance/recurrences/preview', array_replace($this->draft($f), $changes))->assertOk();
        $this->post('/finance/recurrences', ['request_key' => session('finance_recurrence.data.request_key'), 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('financial_recurrences')->max('id');
    }

    public function test_reviewed_activation_generates_month_end_accounts_once_without_moving_money(): void
    {
        $f = $this->fixture();
        $this->get('/finance/planning')->assertOk()->assertSee('Previsão e recorrências');
        $this->post('/finance/recurrences/preview', $this->draft($f))->assertOk()->assertSee('29/02/2028')->assertSee('31/03/2028');
        $this->assertDatabaseCount('financial_accounts', 0);
        $data = ['request_key' => session('finance_recurrence.data.request_key'), 'confirmed' => 1, 'amount' => '1,00'];
        $this->post('/finance/recurrences', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/finance/recurrences', $data)->assertRedirect();
        $this->assertDatabaseCount('financial_recurrences', 1);
        $this->assertDatabaseCount('financial_accounts', 3);
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], DB::table('financial_accounts')->orderBy('due_on')->pluck('due_on')->all());
        $this->assertSame(300003, (int) DB::table('financial_accounts')->sum('amount_cents'));
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('financial_settlements', 0);
        $this->artisan('finance:recurrences')->expectsOutput('Contas programadas: 0; falhas: 0.')->assertSuccessful();
        $id = DB::table('financial_accounts')->value('id');
        $this->get('/finance/'.$id)->assertOk()->assertSee('Programação mensal')->assertSee('Geração mensal programada');
        $this->post('/finance/'.$id, array_replace($this->draft($f), ['amount' => '1200,00', 'request_key' => (string) Str::uuid(), 'revision' => 1]))->assertConflict();
        $this->post('/finance/'.$id.'/cancel', ['request_key' => (string) Str::uuid(), 'revision' => 1, 'confirmed' => 1, 'reason' => 'Conferido e cancelado'])->assertRedirect();
        $second = DB::table('financial_accounts')->where('due_on', '2028-02-29')->value('id');
        $this->post('/finance/'.$second, array_replace($this->draft($f), ['due_on' => '2028-03-01', 'revision' => 1, 'request_key' => (string) Str::uuid()]))->assertRedirect();
        DB::table('financial_recurrences')->update(['next_index' => 0]);
        $this->post('/finance/recurrences/generate', ['store_id' => $f['store'], 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('financial_accounts', 3);
    }

    public function test_scheduler_extends_horizon_and_new_amount_preserves_previous_accounts(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $revision = DB::table('financial_recurrences')->where('id', $id)->value('revision');
        $this->post('/finance/recurrences/'.$id, ['revision' => $revision, 'amount' => '1200,02', 'enabled' => 1, 'confirmed' => 1])->assertRedirect();
        $this->post('/finance/recurrences/'.$id, ['revision' => $revision, 'amount' => '1,00', 'enabled' => 0, 'confirmed' => 1])->assertConflict();
        $this->travelTo(now()->setDate(2028, 2, 15));
        $this->artisan('finance:recurrences')->expectsOutput('Contas programadas: 1; falhas: 0.')->assertSuccessful();
        $this->assertDatabaseHas('financial_accounts', ['recurrence_id' => $id, 'due_on' => '2028-04-30', 'amount_cents' => 120002, 'recurrence_revision' => $revision + 1]);
        $this->post('/finance/recurrences/'.$id, ['revision' => $revision + 1, 'amount' => '1,00', 'enabled' => 0, 'confirmed' => 1])->assertConflict();
        $this->assertSame(3, DB::table('financial_accounts')->where('amount_cents', 100001)->count());
        $this->get('/finance?recurrence_id='.$id.'&from=2028-01-01&to=2028-12-31')->assertOk()->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 4);
        $this->artisan('finance:recurrences')->assertSuccessful();
        $this->assertDatabaseCount('financial_accounts', 4);
    }

    public function test_pause_resume_skips_missed_occurrences_and_end_date_stops_generation(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $this->post('/finance/recurrences/'.$id, ['revision' => DB::table('financial_recurrences')->where('id', $id)->value('revision'), 'amount' => '1000,01', 'enabled' => 0, 'confirmed' => 1])->assertRedirect();
        $this->travelTo(now()->setDate(2028, 8, 15));
        $this->artisan('finance:recurrences')->assertSuccessful();
        $this->assertDatabaseCount('financial_accounts', 3);
        $this->post('/finance/recurrences/'.$id, ['revision' => DB::table('financial_recurrences')->where('id', $id)->value('revision'), 'amount' => '1000,01', 'enabled' => 1, 'confirmed' => 1])->assertRedirect();
        $this->artisan('finance:recurrences')->assertSuccessful();
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31', '2028-08-31', '2028-09-30', '2028-10-31'], DB::table('financial_accounts')->orderBy('due_on')->pluck('due_on')->all());
        $endId = $this->create($f, ['due_on' => '2028-08-31', 'end_on' => '2028-09-30']);
        $this->assertDatabaseHas('financial_recurrences', ['id' => $endId, 'enabled' => false]);
        $this->get('/finance/planning')->assertOk()->assertSee('Encerrada');
        $this->travelTo(now()->setDate(2029, 1, 15));
        $this->artisan('finance:recurrences')->assertSuccessful();
        $this->assertSame(2, DB::table('financial_accounts')->where('recurrence_id', $endId)->count());
    }

    public function test_forecast_uses_only_open_remainders_in_selected_store_and_keeps_overdue_separate(): void
    {
        $f = $this->fixture();
        foreach ([['payable', '100,01', '2028-01-20'], ['receivable', '200,00', '2028-01-21'], ['payable', '80,00', '2028-01-14'], ['payable', '99,00', '2028-01-22']] as [$direction, $amount, $due]) {
            $this->post('/finance', array_replace($this->draft($f), ['direction' => $direction, 'amount' => $amount, 'due_on' => $due, 'request_key' => (string) Str::uuid()]))->assertRedirect();
        }
        $ids = DB::table('financial_accounts')->orderBy('id')->pluck('id');
        foreach ([[$ids[0], '40,01'], [$ids[1], '50,00']] as [$id, $amount]) {
            $this->post('/finance/'.$id.'/settle', ['revision' => 1, 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'amount' => $amount, 'settled_on' => '2028-01-15', 'method' => 'pix', 'destination' => 'external', 'note' => 'Conferido'])->assertRedirect();
        }
        $this->post('/finance/'.$ids[3].'/cancel', ['revision' => 1, 'request_key' => (string) Str::uuid(), 'confirmed' => 1, 'reason' => 'Cancelado'])->assertRedirect();
        $response = $this->get('/finance/planning?from=2028-01-15&to=2028-01-31')->assertOk();
        $this->assertSame(['incoming' => 15000, 'outgoing' => 6000, 'net' => 9000], $response->viewData('totals'));
        $this->assertSame(['incoming' => 0, 'outgoing' => 8000], $response->viewData('overdue'));
        $this->assertSame([-6000, 9000], $response->viewData('daily')->pluck('cumulative')->all());
        $other = $this->store($f['tenant'], 'second');
        $this->get('/finance/planning?store_id='.$other)->assertViewHas('daily', fn ($daily) => $daily->isEmpty());
    }

    public function test_previews_confirmation_and_validation_cannot_be_bypassed(): void
    {
        $f = $this->fixture();
        $this->post('/finance/recurrences', ['request_key' => (string) Str::uuid(), 'confirmed' => 1])->assertConflict();
        $this->post('/finance/recurrences/preview', array_replace($this->draft($f), ['amount' => '0,00']))->assertStatus(422);
        $this->post('/finance/recurrences/preview', array_replace($this->draft($f), ['due_on' => '2028-01-14']))->assertStatus(422);
        $this->post('/finance/recurrences/preview', array_replace($this->draft($f), ['end_on' => '2028-01-30']))->assertSessionHasErrors('end_on');
        $this->post('/finance/recurrences/preview', $this->draft($f))->assertOk();
        $old = session('finance_recurrence.data.request_key');
        $this->post('/finance/recurrences', ['request_key' => $old])->assertSessionHasErrors('confirmed');
        $this->post('/finance/recurrences/preview', array_replace($this->draft($f), ['amount' => '10,00']))->assertOk();
        $this->post('/finance/recurrences', ['request_key' => $old, 'confirmed' => 1])->assertConflict();
        $key = session('finance_recurrence.data.request_key');
        $this->travel(31)->minutes();
        $this->post('/finance/recurrences', ['request_key' => $key, 'confirmed' => 1])->assertConflict();
        $this->get('/finance/planning?from=2028-01-31&to=2028-01-01')->assertSessionHasErrors('to');
        $this->get('/finance/planning?from=2028-01-01&to=2030-01-01')->assertSessionHasErrors('to');
        $this->assertDatabaseCount('financial_recurrences', 0);
    }

    public function test_foreign_company_staff_and_suspended_tenant_cannot_create_or_extend_accounts(): void
    {
        $f = $this->fixture();
        $id = $this->create($f);
        $otherTenant = $this->tenant('other');
        $this->actingAs($this->owner($otherTenant));
        $this->get('/finance/planning?store_id='.$f['store'])->assertNotFound();
        $this->get('/finance?recurrence_id='.$id)->assertNotFound();
        $this->post('/finance/recurrences/preview', $this->draft($f))->assertNotFound();
        $this->post('/finance/recurrences/'.$id, ['revision' => 1, 'amount' => '1,00', 'enabled' => 0, 'confirmed' => 1])->assertNotFound();
        $this->post('/finance/recurrences/generate', ['store_id' => $f['store'], 'confirmed' => 1])->assertNotFound();
        $staff = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff']);
        $this->actingAs($staff)->get('/finance/planning')->assertForbidden();
        $this->post('/finance/recurrences/preview', $this->draft($f))->assertForbidden();
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->travelTo(now()->setDate(2028, 2, 15));
        $this->artisan('finance:recurrences')->assertSuccessful();
        $this->assertDatabaseCount('financial_accounts', 3);
        $this->actingAs($f['owner'])->get('/finance/planning')->assertForbidden();
    }

    public function test_future_start_is_not_generated_early_and_receivables_use_same_rules(): void
    {
        $f = $this->fixture();
        $id = $this->create($f, ['direction' => 'receivable', 'due_on' => '2028-06-30']);
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->travelTo(now()->setDate(2028, 4, 1));
        $this->post('/finance/recurrences/generate', ['store_id' => $f['store'], 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseHas('financial_accounts', ['recurrence_id' => $id, 'direction' => 'receivable', 'due_on' => '2028-06-30', 'amount_cents' => 100001]);
        $this->assertDatabaseCount('financial_accounts', 1);
    }

    public function test_one_failed_program_does_not_block_other_stores_in_scheduler(): void
    {
        $f = $this->fixture();
        $failedId = $this->create($f);
        $otherStore = $this->store($f['tenant'], 'second');
        $secondId = $this->create($f, ['store_id' => $otherStore, 'description' => 'Internet']);
        $this->travelTo(now()->setDate(2028, 2, 15));
        $real = new FinancialRecurrences;
        $this->mock(FinancialRecurrences::class, function ($mock) use ($failedId, $secondId, $real): void {
            $mock->shouldReceive('generate')->once()->with($failedId)->andThrow(new \RuntimeException('Falha simulada'));
            $mock->shouldReceive('generate')->once()->with($secondId)->andReturnUsing(fn (int $id) => $real->generate($id));
        });
        $this->artisan('finance:recurrences')->expectsOutput('Falha na programação #'.$failedId.'. Consulte os logs.')->expectsOutput('Contas programadas: 1; falhas: 1.')->assertFailed();
        $this->assertSame(3, DB::table('financial_accounts')->where('recurrence_id', $failedId)->count());
        $this->assertSame(4, DB::table('financial_accounts')->where('recurrence_id', $secondId)->count());
    }
}
