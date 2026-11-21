<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ProductSalesTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function sale(int $tenant, int $store, string $name, int $cents, array $changes = [], int $quantity = 1): void
    {
        $order = $this->order($tenant, $store);
        DB::table('orders')->where('id', $order)->update($changes + ['payment_status' => 'paid', 'source' => 'menu', 'created_at' => '2026-11-20 15:00:00']);
        DB::table('order_items')->insert(['order_id' => $order, 'name' => $name, 'quantity' => $quantity, 'unit_cents' => $cents]);
    }

    public function test_abc_uses_historical_item_values_and_quantities_before_coupons_without_freight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 15:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->sale($tenant, $store, 'Principal', 4000, ['discount_cents' => 1000], 2);
        $this->sale($tenant, $store, 'Segundo', 1500, ['source' => 'pos']);
        $this->sale($tenant, $store, 'Terceiro', 500);
        $this->sale($tenant, $store, 'Cortesia', 0);
        $response = $this->actingAs($this->owner($tenant))->get('/bi/products');
        $response->assertOk()->assertViewHas('total', 10000)->assertViewHas('units', 5)->assertViewHas('classes', ['A' => 1, 'B' => 1, 'C' => 2]);
        $this->assertSame(['A', 'B', 'C', 'C'], $response->viewData('rows')->pluck('class')->all());
        $this->assertEquals([80, 95, 100, 100], $response->viewData('rows')->pluck('cumulative')->all());
        $response->assertSee('não representam lucro')->assertSee('descontos por cupom');
        $this->get('/bi')->assertOk()->assertSee('Produtos e curva ABC por loja');
    }

    public function test_store_tenant_payment_source_and_local_day_boundaries_are_enforced(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 15:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $otherStore = $this->store($tenant, 'segunda');
        $foreign = $this->tenant('foreign');
        $foreignStore = $this->store($foreign, 'foreign');
        $this->sale($tenant, $store, 'Início', 100, ['created_at' => '2026-11-20 03:00:00']);
        $this->sale($tenant, $store, 'Fim', 100, ['created_at' => '2026-11-21 02:59:59']);
        $this->sale($tenant, $store, 'Antes', 999, ['created_at' => '2026-11-20 02:59:59']);
        $this->sale($tenant, $store, 'Depois', 999, ['created_at' => '2026-11-21 03:00:00']);
        $this->sale($tenant, $store, 'Pendente', 999, ['payment_status' => 'pending']);
        $this->sale($tenant, $store, 'Cancelado', 999, ['status' => 'cancelled']);
        $this->sale($tenant, $store, 'iFood', 999, ['source' => 'ifood']);
        $this->sale($tenant, $otherStore, 'Outra unidade', 999);
        $this->sale($foreign, $foreignStore, 'Outra empresa', 999);
        $response = $this->actingAs($this->owner($tenant))->get('/bi/products?store_id='.$store.'&start=2026-11-20&end=2026-11-20&tenant_id='.$foreign);
        $response->assertOk()->assertViewHas('total', 200)->assertViewHas('units', 2);
        $this->assertSame(['Fim', 'Início'], $response->viewData('rows')->pluck('name')->all());
        $this->assertSame(['A', 'A'], $response->viewData('rows')->pluck('class')->all());
        $this->get('/bi/products?store_id='.$foreignStore)->assertNotFound();
        $this->get('/bi/products/export?store_id='.$foreignStore)->assertNotFound();
    }

    public function test_csv_exports_all_pages_and_neutralizes_formulas(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 15:00:00', 'UTC'));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        for ($i = 0; $i < 51; $i++) {
            $this->sale($tenant, $store, sprintf('Produto %02d', $i), 100);
        }
        $this->sale($tenant, $store, '=HYPERLINK("example")', 100);
        $this->actingAs($this->owner($tenant))->get('/bi/products')->assertOk()->assertViewHas('products', fn ($products) => $products->count() === 50 && $products->total() === 52);
        $response = $this->get('/bi/products/export?page=2');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Produto 50', $csv);
        $this->assertCount(53, array_filter(explode("\n", trim($csv))));
    }

    public function test_invalid_dates_and_disabled_access_are_rejected_and_empty_store_is_supported(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 15:00:00', 'UTC'));
        $tenant = $this->tenant();
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->get('/bi/products')->assertOk()->assertViewHas('total', 0);
        $this->getJson('/bi/products?start=2026-11-21&end=2026-11-20')->assertUnprocessable();
        $this->getJson('/bi/products?end=2026-11-22')->assertUnprocessable();
        $this->getJson('/bi/products?start=2024-01-01')->assertUnprocessable();
        DB::table('users')->where('id', $owner->id)->update(['access_enabled' => false]);
        $this->get('/bi/products')->assertForbidden();
        $this->get('/bi/products/export')->assertForbidden();
    }
}
