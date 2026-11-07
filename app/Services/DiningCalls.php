<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DiningCalls
{
    public function issue(object $tab, object $user, ?string $expectedHash = null): string
    {
        abort_if($tab->closed_at !== null, 409, 'Esta comanda já foi encerrada.');
        if ($expectedHash !== null) {
            abort_unless(hash_equals($tab->guest_token_hash ?? '', $expectedHash), 409, 'O link mudou. Atualize a tela antes de gerar outro.');
        } elseif ($tab->guest_token !== null) {
            return Crypt::decryptString($tab->guest_token);
        }
        $token = bin2hex(random_bytes(32));
        DB::table('dining_tabs')->where('id', $tab->id)->update(['guest_token' => Crypt::encryptString($token), 'guest_token_hash' => hash('sha256', $token), 'updated_at' => now()]);
        Audit::record('dining.guest_link_issued', ['tab_id' => $tab->id, 'rotated' => $expectedHash !== null], $user->tenant_id);

        return $token;
    }

    public function publicTab(string $token, bool $lock = false): ?object
    {
        return DB::table('dining_tabs')->join('tenants', 'tenants.id', '=', 'dining_tabs.tenant_id')->where('guest_token_hash', hash('sha256', $token))
            ->whereNull('dining_tabs.closed_at')->whereNotNull('dining_tabs.active_table_id')->where('tenants.status', 'active')->whereNull('tenants.deleted_at')
            ->select('dining_tabs.*')->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function request(string $token, string $kind, string $key): int
    {
        return DB::transaction(function () use ($token, $kind, $key): int {
            $initial = $this->publicTab($token);
            abort_unless($initial, 404);
            DB::table('tenants')->where('id', $initial->tenant_id)->lockForUpdate()->firstOrFail();
            $tab = $this->publicTab($token, true);
            abort_unless($tab, 404);
            $existing = DB::table('dining_call_requests')->join('dining_calls', 'dining_calls.id', '=', 'dining_call_requests.dining_call_id')->where('request_key', $key)->select('dining_calls.*')->first();
            if ($existing) {
                abort_unless($existing->dining_tab_id === $tab->id && $existing->kind === $kind, 409);

                return $existing->id;
            }
            $pendingKey = $tab->id.':'.$kind;
            $id = DB::table('dining_calls')->where('pending_key', $pendingKey)->value('id');
            if (! $id) {
                $id = DB::table('dining_calls')->insertGetId(['tenant_id' => $tab->tenant_id, 'store_id' => $tab->store_id, 'dining_tab_id' => $tab->id, 'kind' => $kind, 'status' => 'pending', 'pending_key' => $pendingKey, 'created_at' => now()]);
                DB::table('audit_events')->insert(['tenant_id' => $tab->tenant_id, 'user_id' => null, 'action' => 'dining.customer_requested', 'details' => json_encode(['tab_id' => $tab->id, 'call_id' => $id, 'kind' => $kind], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
            DB::table('dining_call_requests')->insert(['dining_call_id' => $id, 'request_key' => $key, 'created_at' => now()]);

            return $id;
        }, 3);
    }

    public function pending(int $tenantId, int $storeId, ?array $tabIds = null): Builder
    {
        return DB::table('dining_calls')->join('dining_tabs', 'dining_tabs.id', '=', 'dining_calls.dining_tab_id')->where('dining_calls.tenant_id', $tenantId)->where('dining_calls.store_id', $storeId)
            ->where('dining_calls.status', 'pending')->whereNull('dining_tabs.closed_at')->when($tabIds !== null, fn ($query) => $query->whereIn('dining_calls.dining_tab_id', $tabIds))
            ->select('dining_calls.*', 'dining_tabs.table_name')->orderBy('dining_calls.created_at')->orderBy('dining_calls.id');
    }

    public function invalidate(object $tab, string $reason): void
    {
        DB::table('dining_tabs')->where('id', $tab->id)->update(['guest_token' => null, 'guest_token_hash' => null]);
        DB::table('dining_calls')->where('dining_tab_id', $tab->id)->where('status', 'pending')->update(['status' => 'cancelled', 'pending_key' => null, 'cancelled_reason' => $reason]);
    }
}
