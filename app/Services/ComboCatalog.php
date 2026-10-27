<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComboCatalog
{
    public function data(object $product): array
    {
        $groups = DB::table('combo_groups')->where('product_id', $product->id)->orderBy('id')->get();
        $options = DB::table('combo_options as co')->join('products as cp', 'cp.id', '=', 'co.component_product_id')
            ->leftJoin('product_variants as cv', function ($join) {
                $join->on('cv.id', '=', 'co.component_variant_id')->on('cv.product_id', '=', 'cp.id');
            })->whereIn('co.combo_group_id', $groups->pluck('id'))->where('co.available', true)
            ->where('cp.tenant_id', $product->tenant_id)->where('cp.store_id', $product->store_id)->where('cp.product_type', 'common')->where('cp.available', true)
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('cp.uses_variants', false)->whereNull('co.component_variant_id'))
                    ->orWhere(fn ($q) => $q->where('cp.uses_variants', true)->whereNotNull('cv.id')->where('cv.available', true));
            })->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('option_groups')->whereColumn('option_groups.product_id', 'cp.id')->where('kind', 'addon')->where('minimum', '>', 0);
            })->select('co.*', 'cp.name as component_name', 'cv.name as variant_name')->orderBy('co.id')->get();
        $byGroup = $options->groupBy('combo_group_id');
        $ready = $groups->contains(fn ($group) => $group->minimum > 0) && $groups->every(fn ($group) => $byGroup->get($group->id, collect())->count() >= $group->minimum);

        return ['groups' => $groups, 'options' => $byGroup, 'ready' => $ready];
    }

    public function quote(object $product, array $choices): array
    {
        $data = $this->data($product);
        if (! $data['ready'] || array_diff(array_map('strval', array_keys($choices)), $data['groups']->pluck('id')->map(fn ($id) => (string) $id)->all())) {
            throw ValidationException::withMessages(['cart' => 'O combo não está disponível ou suas escolhas mudaram. Revise o pedido.']);
        }
        $snapshot = [];
        foreach ($data['groups'] as $group) {
            $ids = array_map('intval', $choices[$group->id] ?? []);
            $available = $data['options']->get($group->id, collect())->whereIn('id', $ids);
            if (count($ids) !== count(array_unique($ids)) || count($ids) < $group->minimum || count($ids) > $group->maximum || $available->count() !== count($ids)) {
                throw ValidationException::withMessages(['cart' => 'Confira '.$group->name.': escolha de '.$group->minimum.' a '.$group->maximum.' opções disponíveis.']);
            }
            foreach ($available as $option) {
                $snapshot[] = ['group' => 'Combo · '.$group->name, 'name' => $option->quantity.' × '.$option->component_name.($option->variant_name ? ' · '.$option->variant_name : ''), 'price_cents' => $option->quantity * $option->surcharge_cents, 'kind' => 'combo'];
            }
        }

        return $snapshot;
    }
}
