<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DeliveryStatementTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function completed(int $tenant, int $store, int $driver, int $fare = 800, int $cash = 0, string $at = '2026-10-28 15:00:00'): int
    {
        $id = $this->ride($tenant, $store);
        DB::table('rides')->where('id', $id)->update(['driver_id' => $driver, 'status' => 'completed', 'fare_cents' => $fare, 'cash_cents' => $cash, 'completed_at' => $at]);

        return $id;
    }

    private function fixture(): array
    {
        $this->travelTo(Carbon::parse('2026-10-29 16:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $driver = $this->driver();
        $otherDriver = $this->driver();
        $courier = User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
        DB::table('users')->where('id', $courier->id)->update(['name' => 'First courier']);
        DB::table('users')->where('id', DB::table('drivers')->where('id', $otherDriver)->value('user_id'))->update(['name' => 'Second courier']);
        $ride = $this->completed($tenant, $store, $otherDriver, 800, 3500);
        foreach ([['released_before_pickup', 450, 0, true], ['returned', 900, 1000, false]] as [$outcome, $fare, $cash, $settled]) {
            DB::table('delivery_attempts')->insert(['tenant_id' => $tenant, 'ride_id' => $ride, 'driver_id' => $driver, 'closed_by' => $owner->id, 'request_key' => Str::uuid(), 'outcome' => $outcome, 'reason' => 'Private reason not for the CSV.', 'quoted_fare_cents' => 800, 'compensation_cents' => $fare, 'expected_cash_cents' => 3500, 'customer_cash_cents' => $cash, 'settled' => $settled, 'closed_at' => '2026-10-28 14:00:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        $foreignTenant = $this->tenant('foreign');
        $foreignStore = $this->store($foreignTenant, 'foreign-store');
        DB::table('stores')->where('id', $foreignStore)->update(['name' => 'Foreign store']);
        $foreignRide = $this->completed($foreignTenant, $foreignStore, $driver, 1200);
        $active = $this->ride($tenant, $store);
        DB::table('rides')->where('id', $active)->update(['driver_id' => $driver, 'status' => 'assigned', 'fare_cents' => 99900]);

        return compact('tenant', 'store', 'owner', 'driver', 'otherDriver', 'courier', 'ride', 'foreignTenant', 'foreignStore', 'foreignRide');
    }

    public function test_owner_totals_include_each_closed_attempt_and_completed_ride_once_without_active_or_foreign_rides(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['owner'])->get('/delivery-statement');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('Foreign store')->assertDontSee('Rua do Cliente')->assertDontSee('11999999999')->assertDontSee('Private reason');
        $summary = $response->viewData('totals');
        $this->assertEquals(3, $summary->entries_count);
        $this->assertEquals(2150, $summary->compensation_cents);
        $this->assertEquals(1700, $summary->pending_compensation_cents);
        $this->assertEquals(3500, $summary->completed_cash_cents);
        $this->assertEquals(1000, $summary->attempt_cash_cents);
        $this->assertDatabaseHas('delivery_attempts', ['outcome' => 'returned', 'settled' => false, 'compensation_cents' => 900]);
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'fare_cents' => 800, 'cash_cents' => 3500, 'settled' => false]);
    }

    public function test_courier_statement_retains_previous_attempts_after_reassignment_but_does_not_show_new_driver_or_customer(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['courier'])->get('/delivery-statement?driver_id='.$f['otherDriver']);
        $response->assertOk()->assertDontSee('Second courier')->assertDontSee('Rua do Cliente')->assertDontSee('11999999999');
        $summary = $response->viewData('totals');
        $this->assertEquals(3, $summary->entries_count);
        $this->assertEquals(2550, $summary->compensation_cents);
        $this->assertEquals(0, $summary->completed_cash_cents);
        $this->assertEquals(1000, $summary->attempt_cash_cents);
        $this->assertNull($response->viewData('filters')['driver_id']);
        $response = $this->get('/delivery-statement?store_id='.$f['store']);
        $this->assertEquals(2, $response->viewData('totals')->entries_count);
        $this->get('/deliveries/'.$f['ride'])->assertNotFound();
    }

    public function test_settlement_store_and_driver_filters_match_summary_and_export(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner']);
        $pending = $this->get('/delivery-statement?settlement=pending&driver_id='.$f['driver'])->assertOk();
        $this->assertEquals(1, $pending->viewData('totals')->entries_count);
        $this->assertEquals(900, $pending->viewData('totals')->compensation_cents);
        $confirmed = $this->get('/delivery-statement?settlement=settled&store_id='.$f['store'])->assertOk();
        $this->assertEquals(450, $confirmed->viewData('totals')->compensation_cents);
        $this->assertEquals(0, $confirmed->viewData('totals')->pending_compensation_cents);
        $csv = $this->get('/delivery-statement/export?settlement=pending&driver_id='.$f['driver'])->assertOk()->streamedContent();
        $this->assertStringContainsString('Devolvida', $csv);
        $this->assertStringContainsString('9,00', $csv);
        $this->assertStringNotContainsString('Liberada antes', $csv);
        $this->assertStringNotContainsString('Second courier', $csv);
        $this->get('/delivery-statement?store_id='.$f['foreignStore'])->assertNotFound();
        $this->get('/delivery-statement/export?store_id='.$f['foreignStore'])->assertNotFound();
        $foreignDriver = $this->driver();
        $this->completed($f['foreignTenant'], $f['foreignStore'], $foreignDriver);
        $this->get('/delivery-statement?driver_id='.$foreignDriver)->assertNotFound();
    }

    public function test_date_bounds_use_closure_time_in_selected_timezone_and_validation_is_bounded(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 16:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver();
        foreach (['2026-10-28 02:59:59', '2026-10-28 03:00:00', '2026-10-29 02:59:59', '2026-10-29 03:00:00'] as $at) {
            $id = $this->completed($tenant, $store, $driver, 800, 0, $at);
            DB::table('rides')->where('id', $id)->update(['created_at' => '2026-01-01 00:00:00']);
        }
        $this->actingAs($this->owner($tenant));
        $response = $this->get('/delivery-statement?start=2026-10-28&end=2026-10-28&timezone=America/Sao_Paulo')->assertOk();
        $this->assertEquals(2, $response->viewData('totals')->entries_count);
        $this->assertEquals(1600, $response->viewData('totals')->compensation_cents);
        foreach (['start=2026-10-29&end=2026-10-28', 'start=2025-01-01&end=2026-10-29', 'end=2026-10-30', 'timezone=unknown', 'settlement=other', 'start=not-a-date'] as $invalid) {
            $this->getJson('/delivery-statement?'.$invalid)->assertUnprocessable();
        }
    }

    public function test_access_rules_apply_to_page_and_csv_including_suspension_and_courier_history(): void
    {
        $f = $this->fixture();
        foreach (['admin', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'tenant_id' => $f['tenant']]))->get('/delivery-statement')->assertForbidden();
            $this->get('/delivery-statement/export')->assertForbidden();
        }
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->actingAs($f['owner'])->get('/delivery-statement')->assertForbidden();
        $this->get('/delivery-statement/export')->assertForbidden();
        $this->actingAs($f['courier'])->get('/delivery-statement')->assertOk();
        $this->get('/delivery-statement/export')->assertOk();
        DB::table('users')->where('id', $f['courier']->id)->update(['access_enabled' => false]);
        $this->actingAs($f['courier']->fresh());
        $this->get('/delivery-statement')->assertForbidden();
        $this->get('/delivery-statement/export')->assertForbidden();
    }

    public function test_csv_exports_all_pages_neutralizes_spreadsheet_formulas_and_omits_customer_secrets(): void
    {
        $f = $this->fixture();
        DB::table('stores')->where('id', $f['store'])->update(['name' => '=HYPERLINK("example")']);
        DB::table('users')->where('id', $f['courier']->id)->update(['name' => "\t+unsafe"]);
        for ($i = 0; $i < 32; $i++) {
            $this->completed($f['tenant'], $f['store'], $f['otherDriver']);
        }
        $this->actingAs($f['owner']);
        $response = $this->get('/delivery-statement')->assertOk();
        $this->assertSame(30, $response->viewData('entries')->count());
        $this->assertEquals(35, $response->viewData('entries')->total());
        $csv = $this->get('/delivery-statement/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $rows = array_filter(explode("\n", trim($csv)));
        $this->assertCount(37, $rows);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'\t+unsafe", $csv);
        $this->assertStringNotContainsString('Foreign store', $csv);
        $this->assertStringNotContainsString('Private reason', $csv);
        $this->assertStringNotContainsString('Rua do Cliente', $csv);
        $this->assertStringNotContainsString('11999999999', $csv);
    }

    public function test_export_rate_limit_does_not_block_the_statement_page_or_modify_settlements(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner']);
        for ($i = 0; $i < 6; $i++) {
            $this->get('/delivery-statement/export')->assertOk();
        }
        $this->get('/delivery-statement/export')->assertStatus(429);
        $this->get('/delivery-statement')->assertOk();
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'settled' => false]);
        $this->assertDatabaseHas('delivery_attempts', ['outcome' => 'returned', 'settled' => false]);
    }
}
