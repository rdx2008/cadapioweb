<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InventoryPurchases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class InventoryPurchasesTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $owner = $this->owner($tenant);
        $store = $this->store($tenant);
        $this->actingAs($owner);
        $this->post('/inventory/suppliers', ['store_id' => $store, 'name' => 'Distribuidora', 'active' => 1])->assertSessionHasNoErrors();
        $supplier = DB::table('inventory_suppliers')->value('id');
        $items = [];
        foreach (['Farinha', 'Leite'] as $name) {
            $this->post('/inventory/items', ['store_id' => $store, 'name' => $name, 'unit' => 'kg', 'minimum' => '0', 'active' => 1])->assertSessionHasNoErrors();
            $items[] = DB::table('inventory_items')->where('name', $name)->value('id');
        }

        return compact('tenant', 'owner', 'store', 'supplier', 'items');
    }

    private function draft(array $f): array
    {
        return ['store_id' => $f['store'], 'supplier_id' => $f['supplier'], 'purchased_on' => '2026-11-08', 'reference' => 'NF 123', 'freight' => '3,01', 'discount' => '1,00', 'request_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $f['items'][0], 'quantity' => '2', 'unit_price' => '7'], ['item_id' => $f['items'][1], 'quantity' => '1', 'unit_price' => '4']]];
    }

    private function receive(int $id): array
    {
        return ['request_key' => (string) Str::uuid(), 'confirmed' => 1, 'review_hash' => app(InventoryPurchases::class)->reviewHash(DB::table('inventory_purchases')->find($id), DB::table('inventory_purchase_lines')->where('purchase_id', $id)->get())];
    }

    public function test_multiline_receipt_allocates_every_cent_and_updates_weighted_cost_once(): void
    {
        $f = $this->fixture();
        $this->post('/inventory/items/'.$f['items'][0].'/movements', ['kind' => 'in', 'quantity' => '10', 'entry_value' => '50', 'reason' => 'Inicial', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $draft = $this->draft($f);
        $this->post('/inventory/purchases', $draft)->assertSessionHasNoErrors();
        $this->post('/inventory/purchases', $draft)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_purchases', 1);
        $id = DB::table('inventory_purchases')->value('id');
        $this->assertDatabaseCount('inventory_movements', 1);
        $lines = DB::table('inventory_purchase_lines')->where('purchase_id', $id)->orderBy('item_id')->get();
        $this->assertSame(301, (int) $lines->sum('freight_cents'));
        $this->assertSame(100, (int) $lines->sum('discount_cents'));
        $this->assertSame(2001, (int) $lines->sum('total_cents'));
        $this->assertSame(1556, $lines[0]->total_cents);
        $this->get('/inventory/purchases/'.$id)->assertOk()->assertSee('Confirmar recebimento integral');
        $receive = $this->receive($id);
        $this->post('/inventory/purchases/'.$id.'/receive', $receive)->assertSessionHasNoErrors();
        $this->post('/inventory/purchases/'.$id.'/receive', $receive)->assertSessionHasNoErrors();
        $this->post('/inventory/purchases/'.$id.'/receive', $this->receive($id))->assertConflict();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 12000, 'stock_value_cents' => 6556, 'average_cost_micros' => 5463333]);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][1], 'balance' => 1000, 'stock_value_cents' => 445]);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseCount('inventory_purchase_events', 2);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->get('/inventory/purchases/'.$id)->assertOk()->assertDontSee('Confirmar recebimento integral');
        $this->get('/inventory/purchases/'.$id.'/edit')->assertConflict();
        $this->post('/inventory/purchases/'.$id, array_replace($draft, ['revision' => 2, 'request_key' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_draft_edit_requires_current_revision_and_receipt_requires_current_review(): void
    {
        $f = $this->fixture();
        $draft = $this->draft($f);
        $this->post('/inventory/purchases', $draft)->assertSessionHasNoErrors();
        $id = DB::table('inventory_purchases')->value('id');
        $old = $this->receive($id);
        $edit = array_replace($draft, ['revision' => 1, 'notes' => 'Corrigida', 'request_key' => (string) Str::uuid()]);
        $this->post('/inventory/purchases/'.$id, $edit)->assertSessionHasNoErrors();
        $this->post('/inventory/purchases/'.$id, array_replace($edit, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->post('/inventory/purchases/'.$id.'/receive', $old)->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
        $snapshot = json_decode(DB::table('inventory_purchase_events')->where('action', 'created')->value('snapshot'), true);
        $this->assertNull($snapshot['purchase']['notes']);
        $this->post('/inventory/purchases/'.$id.'/cancel', ['revision' => 2, 'reason' => 'Duplicada', 'request_key' => (string) Str::uuid(), 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post('/inventory/purchases/'.$id.'/receive', $this->receive($id))->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_later_line_failure_or_audit_failure_rolls_back_entire_receipt(): void
    {
        $f = $this->fixture();
        $this->post('/inventory/purchases', $this->draft($f))->assertSessionHasNoErrors();
        $id = DB::table('inventory_purchases')->value('id');
        $receive = $this->receive($id);
        DB::table('inventory_items')->where('id', $f['items'][1])->update(['active' => false]);
        $this->post('/inventory/purchases/'.$id.'/receive', $receive)->assertSessionHasErrors('purchase');
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 0, 'stock_value_cents' => 0]);
        $this->assertDatabaseCount('inventory_movements', 0);
        DB::table('inventory_items')->where('id', $f['items'][1])->update(['active' => true, 'balance' => 999999999]);
        $this->post('/inventory/purchases/'.$id.'/receive', $receive)->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('inventory_movements', 0);
        DB::table('inventory_items')->where('id', $f['items'][1])->update(['balance' => 0]);
        DB::statement("CREATE TRIGGER fail_purchase BEFORE INSERT ON audit_events WHEN NEW.action='inventory.purchase_received' BEGIN SELECT RAISE(FAIL,'audit unavailable'); END");
        $this->post('/inventory/purchases/'.$id.'/receive', $receive)->assertStatus(500);
        $this->assertDatabaseHas('inventory_purchases', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 0, 'stock_value_cents' => 0]);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_purchase_events', 1);
    }

    public function test_permissions_supplier_scoping_and_history_preservation(): void
    {
        $f = $this->fixture();
        $draft = $this->draft($f);
        $this->post('/inventory/suppliers', ['store_id' => $f['store'], 'name' => ' DISTRIBUIDORA ', 'active' => 1])->assertSessionHasErrors('name');
        $this->post('/inventory/purchases', $draft)->assertSessionHasNoErrors();
        $id = DB::table('inventory_purchases')->value('id');
        $this->post('/inventory/suppliers/'.$f['supplier'], ['store_id' => $f['store'], 'name' => 'Novo nome', 'active' => 0])->assertSessionHasNoErrors();
        $this->get('/inventory/purchases/'.$id)->assertOk()->assertSee('Distribuidora');
        $this->post('/inventory/purchases', array_replace($draft, ['request_key' => (string) Str::uuid()]))->assertSessionHasErrors('supplier_id');
        $otherStore = $this->store($f['tenant'], 'segunda');
        $this->post('/inventory/purchases', array_replace($draft, ['store_id' => $otherStore, 'request_key' => (string) Str::uuid()]))->assertSessionHasErrors('supplier_id');
        foreach (['/inventory/purchases', '/inventory/suppliers', '/inventory/costs'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/inventory/purchases/create?store_id='.$f['store'])->assertOk();
        $this->actingAs($this->owner($this->tenant('other')));
        $this->get('/inventory/purchases/'.$id)->assertNotFound();
        $this->post('/inventory/purchases/'.$id.'/receive', $this->receive($id))->assertNotFound();
        $this->get('/inventory/suppliers?store_id='.$f['store'])->assertNotFound();
        $this->actingAs(User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff']));
        $this->get('/inventory/purchases')->assertForbidden();
        $this->post('/inventory/purchases', $draft)->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/inventory/costs')->assertForbidden();
    }

    public function test_invalid_lines_prices_and_discount_never_create_stock_or_purchase(): void
    {
        $f = $this->fixture();
        $draft = $this->draft($f);
        foreach (['-1', '1.000,00', '1e6', '1000001'] as $price) {
            $bad = $draft;
            $bad['lines'][0]['unit_price'] = $price;
            $this->post('/inventory/purchases', $bad)->assertSessionHasErrors();
        }
        $this->post('/inventory/purchases', array_replace($draft, ['discount' => '19']))->assertSessionHasErrors('discount');
        $bad = $draft;
        $bad['lines'][1]['item_id'] = $bad['lines'][0]['item_id'];
        $this->post('/inventory/purchases',$bad)->assertSessionHasErrors();
        $bad = $draft;
        $bad['lines'][0]['quantity'] = '0';
        $this->post('/inventory/purchases',$bad)->assertSessionHasErrors();
        $this->assertDatabaseCount('inventory_purchases',0);
        $this->assertDatabaseCount('inventory_movements',0);
    }
}
