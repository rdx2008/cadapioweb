<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryCounts
{
    private function capture(object $item, int $movement): array
    {
        return ['id' => $item->id, 'name' => $item->name, 'unit' => $item->unit, 'active' => (bool) $item->active, 'balance' => $item->balance, 'stock_value_cents' => $item->stock_value_cents, 'average_cost_micros' => $item->average_cost_micros, 'valuation_revision' => $item->valuation_revision, 'last_movement' => $movement];
    }

    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function lastMovements(Collection $items): Collection
    {
        return DB::table('inventory_movements')->whereIn('item_id', $items->keys())->selectRaw('item_id, MAX(id) AS last_id')->groupBy('item_id')->pluck('last_id', 'item_id');
    }

    public function count(object $user, int $id, bool $lock = false): object
    {
        $count = DB::table('inventory_counts')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $count->store_id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();

        return DB::table('inventory_counts')->where('id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function start(object $user, array $data): int
    {
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
        $ids = array_map('intval', $data['item_ids']);
        sort($ids);
        abort_unless(count($ids) >= 1 && count($ids) <= 50 && count(array_unique($ids)) === count($ids), 422, 'Selecione de 1 a 50 insumos diferentes.');
        $hash = $this->hash([$user->id, $user->tenant_id, $store->id, $ids, trim($data['reference']), trim($data['reason'])]);
        $existing = DB::table('inventory_counts')->where('request_key', $data['request_key'])->first();
        if ($existing) {
            abort_unless($existing->created_by === $user->id && hash_equals($existing->request_hash, $hash), 409, 'Esta solicitação já foi utilizada com outros dados.');

            return $existing->id;
        }
        abort_if(trim($data['reference']) === '' || trim($data['reason']) === '', 422, 'Informe a identificação e o motivo da contagem.');
        $items = DB::table('inventory_items')->where('tenant_id', $user->tenant_id)->where('store_id', $store->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($items->count() === count($ids), 404);
        abort_if($items->contains(fn ($item) => ! $item->active), 409, 'Selecione somente insumos ativos.');
        $movements = $this->lastMovements($items);
        $id = DB::table('inventory_counts')->insertGetId(['tenant_id' => $user->tenant_id, 'store_id' => $store->id, 'reference' => trim($data['reference']), 'reason' => trim($data['reason']), 'created_by' => $user->id, 'request_key' => $data['request_key'], 'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($items as $item) {
            $snapshot = $this->capture($item, (int) $movements->get($item->id, 0));
            DB::table('inventory_count_lines')->insert(['count_id' => $id, 'item_id' => $item->id, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'baseline_hash' => $this->hash($snapshot)]);
        }
        Audit::record('inventory.count_started', ['count_id' => $id, 'items' => $ids], $user->tenant_id);

        return $id;
    }

    private function draft(object $count, int $revision): void
    {
        abort_unless($count->status === 'draft' && $count->revision === $revision, 409, 'A contagem mudou ou já foi encerrada. Atualize a página.');
    }

    public function save(object $count, object $user, array $data): void
    {
        $this->draft($count, (int) $data['revision']);
        $lines = DB::table('inventory_count_lines')->where('count_id', $count->id)->get()->keyBy('id');
        $submitted = array_map('intval', array_keys($data['quantities']));
        $expected = $lines->keys()->all();
        sort($submitted);
        sort($expected);
        abort_unless($submitted === $expected, 422, 'Envie somente os itens desta contagem, sem omitir linhas.');
        foreach ($data['quantities'] as $id => $value) {
            $counted = $value === null || trim($value) === '' ? null : app(Inventory::class)->quantity($value, 'quantities.'.$id);
            DB::table('inventory_count_lines')->where('id', $id)->update(['counted' => $counted]);
        }
        DB::table('inventory_counts')->where('id', $count->id)->update(['revision' => $count->revision + 1, 'updated_at' => now()]);
        Audit::record('inventory.count_saved', ['count_id' => $count->id, 'revision' => $count->revision + 1], $user->tenant_id);
    }

    public function inspect(object $count, bool $lock = false): array
    {
        $lines = DB::table('inventory_count_lines')->where('count_id', $count->id)->orderBy('item_id')->get();
        $items = DB::table('inventory_items')->where('tenant_id', $count->tenant_id)->where('store_id', $count->store_id)->whereIn('id', $lines->pluck('item_id'))->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $movements = $this->lastMovements($items);
        $rows = [];
        foreach ($lines as $line) {
            $snapshot = json_decode($line->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $item = $items->get($line->item_id);
            $current = $item ? $this->capture($item, (int) $movements->get($item->id, 0)) : null;
            $stale = ! $item || ! $item->active || ! hash_equals($line->baseline_hash, $this->hash($current ?? []));
            $difference = $line->counted === null ? null : $line->counted - $snapshot['balance'];
            $rows[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'name' => $snapshot['name'], 'unit' => $snapshot['unit'], 'expected' => $snapshot['balance'], 'counted' => $line->counted, 'difference' => $difference, 'stale' => $stale, 'current_hash' => $this->hash($current ?? [])];
        }
        $reviewHash = $this->hash([$count->id, $count->revision, $count->reference, $count->reason, $rows]);
        $complete = $rows !== [] && collect($rows)->every(fn ($row) => $row['counted'] !== null);
        $stale = collect($rows)->contains('stale', true);

        return compact('rows', 'items', 'reviewHash', 'complete', 'stale');
    }

    public function apply(object $count, object $user, array $data): void
    {
        if ($count->status === 'applied') {
            abort_unless($count->applied_by === $user->id && $count->applied_key === $data['request_key'] && hash_equals($count->applied_review, $data['review_hash']), 409, 'Esta contagem já foi aplicada. Confira o histórico.');

            return;
        }
        $this->draft($count, (int) $data['revision']);
        abort_if(DB::table('inventory_counts')->where('applied_key', $data['request_key'])->exists(), 409, 'Esta confirmação já foi utilizada.');
        $review = $this->inspect($count, true);
        abort_unless($review['complete'], 422, 'Preencha a contagem física de todos os insumos. Campos vazios não são zero.');
        abort_if($review['stale'], 409, 'O estoque mudou desde o início. Reinicie a conferência e conte novamente antes de ajustar.');
        abort_unless(hash_equals($review['reviewHash'], $data['review_hash']), 409, 'A prévia mudou. Confira novamente as diferenças.');
        foreach ($review['rows'] as $row) {
            if ($row['difference'] === 0) {
                continue;
            }
            $key = (string) Str::uuid();
            app(Inventory::class)->move($review['items'][$row['item_id']], $user, $row['difference'] > 0 ? 'in' : 'out', abs($row['difference']), 'Contagem #'.$count->id.' · '.$count->reason, $key);
            DB::table('inventory_count_lines')->where('id', $row['line_id'])->update(['movement_id' => DB::table('inventory_movements')->where('request_key', $key)->value('id')]);
        }
        DB::table('inventory_counts')->where('id', $count->id)->update(['status' => 'applied', 'revision' => $count->revision + 1, 'applied_key' => $data['request_key'], 'applied_review' => $data['review_hash'], 'applied_by' => $user->id, 'applied_at' => now(), 'updated_at' => now()]);
        Audit::record('inventory.count_applied', ['count_id' => $count->id, 'revision' => $count->revision + 1, 'items' => count($review['rows'])], $user->tenant_id);
    }

    public function restart(object $count, object $user, int $revision): void
    {
        $this->draft($count, $revision);
        $review = $this->inspect($count, true);
        foreach ($review['rows'] as $row) {
            $item = $review['items']->get($row['item_id']);
            abort_unless($item && $item->active, 409, 'Reative os insumos antes de reiniciar, ou cancele e abra uma contagem com os insumos ativos.');
            $snapshot = $this->capture($item, (int) DB::table('inventory_movements')->where('item_id', $item->id)->max('id'));
            DB::table('inventory_count_lines')->where('id', $row['line_id'])->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'baseline_hash' => $this->hash($snapshot), 'counted' => null]);
        }
        DB::table('inventory_counts')->where('id', $count->id)->update(['revision' => $count->revision + 1, 'updated_at' => now()]);
        Audit::record('inventory.count_restarted', ['count_id' => $count->id, 'revision' => $count->revision + 1], $user->tenant_id);
    }

    public function cancel(object $count, object $user, array $data): void
    {
        $this->draft($count, (int) $data['revision']);
        abort_if(trim($data['reason']) === '', 422, 'Informe o motivo do cancelamento.');
        DB::table('inventory_counts')->where('id', $count->id)->update(['status' => 'cancelled', 'revision' => $count->revision + 1, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => trim($data['reason']), 'updated_at' => now()]);
        Audit::record('inventory.count_cancelled', ['count_id' => $count->id, 'reason' => trim($data['reason'])], $user->tenant_id);
    }
}
