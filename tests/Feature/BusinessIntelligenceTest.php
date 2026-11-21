<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BusinessIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class BusinessIntelligenceTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function sale(int $tenant, int $store, array $changes = []): int
    {
        $id = $this->order($tenant, $store);
        DB::table('orders')->where('id', $id)->update($changes + ['status' => 'completed', 'payment_status' => 'paid', 'created_at' => '2026-10-20 15:00:00']);

        return $id;
    }

    public function test_metrics_compare_periods_normalize_contacts_and_exclude_unpaid_and_cancelled_sales(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $id = $this->sale($tenant, $store, ['phone' => '+55 (11) 99999-9999']);
        $this->sale($tenant, $store, ['phone' => '11999999999']);
        $this->sale($tenant, $store, ['phone' => 'invalid']);
        $this->sale($tenant, $store, ['status' => 'cancelled']);
        $this->sale($tenant, $store, ['status' => 'pending', 'payment_status' => 'review']);
        $this->sale($tenant, $store, ['created_at' => '2026-10-13 15:00:00']);
        DB::table('order_items')->insert(['order_id' => $id, 'name' => 'Pizza', 'quantity' => 2, 'unit_cents' => 1500]);
        $report = app(BusinessIntelligence::class)->report($tenant, null, Carbon::parse('2026-10-20', 'America/Sao_Paulo'), Carbon::parse('2026-10-26', 'America/Sao_Paulo'), 'America/Sao_Paulo', false);
        $this->assertSame(10500, $report['summary']['paid_cents']);
        $this->assertSame(9000, $report['summary']['product_cents']);
        $this->assertSame(1500, $report['summary']['freight_cents']);
        $this->assertSame(3500, $report['summary']['average_cents']);
        $this->assertSame(3500, $report['summary']['pending_cents']);
        $this->assertSame(1, $report['summary']['customers']);
        $this->assertSame(1, $report['summary']['returning_customers']);
        $this->assertEquals(2, $report['summary']['frequency']);
        $this->assertEquals(20, $report['summary']['cancellation_rate']);
        $this->assertEquals(200, $report['growth']);
        $this->assertCount(7, $report['daily']);
        $this->assertSame(3500, $report['daily']['2026-10-20']['previous_cents']);
        $this->assertSame(0, $report['daily']['2026-10-21']['paid_cents']);
        $this->assertSame(3, $report['hours'][12]['paid_orders']);
        $this->assertEquals(3000, $report['products']->first()->revenue_cents);
    }

    public function test_owner_html_and_csv_are_scoped_even_with_a_forged_tenant_filter(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 18:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $foreign = $this->tenant('other');
        $foreignStore = $this->store($foreign, 'other');
        $this->sale($tenant, $store);
        $this->sale($foreign, $foreignStore, ['total_cents' => 999999]);
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->get('/bi?tenant_id='.$foreign)->assertOk()->assertSee('R$ 35,00')->assertDontSee('R$ 9.999,99');
        $csv = $this->get('/bi/export?tenant_id='.$foreign)->assertOk()->streamedContent();
        $this->assertStringContainsString('35,00', $csv);
        $this->assertStringNotContainsString('9999,99', $csv);
        $this->assertStringNotContainsString('11999999999', $csv);
        $this->get('/bi?store_id='.$foreignStore)->assertNotFound();
        $this->get('/admin/bi')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant]))->get('/bi')->assertForbidden();
    }

    public function test_platform_history_includes_deleted_companies_and_contact_identity_is_per_tenant(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 18:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->tenant('archived');
        $otherStore = $this->store($other, 'archived');
        $this->sale($tenant, $store);
        $this->sale($other, $otherStore);
        DB::table('tenants')->where('id', $other)->update(['deleted_at' => now(), 'status' => 'suspended']);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/bi')->assertOk()->assertSee('R$ 70,00')->assertSee('Archived');
        $report = $response->viewData('report');
        $this->assertSame(2, $report['summary']['customers']);
        $this->assertSame(1, $report['platformStats']['deleted']);
        $this->assertCount(2, $report['companyRanking']);
        $this->get('/admin/bi?tenant_id='.$tenant)->assertOk()->assertSee('R$ 35,00')->assertDontSee('R$ 70,00');
    }

    public function test_timezone_boundaries_empty_period_and_date_validation(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 18:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->sale($tenant, $store, ['created_at' => '2026-10-21 02:59:59']);
        $this->sale($tenant, $store, ['created_at' => '2026-10-21 03:00:00']);
        $owner = $this->owner($tenant);
        $response = $this->actingAs($owner)->get('/bi?start=2026-10-20&end=2026-10-20')->assertOk();
        $this->assertSame(1, $response->viewData('report')['summary']['paid_orders']);
        $this->assertNull($response->viewData('report')['growth']);
        $empty = $this->get('/bi?start=2026-09-01&end=2026-09-01')->assertOk();
        $this->assertSame(0, $empty->viewData('report')['summary']['paid_cents']);
        $this->get('/bi?start=2026-10-27&end=2026-10-20')->assertSessionHasErrors('start');
        $this->get('/bi?end=2026-10-28')->assertSessionHasErrors('start');
        $this->get('/bi?start=2020-01-01')->assertSessionHasErrors('start');
    }
}
