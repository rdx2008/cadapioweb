<?php

namespace App\Jobs;

use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportCatalogPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public int $tenantId, public int $productId, public string $url) {}

    public function handle(): void
    {
        $product = DB::table('products')->where('tenant_id', $this->tenantId)->where('id', $this->productId)->whereNull('photo_path')->first();
        if (! $product || ! preg_match('~^https://storage\.googleapis\.com/[^\s?#]+$~D', $this->url)) {
            return;
        }
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions(['progress' => function ($total, $downloaded): void {
                if ($total > 2097152 || $downloaded > 2097152) {
                    throw new \RuntimeException('Image too large');
                }
            }])->get($this->url);
            $bytes = $response->body();
            if (! $response->successful() || strlen($bytes) > 2097152) {
                return;
            }
            $size = @getimagesizefromstring($bytes);
            if (! $size || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $size[0] * $size[1] > 16000000) {
                return;
            }
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                return;
            }
            ob_start();
            imagewebp($image, null, 85);
            $clean = ob_get_clean();
            imagedestroy($image);
            if (! is_string($clean) || $clean === '' || strlen($clean) > 2097152) {
                return;
            }
            $path = 'tenants/'.$this->tenantId.'/products/'.Str::uuid().'.webp';
            Storage::disk('local')->put($path, $clean);
            $changed = DB::table('products')->where('tenant_id', $this->tenantId)->where('id', $this->productId)->whereNull('photo_path')->update(['photo_path' => $path, 'updated_at' => now()]);
            if (! $changed) {
                Storage::disk('local')->delete($path);
            }
        } catch (\Throwable) {
            Audit::record('catalog.import.photo_failed', ['product_id' => $this->productId], $this->tenantId);
        }
    }
}
