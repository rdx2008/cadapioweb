<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialRecurrences
{
    public function due(string $first, int $index): string
    {
        return Carbon::parse($first)->addMonthsNoOverflow($index)->toDateString();
    }

    public function horizon(object $store): string
    {
        return min('2100-12-31', Carbon::now($store->timezone)->addMonthsNoOverflow(3)->toDateString());
    }

    public function preview(object $store, array $data): array
    {
        $today = Carbon::now($store->timezone)->toDateString();
        abort_unless($data['due_on'] >= $today && $data['due_on'] <= Carbon::parse($today)->addYearNoOverflow()->toDateString(), 422, 'O primeiro vencimento deve ser de hoje até os próximos 12 meses.');
        $amount = InventoryCosts::money($data['amount'], 'amount');
        abort_if($amount < 1, 422, 'O valor mensal deve ser maior que zero.');
        $rows = [];
        for ($index = 0; $index < 4; $index++) {
            $due = $this->due($data['due_on'], $index);
            if ($due > $this->horizon($store) || (! empty($data['end_on']) && $due > $data['end_on'])) {
                break;
            }
            $rows[] = ['due_on' => $due, 'amount_cents' => $amount];
        }

        return $rows;
    }

    public function create(object $user, array $data): int
    {
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
        $hash = hash('sha256', json_encode([$user->id, $user->tenant_id, $data], JSON_THROW_ON_ERROR));
        $existing = DB::table('financial_recurrences')->where('request_key', $data['request_key'])->first();
        if ($existing) {
            abort_unless($existing->created_by === $user->id && hash_equals($existing->request_hash, $hash), 409, 'Esta confirmação já foi utilizada.');

            return $existing->id;
        }
        $this->preview($store, $data);
        abort_if(DB::table('financial_recurrences')->where('store_id', $store->id)->where('enabled', true)->count() >= 100, 422, 'A loja permite até 100 programações ativas.');
        $id = DB::table('financial_recurrences')->insertGetId([
            'tenant_id' => $user->tenant_id, 'store_id' => $store->id, 'direction' => $data['direction'],
            'description' => trim($data['description']), 'counterparty' => trim($data['counterparty']), 'category' => $data['category'],
            'amount_cents' => InventoryCosts::money($data['amount'], 'amount'), 'notes' => $data['notes'] ?? null,
            'first_due_on' => $data['due_on'], 'end_on' => $data['end_on'] ?? null,
            'request_key' => $data['request_key'], 'request_hash' => $hash, 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::record('finance.recurrence_created', ['recurrence_id' => $id, 'configuration' => $data], $user->tenant_id);
        $this->generate($id);

        return $id;
    }

    public function update(object $user, int $id, array $data): void
    {
        $plan = DB::table('financial_recurrences')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $plan->store_id)->lockForUpdate()->firstOrFail();
        $plan = DB::table('financial_recurrences')->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($plan->revision === (int) $data['revision'], 409, 'A programação mudou. Atualize a página antes de confirmar.');
        $amount = InventoryCosts::money($data['amount'], 'amount');
        abort_if($amount < 1, 422, 'O valor mensal deve ser maior que zero.');
        $enabled = (bool) $data['enabled'];
        $index = $plan->next_index;
        if ($enabled && ! $plan->enabled) {
            abort_if(DB::table('financial_recurrences')->where('store_id', $plan->store_id)->where('enabled', true)->count() >= 100, 422, 'A loja permite até 100 programações ativas.');
            $today = Carbon::now(DB::table('stores')->find($plan->store_id)->timezone)->toDateString();
            while ($this->due($plan->first_due_on, $index) < $today) {
                $index++;
            }
        }
        DB::table('financial_recurrences')->where('id', $id)->update(['amount_cents' => $amount, 'enabled' => $enabled, 'next_index' => $index, 'revision' => $plan->revision + 1, 'updated_at' => now()]);
        Audit::record('finance.recurrence_changed', ['recurrence_id' => $id, 'previous_amount_cents' => $plan->amount_cents, 'amount_cents' => $amount, 'previous_enabled' => (bool) $plan->enabled, 'enabled' => $enabled, 'previous_index' => $plan->next_index, 'next_index' => $index, 'revision' => $plan->revision + 1], $user->tenant_id);
    }

    public function generate(int $id): int
    {
        return DB::transaction(function () use ($id): int {
            $plan = DB::table('financial_recurrences')->find($id);
            if (! $plan) {
                return 0;
            }
            DB::table('tenants')->where('id', $plan->tenant_id)->lockForUpdate()->firstOrFail();
            $store = DB::table('stores')->where('tenant_id', $plan->tenant_id)->where('id', $plan->store_id)->lockForUpdate()->firstOrFail();
            $plan = DB::table('financial_recurrences')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! $plan->enabled || ! app(TenantState::class)->active($plan->tenant_id)) {
                return 0;
            }
            $index = $plan->next_index;
            $created = 0;
            for ($attempt = 0; $attempt < 24; $attempt++) {
                $due = $this->due($plan->first_due_on, $index);
                if ($due > $this->horizon($store) || ($plan->end_on && $due > $plan->end_on)) {
                    break;
                }
                if (! DB::table('financial_accounts')->where('recurrence_id', $id)->where('recurrence_occurrence', $due)->exists()) {
                    $accountId = DB::table('financial_accounts')->insertGetId([
                        'tenant_id' => $plan->tenant_id, 'store_id' => $plan->store_id, 'direction' => $plan->direction,
                        'description' => $plan->description, 'counterparty' => $plan->counterparty, 'category' => $plan->category,
                        'amount_cents' => $plan->amount_cents, 'notes' => $plan->notes, 'due_on' => $due,
                        'recurrence_id' => $id, 'recurrence_occurrence' => $due, 'recurrence_revision' => $plan->revision,
                        'created_by' => $plan->created_by, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $account = DB::table('financial_accounts')->find($accountId);
                    DB::table('financial_events')->insert(['account_id' => $accountId, 'user_id' => $plan->created_by, 'action' => 'recurrence', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', $id.':'.$due), 'snapshot' => json_encode($account, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                    Audit::record('finance.recurrence_generated', ['recurrence_id' => $id, 'account_id' => $accountId, 'occurrence' => $due, 'revision' => $plan->revision], $plan->tenant_id);
                    $created++;
                }
                $index++;
            }
            $ended = $this->due($plan->first_due_on, $index) > ($plan->end_on ?? '2100-12-31');
            $revision = $plan->revision + ($index !== $plan->next_index || $ended ? 1 : 0);
            DB::table('financial_recurrences')->where('id', $id)->update(['next_index' => $index, 'enabled' => ! $ended, 'revision' => $revision, 'last_run_at' => now(), 'updated_at' => now()]);
            if ($ended) {
                Audit::record('finance.recurrence_ended', ['recurrence_id' => $id, 'revision' => $revision], $plan->tenant_id);
            }

            return $created;
        }, 3);
    }
}
