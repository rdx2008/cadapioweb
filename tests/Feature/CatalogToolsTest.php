<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogExport;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CatalogToolsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        config(['app.url' => 'https://cardapio.example.test']);
        Http::preventStrayRequests();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);

        return compact('tenant', 'store', 'owner');
    }

    private function product(array $f, string $name, array $extra = []): int
    {
        return DB::table('products')->insertGetId(array_replace(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => $name, 'price_cents' => 3090, 'category' => 'Menu'], $extra));
    }

    public function test_workbook_keeps_catalog_graph_paused_items_and_typed_prices_without_external_data_or_formulas(): void
    {
        $f = $this->fixture();
        $category = DB::table('catalog_categories')->insertGetId(['store_id' => $f['store'], 'name' => 'Pizzas', 'sort_order' => 2]);
        $pizza = $this->product($f, '=HYPERLINK("unsafe")', ['category_id' => $category, 'category' => 'Pizzas', 'description' => 'Massa natural', 'product_type' => 'pizza', 'pizza_enabled' => true, 'uses_variants' => true, 'photo_path' => 'private/example.png', 'kitchen_station' => 'Forno']);
        $drink = $this->product($f, '00123', ['available' => false]);
        $combo = $this->product($f, 'Combo da casa', ['product_type' => 'combo', 'combo_fixed' => true]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $pizza, 'name' => 'Grande', 'price_cents' => 3090, 'max_flavors' => 2]);
        $small = DB::table('product_variants')->insertGetId(['product_id' => $pizza, 'name' => 'Pequena', 'price_cents' => 2590, 'available' => false]);
        $foreign = ['tenant' => $this->tenant('foreign')];
        $foreign['store'] = $this->store($foreign['tenant'], 'foreign');
        $secret = $this->product($foreign, 'SEGREDO OUTRA EMPRESA');
        $foreignVariant = DB::table('product_variants')->insertGetId(['product_id' => $secret, 'name' => 'VARIACAO SECRETA', 'price_cents' => 1]);
        DB::table('pizza_flavors')->insert(['product_id' => $pizza, 'name' => 'Calabresa', 'prices' => json_encode([$variant => 3550, $small => 2990, $foreignVariant => 99999]), 'available' => false]);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $pizza, 'name' => 'Borda', 'kind' => 'border', 'minimum' => 0, 'maximum' => 1]);
        DB::table('product_options')->insert(['option_group_id' => $group, 'name' => '+Catupiry', 'price_cents' => 250, 'available' => false]);
        $comboGroup = DB::table('combo_groups')->insertGetId(['product_id' => $combo, 'name' => 'Bebida', 'minimum' => 1, 'maximum' => 1]);
        DB::table('combo_options')->insert([
            ['combo_group_id' => $comboGroup, 'component_product_id' => $drink, 'quantity' => 2, 'surcharge_cents' => 150, 'label' => null],
            ['combo_group_id' => $comboGroup, 'component_product_id' => null, 'quantity' => 1, 'surcharge_cents' => 0, 'label' => 'Bebida livre'],
            ['combo_group_id' => $comboGroup, 'component_product_id' => $secret, 'quantity' => 1, 'surcharge_cents' => 0, 'label' => null],
        ]);
        $response = $this->get('/catalog/tools/'.$f['store'].'/export?q=missing&availability=active')->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = null;
        try {
            $this->assertGreaterThan(0, filesize($path));
            $this->assertSame((string) filesize($path), $response->headers->get('Content-Length'));
            $book = IOFactory::load($path);
            $this->assertCount(10, $book->getAllSheets());
            $products = $book->getSheetByName('Produtos');
            $this->assertSame('=HYPERLINK("unsafe")', $products->getCell('D2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $products->getCell('D2')->getDataType());
            $this->assertSame('00123', $products->getCell('D3')->getValue());
            $this->assertSame('Não', $products->getCell('H3')->getValue());
            $this->assertEqualsWithDelta(30.90, $products->getCell('G2')->getValue(), 0.00001);
            $this->assertSame(DataType::TYPE_NUMERIC, $products->getCell('G2')->getDataType());
            $this->assertSame('https://cardapio.example.test/media/products/'.$pizza, $products->getCell('P2')->getValue());
            $this->assertSame('A2', $products->getFreezePane());
            $this->assertSame('A1:P4', $products->getAutoFilter()->getRange());
            $this->assertSame('Não', $book->getSheetByName('Variacoes')->getCell('F3')->getValue());
            $this->assertSame('+Catupiry', $book->getSheetByName('Adicionais')->getCell('F2')->getValue());
            $this->assertEqualsWithDelta(35.50, $book->getSheetByName('Precos sabores')->getCell('E2')->getValue(), 0.00001);
            $this->assertSame(3, $book->getSheetByName('Precos sabores')->getHighestDataRow());
            $this->assertSame(2, $book->getSheetByName('Itens combos')->getCell('J2')->getValue());
            $this->assertSame('Bebida livre', $book->getSheetByName('Itens combos')->getCell('M3')->getValue());
            $this->assertSame('Componente fora da loja ou referência inválida', $book->getSheetByName('Itens combos')->getCell('N4')->getValue());
            $all = json_encode(array_map(fn ($sheet) => $sheet->toArray(null, false), $book->getAllSheets()));
            $this->assertStringNotContainsString('SEGREDO', $all);
            $this->assertStringNotContainsString('SECRETA', $all);
            $this->assertStringNotContainsString('private/example.png', $all);
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
        $this->assertDatabaseCount('products', 4);
        $this->assertDatabaseHas('products', ['id' => $drink, 'available' => false]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $f['tenant'], 'action' => 'catalog.exported']);
        Http::assertNothingSent();
    }

    public function test_shared_url_and_qr_use_configured_domain_and_only_public_menu_information(): void
    {
        $f = $this->fixture();
        $url = 'https://cardapio.example.test/cardapio/loja';
        $this->get('/catalog?store_id='.$f['store'])->assertOk()->assertSee('Compartilhar, QR Code e exportar Excel');
        $this->withHeader('Host', 'attacker.test')->get('/catalog/tools/'.$f['store'])->assertOk()->assertViewHas('url', $url)->assertSee($url)->assertSee('Copiar link');
        $this->get('/catalog/tools/'.$f['store'].'/print')->assertOk()->assertSee($url)->assertSee('Aponte a câmera');
        $svg = $this->get('/catalog/tools/'.$f['store'].'/qr')->assertOk()->assertDownload('cardapio-'.$f['store'].'-qrcode.svg')->assertHeader('Content-Type', 'image/svg+xml');
        $expected = (new SvgWriter)->write(new QrCode(data: $url, size: 800, margin: 80))->getString();
        $this->assertSame($expected, $svg->getContent());
        $this->assertStringNotContainsString('<script', $svg->getContent());
        $this->assertDatabaseCount('dining_tabs', 0);
    }

    public function test_tools_and_exports_require_active_owner_and_own_store(): void
    {
        $f = $this->fixture();
        $foreign = $this->store($this->tenant('foreign'), 'foreign');
        foreach (['', '/print', '/qr', '/export'] as $suffix) {
            $this->get('/catalog/tools/'.$foreign.$suffix)->assertNotFound();
        }
        $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $f['tenant']]);
        foreach (['', '/print', '/qr', '/export'] as $suffix) {
            $this->actingAs($staff)->get('/catalog/tools/'.$f['store'].$suffix)->assertForbidden();
        }
        $this->actingAs($f['owner']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/catalog/tools/'.$f['store'])->assertForbidden();
        $this->get('/catalog/tools/'.$f['store'].'/export')->assertForbidden();
    }

    public function test_export_of_empty_catalog_remains_a_valid_workbook(): void
    {
        $f = $this->fixture();
        $response = $this->get('/catalog/tools/'.$f['store'].'/export')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $this->assertCount(10, $book->getAllSheets());
            $this->assertSame('Produto', $book->getSheetByName('Produtos')->getCell('D1')->getValue());
            $this->assertNull($book->getSheetByName('Produtos')->getCell('D2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_export_is_bounded_and_invalid_public_address_is_not_shared(): void
    {
        $f = $this->fixture();
        $rows = array_fill(0, 100, ['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Produto', 'category' => 'Menu', 'price_cents' => 100]);
        for ($i = 0; $i < 20; $i++) {
            DB::table('products')->insert($rows);
        }
        $this->product($f, 'Produto excedente');
        $this->get('/catalog/tools/'.$f['store'].'/export')->assertStatus(422);
        config(['app.url' => 'javascript:alert(1)']);
        $this->get('/catalog/tools/'.$f['store'])->assertStatus(422);
        $this->assertDatabaseMissing('audit_events', ['action' => 'catalog.exported']);
    }

    public function test_access_is_rechecked_before_download_and_failed_export_cleans_temporary_file(): void
    {
        $f = $this->fixture();
        $path = null;
        $real = new CatalogExport;
        $this->partialMock(CatalogExport::class, function ($mock) use ($real, $f, &$path): void {
            $mock->shouldReceive('write')->once()->andReturnUsing(function (array $tables, string $target) use ($real, $f, &$path): void {
                $path = $target;
                $real->write($tables, $target);
                DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
            });
        });
        $this->get('/catalog/tools/'.$f['store'].'/export')->assertForbidden();
        $this->assertNotNull($path);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseMissing('audit_events', ['action' => 'catalog.exported']);

    }
}
