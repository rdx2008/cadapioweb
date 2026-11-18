<?php

namespace App\Http\Controllers;

use App\Services\CatalogExport;
use App\Services\Inventory;
use App\Support\Audit;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CatalogToolsController extends Controller
{
    private function store(Request $request, int $id, Inventory $access, bool $lock = false): object
    {
        $user = $access->member($request->user(), $lock);

        return DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        abort_unless(filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) && ! parse_url($url, PHP_URL_QUERY) && ! parse_url($url, PHP_URL_FRAGMENT), 422, 'O endereço público da plataforma precisa ser configurado pela administração.');

        return $url;
    }

    private function menuUrl(object $store): string
    {
        return $this->baseUrl().'/cardapio/'.rawurlencode($store->slug);
    }

    public function index(Request $request, int $storeId, Inventory $access): Response
    {
        $store = $this->store($request, $storeId, $access);
        $url = $this->menuUrl($store);
        $qr = (new SvgWriter)->write(new QrCode(data: $url, size: 320, margin: 40))->getDataUri();

        return response()->view('catalog.tools', compact('store', 'url', 'qr'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function print(Request $request, int $storeId, Inventory $access): Response
    {
        $store = $this->store($request, $storeId, $access);
        $url = $this->menuUrl($store);
        $qr = (new SvgWriter)->write(new QrCode(data: $url, size: 400, margin: 40))->getDataUri();

        return response()->view('catalog.share-print', compact('store', 'url', 'qr'))->header('Cache-Control', 'private, no-store');
    }

    public function qr(Request $request, int $storeId, Inventory $access): Response
    {
        $store = $this->store($request, $storeId, $access);
        $svg = (new SvgWriter)->write(new QrCode(data: $this->menuUrl($store), size: 800, margin: 80))->getString();

        return response($svg)->header('Content-Type', 'image/svg+xml')->header('Content-Disposition', 'attachment; filename="cardapio-'.$store->id.'-qrcode.svg"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function export(Request $request, int $storeId, Inventory $access, CatalogExport $export): BinaryFileResponse
    {
        [$store, $tables] = DB::transaction(function () use ($request, $storeId, $access, $export): array {
            $store = $this->store($request, $storeId, $access, true);

            return [$store, $export->snapshot($store, $this->baseUrl())];
        }, 3);
        $path = tempnam(sys_get_temp_dir(), 'catalog-export-');
        abort_if($path === false, 503, 'Não foi possível preparar o arquivo. Tente novamente.');
        try {
            $export->write($tables, $path);
            clearstatcache(true, $path);
            $access->member($request->user());
            Audit::record('catalog.exported', ['store_id' => $store->id, 'tables' => count($tables), 'products' => count($tables[2]['rows'])], $store->tenant_id);
            $filename = 'catalogo-loja-'.$store->id.'-'.Carbon::now($store->timezone)->format('Ymd').'.xlsx';

            return response()->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
    }
}
