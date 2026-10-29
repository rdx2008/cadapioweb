<?php

namespace App\Services;

use App\Jobs\ImportCatalogPhoto;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogImportWriter
{
    public function confirm(int $tenantId, string $reference, int $revision, string $duplicates, bool $photos): array
    {
        return DB::transaction(function () use ($tenantId, $reference, $revision, $duplicates, $photos): array {
            $snapshot = DB::table('catalog_imports')->where('tenant_id', $tenantId)->where('reference', $reference)->firstOrFail();
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
            abort_unless(app(TenantState::class)->active($tenantId), 403);
            $store = DB::table('stores')->where('tenant_id', $tenantId)->where('id', $snapshot->store_id)->lockForUpdate()->firstOrFail();
            $import = DB::table('catalog_imports')->where('id', $snapshot->id)->lockForUpdate()->firstOrFail();
            if ($import->state === 'completed') {
                return json_decode($import->result, true);
            }
            abort_if(now()->gt($import->expires_at), 410, 'A prévia expirou. Envie a planilha novamente.');
            if ($import->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'A prévia mudou. Confira a versão atual antes de confirmar.']);
            }
            $payload = json_decode(Crypt::decryptString($import->payload), true, flags: JSON_THROW_ON_ERROR);
            $plan = app(CatalogImportPlan::class)->build($payload['sheets'], $payload['mapping']);
            if ($plan['errors']) {
                throw ValidationException::withMessages(['file' => 'Corrija os erros da prévia antes de importar.']);
            }
            $existing = DB::table('products')->where('store_id', $store->id)->get(['id', 'name', 'category']);
            $normalizer = app(CatalogImportPlan::class);
            $keys = $existing->mapWithKeys(fn ($p) => [$normalizer->normalize($p->category).'|'.$normalizer->normalize($p->name) => $p->id])->all();
            if ($duplicates === 'reject' && collect($plan['products'])->contains(fn ($p) => isset($keys[$p['key']]))) {
                throw ValidationException::withMessages(['duplicates' => 'Há produtos já cadastrados. Escolha ignorá-los ou revise a planilha.']);
            }
            $categories = DB::table('catalog_categories')->where('store_id', $store->id)->get()->keyBy(fn ($category) => $normalizer->normalize($category->name));
            $result = ['created' => 0, 'skipped' => 0, 'photos_queued' => 0, 'product_ids' => []];
            foreach ($plan['products'] as $position => $product) {
                if (isset($keys[$product['key']])) {
                    $result['skipped']++;

                    continue;
                }
                $categoryKey = $normalizer->normalize($product['category']);
                $category = $categories->get($categoryKey);
                $categoryId = $category?->id ?? DB::table('catalog_categories')->insertGetId(['store_id' => $store->id, 'name' => $product['category'], 'sort_order' => $position, 'created_at' => now(), 'updated_at' => now()]);
                if ($category) {
                    $product['category'] = $category->name;
                } else {
                    $categories->put($categoryKey, (object) ['id' => $categoryId, 'name' => $product['category']]);
                }
                $pizza = $product['type'] === 'pizza';
                $variants = $product['variants'];
                $price = $product['price_cents'] ?? (collect($variants)->min('price_cents') ?? 1);
                if ($pizza) {
                    $price = collect($product['flavors'])->min('price_cents');
                }
                $id = DB::table('products')->insertGetId(['tenant_id' => $tenantId, 'store_id' => $store->id, 'name' => $product['name'], 'category' => $product['category'], 'category_id' => $categoryId, 'description' => $product['description'], 'price_cents' => $price, 'available' => false, 'featured' => $product['featured'], 'sort_order' => $position, 'product_type' => $product['type'], 'uses_variants' => $pizza || count($variants) > 0, 'pizza_enabled' => $pizza, 'pizza_pricing' => 'highest', 'combo_fixed' => $product['type'] === 'combo' && ! count($product['combo_groups']), 'created_at' => now(), 'updated_at' => now()]);
                if ($pizza) {
                    $variantId = DB::table('product_variants')->insertGetId(['product_id' => $id, 'name' => mb_substr($product['name'], 0, 80), 'price_cents' => $price, 'max_flavors' => $product['pizza_max'], 'available' => true, 'created_at' => now(), 'updated_at' => now()]);
                    foreach ($product['flavors'] as $flavor) {
                        DB::table('pizza_flavors')->insert(['product_id' => $id, 'name' => $flavor['name'], 'prices' => json_encode([$variantId => $flavor['price_cents']]), 'available' => true, 'created_at' => now(), 'updated_at' => now()]);
                    }
                } else {
                    foreach ($variants as $variant) {
                        DB::table('product_variants')->insert($variant + ['product_id' => $id, 'available' => true, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                foreach ($product['groups'] as $group) {
                    $groupId = DB::table('option_groups')->insertGetId(['product_id' => $id, 'name' => $group['name'], 'minimum' => $group['minimum'], 'maximum' => $group['maximum'], 'kind' => 'addon', 'created_at' => now(), 'updated_at' => now()]);
                    foreach ($group['options'] as $option) {
                        DB::table('product_options')->insert($option + ['option_group_id' => $groupId, 'available' => true, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                foreach ($product['combo_groups'] as $group) {
                    $groupId = DB::table('combo_groups')->insertGetId(['product_id' => $id, 'name' => $group['name'], 'minimum' => $group['minimum'], 'maximum' => $group['maximum'], 'created_at' => now(), 'updated_at' => now()]);
                    foreach ($group['options'] as $option) {
                        DB::table('combo_options')->insert(['combo_group_id' => $groupId, 'label' => $option['name'], 'component_product_id' => null, 'quantity' => 1, 'surcharge_cents' => $option['price_cents'], 'available' => true, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                $keys[$product['key']] = $id;
                $result['created']++;
                $result['product_ids'][] = $id;
                if ($photos && preg_match('~^https://storage\.googleapis\.com/[^\s?#]+$~D', $product['photo'])) {
                    ImportCatalogPhoto::dispatch($tenantId, $id, $product['photo'])->afterCommit();
                    $result['photos_queued']++;
                }
            }
            DB::table('catalog_imports')->where('id', $import->id)->update(['state' => 'completed', 'result' => json_encode($result), 'payload' => null, 'updated_at' => now()]);
            Audit::record('catalog.import.completed', ['import_id' => $import->id, 'store_id' => $store->id, 'created' => $result['created'], 'skipped' => $result['skipped']], $tenantId);

            return $result;
        });
    }
}
