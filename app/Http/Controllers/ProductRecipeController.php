<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\ProductRecipes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProductRecipeController extends Controller
{
    public function edit(Request $request, int $id, Inventory $inventory, ProductRecipes $recipes): Response
    {
        $user = $inventory->member($request->user());
        $product = DB::table('products')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        $data = $request->validate(['target' => 'nullable|string|max:64', 'page' => 'nullable|integer|min:1|max:100000']);
        $targets = $recipes->targets($product);
        $target = $data['target'] ?? 'base';
        $editable = isset($targets[$target]);
        $retired = DB::table('product_recipes')->join('product_recipe_revisions', 'product_recipe_revisions.recipe_id', '=', 'product_recipes.id')->whereColumn('product_recipe_revisions.revision', 'product_recipes.revision')
            ->where('product_recipes.product_id', $id)->whereNotIn('target_key', array_keys($targets))->select('target_key', 'target_label')->get();
        foreach ($retired as $recipe) {
            $targets[$recipe->target_key] = $recipe->target_label.' · somente histórico';
        }
        abort_unless(isset($targets[$target]), 404);
        $store = DB::table('stores')->where('id', $product->store_id)->where('tenant_id', $user->tenant_id)->firstOrFail();
        $current = DB::table('product_recipes')->where('product_id', $id)->where('target_key', $target)->first();
        $items = DB::table('inventory_items')->where('tenant_id', $product->tenant_id)->where('store_id', $product->store_id)->orderBy('name')->get();
        $history = DB::table('product_recipe_revisions')->join('users', 'users.id', '=', 'product_recipe_revisions.user_id')->where('recipe_id', $current?->id ?? 0)->select('product_recipe_revisions.*', 'users.name as operator_name')->orderByDesc('product_recipe_revisions.id')->paginate(10)->withQueryString();

        return response()->view('inventory.recipe', compact('product', 'targets', 'target', 'current', 'items', 'history', 'editable', 'store') + ['contextHash' => $recipes->context($product, $target, $targets)])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, int $id, Inventory $inventory, ProductRecipes $recipes): RedirectResponse
    {
        $data = $request->validate(['target' => 'required|string|max:64', 'revision' => 'required|integer|min:0|max:1000000', 'context_hash' => 'required|string|size:64', 'request_key' => 'required|uuid', 'confirmed' => 'accepted',
            'reason' => 'required|string|max:500', 'lines' => 'nullable|array|max:50', 'lines.*' => 'array:item_id,quantity', 'lines.*.item_id' => 'nullable|integer|min:1|distinct', 'lines.*.quantity' => 'nullable|string|max:15']);
        DB::transaction(function () use ($request, $id, $inventory, $recipes, $data): void {
            $user = $inventory->member($request->user(), true);
            $product = DB::table('products')->where('tenant_id', $user->tenant_id)->where('id', $id)->lockForUpdate()->firstOrFail();
            $recipes->save($product, $user, $data, $inventory);
        }, 3);

        return redirect()->route('recipes.edit', ['id' => $id, 'target' => $data['target']])->with('success', 'Ficha técnica salva. O saldo de estoque não foi alterado.');
    }
}
