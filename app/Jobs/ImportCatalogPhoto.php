<?php

namespace App\Jobs;

use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportCatalogPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public int $tenantId, public int $productId, public string $url, public ?int $importId = null) {}

    private function record(string $state, string $message): void
    {
        if ($this->importId ?? null) {
            DB::transaction(function () use ($state, $message): void {
                $import = DB::table('catalog_imports')->where('id', $this->importId)->where('tenant_id', $this->tenantId)->lockForUpdate()->first();
                if (! $import) {
                    return;
                }
                $result = json_decode($import->result ?? '{}', true);
                if (! isset($result['photos'][$this->productId])) {
                    return;
                }
                if ($state === 'kept' && $result['photos'][$this->productId]['state'] === 'downloaded') {
                    return;
                }
                $result['photos'][$this->productId]['state'] = $state;
                $result['photos'][$this->productId]['message'] = $message;
                $result['photos'][$this->productId]['updated_at'] = now()->toIso8601String();
                DB::table('catalog_imports')->where('id', $import->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            });
        }
        if ($state === 'failed') {
            Audit::record('catalog.import.photo_failed', ['product_id' => $this->productId, 'reason' => $message], $this->tenantId);
        }
    }

    public function handle(): void
    {
        if (! preg_match('~^https://storage\.googleapis\.com/[^\s?#]+$~D', $this->url)) {
            $this->record('failed', 'Link de imagem não compatível.');

            return;
        }
        $lock = Cache::lock('catalog-photo:'.$this->productId, 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $product = DB::table('products')->where('tenant_id', $this->tenantId)->where('id', $this->productId)->first();
            if (! $product) {
                $this->record('failed', 'Produto não encontrado.');

                return;
            }
            if ($product->photo_path && Storage::disk('local')->exists($product->photo_path)) {
                $this->record('kept', 'O produto já possui foto; imagem preservada.');

                return;
            }
            if ($product->photo_path) {
                DB::table('products')->where('id', $product->id)->where('photo_path', $product->photo_path)->update(['photo_path' => null]);
            }
            $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions(['progress' => function ($total, $downloaded): void {
                if ($total > 2097152 || $downloaded > 2097152) {
                    throw new \RuntimeException('Image too large');
                }
            }])->get($this->url);
            if (! $response->successful()) {
                $this->record('failed', 'A origem retornou HTTP '.$response->status().'.');

                return;
            }
            $bytes = $response->body();
            if (strlen($bytes) > 2097152) {
                $this->record('failed', 'A imagem ultrapassa 2 MB.');

                return;
            }
            $size = @getimagesizefromstring($bytes);
            if (! $size || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $size[0] * $size[1] > 16000000) {
                $this->record('failed', 'Imagem inválida ou com dimensões acima do limite.');

                return;
            }
            if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
                $this->record('failed', 'O processamento de imagens está indisponível. Entre em contato com o suporte.');

                return;
            }
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                $this->record('failed', 'Não foi possível abrir a imagem.');

                return;
            }
            ob_start();
            imagewebp($image, null, 85);
            $clean = ob_get_clean();
            imagedestroy($image);
            if (! is_string($clean) || $clean === '' || strlen($clean) > 2097152) {
                $this->record('failed', 'Não foi possível converter a imagem dentro do limite.');

                return;
            }
            $path = 'tenants/'.$this->tenantId.'/products/'.Str::uuid().'.webp';
            if (! Storage::disk('local')->put($path, $clean) || ! Storage::disk('local')->exists($path)) {
                $this->record('failed', 'Não foi possível salvar a imagem. Tente novamente; se persistir, contate o suporte.');

                return;
            }
            $changed = DB::table('products')->where('tenant_id', $this->tenantId)->where('id', $this->productId)->whereNull('photo_path')->update(['photo_path' => $path, 'updated_at' => now()]);
            if (! $changed) {
                Storage::disk('local')->delete($path);
                $this->record('kept', 'Outra foto foi salva no produto durante a consulta.');

                return;
            }
            $this->record('downloaded', 'Foto baixada e salva.');
        } catch (\Throwable) {
            $this->record('failed', 'Falha ao consultar ou processar a imagem. Tente novamente; se persistir, contate o suporte.');
        } finally {
            $lock->release();
        }
    }
}
