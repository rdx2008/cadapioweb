<?php

namespace Tests\Feature;

use App\Services\Inventory;
use App\Services\OrderInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class OrderCostReportTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(Carbon::parse('2026-11-21 15:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        $item = DB::table('inventory_items')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Insumo', 'name_key' => 'insumo', 'unit' => 'kg', 'balance' => 100000, 'stock_value_cents' => 100000, 'average_cost_micros' => 10000000]);

        return compact('tenant', 'store', 'owner', 'item');
    }

    private function sale(array $fixture, array $changes = [], bool $consume = true): int
    {
        $id = $this->order($fixture['tenant'], $fixture['store']);
        DB::table('orders')->where('id', $id)->update($changes + ['source' => 'menu', 'status' => 'completed', 'payment_status' => 'paid', 'subtotal_cents' => 3000, 'discount_cents' => 500, 'fee_cents' => 500, 'total_cents' => 3000, 'created_at' => '2026-11-20 15:00:00',
            'inventory_plan' => json_encode(['version' => 1, 'items' => [['item_id' => $fixture['item'], 'name' => 'Insumo', 'unit' => 'kg', 'quantity' => 1000]], 'recipes' => []])]);
        if ($consume) {
            DB::transaction(fn () => app(OrderInventory::class)->consume(DB::table('orders')->find($id), $fixture['owner']->id));
        }

        return $id;
    }

    public function test_costs_are_historical_and_margin_uses_discounted_products_without_freight(): void
    {
        $fixture = $this->fixture();
        $id = $this->sale($fixture);
        DB::table('inventory_items')->where('id', $fixture['item'])->update(['stock_value_cents' => 990000]);
        $response = $this->get('/bi/order-costs');
        $response->assertOk()->assertSee('Não representa lucro líquido.');
        $metrics = $response->viewData('metrics');
        $this->assertEquals(1, $metrics->covered_count);
        $this->assertEquals(2500, $metrics->covered_sales_cents);
        $this->assertEquals(1000, $metrics->covered_cost_cents);
        $this->assertEquals(1500, $metrics->covered_sales_cents - $metrics->covered_cost_cents);
        $this->assertEquals($id, $response->viewData('orders')->first()->id);
        $this->get('/bi')->assertOk()->assertSee('Custos históricos dos pedidos');
        $this->get('/inventory/costs')->assertOk()->assertSee('Ver custos históricos dos pedidos');
    }

    public function test_unknown_and_missing_consumption_remain_pending_but_known_zero_is_complete(): void
    {
        $fixture = $this->fixture();
        $this->sale($fixture, ['inventory_plan' => null], false);
        $this->sale($fixture, [], false);
        DB::table('inventory_items')->where('id', $fixture['item'])->update(['stock_value_cents' => null, 'average_cost_micros' => null]);
        $this->sale($fixture);
        DB::table('inventory_items')->where('id', $fixture['item'])->update(['stock_value_cents' => 0, 'average_cost_micros' => 0]);
        $zero = $this->sale($fixture, ['source' => 'pos']);
        $response = $this->get('/bi/order-costs');
        $metrics = $response->viewData('metrics');
        $this->assertEquals(4, $metrics->orders_count);
        $this->assertEquals(1, $metrics->covered_count);
        $this->assertEquals(0, $metrics->covered_cost_cents);
        $this->assertEquals(7500, $metrics->pending_sales_cents);
        $this->get('/bi/order-costs?coverage=complete&source=pos')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->id === $zero);
        $csv = $this->get('/bi/order-costs/export?coverage=pending')->assertOk()->streamedContent();
        $this->assertStringContainsString('25,00;;;Pendente', $csv);
        $this->assertStringNotContainsString(';Completo', $csv);
    }

    public function test_only_paid_completed_local_sales_in_the_store_day_are_included(): void
    {
        $fixture = $this->fixture();
        $first = $this->sale($fixture, ['created_at' => '2026-11-20 03:00:00']);
        $last = $this->sale($fixture, ['created_at' => '2026-11-21 02:59:59']);
        $this->sale($fixture, ['created_at' => '2026-11-20 02:59:59']);
        $this->sale($fixture, ['created_at' => '2026-11-21 03:00:00']);
        $this->sale($fixture, ['status' => 'cancelled']);
        $this->sale($fixture, ['status' => 'ready']);
        $this->sale($fixture, ['payment_status' => 'pending']);
        $this->sale($fixture, ['source' => 'ifood']);
        $otherStore = $this->store($fixture['tenant'], 'other');
        $this->order($fixture['tenant'], $otherStore);
        $response = $this->get('/bi/order-costs?store_id='.$fixture['store'].'&start=2026-11-20&end=2026-11-20');
        $response->assertOk();
        $this->assertSame([$last, $first], $response->viewData('orders')->pluck('id')->all());
        $this->assertEquals(2000, $response->viewData('metrics')->covered_cost_cents);
    }

    public function test_partial_unknown_cost_excludes_the_entire_order_from_covered_metrics(): void
    {
        $fixture = $this->fixture();
        $id = $this->sale($fixture);
        $item = DB::table('inventory_items')->insertGetId(['tenant_id' => $fixture['tenant'], 'store_id' => $fixture['store'], 'name' => 'Sem custo', 'name_key' => 'sem custo', 'unit' => 'kg', 'balance' => 10000]);
        app(Inventory::class)->move(DB::table('inventory_items')->find($item), $fixture['owner'], 'out', 1000, 'Preparo', (string) Str::uuid(), $id);
        $response = $this->get('/bi/order-costs');
        $this->assertEquals(0, $response->viewData('metrics')->covered_count);
        $this->assertEquals(0, $response->viewData('metrics')->covered_cost_cents);
        $this->assertEquals(2500, $response->viewData('metrics')->pending_sales_cents);
    }

    public function test_csv_includes_all_pages_and_negative_results_are_preserved(): void
    {
        $fixture = $this->fixture();
        for ($i = 0; $i < 51; $i++) {
            $this->sale($fixture, ['subtotal_cents' => 600, 'discount_cents' => 100]);
        }
        $this->get('/bi/order-costs')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 50 && $orders->total() === 51);
        $csv = $this->get('/bi/order-costs/export?page=2')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertCount(52, array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('5,00;10,00;-5,00;Completo', $csv);
        $this->assertDatabaseCount('inventory_movements', 51);
    }

    public function test_access_filters_and_empty_reports_are_safe(): void
    {
        $fixture = $this->fixture();
        $foreign = $this->tenant('foreign');
        $foreignStore = $this->store($foreign, 'foreign');
        $this->get('/bi/order-costs?store_id='.$foreignStore)->assertNotFound();
        $this->get('/bi/order-costs/export?store_id='.$foreignStore)->assertNotFound();
        $this->getJson('/bi/order-costs?source=ifood')->assertUnprocessable();
        $this->getJson('/bi/order-costs?start=2026-11-21&end=2026-11-20')->assertUnprocessable();
        $this->getJson('/bi/order-costs?end=2026-11-22')->assertUnprocessable();
        $this->getJson('/bi/order-costs?start=2024-01-01')->assertUnprocessable();
        $this->get('/bi/order-costs')->assertOk()->assertViewHas('metrics', fn ($metrics) => (int) $metrics->orders_count === 0);
        DB::table('tenants')->where('id', $fixture['tenant'])->update(['status' => 'suspended']);
        $this->get('/bi/order-costs')->assertForbidden();
        $this->get('/bi/order-costs/export')->assertForbidden();
        DB::table('tenants')->where('id', $fixture['tenant'])->update(['status' => 'active']);
        DB::table('users')->where('id', $fixture['owner']->id)->update(['access_enabled' => false]);
        $this->get('/bi/order-costs')->assertForbidden();
        $this->get('/bi/order-costs/export')->assertForbidden();

    }
}
