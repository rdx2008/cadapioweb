<?php

namespace Tests\Feature;

use App\Services\BusinessIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ProductionIntelligenceTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_production_metrics_use_valid_completed_times_and_snapshot_targets(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        foreach ([
            ['ready', '12:00:00', '12:10:00', 10],
            ['completed', '12:00:00', '12:20:00', 10],
            ['completed', '12:00:00', '12:00:00', null],
            ['completed', null, null, 30],
            ['ready', '12:20:00', '12:00:00', 30],
            ['cancelled', '12:00:00', '12:30:00', 10],
            ['preparing', '12:00:00', null, 10],
        ] as [$status, $started, $ready, $target]) {
            $id = $this->order($tenant, $store);
            DB::table('orders')->where('id', $id)->update([
                'status' => $status, 'payment_status' => 'pending', 'created_at' => '2026-10-20 15:00:00',
                'preparation_started_at' => $started ? '2026-10-20 '.$started : null,
                'ready_at' => $ready ? '2026-10-20 '.$ready : null,
                'preparation_target_minutes' => $target,
            ]);
        }
        DB::table('stores')->where('id', $store)->update(['preparation_target_minutes' => 240]);
        $report = app(BusinessIntelligence::class)->report($tenant, $store, Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'), 'UTC', false);
        $this->assertSame(3, $report['production']['measured']);
        $this->assertSame(2, $report['production']['without_measurement']);
        $this->assertEquals(10, $report['production']['average_minutes']);
        $this->assertEquals(50, $report['production']['on_time_rate']);
        $this->assertSame(2, $report['production']['with_target']);
    }

    public function test_empty_metrics_and_tenant_store_and_date_filters(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 18:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $otherStore = $this->store($tenant, 'second');
        $foreign = $this->tenant('foreign');
        $foreignStore = $this->store($foreign, 'foreign');
        foreach ([[$tenant, $otherStore], [$foreign, $foreignStore]] as [$company, $branch]) {
            $id = $this->order($company, $branch);
            DB::table('orders')->where('id', $id)->update(['status' => 'ready', 'created_at' => '2026-10-20 15:00:00', 'preparation_started_at' => '2026-10-20 15:00:00', 'ready_at' => '2026-10-20 15:20:00']);
        }
        $this->actingAs($this->owner($tenant));
        $response = $this->get('/bi?start=2026-10-20&end=2026-10-20&store_id='.$store)->assertOk();
        $production = $response->viewData('report')['production'];
        $this->assertSame(0, $production['measured']);
        $this->assertNull($production['average_minutes']);
        $this->assertNull($production['on_time_rate']);
        $this->assertSame(1, $this->get('/bi?start=2026-10-20&end=2026-10-20')->assertOk()->viewData('report')['production']['measured']);
        $this->assertSame(0, $this->get('/bi?start=2026-10-21&end=2026-10-21')->assertOk()->viewData('report')['production']['measured']);
        $this->get('/bi?store_id='.$foreignStore)->assertNotFound();
    }
}
