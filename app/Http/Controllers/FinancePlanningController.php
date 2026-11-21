<?php

namespace App\Http\Controllers;

use App\Services\FinancialRecurrences;
use App\Services\Inventory;
use App\Services\StoreFinance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancePlanningController extends Controller
{
    public function index(Request $request, Inventory $access, FinancialRecurrences $recurrences): Response
    {
        $user = $access->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:1970-01-01|before_or_equal:2100-12-31', 'page' => 'nullable|integer|min:1|max:100000']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $today = Carbon::now($store?->timezone ?? 'America/Sao_Paulo');
        $from = $filters['from'] ?? $today->toDateString();
        $to = $filters['to'] ?? min('2100-12-31', $today->copy()->addMonthsNoOverflow(3)->toDateString());
        if ($from > $to || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw ValidationException::withMessages(['to' => 'Escolha um período de até 366 dias, com o fim após o início.']);
        }
        $base = DB::table('financial_accounts')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->where('status', 'open');
        $daily = (clone $base)->whereBetween('due_on', [$from, $to])->selectRaw("due_on, SUM(CASE WHEN direction = 'receivable' THEN amount_cents - settled_cents ELSE 0 END) AS incoming, SUM(CASE WHEN direction = 'payable' THEN amount_cents - settled_cents ELSE 0 END) AS outgoing")->groupBy('due_on')->orderBy('due_on')->get();
        $cumulative = 0;
        $daily = $daily->map(function ($day) use (&$cumulative): array {
            $net = (int) $day->incoming - (int) $day->outgoing;
            $cumulative += $net;

            return ['due_on' => $day->due_on, 'incoming' => (int) $day->incoming, 'outgoing' => (int) $day->outgoing, 'net' => $net, 'cumulative' => $cumulative];
        });
        $totals = ['incoming' => $daily->sum('incoming'), 'outgoing' => $daily->sum('outgoing'), 'net' => $cumulative];
        $overdue = ['incoming' => (int) (clone $base)->where('direction', 'receivable')->where('due_on', '<', $today->toDateString())->sum(DB::raw('amount_cents - settled_cents')), 'outgoing' => (int) (clone $base)->where('direction', 'payable')->where('due_on', '<', $today->toDateString())->sum(DB::raw('amount_cents - settled_cents'))];
        $plans = DB::table('financial_recurrences')->where('tenant_id', $user->tenant_id)->where('store_id', $store?->id ?? 0)->orderByDesc('id')->paginate(15)->withQueryString();
        foreach ($plans as $plan) {
            $plan->next_due = $recurrences->due($plan->first_due_on, $plan->next_index);
        }

        return response()->view('finance.planning', compact('stores', 'store', 'today', 'from', 'to', 'daily', 'totals', 'overdue', 'plans'))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, Inventory $access, FinancialRecurrences $recurrences): Response
    {
        $user = $access->member($request->user());
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'direction' => 'required|in:payable,receivable', 'description' => 'required|string|max:160', 'counterparty' => 'required|string|max:120', 'category' => ['required', Rule::in(array_keys(StoreFinance::CATEGORIES))], 'due_on' => 'required|date_format:Y-m-d|before_or_equal:2100-12-31', 'end_on' => 'nullable|date_format:Y-m-d|after_or_equal:due_on|before_or_equal:2100-12-31', 'amount' => 'required|string|max:16', 'notes' => 'nullable|string|max:500']);
        $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $data['store_id'])->firstOrFail();
        $rows = $recurrences->preview($store, $data);
        $horizon = $recurrences->horizon($store);
        $data['request_key'] = (string) Str::uuid();
        $request->session()->put('finance_recurrence', ['user_id' => $user->id, 'data' => $data, 'expires' => now()->addMinutes(30)->timestamp, 'day' => Carbon::now($store->timezone)->toDateString()]);

        return response()->view('finance.recurrence-preview', compact('store', 'data', 'rows', 'horizon'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, Inventory $access, FinancialRecurrences $recurrences): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'confirmed' => 'accepted']);
        $preview = $request->session()->get('finance_recurrence');
        abort_unless($preview && $preview['user_id'] === $request->user()->id && $preview['expires'] >= now()->timestamp && hash_equals($preview['data']['request_key'], $data['request_key']), 409, 'A prévia mudou ou expirou. Confira novamente a programação.');
        DB::transaction(function () use ($request, $access, $recurrences, $preview): void {
            $user = $access->member($request->user(), true);
            $store = DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $preview['data']['store_id'])->firstOrFail();
            abort_unless($preview['day'] === Carbon::now($store->timezone)->toDateString(), 409, 'O dia mudou. Confira novamente os vencimentos.');
            $recurrences->create($user, $preview['data']);
        }, 3);

        return redirect()->route('finance.planning', ['store_id' => $preview['data']['store_id']])->with('success', 'Programação ativada e contas iniciais geradas. As baixas continuam sendo registradas quando realizadas.');
    }

    public function update(Request $request, int $id, Inventory $access, FinancialRecurrences $recurrences): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'amount' => 'required|string|max:16', 'enabled' => 'required|boolean', 'confirmed' => 'accepted']);
        $storeId = DB::transaction(function () use ($request, $id, $access, $recurrences, $data): int {
            $user = $access->member($request->user(), true);
            $recurrences->update($user, $id, $data);

            return DB::table('financial_recurrences')->where('id', $id)->value('store_id');
        }, 3);

        return redirect()->route('finance.planning', ['store_id' => $storeId])->with('success', 'Programação atualizada. Contas já geradas foram preservadas.');
    }

    public function generate(Request $request, Inventory $access, FinancialRecurrences $recurrences): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'confirmed' => 'accepted']);
        $count = DB::transaction(function () use ($request, $access, $recurrences, $data): int {
            $user = $access->member($request->user(), true);
            DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
            $count = 0;
            foreach (DB::table('financial_recurrences')->where('store_id', $data['store_id'])->where('enabled', true)->pluck('id') as $id) {
                $count += $recurrences->generate($id);
            }

            return $count;
        }, 3);

        return redirect()->route('finance.planning', ['store_id' => $data['store_id']])->with('success', $count.' nova(s) conta(s) programada(s). Contas existentes não foram duplicadas.');
    }
}
