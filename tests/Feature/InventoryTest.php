<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $owner = $this->owner($tenant);
        $store = $this->store($tenant);
        $this->actingAs($owner)->post('/inventory/items', ['store_id' => $store, 'name' => 'Farinha', 'unit' => 'kg', 'minimum' => '5,125', 'active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $item = DB::table('inventory_items')->firstOrFail();

        return compact('tenant', 'owner', 'store', 'item');
    }

    private function movement(string $quantity = '10,125', string $kind = 'in'): array
    {
        return ['quantity' => $quantity, 'kind' => $kind, 'reason' => 'Conferencia de estoque', 'request_key' => (string) Str::uuid(), 'confirmed' => 1];
    }

    public function test_precise_movements_update_stock_and_preserve_history_with_actor(): void
    {
        $f = $this->fixture();
        $path = '/inventory/items/'.$f['item']->id.'/movements';
        $this->post($path, $this->movement())->assertRedirect()->assertSessionHasNoErrors();
        $this->post($path, $this->movement('0,125', 'out'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item']->id, 'balance' => 10000, 'minimum' => 5125]);
        $this->assertDatabaseHas('inventory_movements', ['kind' => 'out', 'quantity' => 125, 'balance_after' => 10000, 'user_id' => $f['owner']->id, 'unit' => 'kg']);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame('10,000', Inventory::format(10000));
        $this->assertSame('0,001', Inventory::format(1));
        $this->get('/inventory?store_id='.$f['store'])->assertOk()->assertSee('10,000 kg')->assertSee('Conferencia de estoque')->assertSee($f['owner']->name)->assertHeader('Cache-Control', 'no-store, private')->assertViewHas('lowCount', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.moved', 'tenant_id' => $f['tenant']]);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_retries_do_not_duplicate_and_cannot_reuse_key_with_changed_payload(): void
    {
        $f = $this->fixture();
        $path = '/inventory/items/'.$f['item']->id.'/movements';
        $data = $this->movement();
        $this->post($path, $data)->assertSessionHasNoErrors();
        $this->post($path, $data)->assertSessionHasNoErrors();
        $this->post($path, array_replace($data, ['quantity' => '11']))->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item']->id, 'balance' => 10125]);
        $this->post($path, $this->movement('10,126', 'out'))->assertSessionHasErrors('quantity');
        $this->post($path, $this->movement('10,125', 'out'))->assertSessionHasNoErrors();
        $this->post($path, $this->movement('0,001', 'out'))->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item']->id, 'balance' => 0]);
    }

    public function test_quantities_reject_invalid_precision_negative_values_and_overflow(): void
    {
        $f = $this->fixture();
        $path = '/inventory/items/'.$f['item']->id.'/movements';
        foreach (['-1', '1e3', '1.000,000', '0.0001', '1000000', 'abc'] as $quantity) {
            $this->post($path, $this->movement($quantity))->assertSessionHasErrors('quantity');
        }
        $this->post($path, $this->movement('0'))->assertStatus(422);
        $this->post($path, $this->movement('999999,999'))->assertSessionHasNoErrors();
        $this->post($path, $this->movement('0,001'))->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_editing_preserves_unit_balance_and_movement_snapshot(): void
    {
        $f = $this->fixture();
        $path = '/inventory/items/'.$f['item']->id;
        $movement = $this->movement();
        $this->post($path.'/movements', $movement)->assertSessionHasNoErrors();
        $data = ['store_id' => $f['store'], 'name' => 'Farinha especial', 'minimum' => '20', 'active' => 0];
        $this->post($path, $data + ['unit' => 'g'])->assertSessionHasErrors('unit');
        $this->post($path, $data)->assertSessionHasNoErrors();
        $this->post($path.'/movements', $this->movement())->assertConflict();
        $this->post($path.'/movements', $movement)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_movements', ['item_name' => 'Farinha', 'unit' => 'kg']);
        $this->assertDatabaseHas('inventory_items', ['name' => 'Farinha especial', 'unit' => 'kg', 'balance' => 10125, 'active' => false]);
        $this->post($path, array_replace($data, ['active' => 1]))->assertSessionHasNoErrors();
        $this->get('/inventory')->assertOk()->assertViewHas('lowCount', 1);
    }

    public function test_names_and_history_are_scoped_to_store_and_foreign_tenants_are_blocked(): void
    {
        $f = $this->fixture();
        $data = ['store_id' => $f['store'], 'name' => '  FARINHA  ', 'unit' => 'kg', 'minimum' => '0', 'active' => 1];
        $this->post('/inventory/items', $data)->assertSessionHasErrors('name');
        $second = $this->store($f['tenant'], 'segunda');
        $this->post('/inventory/items', array_replace($data, ['store_id' => $second]))->assertSessionHasNoErrors();
        $this->post('/inventory/items/'.$f['item']->id.'/movements', $this->movement())->assertSessionHasNoErrors();
        $this->get('/inventory?store_id='.$second)->assertOk()->assertDontSee('Conferencia de estoque');
        $this->get('/inventory?store_id='.$second.'&item='.$f['item']->id)->assertNotFound();
        $otherTenant = $this->tenant('foreign');
        $this->actingAs($this->owner($otherTenant));
        $this->get('/inventory?store_id='.$f['store'])->assertNotFound();
        $this->post('/inventory/items', $data)->assertNotFound();
        $this->post('/inventory/items/'.$f['item']->id.'/movements', $this->movement())->assertNotFound();
    }

    public function test_only_current_active_owner_can_access_inventory(): void
    {
        $f = $this->fixture();
        foreach (['admin', 'courier', 'operator', 'waiter', 'kitchen'] as $role) {
            $user = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => in_array($role, ['admin', 'courier']) ? $role : 'staff', 'staff_profile' => $role]);
            $this->actingAs($user)->get('/inventory')->assertForbidden();
            $this->post('/inventory/items/'.$f['item']->id.'/movements', $this->movement())->assertForbidden();
        }
        $this->actingAs($f['owner']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->get('/inventory')->assertForbidden();
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => true]);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->post('/inventory/items/'.$f['item']->id.'/movements', $this->movement())->assertForbidden();
        auth()->logout();
        $this->get('/inventory')->assertRedirect('/login');
    }

    public function test_failed_audit_rolls_back_balance_and_movement(): void
    {
        $f = $this->fixture();
        DB::statement("CREATE TRIGGER fail_inventory_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'inventory.moved' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post('/inventory/items/'.$f['item']->id.'/movements', $this->movement())->assertStatus(500);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item']->id, 'balance' => 0]);
    }
}
