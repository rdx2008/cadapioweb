<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ProductRecipesTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $owner = $this->owner($tenant);
        $store = $this->store($tenant);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Prato da casa', 'category' => 'Pratos', 'price_cents' => 3000]);
        $this->actingAs($owner)->post('/inventory/items', ['store_id' => $store, 'name' => 'Farinha', 'unit' => 'kg', 'minimum' => '0', 'active' => 1])->assertSessionHasNoErrors();
        $item = DB::table('inventory_items')->firstOrFail();

        return compact('tenant', 'owner', 'store', 'product', 'item');
    }

    private function payload(array $f, string $target = 'base'): array
    {
        $page = $this->get('/products/'.$f['product'].'/recipe?target='.urlencode($target))->assertOk();

        return ['target' => $target, 'revision' => $page->viewData('current')?->revision ?? 0, 'context_hash' => $page->viewData('contextHash'), 'request_key' => (string) Str::uuid(),
            'confirmed' => 1, 'reason' => 'Composicao conferida', 'lines' => [['item_id' => $f['item']->id, 'quantity' => '0,125']]];
    }

    public function test_recipe_is_exact_versioned_and_does_not_move_stock_or_change_price(): void
    {
        $f = $this->fixture();
        $path = '/products/'.$f['product'].'/recipe';
        $this->post($path, $this->payload($f))->assertRedirect()->assertSessionHasNoErrors();
        $recipe = DB::table('product_recipes')->firstOrFail();
        $line = json_decode($recipe->lines, true)[0];
        $this->assertSame(125, $line['quantity']);
        $this->assertSame('kg', $line['unit']);
        $this->assertSame('Farinha', $line['name']);
        $this->assertDatabaseCount('product_recipe_revisions', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('inventory_items', ['id' => $f['item']->id, 'balance' => 0]);
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'price_cents' => 3000]);
        $this->get($path)->assertOk()->assertSee('Farinha')->assertSee('0,125 kg');
        DB::table('inventory_items')->where('id', $f['item']->id)->update(['name' => 'Farinha nova']);
        $this->post($path, $this->payload($f))->assertSessionHasNoErrors();
        $versions = DB::table('product_recipe_revisions')->orderBy('id')->get();
        $this->assertSame('Farinha', json_decode($versions[0]->lines, true)[0]['name']);
        $this->assertSame('Farinha nova', json_decode($versions[1]->lines, true)[0]['name']);
        $clear = $this->payload($f);
        $clear['lines'] = [];
        $this->post($path, $clear)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_recipes', ['id' => $recipe->id, 'revision' => 3, 'lines' => '[]']);
        $this->assertDatabaseCount('product_recipe_revisions', 3);
    }

    public function test_retries_are_idempotent_and_old_forms_cannot_overwrite_recipe(): void
    {
        $f = $this->fixture();
        $path = '/products/'.$f['product'].'/recipe';
        $data = $this->payload($f);
        $this->post($path, $data)->assertSessionHasNoErrors();
        $this->post($path, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_recipe_revisions', 1);
        $this->post($path, array_replace($data, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $data['reason'] = 'Outro motivo';
        $this->post($path, $data)->assertConflict();
        $this->assertDatabaseCount('product_recipe_revisions', 1);
    }

    public function test_recipe_rejects_inactive_foreign_and_duplicate_ingredients(): void
    {
        $f = $this->fixture();
        $data = $this->payload($f);
        $path = '/products/'.$f['product'].'/recipe';
        $data['lines'][] = $data['lines'][0];
        $this->post($path, $data)->assertSessionHasErrors('lines.0.item_id');
        array_pop($data['lines']);
        DB::table('inventory_items')->where('id', $f['item']->id)->update(['active' => false]);
        $this->post($path, $data)->assertSessionHasErrors('lines');
        $otherStore = $this->store($f['tenant'], 'segunda');
        DB::table('inventory_items')->where('id', $f['item']->id)->update(['active' => true, 'store_id' => $otherStore]);
        $this->post($path, $data)->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('product_recipes', 0);
    }

    public function test_only_owner_of_active_tenant_can_read_or_save_recipes(): void
    {
        $f = $this->fixture();
        $data = $this->payload($f);
        $path = '/products/'.$f['product'].'/recipe';
        foreach (['operator', 'kitchen', 'waiter'] as $profile) {
            $this->actingAs(User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => $profile]));
            $this->get($path)->assertForbidden();
            $this->post($path, $data)->assertForbidden();
        }
        $this->actingAs($this->owner($this->tenant('other')));
        $this->get($path)->assertNotFound();
        $this->post($path, $data)->assertNotFound();
        $this->actingAs($f['owner']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->post($path, $data)->assertForbidden();
        $this->assertDatabaseCount('product_recipes', 0);
    }

    public function test_variant_option_and_pizza_flavor_targets_are_separate_and_owned(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['product_type' => 'pizza']);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $f['product'], 'name' => 'Grande', 'price_cents' => 5000]);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $f['product'], 'name' => 'Extras']);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Queijo']);
        $flavor = DB::table('pizza_flavors')->insertGetId(['product_id' => $f['product'], 'name' => 'Margherita', 'prices' => '{}']);
        $path = '/products/'.$f['product'].'/recipe';
        foreach (['base', 'variant:'.$variant, 'option:'.$option, 'flavor:'.$flavor.':'.$variant] as $target) {
            $this->post($path, $this->payload($f, $target))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('product_recipes', 4);
        $data = $this->payload($f);
        $data['target'] = 'variant:999999';
        $this->post($path, $data)->assertStatus(422);
        $this->get($path.'?target=variant:999999')->assertNotFound();
        $data = $this->payload($f);
        DB::table('products')->where('id', $f['product'])->update(['product_type' => 'combo']);
        $this->post($path, $data)->assertConflict();
        $this->get($path.'?target=variant:'.$variant)->assertOk()->assertSee('Seu histórico permanece abaixo')->assertDontSee('data-recipe-form', false);
    }

    public function test_invalid_quantities_and_failed_audit_cannot_leave_partial_versions(): void
    {
        $f = $this->fixture();
        $path = '/products/'.$f['product'].'/recipe';
        $data = $this->payload($f);
        $data['lines'][0]['quantity'] = '0,0001';
        $this->post($path, $data)->assertSessionHasErrors('lines.0.quantity');
        $data['lines'][0]['quantity'] = '0';
        $this->post($path, $data)->assertSessionHasErrors('lines.0.quantity');
        $data['lines'][0]['quantity'] = '1,250';
        DB::statement("CREATE TRIGGER fail_recipe_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'inventory.recipe_saved' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post($path, $data)->assertStatus(500);
        $this->assertDatabaseCount('product_recipes', 0);
        $this->assertDatabaseCount('product_recipe_revisions', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
