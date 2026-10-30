<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComboController extends Controller
{
    private function product(int $id): object
    {
        return DB::table('products')->where('tenant_id', auth()->user()->tenant_id)->where('id', $id)->firstOrFail();
    }

    private function lockCombo(object $product): void
    {
        DB::table('stores')->where('id', $product->store_id)->lockForUpdate()->firstOrFail();
        if (DB::table('products')->where('id', $product->id)->value('product_type') !== 'combo') {
            throw ValidationException::withMessages(['product_type' => 'Selecione o tipo Combo e salve o produto antes de configurar componentes.']);
        }
    }

    public function group(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        $data = $request->validate(['group_id' => 'nullable|integer|min:1', 'name' => 'required|string|max:100', 'minimum' => 'required|integer|min:0|max:10', 'maximum' => 'required|integer|min:1|max:10|gte:minimum']);
        DB::transaction(function () use ($product, $data): void {
            $this->lockCombo($product);
            $groupId = $data['group_id'] ?? null;
            unset($data['group_id']);
            if ($groupId) {
                DB::table('combo_groups')->where('product_id', $product->id)->where('id', $groupId)->firstOrFail();
                DB::table('combo_groups')->where('id', $groupId)->update($data + ['updated_at' => now()]);
            } else {
                if (DB::table('combo_groups')->where('product_id', $product->id)->count() >= 10) {
                    throw ValidationException::withMessages(['name' => 'Limite de 10 grupos por combo.']);
                }
                $groupId = DB::table('combo_groups')->insertGetId($data + ['product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('combo.group.saved', ['product_id' => $product->id, 'group_id' => $groupId], $product->tenant_id);
        });

        return back()->with('success', 'Grupo do combo salvo. Cadastre opções suficientes para atender ao mínimo.');
    }

    public function option(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        MoneyInput::normalize($request, ['surcharge' => 'surcharge_cents']);
        $data = $request->validate(['group_id' => 'required|integer|min:1', 'option_id' => 'nullable|integer|min:1', 'label' => 'nullable|required_without:component_key|string|max:120', 'component_key' => ['nullable', 'required_without:label', 'string', 'max:80', 'regex:/^[1-9][0-9]*:(0|[1-9][0-9]*)$/D'], 'quantity' => 'required|integer|min:1|max:10', 'surcharge_cents' => 'required|integer|min:0|max:100000', 'available' => 'required|boolean']);
        DB::transaction(function () use ($product, $data): void {
            $this->lockCombo($product);
            DB::table('combo_groups')->where('product_id', $product->id)->where('id', $data['group_id'])->firstOrFail();
            $component = null;
            $variantId = null;
            if (! empty($data['component_key'])) {
                [$componentId, $variantId] = array_map('intval', explode(':', $data['component_key']));
                $component = DB::table('products')->where('tenant_id', $product->tenant_id)->where('store_id', $product->store_id)->where('id', $componentId)->where('product_type', 'common')->firstOrFail();
                if (DB::table('option_groups')->where('product_id', $component->id)->where('kind', 'addon')->where('minimum', '>', 0)->exists()) {
                    throw ValidationException::withMessages(['component_key' => 'O componente exige escolhas próprias. Use um produto simples, sem adicionais obrigatórios.']);
                }
                if ($component->uses_variants) {
                    DB::table('product_variants')->where('product_id', $component->id)->where('id', $variantId)->firstOrFail();
                } elseif ($variantId) {
                    throw ValidationException::withMessages(['component_key' => 'Este componente não utiliza tamanhos.']);
                }
            }
            $optionId = $data['option_id'] ?? null;
            $groupId = $data['group_id'];
            unset($data['component_key'], $data['option_id'], $data['group_id']);
            $data['label'] = $component ? null : $data['label'];
            $values = $data + ['combo_group_id' => $groupId, 'component_product_id' => $component?->id, 'component_variant_id' => $variantId ?: null, 'updated_at' => now()];
            if ($optionId) {
                DB::table('combo_options')->where('combo_group_id', $groupId)->where('id', $optionId)->firstOrFail();
                DB::table('combo_options')->where('id', $optionId)->update($values);
            } else {
                if (DB::table('combo_options')->where('combo_group_id', $groupId)->count() >= 20) {
                    throw ValidationException::withMessages(['component_key' => 'Limite de 20 opções por grupo.']);
                }
                $optionId = DB::table('combo_options')->insertGetId($values + ['created_at' => now()]);
            }
            Audit::record('combo.option.saved', ['product_id' => $product->id, 'option_id' => $optionId], $product->tenant_id);
        });

        return back()->with('success', 'Componente do combo salvo.');
    }

    public function settings(Request $request, int $id): RedirectResponse
    {
        $product = $this->product($id);
        $data = $request->validate(['combo_fixed' => 'required|boolean']);
        DB::transaction(function () use ($product, $data): void {
            $this->lockCombo($product);
            DB::table('products')->where('id', $product->id)->update(['combo_fixed' => (bool) $data['combo_fixed'], 'updated_at' => now()]);
            Audit::record('combo.settings.saved', ['product_id' => $product->id, 'fixed' => (bool) $data['combo_fixed']], $product->tenant_id);
        });

        return back()->with('success', 'Formato do combo salvo.');
    }

    public function availability(Request $request, int $id, int $optionId): RedirectResponse
    {
        $product = $this->product($id);
        $data = $request->validate(['available' => 'required|boolean']);
        DB::transaction(function () use ($product, $data, $optionId): void {
            $this->lockCombo($product);
            DB::table('combo_options')->join('combo_groups', 'combo_groups.id', '=', 'combo_options.combo_group_id')->where('combo_groups.product_id', $product->id)->where('combo_options.id', $optionId)->firstOrFail();
            DB::table('combo_options')->where('id', $optionId)->update($data + ['updated_at' => now()]);
            Audit::record('combo.option.availability', ['product_id' => $product->id, 'option_id' => $optionId, 'available' => (bool) $data['available']], $product->tenant_id);
        });

        return back()->with('success', 'Disponibilidade da opção atualizada.');
    }
}
