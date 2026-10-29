<?php

namespace App\Http\Controllers;

use App\Services\CatalogImportPlan;
use App\Services\CatalogImportWriter;
use App\Services\CatalogWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CatalogImportController extends Controller
{
    private function tenant(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    public function index(): View
    {
        return view('catalog.import', ['stores' => DB::table('stores')->where('tenant_id', $this->tenant())->orderBy('name')->get(), 'imports' => DB::table('catalog_imports')->where('tenant_id', $this->tenant())->latest('id')->limit(20)->get()]);
    }

    public function upload(Request $request, CatalogWorkbook $reader, CatalogImportPlan $planner): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer', 'file' => 'required|file|extensions:xlsx,xls,csv|max:5120']);
        $store = DB::table('stores')->where('tenant_id', $this->tenant())->where('id', $data['store_id'])->firstOrFail();
        $file = $request->file('file');
        $sheets = $reader->read($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        $mapping = $planner->detect($sheets);
        $reference = (string) Str::uuid();
        DB::table('catalog_imports')->insert(['reference' => $reference, 'tenant_id' => $this->tenant(), 'store_id' => $store->id, 'user_id' => $request->user()->id, 'filename' => mb_substr($file->getClientOriginalName(), 0, 180), 'fingerprint' => hash_file('sha256', $file->getRealPath()), 'payload' => Crypt::encryptString(json_encode(compact('sheets', 'mapping'), JSON_THROW_ON_ERROR)), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('catalog.import.show', $reference);
    }

    private function draft(string $reference): object
    {
        return DB::table('catalog_imports')->where('tenant_id', $this->tenant())->where('reference', $reference)->firstOrFail();
    }

    public function show(string $reference, CatalogImportPlan $planner): View
    {
        $import = $this->draft($reference);
        $payload = null;
        $plan = null;
        abort_if($import->state === 'expired', 410, 'A prévia expirou. Envie novamente.');
        if ($import->state === 'draft') {
            abort_if(now()->gt($import->expires_at), 410, 'A prévia expirou. Envie novamente.');
            $payload = json_decode(Crypt::decryptString($import->payload), true, flags: JSON_THROW_ON_ERROR);
            $plan = $planner->build($payload['sheets'], $payload['mapping']);
        }

        $normalizer = app(CatalogImportPlan::class);
        $existingKeys = DB::table('products')->where('tenant_id', $this->tenant())->where('store_id', $import->store_id)->get(['category', 'name'])->map(fn ($product) => $normalizer->normalize($product->category).'|'.$normalizer->normalize($product->name))->all();

        return view('catalog.import-preview', compact('import', 'payload', 'plan', 'existingKeys') + ['fields' => CatalogImportPlan::FIELDS, 'store' => DB::table('stores')->find($import->store_id)]);
    }

    public function mapping(Request $request, string $reference, CatalogImportPlan $planner): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer', 'mapping' => 'required|array|max:10', 'mapping.*' => 'array:role,header,columns', 'mapping.*.role' => 'required|in:products,options,ignore', 'mapping.*.header' => 'required|integer|min:1|max:20', 'mapping.*.columns' => 'required|array', 'mapping.*.columns.*' => 'nullable|integer|min:0|max:49']);
        DB::transaction(function () use ($reference, $data): void {
            $import = DB::table('catalog_imports')->where('tenant_id', $this->tenant())->where('reference', $reference)->lockForUpdate()->firstOrFail();
            abort_unless($import->state === 'draft' && $import->revision === (int) $data['revision'] && now()->lte($import->expires_at), 409, 'Esta prévia mudou ou expirou. Atualize a página.');
            $payload = json_decode(Crypt::decryptString($import->payload), true, flags: JSON_THROW_ON_ERROR);
            $payload['mapping'] = $data['mapping'];
            DB::table('catalog_imports')->where('id', $import->id)->update(['payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'revision' => $import->revision + 1, 'updated_at' => now()]);
        });

        return back()->with('success', 'Prévia atualizada. Confira os dados antes de importar.');
    }

    public function confirm(Request $request, string $reference, CatalogImportWriter $writer): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer', 'duplicates' => 'required|in:skip,reject', 'photos' => 'sometimes|boolean', 'acknowledged' => 'accepted']);
        $writer->confirm($this->tenant(), $reference, (int) $data['revision'], $data['duplicates'], $request->boolean('photos'));

        return redirect()->route('catalog.import.show', $reference)->with('success', 'Importação concluída. Produtos novos estão pausados para sua revisão.');
    }
}
