<?php

namespace App\Http\Controllers;

use App\Services\CashRegister;
use App\Services\TenantState;
use App\Support\MoneyInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CashRegisterController extends Controller
{
    private function member(Request $request, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $request->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($user->access_enabled && $user->tenant_id && $user->tenant_id === $request->user()->tenant_id && ($user->role === 'owner' || ($user->role === 'staff' && $user->staff_profile === 'operator')), 403);
        if ($lock) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->firstOrFail();
        }
        abort_unless(app(TenantState::class)->active($user->tenant_id), 403);

        return $user;
    }

    private function stores(object $user): Builder
    {
        return DB::table('stores')->where('tenant_id', $user->tenant_id)->when($user->role === 'staff', fn ($q) => $q->whereIn('id', DB::table('user_store_access')->where('user_id', $user->id)->where('tenant_id', $user->tenant_id)->select('store_id')));
    }

    private function session(Request $request, int $id, bool $lock = false): object
    {
        $user = $this->member($request, $lock);

        return DB::table('cash_sessions')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $this->stores($user)->select('id'))->where('id', $id)->firstOrFail();
    }

    public function index(Request $request): Response
    {
        $user = $this->member($request);
        $stores = $this->stores($user)->orderBy('name')->get();
        $history = DB::table('cash_sessions')->join('stores', 'stores.id', '=', 'cash_sessions.store_id')->join('users', 'users.id', '=', 'cash_sessions.opened_by')
            ->where('cash_sessions.tenant_id', $user->tenant_id)->whereIn('cash_sessions.store_id', $stores->pluck('id'))
            ->select('cash_sessions.*', 'stores.name as store_name', 'users.name as operator_name')->orderByDesc('cash_sessions.id')->paginate(20);

        return response()->view('cash.index', ['stores' => $stores, 'active' => DB::table('cash_sessions')->whereIn('open_store_id', $stores->pluck('id'))->get()->keyBy('store_id'), 'history' => $history])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, CashRegister $cash): RedirectResponse
    {
        MoneyInput::normalize($request, ['opening' => 'opening_cents']);
        $data = $request->validate(['store_id' => 'required|integer|min:1', 'opening_cents' => 'required|integer|min:0|max:100000000', 'request_key' => 'required|uuid']);
        $id = DB::transaction(function () use ($request, $cash, $data): int {
            $user = $this->member($request, true);
            $store = $this->stores($user)->where('id', $data['store_id'])->firstOrFail();

            return $cash->open($store, $user->id, (int) $data['opening_cents'], $data['request_key']);
        }, 3);

        return redirect()->route('cash.show', $id)->with('success', 'Caixa aberto. Os recebimentos presenciais do PDV serão registrados neste turno.');
    }

    private function data(Request $request, int $id, CashRegister $cash): array
    {
        $session = $this->session($request, $id);
        $refunds = DB::table('cash_entries')->where('kind', 'refund')->select('order_id');

        return ['session' => $session, 'store' => DB::table('stores')->find($session->store_id),
            'operator' => DB::table('users')->find($session->opened_by), 'closer' => $session->closed_by ? DB::table('users')->find($session->closed_by) : null,
            'totals' => $session->closed_at ? json_decode($session->closing_totals, true, flags: JSON_THROW_ON_ERROR) : $cash->totals($session),
            'entries' => DB::table('cash_entries')->join('users', 'users.id', '=', 'cash_entries.user_id')->where('cash_session_id', $id)->select('cash_entries.*', 'users.name as operator_name')->orderByDesc('cash_entries.id')->paginate(40),
            'refundable' => $session->closed_at ? collect() : DB::table('cash_entries')->join('orders', 'orders.id', '=', 'cash_entries.order_id')->where('cash_entries.store_id', $session->store_id)->where('kind', 'sale')->where('orders.status', 'cancelled')->whereNotIn('orders.id', $refunds)->select('orders.id', 'cash_entries.amount_cents', 'cash_entries.payment_method')->orderByDesc('orders.id')->limit(30)->get()];
    }

    public function show(Request $request, int $id, CashRegister $cash): Response
    {
        return response()->view('cash.show', $this->data($request, $id, $cash))->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $request, int $id, CashRegister $cash): Response
    {
        $data = $this->data($request, $id, $cash);
        abort_unless($data['session']->closed_at, 409, 'Feche o caixa antes de imprimir a conferência.');

        return response()->view('cash.print', $data)->header('Cache-Control', 'private, no-store');
    }

    public function movement(Request $request, int $id, CashRegister $cash): RedirectResponse
    {
        MoneyInput::normalize($request, ['amount' => 'amount_cents']);
        $data = $request->validate(['kind' => 'required|in:supply,withdrawal', 'amount_cents' => 'required|integer|min:1|max:100000000', 'note' => 'required|string|max:500', 'request_key' => 'required|uuid']);
        DB::transaction(function () use ($request, $id, $cash, $data): void {
            $session = $this->session($request, $id, true);
            $cash->movement($session, $request->user()->id, $data['kind'], (int) $data['amount_cents'], trim($data['note']), $data['request_key']);
        }, 3);

        return redirect()->route('cash.show', $id)->with('success', 'Movimentação registrada.');
    }

    public function refund(Request $request, int $id, CashRegister $cash): RedirectResponse
    {
        $data = $request->validate(['order_id' => 'required|integer|min:1', 'note' => 'required|string|max:500', 'confirmed' => 'accepted', 'request_key' => 'required|uuid']);
        DB::transaction(function () use ($request, $id, $cash, $data): void {
            $session = $this->session($request, $id, true);
            $cash->refund($session, $data['order_id'], $request->user()->id, trim($data['note']), $data['request_key']);
        }, 3);

        return redirect()->route('cash.show', $id)->with('success', 'Devolução manual registrada no caixa.');
    }

    public function close(Request $request, int $id, CashRegister $cash): RedirectResponse
    {
        MoneyInput::normalize($request, ['counted' => 'counted_cents']);
        $data = $request->validate(['counted_cents' => 'required|integer|min:0|max:100000000', 'closing_note' => 'nullable|string|max:500', 'confirmed' => 'accepted', 'request_key' => 'required|uuid']);
        DB::transaction(function () use ($request, $id, $cash, $data): void {
            $session = $this->session($request, $id, true);
            $cash->close($session, $request->user()->id, (int) $data['counted_cents'], trim($data['closing_note'] ?? ''), $data['request_key']);
        }, 3);

        return redirect()->route('cash.show', $id)->with('success', 'Caixa fechado e conferência salva.');
    }
}
