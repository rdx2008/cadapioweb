<?php

namespace Tests\Feature;

use App\Jobs\ImportCatalogPhoto;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ImportPhotoTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_compatible_image_is_validated_converted_and_does_not_replace_a_manually_uploaded_photo(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $id = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Pizza', 'category' => 'Pizza', 'price_cents' => 4000]);
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Http::fake(['storage.googleapis.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);
        (new ImportCatalogPhoto($tenant, $id, 'https://storage.googleapis.com/menu/photo.png'))->handle();
        $path = DB::table('products')->where('id', $id)->value('photo_path');
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('local')->assertExists($path);
        (new ImportCatalogPhoto($tenant, $id, 'https://storage.googleapis.com/menu/other.png'))->handle();
        Http::assertSentCount(1);
        $this->assertSame($path, DB::table('products')->where('id', $id)->value('photo_path'));
    }

    public function test_private_urls_foreign_products_and_non_images_are_rejected(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $id = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Pizza', 'category' => 'Pizza', 'price_cents' => 4000]);
        (new ImportCatalogPhoto($tenant, $id, 'http://127.0.0.1/admin'))->handle();
        (new ImportCatalogPhoto($this->tenant('foreign'), $id, 'https://storage.googleapis.com/menu/photo.png'))->handle();
        Http::assertNothingSent();
        Http::fake(['storage.googleapis.com/*' => Http::response('<html>not an image</html>')]);
        (new ImportCatalogPhoto($tenant, $id, 'https://storage.googleapis.com/menu/photo.png'))->handle();
        $this->assertNull(DB::table('products')->where('id', $id)->value('photo_path'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_storage_write_failure_does_not_mark_a_product_as_having_a_photo(): void
    {
        Http::preventStrayRequests();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $id = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Pizza', 'category' => 'Pizza', 'price_cents' => 4000]);
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Http::fake(['storage.googleapis.com/*' => Http::response($bytes)]);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        (new ImportCatalogPhoto($tenant, $id, 'https://storage.googleapis.com/menu/photo.png'))->handle();
        $this->assertNull(DB::table('products')->where('id', $id)->value('photo_path'));
        $this->assertStringContainsString('suporte', json_decode(DB::table('audit_events')->where('action', 'catalog.import.photo_failed')->value('details'), true)['reason']);
    }

    public function test_real_source_image_when_live_check_is_explicitly_enabled(): void
    {
        if (getenv('PHOTO_DOWNLOAD_LIVE') !== '1') {
            $this->markTestSkipped('Consulta externa opcional.');
        }
        Storage::fake('local');
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $id = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Pizza', 'category' => 'Pizza', 'price_cents' => 4000]);
        (new ImportCatalogPhoto($tenant, $id, 'https://storage.googleapis.com/prod-cardapio-web/uploads/item/image/5258150/ac81b235combooutubro.png'))->handle();
        $path = DB::table('products')->where('id', $id)->value('photo_path');
        $this->assertNotNull($path, DB::table('audit_events')->where('action', 'catalog.import.photo_failed')->value('details') ?? 'Download falhou');
        Storage::disk('local')->assertExists($path);
        $this->assertGreaterThan(0, strlen(Storage::disk('local')->get($path)));
    }
}
