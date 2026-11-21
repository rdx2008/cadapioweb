<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Coupons
{
    public function quote(object $store, ?string $code, ?string $phone, int $subtotal, int $fee): array
    {
        $empty = ['coupon_id' => null, 'coupon_code' => null, 'coupon_customer_hash' => null, 'discount_cents' => 0];
        if (! $code) {
            return $empty;
        }
        $today = Carbon::now($store->timezone)->toDateString();
        $coupon = DB::table('store_coupons')->where('store_id', $store->id)->where('code', strtoupper($code))
            ->where('enabled', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->first();
        if (! $coupon || $subtotal < $coupon->minimum_cents) {
            throw ValidationException::withMessages(['coupon_code' => 'Cupom indisponível ou valor mínimo em produtos não atingido.']);
        }
        $digits = preg_replace('/[\s+().-]/u', '', $phone ?? '');
        if (is_string($digits) && preg_match('/^55\d{10,11}$/D', $digits)) {
            $digits = substr($digits, 2);
        }
        if (! is_string($digits) || ! preg_match('/^[0-9]{10,11}$/D', $digits)) {
            throw ValidationException::withMessages(['coupon_code' => 'Informe um telefone brasileiro com DDD para usar o cupom.']);
        }
        $identity = hash_hmac('sha256', $store->id.':'.$digits, config('app.key'));
        $uses = DB::table('orders')->where('coupon_id', $coupon->id)
            ->where(fn ($query) => $query->where('status', '!=', 'cancelled')->orWhereIn('payment_status', ['paid', 'review']));
        if ((clone $uses)->count() >= $coupon->maximum_uses || $uses->where('coupon_customer_hash', $identity)->count() >= $coupon->per_customer) {
            throw ValidationException::withMessages(['coupon_code' => 'O limite de usos deste cupom foi atingido.']);
        }
        $value = $coupon->kind === 'percent' ? intdiv($subtotal * $coupon->value, 100) : $coupon->value;
        $discount = min($subtotal, $value);
        if ($discount < 1 || $subtotal + $fee - $discount < 1) {
            throw ValidationException::withMessages(['coupon_code' => 'O cupom requer um total final de pelo menos R$ 0,01.']);
        }

        return ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code, 'coupon_customer_hash' => $identity, 'discount_cents' => $discount];
    }
}
