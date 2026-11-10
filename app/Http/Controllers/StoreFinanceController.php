<?php

namespace App\Http\Controllers;

use App\Services\CashRegister;
use App\Services\Inventory;
use App\Services\StoreFinance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreFinanceController extends Controller
{
    public function index(Request $request, Inventory $access): Response
    {
        $user = $access->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'direction' => 'nullable|in:payable,receivable', 'status' => 'nullable|in:open,settled,cancelled,overdue', 'category' => ['nullable', Rule::in(array_keys(StoreFinance::CATEGORIES))], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1|max:100000', 'movement_page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $today = Carbon::now($store?->timezone ?? 'America/Sao_Paulo');
        $from = $filters['from'] ?? $today->copy()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $today->copy()->endOfMonth()->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'A data final deve ser posterior ao início.']);
        }
        $base = DB::table('financial_accounts')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0);
        $open = (clone $base)->where('status', 'open');
        $balances = [
            'payable' => (int) (clone $open)->where('direction', 'payable')->sum(DB::raw('amount_cents - settled_cents')),
            'receivable' => (int) (clone $open)->where('direction', 'receivable')->sum(DB::raw('amount_cents - settled_cents')),
            'overdue' => (int) (clone $open)->where('direction', 'payable')->where('due_on', '<', $today->toDateString())->sum(DB::raw('amount_cents - settled_cents')),
        ];
        $accounts = $base->whereBetween('due_on', [$from, $to])->when(! empty($filters['direction']), fn ($q) => $q->where('direction', $filters['direction']))->when(! empty($filters['category']), fn ($q) => $q->where('category', $filters['category']))
            ->when(! empty($filters['status']), fn ($q) => $filters['status'] === 'overdue' ? $q->where('status', 'open')->where('due_on', '<', $today->toDateString()) : $q->where('status', $filters['status']))->orderBy('due_on')->orderBy('id')->paginate(20)->withQueryString();
        $ledgerBase = DB::table('financial_settlements as s')->join('financial_accounts as a', 'a.id', '=', 's.account_id')->where('a.tenant_id', $user->tenant_id)->where('a.store_id', $store?->id ?? 0);
        $normal = (clone $ledgerBase)->whereBetween('s.settled_on', [$from, $to])->selectRaw('s.id, a.id AS account_id, a.description, a.direction, s.method, s.destination, s.settled_on AS movement_on, s.amount_cents AS amount_cents, 0 AS is_reversal');
        $reversed = (clone $ledgerBase)->whereNotNull('s.reversed_at')->whereBetween('s.reversed_on', [$from, $to])->selectRaw('s.id, a.id AS account_id, a.description, a.direction, s.method, s.destination, s.reversed_on AS movement_on, -s.amount_cents AS amount_cents, 1 AS is_reversal');
        $ledger = DB::query()->fromSub($normal->unionAll($reversed), 'movements');
        $realized = ['received' => (int) (clone $ledger)->where('direction', 'receivable')->sum('amount_cents'), 'paid' => (int) (clone $ledger)->where('direction', 'payable')->sum('amount_cents')];
        $movements = $ledger->orderByDesc('movement_on')->orderByDesc('id')->orderByDesc('is_reversal')->paginate(20, ['*'], 'movement_page')->withQueryString();

        return response()->view('finance.index', compact('stores', 'store', 'accounts', 'balances', 'realized', 'movements', 'from', 'to', 'today'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id, Inventory $access, CashRegister $cash): Response
    {
        $user = $access->member($request->user());
        $account = DB::table('financial_accounts')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        $account->has_settlements = DB::table('financial_settlements')->where('account_id', $id)->exists();
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $account->store_id)->firstOrFail();
        $settlements = DB::table('financial_settlements')->join('users', 'users.id', '=', 'financial_settlements.user_id')->where('account_id', $id)->select('financial_settlements.*', 'users.name as operator_name')->orderByDesc('financial_settlements.id')->paginate(15)->withQueryString();
        $events = DB::table('financial_events')->join('users', 'users.id', '=', 'financial_events.user_id')->where('account_id', $id)->select('financial_events.*', 'users.name as operator_name')->orderByDesc('financial_events.id')->paginate(15, ['*'], 'event_page')->withQueryString();
        $cashSession = $cash->current($store->id);
        $today = Carbon::now($store->timezone)->toDateString();

        return response()->view('finance.show', compact('account', 'store', 'settlements', 'events', 'cashSession', 'today'))->header('Cache-Control', 'private, no-store');
    }

    private function common(): array
    {
        return ['request_key' => 'required|uuid', 'revision' => 'required|integer|min:1|max:1000000000', 'confirmed' => 'accepted'];
    }

    private function run(Request $request, callable $action): mixed
    {
        return DB::transaction(function () use ($request, $action) {
            $user = app(Inventory::class)->member($request->user(), true);

            return $action($user, app(StoreFinance::class));
        }, 3);
    }

    public function save(Request $request, ?int $id = null): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'direction' => 'required|in:payable,receivable', 'description' => 'required|string|max:160', 'counterparty' => 'required|string|max:120', 'category' => ['required', Rule::in(array_keys(StoreFinance::CATEGORIES))], 'due_on' => 'required|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'amount' => 'required|string|max:16', 'notes' => 'nullable|string|max:500', 'request_key' => 'required|uuid', 'revision' => $id ? 'required|integer|min:1|max:1000000000' : 'nullable|integer|min:0|max:0']);
        $accountId = $this->run($request, fn ($user, $service) => $service->save($user, $data, $id));

        return redirect()->route('finance.show', $accountId)->with('success', 'Conta salva. O cadastro não movimenta dinheiro.');
    }

    public function purchase(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate($this->common() + ['due_on' => 'required|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31']);
        $accountId = $this->run($request, fn ($user, $service) => $service->purchase($user, $id, $data));

        return redirect()->route('finance.show', $accountId)->with('success', 'Conta a pagar vinculada à compra. Confira o vencimento antes da baixa.');
    }

    public function settle(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate($this->common() + ['amount' => 'required|string|max:16', 'settled_on' => 'required|date_format:Y-m-d|after_or_equal:1970-01-01', 'method' => ['required', Rule::in(array_keys(StoreFinance::METHODS))], 'destination' => 'required|in:external,cash', 'cash_session_id' => 'nullable|integer|min:1', 'note' => 'required|string|max:500']);
        $this->run($request, fn ($user, $service) => $service->settle($user, $id, $data));

        return redirect()->route('finance.show', $id)->with('success', 'Baixa registrada. Confira o saldo e o histórico abaixo.');
    }

    public function reverse(Request $request, int $id, int $settlementId): RedirectResponse
    {
        $data = $request->validate($this->common() + ['reversed_on' => 'required|date_format:Y-m-d|after_or_equal:1970-01-01', 'reason' => 'required|string|max:500', 'cash_session_id' => 'nullable|integer|min:1']);
        $this->run($request, fn ($user, $service) => $service->reverse($user, $id, $settlementId, $data));

        return redirect()->route('finance.show', $id)->with('success', 'Estorno registrado e saldo reaberto. O histórico original foi preservado.');
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate($this->common() + ['reason' => 'required|string|max:500']);
        $this->run($request, fn ($user, $service) => $service->cancel($user, $id, $data));

        return redirect()->route('finance.show', $id)->with('success', 'Conta cancelada com histórico preservado.');
    }
}
