<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class FinanceExportTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_export_respects_filters_and_store_isolation_includes_all_pages_and_protects_spreadsheet_cells(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $otherStore = $this->store($tenant, 'other');
        $foreign = $this->store($this->tenant('foreign'), 'foreign');
        $this->actingAs($this->owner($tenant));
        $data = ['store_id' => $store, 'direction' => 'payable', 'description' => '=HYPERLINK("evil")', 'counterparty' => '+Formula', 'category' => 'other', 'due_on' => '2026-11-20', 'amount' => '100,01'];
        for ($i = 0; $i < 23; $i++) {
            $this->post('/finance', $data + ['request_key' => (string) Str::uuid()])->assertRedirect();
        }
        $this->post('/finance', array_replace($data, ['store_id' => $otherStore, 'description' => 'Outra loja', 'request_key' => (string) Str::uuid()]))->assertRedirect();
        $this->post('/finance', array_replace($data, ['direction' => 'receivable', 'description' => 'Recebimento', 'request_key' => (string) Str::uuid()]))->assertRedirect();
        $last = DB::table('financial_accounts')->where('store_id', $store)->where('direction', 'payable')->max('id');
        DB::table('financial_accounts')->where('id', $last)->update(['status' => 'cancelled', 'installment_group' => (string) Str::uuid(), 'installment_number' => 1, 'installment_count' => 2]);
        $url = '/finance/export?store_id='.$store.'&direction=payable&from=2026-11-01&to=2026-11-30&page=2';
        $response = $this->get($url)->assertOk()->assertDownload('contas-2026-11-01-2026-11-30.csv');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(23, substr_count($csv, 'HYPERLINK'));
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+Formula", $csv);
        $this->assertStringNotContainsString('Outra loja', $csv);
        $this->assertStringNotContainsString('Recebimento', $csv);
        $this->assertStringContainsString('Cancelada;1/2;100,01;0,00;0,00', $csv);
        $this->assertSame(22, substr_count($this->get($url.'&status=open')->streamedContent(), 'HYPERLINK'));
        $this->get('/finance/export?store_id='.$foreign)->assertNotFound();
        $this->get('/finance/export?from=2026-11-30&to=2026-11-01')->assertSessionHasErrors('to');
        $this->assertDatabaseCount('financial_settlements', 0);
    }

    public function test_staff_cannot_export_owner_finances(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $staff = User::factory()->create(['tenant_id' => $tenant, 'role' => 'staff']);
        $this->actingAs($staff)->get('/finance/export?store_id='.$store)->assertForbidden();
    }
}
