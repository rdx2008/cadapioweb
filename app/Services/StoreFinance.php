<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StoreFinance
{
    public const CATEGORIES = ['supplies' => 'Insumos e compras', 'rent' => 'Aluguel', 'payroll' => 'Pessoal', 'utilities' => 'Água, luz e internet', 'services' => 'Serviços', 'customers' => 'Clientes', 'other' => 'Outros'];

    public const METHODS = ['cash' => 'Dinheiro', 'pix' => 'Pix', 'card' => 'Cartão', 'transfer' => 'Transferência'];

    private function store(object $user, int $id): object
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function account(object $user, int $id): object
    {
        $account = DB::table('financial_accounts')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        $this->store($user, $account->store_id);

        return DB::table('financial_accounts')->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function hash(object $user, string $action, array $data, ?int $id): string
    {
        unset($data['request_key']);

        return hash('sha256', json_encode([$user->id, $user->tenant_id, $action, $id, $data], JSON_THROW_ON_ERROR));
    }

    private function replay(object $user, array $data, string $hash): ?int
    {
        $event = DB::table('financial_events')->where('request_key', $data['request_key'])->first();
        if (! $event) {
            return null;
        }
        abort_unless($event->user_id === $user->id && hash_equals($event->request_hash, $hash), 409, 'Esta confirmação já foi usada com outros dados.');

        return $event->account_id;
    }

    private function event(object $user, int $id, string $action, array $data, string $hash): void
    {
        $account = DB::table('financial_accounts')->where('id', $id)->firstOrFail();
        DB::table('financial_events')->insert(['account_id' => $id, 'user_id' => $user->id, 'action' => $action, 'request_key' => $data['request_key'], 'request_hash' => $hash, 'snapshot' => json_encode($account, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Audit::record('finance.'.$action, ['account_id' => $id, 'revision' => $account->revision, 'amount_cents' => $account->amount_cents, 'settled_cents' => $account->settled_cents], $user->tenant_id);
    }

    public function save(object $user, array $data, ?int $id = null): int
    {
        $store = $this->store($user, (int) $data['store_id']);
        $hash = $this->hash($user, 'save', $data, $id);
        if ($existing = $this->replay($user, $data, $hash)) {
            return $existing;
        }
        $amount = InventoryCosts::money($data['amount'], 'amount');
        abort_if($amount < 1, 422, 'O valor deve ser maior que zero.');
        $fields = ['direction' => $data['direction'], 'description' => trim($data['description']), 'counterparty' => trim($data['counterparty']), 'category' => $data['category'], 'due_on' => $data['due_on'], 'amount_cents' => $amount, 'notes' => $data['notes'] ?? null, 'updated_at' => now()];
        if ($id) {
            $account = $this->account($user, $id);
            abort_unless($account->store_id === $store->id && $account->revision === (int) $data['revision'], 409, 'A conta mudou. Atualize antes de editar.');
            abort_unless($account->status === 'open', 409, 'Somente contas abertas podem ser editadas.');
            if ($account->purchase_id || DB::table('financial_settlements')->where('account_id', $id)->exists()) {
                abort_unless($amount === $account->amount_cents && $data['direction'] === $account->direction && trim($data['counterparty']) === $account->counterparty, 409, 'Conta de compra ou com baixas preserva valor, tipo e pessoa/empresa. Altere apenas os dados de organização.');
            }
            DB::table('financial_accounts')->where('id', $id)->update($fields + ['revision' => $account->revision + 1]);
        } else {
            $id = DB::table('financial_accounts')->insertGetId($fields + ['tenant_id' => $user->tenant_id, 'store_id' => $store->id, 'created_by' => $user->id, 'created_at' => now()]);
        }
        $this->event($user, $id, 'save', $data, $hash);

        return $id;
    }

    public function purchase(object $user, int $purchaseId, array $data): int
    {
        $purchase = DB::table('inventory_purchases')->where('tenant_id', $user->tenant_id)->where('id', $purchaseId)->firstOrFail();
        $this->store($user, $purchase->store_id);
        $purchase = DB::table('inventory_purchases')->where('id', $purchaseId)->lockForUpdate()->firstOrFail();
        $hash = $this->hash($user, 'purchase', $data, $purchaseId);
        if ($existing = $this->replay($user, $data, $hash)) {
            return $existing;
        }
        abort_unless($purchase->status === 'received' && $purchase->total_cents > 0 && $purchase->revision === (int) $data['revision'], 409, 'Confira o recebimento e a versão da compra.');
        abort_if(DB::table('financial_accounts')->where('purchase_id', $purchaseId)->exists(), 409, 'Esta compra já tem uma conta financeira. Abra a conta existente.');
        $id = DB::table('financial_accounts')->insertGetId(['tenant_id' => $user->tenant_id, 'store_id' => $purchase->store_id, 'purchase_id' => $purchaseId, 'direction' => 'payable', 'description' => 'Compra #'.$purchaseId.($purchase->reference ? ' · '.$purchase->reference : ''), 'counterparty' => $purchase->supplier_name, 'category' => 'supplies', 'due_on' => $data['due_on'], 'amount_cents' => $purchase->total_cents, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->event($user, $id, 'purchase', $data, $hash);

        return $id;
    }

    private function revision(object $account, array $data): void
    {
        abort_unless($account->revision === (int) $data['revision'], 409, 'A conta mudou. Atualize a página e confira o saldo.');
    }

    private function date(object $store, string $date, bool $cash = false, ?string $minimum = null): void
    {
        $today = Carbon::now($store->timezone)->toDateString();
        abort_if($date > $today || ($cash && $date !== $today) || ($minimum && $date < $minimum), 422, 'Confira a data. No caixa, o movimento deve ser de hoje; estornos não podem anteceder a baixa.');
    }

    private function cash(object $store, object $user, array $data, bool $outgoing, int $amount, string $note): ?int
    {
        if ($data['destination'] !== 'cash') {
            return null;
        }
        abort_unless($data['method'] === 'cash', 422, 'A gaveta movimenta somente dinheiro. Pix, cartão e transferência ficam fora do caixa físico.');
        $cash = app(CashRegister::class);
        $session = $cash->current($store->id);
        abort_unless($session && $session->id === (int) ($data['cash_session_id'] ?? 0), 409, 'O turno mudou ou foi fechado. Atualize a página e confira o caixa.');
        $cash->movement($session, $user->id, $outgoing ? 'withdrawal' : 'supply', $amount, $note, $data['request_key']);

        return DB::table('cash_entries')->where('request_key', $data['request_key'])->value('id');
    }

    public function settle(object $user, int $id, array $data): void
    {
        $account = $this->account($user, $id);
        $hash = $this->hash($user, 'settle', $data, $id);
        if ($this->replay($user, $data, $hash)) {
            return;
        }
        $this->revision($account, $data);
        $amount = InventoryCosts::money($data['amount'], 'amount');
        abort_unless($account->status === 'open' && $amount > 0 && $amount <= $account->amount_cents - $account->settled_cents, 409, 'Valor superior ao saldo ou conta indisponível.');
        $store = $this->store($user, $account->store_id);
        $this->date($store, $data['settled_on'], $data['destination'] === 'cash');
        $entry = $this->cash($store, $user, $data, $account->direction === 'payable', $amount, 'Financeiro #'.$id.' · '.$account->description);
        DB::table('financial_settlements')->insert(['account_id' => $id, 'amount_cents' => $amount, 'settled_on' => $data['settled_on'], 'method' => $data['method'], 'destination' => $data['destination'], 'cash_entry_id' => $entry, 'note' => $data['note'], 'user_id' => $user->id, 'created_at' => now()]);
        $settled = $account->settled_cents + $amount;
        DB::table('financial_accounts')->where('id', $id)->update(['settled_cents' => $settled, 'status' => $settled === $account->amount_cents ? 'settled' : 'open', 'revision' => $account->revision + 1, 'updated_at' => now()]);
        $this->event($user, $id, 'settle', $data, $hash);
    }

    public function reverse(object $user, int $id, int $settlementId, array $data): void
    {
        $account = $this->account($user, $id);
        $hash = $this->hash($user, 'reverse', $data + ['settlement_id' => $settlementId], $id);
        if ($this->replay($user, $data, $hash)) {
            return;
        }
        $this->revision($account, $data);
        $settlement = DB::table('financial_settlements')->where('account_id', $id)->where('id', $settlementId)->lockForUpdate()->firstOrFail();
        abort_if($settlement->reversed_at || $account->status === 'cancelled', 409, 'Esta baixa já foi estornada ou a conta foi cancelada.');
        $store = $this->store($user, $account->store_id);
        $this->date($store, $data['reversed_on'], $settlement->destination === 'cash', $settlement->settled_on);
        $cashData = $data + ['destination' => $settlement->destination, 'method' => $settlement->method];
        $entry = $this->cash($store, $user, $cashData, $account->direction === 'receivable', $settlement->amount_cents, 'Estorno financeiro #'.$id.' · baixa #'.$settlementId);
        DB::table('financial_settlements')->where('id', $settlementId)->update(['reversed_on' => $data['reversed_on'], 'reversed_at' => now(), 'reversed_by' => $user->id, 'reversal_cash_entry_id' => $entry, 'reversal_reason' => $data['reason']]);
        DB::table('financial_accounts')->where('id', $id)->update(['settled_cents' => $account->settled_cents - $settlement->amount_cents, 'status' => 'open', 'revision' => $account->revision + 1, 'updated_at' => now()]);
        $this->event($user, $id, 'reverse', $data, $hash);
    }

    public function cancel(object $user, int $id, array $data): void
    {
        $account = $this->account($user, $id);
        $hash = $this->hash($user, 'cancel', $data, $id);
        if ($this->replay($user, $data, $hash)) {
            return;
        }
        $this->revision($account, $data);
        abort_unless($account->status === 'open' && $account->settled_cents === 0, 409, 'Estorne as baixas antes de cancelar a conta.');
        DB::table('financial_accounts')->where('id', $id)->update(['status' => 'cancelled', 'cancel_reason' => $data['reason'], 'revision' => $account->revision + 1, 'updated_at' => now()]);
        $this->event($user, $id, 'cancel', $data, $hash);
    }
}
