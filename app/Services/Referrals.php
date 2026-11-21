<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Referrals
{
    public function identity(int $orderId): ?string
    {
        $expression = app(BusinessIntelligence::class)->customerExpression();

        return DB::table('orders')->where('id', $orderId)->selectRaw("$expression AS identity")->first()?->identity;
    }

    public function hash(string $identity): string
    {
        return hash_hmac('sha256', $identity, config('app.key'));
    }

    public function record(object $store, int $orderId, ?string $code): void
    {
        if (! $code) {
            return;
        }
        $campaign = DB::table('store_referrals')->where('store_id', $store->id)->where('enabled', true)->first();
        $invite = DB::table('referral_invites')->where('store_id', $store->id)->where('code', $code)->first();
        $identity = $this->identity($orderId);
        if (! $campaign || ! $invite || ! $identity || hash_equals($invite->customer_hash, $this->hash($identity))) {
            return;
        }
        $order = DB::table('orders')->find($orderId);
        if ($order->subtotal_cents < $campaign->minimum_cents) {
            return;
        }
        $expression = app(BusinessIntelligence::class)->customerExpression();
        $previous = DB::table('orders')->where('store_id', $store->id)->where('tenant_id', $store->tenant_id)
            ->where('id', '<', $orderId)->whereIn('source', ['pos', 'menu'])->where('status', '!=', 'cancelled')
            ->whereRaw("($expression) = ?", [$identity])->exists();
        if ($previous) {
            return;
        }
        $customerHash = $this->hash($identity);
        $alreadyAttributed = DB::table('referral_conversions as conversions')
            ->join('orders', 'orders.id', '=', 'conversions.order_id')
            ->where('conversions.store_id', $store->id)->where('conversions.customer_hash', $customerHash)
            ->where(fn ($query) => $query->whereNotNull('conversions.delivered_at')->orWhere('orders.status', '!=', 'cancelled'))
            ->exists();
        if ($alreadyAttributed) {
            return;
        }
        DB::table('referral_conversions')->insert([
            'store_id' => $store->id, 'invite_id' => $invite->id, 'order_id' => $orderId,
            'customer_hash' => $customerHash, 'reward' => $campaign->reward,
        ]);
    }
}
