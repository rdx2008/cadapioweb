<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InventoryCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class InventoryCountTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        $items = [];
        foreach ([['Farinha', 'kg', '10', '100,00'], ['Queijo', 'kg', '5', '50,00'], ['Embalagens', 'un', '20', '20,00']] as [$name, $unit, $quantity, $value]) {
            $this->post('/inventory/items', ['store_id' => $store, 'name' => $name, 'unit' => $unit, 'minimum' => '0', 'active' => 1])->assertRedirect();
            $id = DB::table('inventory_items')->max('id');
            $this->move($id, 'in', $quantity, $value);
            $items[] = $id;
        }

        return compact('tenant', 'store', 'owner', 'items');
    }

    private function move(int $id, string $kind, string $quantity, ?string $value = null): void
    {
        $data = ['kind' => $kind, 'quantity' => $quantity, 'reason' => 'Movimento conferido', 'request_key' => (string) Str::uuid(), 'confirmed' => 1];
        if ($value !== null) {
            $data['entry_value'] = $value;
        }
        $this->post('/inventory/items/'.$id.'/movements', $data)->assertRedirect()->assertSessionHasNoErrors();
    }

    private function start(array $f, ?array $ids = null): int
    {
        $this->post('/inventory/counts', ['store_id' => $f['store'], 'reference' => 'Conferência semanal', 'reason' => 'Inventário físico', 'item_ids' => $ids ?? $f['items'], 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('inventory_counts')->max('id');
    }

    private function quantities(int $id, array $values): array
    {
        return DB::table('inventory_count_lines')->where('count_id', $id)->orderBy('item_id')->get()->mapWithKeys(fn ($line, $index) => [$line->id => $values[$index] ?? null])->all();
    }

    private function save(int $id, array $values): void
    {
        $this->post('/inventory/counts/'.$id, ['revision' => DB::table('inventory_counts')->find($id)->revision, 'quantities' => $this->quantities($id, $values)])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function confirmation(int $id): array
    {
        $response = $this->get('/inventory/counts/'.$id.'/review')->assertOk();

        return ['revision' => DB::table('inventory_counts')->find($id)->revision, 'review_hash' => $response->viewData('review')['reviewHash'], 'request_key' => (string) Str::uuid(), 'confirmed' => 1];
    }

    public function test_batch_count_creates_only_differences_once_and_keeps_costs_truthful(): void
    {
        $f = $this->fixture();
        $id = $this->start($f);
        $this->save($id, ['8,000', '6,250', '20']);
        $data = $this->confirmation($id);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->post('/inventory/counts/'.$id.'/apply', array_replace($data, ['review_hash' => str_repeat('a', 64)]))->assertConflict();
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertRedirect();
        $this->assertDatabaseCount('inventory_movements', 5);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 8000, 'stock_value_cents' => 8000]);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][1], 'balance' => 6250, 'stock_value_cents' => null]);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][2], 'balance' => 20000, 'stock_value_cents' => 2000]);
        $this->assertDatabaseHas('inventory_counts', ['id' => $id, 'status' => 'applied', 'applied_by' => $f['owner']->id]);
        $this->assertSame(2, DB::table('inventory_count_lines')->where('count_id', $id)->whereNotNull('movement_id')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'inventory.count_applied')->count());
        $this->get('/inventory/counts/'.$id)->assertOk()->assertSee('Ajustes aplicados')->assertSee('−2,000')->assertSee('Movimento #');
        $this->post('/inventory/counts/'.$id.'/apply', array_replace($data, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->post('/inventory/counts/'.$id.'/cancel', ['revision' => 3, 'reason' => 'Não pode', 'confirmed' => 1])->assertConflict();
        $this->get('/inventory/counts/'.$id.'/print')->assertConflict();
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_blank_is_not_zero_and_no_adjustment_occurs_until_every_item_is_counted(): void
    {
        $f = $this->fixture();
        $id = $this->start($f, array_slice($f['items'], 0, 2));
        $this->save($id, ['0', '']);
        $data = $this->confirmation($id);
        $this->get('/inventory/counts/'.$id.'/review')->assertOk()->assertSee('ainda não contados')->assertDontSee('Aplicar ajustes ao estoque');
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertStatus(422);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 10000]);
        $this->save($id, ['0', '5']);
        $this->post('/inventory/counts/'.$id.'/apply', $this->confirmation($id))->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 0, 'stock_value_cents' => 0]);
        $this->assertDatabaseCount('inventory_movements', 4);
    }

    public function test_net_zero_movements_invalidate_count_and_restart_requires_recount(): void
    {
        $f = $this->fixture();
        $id = $this->start($f, [$f['items'][0]]);
        $this->save($id, ['9']);
        $old = $this->confirmation($id);
        $this->move($f['items'][0], 'out', '1');
        $this->move($f['items'][0], 'in', '1', '10,00');
        $this->get('/inventory/counts/'.$id.'/review')->assertOk()->assertViewHas('review', fn ($review) => $review['stale']);
        $this->post('/inventory/counts/'.$id.'/apply', $old)->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 5);
        $this->post('/inventory/counts/'.$id.'/restart', ['revision' => 2, 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseHas('inventory_count_lines', ['count_id' => $id, 'counted' => null]);
        $this->post('/inventory/counts/'.$id, ['revision' => 2, 'quantities' => $this->quantities($id, ['9'])])->assertConflict();
        $this->save($id, ['9']);
        $this->post('/inventory/counts/'.$id.'/apply', $this->confirmation($id))->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 9000, 'stock_value_cents' => 9000]);
    }

    public function test_cost_change_without_quantity_movement_invalidates_review(): void
    {
        $f = $this->fixture();
        $id = $this->start($f, [$f['items'][0]]);
        $this->save($id, ['8']);
        $data = $this->confirmation($id);
        $item = DB::table('inventory_items')->find($f['items'][0]);
        $last = (int) DB::table('inventory_movements')->where('item_id', $item->id)->max('id');
        $this->post('/inventory/items/'.$item->id.'/value', ['value' => '120,00', 'reason' => 'Valor conferido', 'request_key' => (string) Str::uuid(), 'review_hash' => InventoryCosts::reviewHash($item, $last), 'confirmed' => 1])->assertRedirect();
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'balance' => 10000, 'stock_value_cents' => 12000]);
    }

    public function test_overlapping_counts_cannot_overwrite_adjustments_and_unrelated_items_do_not_block(): void
    {
        $f = $this->fixture();
        $first = $this->start($f, [$f['items'][0]]);
        $second = $this->start($f, [$f['items'][0]]);
        $this->save($first, ['8']);
        $this->save($second, ['7']);
        $secondReview = $this->confirmation($second);
        $this->move($f['items'][1], 'out', '1');
        $this->post('/inventory/counts/'.$first.'/apply', $this->confirmation($first))->assertRedirect();
        $this->post('/inventory/counts/'.$second.'/apply', $secondReview)->assertConflict();
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 8000]);
    }

    public function test_validation_is_atomic_cancellation_preserves_draft_and_print_is_blind(): void
    {
        $f = $this->fixture();
        $id = $this->start($f);
        $this->get('/inventory/counts/'.$id.'/print')->assertOk()->assertSee('Farinha')->assertSee('Quantidade física')->assertDontSee('10,000');
        $this->post('/inventory/counts/'.$id, ['revision' => 1, 'quantities' => $this->quantities($id, ['9', '-1', '20'])])->assertSessionHasErrors();
        $this->assertSame(0, DB::table('inventory_count_lines')->where('count_id', $id)->whereNotNull('counted')->count());
        $this->save($id, ['9', '4', '19']);
        $this->post('/inventory/counts/'.$id.'/cancel', ['revision' => 2, 'reason' => 'Conferência interrompida', 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertSame(3, DB::table('inventory_count_lines')->where('count_id', $id)->whereNotNull('counted')->count());
        $this->get('/inventory/counts/'.$id)->assertOk()->assertSee('Conferência interrompida');
        $this->post('/inventory/counts/'.$id, ['revision' => 3, 'quantities' => $this->quantities($id, ['1', '1', '1'])])->assertConflict();
    }

    public function test_inactivation_blocks_whole_apply_and_restart_rolls_back_every_line(): void
    {
        $f = $this->fixture();
        $id = $this->start($f);
        $this->save($id, ['9', '4', '19']);
        $review = $this->confirmation($id);
        DB::table('inventory_items')->where('id', $f['items'][1])->update(['active' => false]);
        $this->post('/inventory/counts/'.$id.'/apply', $review)->assertConflict();
        $this->post('/inventory/counts/'.$id.'/restart', ['revision' => 2, 'confirmed' => 1])->assertConflict();
        $this->assertSame(3, DB::table('inventory_count_lines')->where('count_id', $id)->whereNotNull('counted')->count());
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 10000]);
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_start_is_idempotent_requires_own_store_and_rejects_unselected_lines(): void
    {
        $f = $this->fixture();
        $data = ['store_id' => $f['store'], 'reference' => 'Contagem', 'reason' => 'Conferir', 'item_ids' => [$f['items'][0]], 'request_key' => (string) Str::uuid()];
        $this->get('/inventory/counts')->assertOk()->assertSee('Contagem física');
        $this->post('/inventory/counts', $data)->assertRedirect();
        $this->post('/inventory/counts', $data)->assertRedirect();
        $this->post('/inventory/counts', array_replace($data, ['item_ids' => [$f['items'][1]]]))->assertConflict();
        $this->assertDatabaseCount('inventory_counts', 1);
        $id = DB::table('inventory_counts')->value('id');
        $this->post('/inventory/counts/'.$id, ['revision' => 1, 'quantities' => [99999 => '1']])->assertStatus(422);
        $otherStore = $this->store($f['tenant'], 'second');
        $this->post('/inventory/counts', array_replace($data, ['store_id' => $otherStore, 'request_key' => (string) Str::uuid()]))->assertNotFound();
        $this->post('/inventory/counts/'.$id.'/apply', array_replace($this->confirmation($id), ['confirmed' => 0]))->assertSessionHasErrors('confirmed');
    }

    public function test_foreign_companies_staff_and_revoked_owners_cannot_read_or_apply_counts(): void
    {
        $f = $this->fixture();
        $id = $this->start($f);
        $this->save($id, ['8', '4', '19']);
        $data = $this->confirmation($id);
        $otherTenant = $this->tenant('other');
        $this->actingAs($this->owner($otherTenant));
        $this->get('/inventory/counts?store_id='.$f['store'])->assertNotFound();
        foreach (['', '/review', '/print'] as $suffix) {
            $this->get('/inventory/counts/'.$id.$suffix)->assertNotFound();
        }
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertNotFound();
        $staff = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff']);
        $this->actingAs($staff)->get('/inventory/counts/'.$id)->assertForbidden();
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->get('/inventory/counts/'.$id)->assertForbidden();
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertForbidden();
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => true]);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->post('/inventory/counts/'.$id.'/apply', $data)->assertForbidden();
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_save_and_review_keeps_entered_quantities_without_adjusting_stock(): void
    {
        $f = $this->fixture();
        $id = $this->start($f, [$f['items'][0]]);
        $this->post('/inventory/counts/'.$id, ['revision' => 1, 'quantities' => $this->quantities($id, ['8,125']), 'next' => 'review'])->assertRedirect('/inventory/counts/'.$id.'/review')->assertSessionHasNoErrors();
        $this->get('/inventory/counts/'.$id.'/review')->assertOk()->assertViewHas('review', fn ($review) => $review['complete'] && $review['rows'][0]['counted'] === 8125);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['items'][0], 'balance' => 10000]);
        $this->assertDatabaseCount('inventory_movements', 3);
    }
}
