<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductRecipes
{
    public function targets(object $product): array
    {
        $targets = ['base' => $product->product_type === 'combo' ? 'Extras / embalagem do combo' : 'Base do produto'];
        $variants = DB::table('product_variants')->where('product_id', $product->id)->orderBy('id')->get();
        if ($product->product_type !== 'combo') {
            foreach ($variants as $variant) {
                $targets['variant:'.$variant->id] = 'Tamanho: '.$variant->name;
            }
        }
        $options = DB::table('product_options')->join('option_groups', 'option_groups.id', '=', 'product_options.option_group_id')->where('option_groups.product_id', $product->id)
            ->when($product->product_type !== 'pizza', fn ($q) => $q->where('option_groups.kind', '!=', 'border'))->select('product_options.id', 'product_options.name', 'option_groups.name as group_name')->orderBy('product_options.id')->get();
        foreach ($options as $option) {
            $targets['option:'.$option->id] = 'Adicional: '.$option->group_name.' / '.$option->name;
        }
        if ($product->product_type === 'pizza') {
            foreach (DB::table('pizza_flavors')->where('product_id', $product->id)->orderBy('id')->get() as $flavor) {
                foreach ($variants as $variant) {
                    $targets['flavor:'.$flavor->id.':'.$variant->id] = 'Sabor: '.$flavor->name.' / '.$variant->name.' (pizza inteira)';
                }
            }
        }

        return $targets;
    }

    public function context(object $product, string $target, array $targets): string
    {
        return hash('sha256', json_encode([$product->id, $product->product_type, $target, $targets[$target] ?? null], JSON_THROW_ON_ERROR));
    }

    public function save(object $product, object $user, array $data, Inventory $inventory): void
    {
        $quantities = [];
        foreach ($data['lines'] ?? [] as $index => $line) {
            if (empty($line['item_id']) && trim($line['quantity'] ?? '') === '') {
                continue;
            }
            if (empty($line['item_id']) || ! isset($line['quantity'])) {
                throw ValidationException::withMessages(['lines.'.$index.'.quantity' => 'Selecione o insumo e informe a quantidade em cada linha.']);
            }
            $amount = $inventory->quantity($line['quantity'], 'lines.'.$index.'.quantity');
            if ($amount < 1) {
                throw ValidationException::withMessages(['lines.'.$index.'.quantity' => 'A quantidade de cada insumo deve ser maior que zero.']);
            }
            $quantities[(int) $line['item_id']] = $amount;
        }
        ksort($quantities);
        $reason = trim($data['reason']);
        abort_if($reason === '', 422);
        $digest = hash('sha256', json_encode([$data['target'], (int) $data['revision'], $data['context_hash'], $quantities, $reason], JSON_THROW_ON_ERROR));
        $previous = DB::table('product_recipe_revisions')->where('request_key', $data['request_key'])->first();
        if ($previous) {
            abort_unless($previous->product_id === $product->id && $previous->user_id === $user->id && hash_equals($previous->request_hash, $digest), 409);

            return;
        }
        $targets = $this->targets($product);
        abort_unless(isset($targets[$data['target']]), 422, 'Esta configuração não pertence ao produto atual.');
        abort_unless(hash_equals($this->context($product, $data['target'], $targets), $data['context_hash']), 409, 'O produto mudou. Reabra a ficha técnica antes de salvar.');
        $current = DB::table('product_recipes')->where('product_id', $product->id)->where('target_key', $data['target'])->lockForUpdate()->first();
        abort_unless(($current?->revision ?? 0) === (int) $data['revision'], 409, 'A ficha mudou em outra tela. Reabra para conferir a versão atual.');
        $items = DB::table('inventory_items')->where('tenant_id', $product->tenant_id)->where('store_id', $product->store_id)->where('active', true)->whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($items->count() !== count($quantities)) {
            throw ValidationException::withMessages(['lines' => 'Use somente insumos ativos da mesma loja. Remova ou substitua insumos inativos.']);
        }
        $lines = [];
        foreach ($quantities as $id => $amount) {
            $lines[] = ['item_id' => $id, 'name' => $items[$id]->name, 'unit' => $items[$id]->unit, 'quantity' => $amount];
        }
        $revision = ($current?->revision ?? 0) + 1;
        $values = ['revision' => $revision, 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'updated_at' => now()];
        if ($current) {
            DB::table('product_recipes')->where('id', $current->id)->update($values);
            $recipeId = $current->id;
        } else {
            $recipeId = DB::table('product_recipes')->insertGetId($values + ['tenant_id' => $product->tenant_id, 'store_id' => $product->store_id, 'product_id' => $product->id, 'target_key' => $data['target'], 'created_at' => now()]);
        }
        $revisionId = DB::table('product_recipe_revisions')->insertGetId(['recipe_id' => $recipeId, 'product_id' => $product->id, 'user_id' => $user->id, 'revision' => $revision,
            'target_label' => $targets[$data['target']], 'product_name' => $product->name, 'lines' => $values['lines'], 'reason' => $reason, 'request_key' => $data['request_key'], 'request_hash' => $digest, 'created_at' => now()]);
        Audit::record('inventory.recipe_saved', ['recipe_id' => $recipeId, 'revision_id' => $revisionId, 'product_id' => $product->id, 'target' => $data['target'], 'revision' => $revision], $product->tenant_id);
    }
}
