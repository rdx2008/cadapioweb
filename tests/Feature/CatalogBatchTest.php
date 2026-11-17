<?php

namespace Tests\Feature;

use App\Http\Controllers\CatalogBatchController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CatalogBatchTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_batch_requires_review_and_publishes_atomically(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $category = DB::table('catalog_categories')->insertGetId(['store_id' => $store, 'name' => 'Promoções']);
        $rows = [];
        foreach (['Lanche', 'Suco'] as $name) {
            $id = DB::table('products')->insertGetId([
                'tenant_id' => $tenant, 'store_id' => $store, 'name' => $name,
                'category' => 'Cardápio', 'price_cents' => 1500, 'available' => true,
            ]);
            $rows[] = ['id' => $id, 'version' => CatalogBatchController::version(DB::table('products')->find($id)), 'price' => '19,90', 'available' => '0', 'category_id' => $category];
        }
        $path = '/catalog/batch/'.$store;
        $this->get($path)->assertOk()->assertSee('Lanche');
        $this->post($path.'/review', ['rows' => $rows])->assertRedirect($path)->assertSessionHasNoErrors();
        $draft = session('catalog_batch.'.$store);
        $this->get($path)->assertOk()->assertSee('19,90');
        $this->assertSame(2, DB::table('products')->where('price_cents', 1500)->count());
        $publish = ['key' => $draft['key'], 'confirmed' => 1];
        $this->postJson($path.'/publish', ['key' => $draft['key']])->assertUnprocessable();

        DB::table('products')->where('id', $rows[1]['id'])->update(['price_cents' => 1600]);
        $this->post($path.'/publish', $publish)->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $rows[0]['id'], 'price_cents' => 1500, 'available' => true]);
        $rows[1]['version'] = CatalogBatchController::version(DB::table('products')->find($rows[1]['id']));
        $this->post($path.'/review', ['rows' => $rows])->assertRedirect();
        $publish['key'] = session('catalog_batch.'.$store.'.key');
        $this->post($path.'/publish', $publish)->assertRedirect($path);
        $this->assertSame(2, DB::table('products')->where('price_cents', 1990)->where('available', false)->count());
        $this->assertSame(2, DB::table('products')->where('category_id', $category)->where('category', 'Promoções')->count());
        $this->post($path.'/publish', $publish)->assertConflict();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.batch_published')->count());
        $this->actingAs($this->owner($this->tenant('foreign')))->get($path)->assertNotFound();
    }

    public function test_noop_and_variant_price_are_rejected_and_draft_can_be_discarded(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $id = DB::table('products')->insertGetId([
            'tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Pizza',
            'category' => 'Cardápio', 'price_cents' => 1500, 'uses_variants' => true, 'available' => true,
        ]);
        $row = ['id' => $id, 'version' => CatalogBatchController::version(DB::table('products')->find($id))];
        $path = '/catalog/batch/'.$store;
        $this->postJson($path.'/review', ['rows' => [$row]])->assertUnprocessable();
        $this->postJson($path.'/review', ['rows' => [$row + ['price' => '20,00']]])->assertUnprocessable();
        $this->post($path.'/review', ['rows' => [$row + ['available' => '0']]])->assertRedirect();
        $this->post($path.'/discard')->assertRedirect($path);
        $this->assertNull(session('catalog_batch.'.$store));
        $this->assertDatabaseHas('products', ['id' => $id, 'available' => true, 'price_cents' => 1500]);
    }
}
