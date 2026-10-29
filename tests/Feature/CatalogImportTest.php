<?php

namespace Tests\Feature;

use App\Jobs\ImportCatalogPhoto;
use App\Models\User;
use App\Services\CatalogImportPlan;
use App\Services\CatalogImportWriter;
use App\Services\CatalogWorkbook;
use App\Services\ComboCatalog;
use App\Services\PizzaPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function upload(int $store): object
    {
        $file = new UploadedFile(base_path('tests/Fixtures/granovivo-menu.xlsx'), 'menu.xlsx', test: true);
        $this->post('/catalog/import', ['store_id' => $store, 'file' => $file])->assertRedirect();

        return DB::table('catalog_imports')->latest('id')->firstOrFail();
    }

    public function test_real_workbook_recognizes_all_products_options_and_pizza_prices(): void
    {
        $sheets = app(CatalogWorkbook::class)->read(base_path('tests/Fixtures/granovivo-menu.xlsx'), 'xlsx');
        $planner = app(CatalogImportPlan::class);
        $plan = $planner->build($sheets, $planner->detect($sheets));
        $this->assertSame([], $plan['errors']);
        $this->assertCount(54, $plan['products']);
        $this->assertSame(114, $plan['option_count']);
        $this->assertCount(13, collect($plan['products'])->pluck('category')->unique());
        $pizza = collect($plan['products'])->firstWhere('name', '35 cm');
        $this->assertSame('pizza', $pizza['type']);
        $this->assertCount(25, $pizza['flavors']);
        $this->assertSame(2, $pizza['pizza_max']);
        $combo = collect($plan['products'])->firstWhere('name', 'Combo Tradicional');
        $this->assertSame(15400, $combo['price_cents']);
        $this->assertCount(2, $combo['combo_groups']);
        $this->assertCount(2, $combo['groups']);
    }

    public function test_confirmation_is_idempotent_creates_paused_products_and_preserves_combo_and_pizza_quotes(): void
    {
        Queue::fake();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $this->get('/catalog/import')->assertOk();
        $draft = $this->upload($store);
        $this->assertDatabaseCount('products', 0);
        $this->get('/catalog/import/'.$draft->reference)->assertOk()->assertSee('54')->assertSee('114');
        $data = ['revision' => 1, 'duplicates' => 'skip', 'photos' => 0, 'acknowledged' => 1];
        $this->post('/catalog/import/'.$draft->reference.'/confirm', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 54);
        $this->assertSame(0, DB::table('products')->where('available', true)->count());
        $this->post('/catalog/import/'.$draft->reference.'/confirm', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 54);
        $combo = DB::table('products')->where('name', 'Combo Tradicional')->firstOrFail();
        $catalog = app(ComboCatalog::class)->data($combo);
        $this->assertTrue($catalog['ready']);
        $chosen = [];
        foreach ($catalog['groups'] as $group) {
            $chosen[$group->id] = $catalog['options']->get($group->id)->take($group->minimum)->pluck('id')->all();
        }
        $this->assertSame(0, collect(app(ComboCatalog::class)->quote($combo, $chosen))->sum('price_cents'));
        $fixed = DB::table('products')->where('name', 'Combo Família')->firstOrFail();
        $this->assertTrue((bool) $fixed->combo_fixed);
        $this->assertTrue(app(ComboCatalog::class)->data($fixed)['ready']);
        $pizza = DB::table('products')->where('name', '35 cm')->firstOrFail();
        $variant = DB::table('product_variants')->where('product_id', $pizza->id)->firstOrFail();
        $flavors = DB::table('pizza_flavors')->where('product_id', $pizza->id)->limit(2)->get();
        $quote = app(PizzaPricing::class)->quote($pizza, $variant, $flavors->pluck('id')->all());
        $this->assertSame($flavors->map(fn ($f) => app(PizzaPricing::class)->price($f, $variant->id))->max(), $quote['price_cents']);
        $this->get('/products/'.$combo->id.'/edit')->assertOk();
        $this->get('/catalog/import/'.$draft->reference)->assertOk()->assertSee('54');
        $this->assertNull(DB::table('catalog_imports')->where('id', $draft->id)->value('payload'));
    }

    public function test_owner_scope_expiry_revision_and_duplicate_policy_are_enforced(): void
    {
        Queue::fake();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        $draft = $this->upload($store);
        $this->post('/catalog/import/'.$draft->reference.'/confirm', ['revision' => 9, 'duplicates' => 'skip', 'acknowledged' => 1])->assertSessionHasErrors('revision');
        $foreignOwner = $this->owner($this->tenant('foreign'));
        $this->actingAs($foreignOwner)->get('/catalog/import/'.$draft->reference)->assertNotFound();
        $this->post('/catalog/import/'.$draft->reference.'/confirm', ['revision' => 1, 'duplicates' => 'skip', 'acknowledged' => 1])->assertNotFound();
        $this->post('/catalog/import', ['store_id' => $store, 'file' => new UploadedFile(base_path('tests/Fixtures/granovivo-menu.xlsx'), 'menu.xlsx', test: true)])->assertNotFound();
        $this->actingAs($owner);
        $this->travel(25)->hours();
        $this->get('/catalog/import/'.$draft->reference)->assertGone();
        $fresh = $this->upload($store);
        $writer = app(CatalogImportWriter::class);
        $writer->confirm($tenant, $fresh->reference, 1, 'skip', false);
        $next = $this->upload($store);
        $this->post('/catalog/import/'.$next->reference.'/confirm', ['revision' => 1, 'duplicates' => 'reject', 'acknowledged' => 1])->assertSessionHasErrors('duplicates');
        $result = $writer->confirm($tenant, $next->reference, 1, 'skip', false);
        $this->assertSame(0, $result['created']);
        $this->assertSame(54, $result['skipped']);
    }

    public function test_csv_with_reordered_aliases_and_excel_xls_are_supported_and_formulas_not_evaluated(): void
    {
        $planner = app(CatalogImportPlan::class);
        $reader = app(CatalogWorkbook::class);
        $file = UploadedFile::fake()->createWithContent('menu.csv', "Categoria;Valor;Nome\nBebidas;4,50;Agua\n");
        $sheets = $reader->read($file->getRealPath(), 'csv');
        $plan = $planner->build($sheets, $planner->detect($sheets));
        $this->assertSame([], $plan['errors']);
        $this->assertSame(450, $plan['products'][0]['price_cents']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Product', 'Price'], ['Juice', 8.5]]);
        $path = tempnam(sys_get_temp_dir(), 'import-xls');
        try {
            (new Xls($book))->save($path);
            $sheets = $reader->read($path, 'xls');
            $this->assertSame(850, $planner->build($sheets, $planner->detect($sheets))['products'][0]['price_cents']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
        $rows = [['name' => 'Products', 'rows' => [['Nome', 'Preço'], ['Unsafe', '=1+2']]]];
        $this->assertNotEmpty($planner->build($rows, $planner->detect($rows))['errors']);
    }

    public function test_mapping_can_be_corrected_and_previous_confirmation_is_rejected(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $file = UploadedFile::fake()->createWithContent('custom.csv', "Rotulo;Quantia;Setor\nAgua;4,50;Bebidas\n");
        $this->post('/catalog/import', ['store_id' => $store, 'file' => $file])->assertRedirect();
        $draft = DB::table('catalog_imports')->firstOrFail();
        $this->get('/catalog/import/'.$draft->reference)->assertOk()->assertSee('Nenhum produto encontrado');
        $this->post('/catalog/import/'.$draft->reference.'/mapping', ['revision' => 1, 'mapping' => [['role' => 'products', 'header' => 1, 'columns' => ['name' => 0, 'price' => 1, 'category' => 2]]]])->assertSessionHasNoErrors();
        $this->post('/catalog/import/'.$draft->reference.'/confirm', ['revision' => 1, 'duplicates' => 'skip', 'acknowledged' => 1])->assertSessionHasErrors('revision');
        $this->post('/catalog/import/'.$draft->reference.'/confirm', ['revision' => 2, 'duplicates' => 'skip', 'acknowledged' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['name' => 'Agua', 'price_cents' => 450]);
    }

    public function test_variants_in_columns_and_multiple_rows_and_ambiguous_option_links_are_handled(): void
    {
        $planner = app(CatalogImportPlan::class);
        $sheets = [['name' => 'menu', 'rows' => [['Nome', 'P', 'M', 'G'], ['Suco', 4, 6, 8]]]];
        $plan = $planner->build($sheets, $planner->detect($sheets));
        $this->assertSame([], $plan['errors']);
        $this->assertCount(3, $plan['products'][0]['variants']);
        $sheets = [['name' => 'menu', 'rows' => [['Nome', 'Tamanho', 'Valor'], ['Suco', 'P', 4], ['Suco', 'G', 8]]]];
        $plan = $planner->build($sheets, $planner->detect($sheets));
        $this->assertCount(1, $plan['products']);
        $this->assertCount(2, $plan['products'][0]['variants']);
        $sheets = [['name' => 'menu', 'rows' => [['Nome', 'Categoria', 'Valor'], ['Suco', 'A', 4], ['Suco', 'B', 8]]], ['name' => 'Extras', 'rows' => [['Produto', 'Opção', 'Grupo', 'Regra de seleção', 'Valor'], ['Suco', 'Gelo', 'Extras', 'Escolha 1 opção', 0]]]];
        $plan = $planner->build($sheets, $planner->detect($sheets));
        $this->assertNotEmpty($plan['errors']);
    }

    public function test_import_requires_owner_confirmation_and_rejects_oversized_or_mislabeled_files(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs(User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant]))->get('/catalog/import')->assertForbidden();
        $this->actingAs($this->owner($tenant));
        $this->post('/catalog/import', ['store_id' => $store, 'file' => UploadedFile::fake()->create('big.xlsx', 6000)])->assertSessionHasErrors('file');
        $this->post('/catalog/import', ['store_id' => $store, 'file' => UploadedFile::fake()->createWithContent('bad.xlsx', 'not an Excel workbook')])->assertSessionHasErrors('file');
        $draft = $this->upload($store);
        $this->post('/catalog/import/'.$draft->reference.'/confirm', ['revision' => 1, 'duplicates' => 'skip'])->assertSessionHasErrors('acknowledged');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_photo_jobs_are_queued_only_for_new_products_after_confirmation(): void
    {
        Queue::fake();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $draft = $this->upload($store);
        Queue::assertNothingPushed();
        $writer = app(CatalogImportWriter::class);
        $result = $writer->confirm($tenant, $draft->reference, 1, 'skip', true);
        $this->assertGreaterThan(0, $result['photos_queued']);
        Queue::assertPushed(ImportCatalogPhoto::class, $result['photos_queued']);
        $writer->confirm($tenant, $draft->reference, 1, 'skip', true);
        Queue::assertPushed(ImportCatalogPhoto::class, $result['photos_queued']);
    }
}
