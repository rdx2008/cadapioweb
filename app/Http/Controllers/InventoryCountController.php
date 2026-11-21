<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\InventoryCounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InventoryCountController extends Controller
{
    public function index(Request $request, Inventory $inventory): Response
    {
        $user = $inventory->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100', 'items_page' => 'nullable|integer|min:1|max:100000', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $items = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->where('active', true)
            ->when(! empty($filters['q']), fn ($query) => $query->where('name_key', 'like', '%'.mb_strtolower(trim($filters['q'])).'%'))->orderBy('name')->paginate(50, ['*'], 'items_page')->withQueryString();
        $counts = DB::table('inventory_counts')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)
            ->select('inventory_counts.*')->selectSub(DB::table('users')->whereColumn('users.id', 'inventory_counts.created_by')->select('name'), 'operator_name')
            ->selectSub(DB::table('inventory_count_lines')->whereColumn('count_id', 'inventory_counts.id')->selectRaw('COUNT(*)'), 'line_count')
            ->orderByDesc('id')->paginate(15)->withQueryString();

        return response()->view('inventory.counts', compact('stores', 'store', 'items', 'counts'))->header('Cache-Control', 'private, no-store');
    }

    private function run(Request $request, int $id, callable $action): mixed
    {
        return DB::transaction(function () use ($request, $id, $action) {
            $user = app(Inventory::class)->member($request->user(), true);
            $counts = app(InventoryCounts::class);

            return $action($counts, $counts->count($user, $id, true), $user);
        }, 3);
    }

    public function start(Request $request, Inventory $inventory, InventoryCounts $counts): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'reference' => 'required|string|max:120', 'reason' => 'required|string|max:400', 'request_key' => 'required|uuid', 'item_ids' => 'required|array|min:1|max:50', 'item_ids.*' => 'required|integer|min:1|distinct']);
        $id = DB::transaction(fn () => $counts->start($inventory->member($request->user(), true), $data), 3);

        return redirect()->route('inventory.counts.show', $id)->with('success', 'Contagem aberta. Preencha somente depois de conferir as quantidades físicas.');
    }

    public function show(Request $request, int $id, Inventory $inventory, InventoryCounts $counts): Response
    {
        $user = $inventory->member($request->user());
        $count = $counts->count($user, $id);
        $count->creator_name = DB::table('users')->where('id', $count->created_by)->value('name');
        $count->applier_name = $count->applied_by ? DB::table('users')->where('id', $count->applied_by)->value('name') : null;
        $count->canceller_name = $count->cancelled_by ? DB::table('users')->where('id', $count->cancelled_by)->value('name') : null;
        $store = DB::table('stores')->where('id', $count->store_id)->where('tenant_id', $user->tenant_id)->firstOrFail();
        $lines = DB::table('inventory_count_lines')->where('count_id', $id)->orderBy('item_id')->get();

        return response()->view('inventory.count-show', compact('count', 'store', 'lines'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'quantities' => 'required|array|min:1|max:50', 'quantities.*' => 'nullable|string|max:15', 'next' => 'nullable|in:draft,review']);
        $this->run($request, $id, fn ($counts, $count, $user) => $counts->save($count, $user, $data));

        return redirect()->route(($data['next'] ?? '') === 'review' ? 'inventory.counts.review' : 'inventory.counts.show', $id)->with('success', 'Rascunho salvo. O saldo do estoque ainda não foi alterado.');
    }

    public function review(Request $request, int $id): Response
    {
        $data = $this->run($request, $id, function ($counts, $count): array {
            abort_unless($count->status === 'draft', 409, 'Esta contagem já foi encerrada. Consulte o histórico.');

            return ['count' => $count, 'store' => DB::table('stores')->find($count->store_id), 'review' => $counts->inspect($count, true)];
        });

        return response()->view('inventory.count-review', $data)->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'request_key' => 'required|uuid', 'review_hash' => 'required|string|size:64', 'confirmed' => 'accepted']);
        $this->run($request, $id, fn ($counts, $count, $user) => $counts->apply($count, $user, $data));

        return redirect()->route('inventory.counts.show', $id)->with('success', 'Contagem aplicada. Diferenças registradas no histórico de estoque.');
    }

    public function restart(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'confirmed' => 'accepted']);
        $this->run($request, $id, fn ($counts, $count, $user) => $counts->restart($count, $user, (int) $data['revision']));

        return redirect()->route('inventory.counts.show', $id)->with('success', 'Saldos de referência atualizados. Conte novamente: as quantidades anteriores foram limpas.');
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'confirmed' => 'accepted']);
        $this->run($request, $id, fn ($counts, $count, $user) => $counts->cancel($count, $user, $data));

        return redirect()->route('inventory.counts.show', $id)->with('success', 'Contagem cancelada. Nenhum ajuste foi aplicado.');
    }

    public function print(Request $request, int $id, Inventory $inventory, InventoryCounts $counts): Response
    {
        $count = $counts->count($inventory->member($request->user()), $id);
        abort_unless($count->status === 'draft', 409, 'Imprima somente contagens em andamento.');
        $store = DB::table('stores')->find($count->store_id);
        $lines = DB::table('inventory_count_lines')->where('count_id', $id)->orderBy('item_id')->get();

        return response()->view('inventory.count-print', compact('count', 'store', 'lines'))->header('Cache-Control', 'private, no-store');
    }
}
