<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\Referrals;
use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    public function index(Request $request, Inventory $access): Response
    {
        $user = $access->member($request->user());
        $filters = $request->validate(['store_id' => 'nullable|integer|min:1']);
        $stores = DB::table('stores')->where('tenant_id', $user->tenant_id)->orderBy('name')->get();
        $store = isset($filters['store_id']) ? $stores->firstWhere('id', (int) $filters['store_id']) : $stores->first();
        abort_if(isset($filters['store_id']) && ! $store, 404);
        $campaign = DB::table('store_referrals')->where('store_id', $store?->id)->first();
        $invites = DB::table('referral_invites as invites')->join('orders as inviter', 'inviter.id', '=', 'invites.inviter_order_id')
            ->where('invites.store_id', $store?->id)->select('invites.*', 'inviter.customer_name')->orderByDesc('invites.id')->paginate(20, ['*'], 'invites_page')->appends($filters);
        $conversions = DB::table('referral_conversions as conversions')->join('orders as orders', 'orders.id', '=', 'conversions.order_id')
            ->join('referral_invites as invites', 'invites.id', '=', 'conversions.invite_id')
            ->join('orders as inviter', 'inviter.id', '=', 'invites.inviter_order_id')
            ->where('conversions.store_id', $store?->id)
            ->select('conversions.*', 'orders.status', 'orders.payment_status', 'inviter.customer_name as inviter_name')
            ->orderByDesc('conversions.id')->paginate(20)->appends($filters);

        return response()->view('customers.referrals', compact('stores', 'store', 'campaign', 'invites', 'conversions'))->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $request, int $storeId, Inventory $access): RedirectResponse
    {
        MoneyInput::normalize($request, ['minimum' => 'minimum_cents']);
        $data = $request->validate(['enabled' => 'required|boolean', 'reward' => 'required|string|max:140', 'minimum_cents' => 'required|integer|min:0|max:99999999']);
        DB::transaction(function () use ($request, $storeId, $access, $data): void {
            $user = $access->member($request->user(), true);
            DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $storeId)->lockForUpdate()->firstOrFail();
            DB::table('store_referrals')->upsert(
                [$data + ['store_id' => $storeId, 'updated_at' => now(), 'created_at' => now()]],
                ['store_id'],
                ['enabled', 'reward', 'minimum_cents', 'updated_at'],
            );
            Audit::record('referrals.configure', ['store_id' => $storeId] + $data, $user->tenant_id);
        }, 3);

        return back()->with('success', 'Programa de indicações atualizado.');
    }

    public function invite(Request $request, int $orderId, Inventory $access, Referrals $referrals): RedirectResponse
    {
        $storeId = DB::transaction(function () use ($request, $orderId, $access, $referrals): int {
            $user = $access->member($request->user(), true);
            $order = DB::table('orders')->where('tenant_id', $user->tenant_id)->whereIn('source', ['menu', 'pos'])
                ->where('status', 'completed')->where('payment_status', 'paid')->where('id', $orderId)->firstOrFail();
            $identity = $referrals->identity($orderId);
            abort_unless($identity, 422, 'O pedido precisa ter um telefone identificável.');
            abort_unless(DB::table('store_referrals')->where('store_id', $order->store_id)->where('enabled', true)->exists(), 409, 'Ative o programa de indicações da loja.');
            $customer = ['store_id' => $order->store_id, 'customer_hash' => $referrals->hash($identity)];
            if (! DB::table('referral_invites')->where($customer)->exists()) {
                DB::table('referral_invites')->insert($customer + [
                    'inviter_order_id' => $orderId,
                    'code' => Str::lower(Str::random(24)),
                ]);
            }

            return $order->store_id;
        }, 3);

        return redirect()->route('referrals.index', ['store_id' => $storeId])->with('success', 'Link de indicação disponível na lista.');
    }

    public function deliver(Request $request, int $id, Inventory $access): RedirectResponse
    {
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id, $access): void {
            $user = $access->member($request->user(), true);
            $conversion = DB::table('referral_conversions')->whereIn('store_id', DB::table('stores')->where('tenant_id', $user->tenant_id)->select('id'))
                ->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($conversion->delivered_at) {
                return;
            }
            $order = DB::table('orders')->where('id', $conversion->order_id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'completed' && $order->payment_status === 'paid', 409, 'A recompensa depende de um pedido concluído e pago.');
            DB::table('referral_conversions')->where('id', $id)->update(['delivered_at' => now(), 'delivered_by' => $user->id]);
            Audit::record('referrals.deliver', ['conversion_id' => $id, 'order_id' => $order->id], $user->tenant_id);
        }, 3);

        return back()->with('success', 'Entrega da recompensa registrada.');
    }
}
