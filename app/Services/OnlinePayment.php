<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlinePayment
{
    public const LABELS = ['online_infinitepay' => 'Pagar online · InfinitePay', 'online_pagbank' => 'Pagar online · PagSeguro/PagBank'];

    public function isOnline(object $order): bool
    {
        return array_key_exists($order->payment_method, self::LABELS);
    }

    public function accounts(int $storeId): Collection
    {
        return DB::table('store_payment_accounts')->where('store_id', $storeId)->where('enabled', true)->where('environment', 'production')->get();
    }

    public function reserve(object $order): void
    {
        if (! $this->isOnline($order)) {
            return;
        }
        $provider = substr($order->payment_method, 7);
        $account = $this->accounts($order->store_id)->firstWhere('provider', $provider);
        if (! $account) {
            throw ValidationException::withMessages(['payment_method' => 'O pagamento online está indisponível. Revise o pedido.']);
        }
        $expires = now()->addMinutes(30);
        if ($order->prepare_at && Carbon::parse($order->prepare_at, 'UTC')->lt($expires)) {
            $expires = Carbon::parse($order->prepare_at, 'UTC');
        }
        DB::table('payment_attempts')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order->id, 'account_id' => $account->id, 'provider' => $provider, 'environment' => $account->environment, 'credential' => $account->credential, 'handle' => $account->handle, 'state' => 'reserved', 'amount_cents' => $order->total_cents, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function start(object $order): ?string
    {
        if (! $this->isOnline($order)) {
            return null;
        }
        $lock = Cache::lock('payment.create:'.$order->id, 40);
        if (! $lock->get()) {
            return null;
        }
        try {
            $attempt = DB::transaction(function () use ($order) {
                DB::table('tenants')->where('id', $order->tenant_id)->lockForUpdate()->firstOrFail();
                if (! app(TenantState::class)->active($order->tenant_id)) {
                    return null;
                }
                $current = DB::table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $attempt = DB::table('payment_attempts')->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
                if ($current->status === 'cancelled' || $current->payment_status === 'paid' || $attempt->needs_review || now()->gte(Carbon::parse($attempt->expires_at, 'UTC'))) {
                    return null;
                }
                if ($attempt->state === 'reserved') {
                    if (! DB::table('store_payment_accounts')->where('id', $attempt->account_id)->where('enabled', true)->exists()) {
                        return null;
                    }
                    DB::table('payment_attempts')->where('id', $attempt->id)->update(['state' => 'creating', 'updated_at' => now()]);
                }

                return $attempt;
            });
            if (! $attempt) {
                return null;
            }
            if ($attempt->state !== 'reserved') {
                return $attempt->state === 'waiting' && $attempt->checkout_url && app(PaymentGateway::class)->validUrl($attempt->checkout_url, $attempt) ? $attempt->checkout_url : null;
            }
            try {
                $account = DB::table('store_payment_accounts')->where('id', $attempt->account_id)->firstOrFail();
                $created = app(PaymentGateway::class)->create($attempt, $order, $account);
                DB::table('payment_attempts')->where('id', $attempt->id)->where('state', 'creating')->update($created + ['state' => 'waiting', 'next_check_at' => now()->addSeconds(30), 'updated_at' => now()]);
                Audit::record('payment.link.created', ['order_id' => $order->id, 'attempt_id' => $attempt->id, 'provider' => $attempt->provider, 'environment' => $attempt->environment], $order->tenant_id);

                return DB::table('orders')->where('id', $order->id)->where('status', '!=', 'cancelled')->where('payment_status', 'pending')->exists() ? $created['checkout_url'] : null;
            } catch (\Throwable) {
                DB::table('payment_attempts')->where('id', $attempt->id)->where('state', 'creating')->update(['state' => 'uncertain', 'message' => 'Não foi possível confirmar a criação do link. A loja deve conferir no provedor antes de tentar outra cobrança.', 'updated_at' => now()]);

                return null;
            }
        } finally {
            $lock->release();
        }
    }

    public function hints(object $attempt, array $data, bool $trusted = false): void
    {
        DB::transaction(function () use ($attempt, $data, $trusted): void {
            $attempt = DB::table('payment_attempts')->where('id', $attempt->id)->lockForUpdate()->firstOrFail();
            $hints = $attempt->hints ? json_decode(Crypt::decryptString($attempt->hints), true) : [];
            foreach (['transaction_nsu', 'slug', 'order_id'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $data[$key])) {
                    if ($trusted && $attempt->paid_at && isset($hints[$key]) && $hints[$key] !== $data[$key]) {
                        $hints['adverse'] = true;
                    }
                    if ((! $attempt->paid_at && $trusted) || ! isset($hints[$key]) || $hints[$key] === $data[$key]) {
                        $hints[$key] = $data[$key];
                    }
                }
            }
            if ($trusted && ! empty($data['adverse'])) {
                $hints['adverse'] = true;
            }
            $next = $attempt->next_check_at ? Carbon::parse($attempt->next_check_at, 'UTC')->min(now()->addSeconds(30)) : now();
            $values = ['hints' => Crypt::encryptString(json_encode($hints, JSON_THROW_ON_ERROR)), 'next_check_at' => $next, 'updated_at' => now()];
            if ($trusted && $attempt->state === 'expired') {
                $values['state'] = 'waiting';
            }
            DB::table('payment_attempts')->where('id', $attempt->id)->update($values);
        });
    }

    public function sync(int $id): void
    {
        $lock = Cache::lock('payment.sync:'.$id, 40);
        if (! $lock->get()) {
            return;
        }
        try {
            $attempt = DB::table('payment_attempts')->find($id);
            if (! $attempt || $attempt->state === 'reserved' || ($attempt->next_check_at && Carbon::parse($attempt->next_check_at, 'UTC')->gt(now()))) {
                return;
            }
            DB::table('payment_attempts')->where('id', $id)->update(['next_check_at' => now()->addMinutes(2)]);
            try {
                $result = app(PaymentGateway::class)->check($attempt);
            } catch (\DomainException) {
                DB::transaction(function () use ($attempt): void {
                    $tenant = DB::table('orders')->where('id', $attempt->order_id)->value('tenant_id');
                    DB::table('tenants')->where('id', $tenant)->lockForUpdate()->firstOrFail();
                    DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->firstOrFail();
                    DB::table('payment_attempts')->where('id', $attempt->id)->update(['needs_review' => true, 'message' => 'Identidade, moeda ou valor divergente na API. A loja precisa conferir este pagamento.', 'updated_at' => now()]);
                    DB::table('orders')->where('id', $attempt->order_id)->update(['payment_status' => 'review', 'updated_at' => now()]);
                    Audit::record('payment.verification.review', ['order_id' => $attempt->order_id, 'attempt_id' => $attempt->id], $tenant);
                });

                return;
            } catch (\Throwable) {
                DB::table('payment_attempts')->where('id', $id)->update(['message' => 'Conferência pendente. A API não confirmou identidade, valor ou disponibilidade. Tente consultar novamente mais tarde.', 'updated_at' => now()]);

                return;
            }
            DB::transaction(function () use ($attempt, $result): void {
                $tenant = DB::table('orders')->where('id', $attempt->order_id)->value('tenant_id');
                DB::table('tenants')->where('id', $tenant)->lockForUpdate()->firstOrFail();
                $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->firstOrFail();
                $current = DB::table('payment_attempts')->where('id', $attempt->id)->lockForUpdate()->firstOrFail();
                $paid = $result['paid'];
                $currentHints = $current->hints ? json_decode(Crypt::decryptString($current->hints), true) : [];
                $review = $current->needs_review || ($result['review'] ?? false) || ($currentHints['adverse'] ?? false);
                $late = now()->gt(Carbon::parse($current->expires_at, 'UTC')) && ! $current->paid_at;
                if ($paid && DB::table('payment_attempts')->where('provider', $attempt->provider)->where('transaction_id', $result['transaction_id'])->where('id', '!=', $attempt->id)->exists()) {
                    $review = true;
                    $paid = false;
                }
                if ($paid) {
                    $review = $review || $order->status === 'cancelled' || $late;
                    $values = ['state' => 'paid', 'transaction_id' => $result['transaction_id'], 'paid_at' => $current->paid_at ?? now(), 'next_check_at' => now()->addHours(6), 'needs_review' => $review, 'message' => $review ? 'Pagamento exige conferência da loja: prazo, cancelamento, estorno ou divergência. Confira no provedor antes de atender.' : null, 'updated_at' => now()];
                    DB::table('payment_attempts')->where('id', $current->id)->update($values);
                    DB::table('orders')->where('id', $order->id)->update(['payment_status' => $review ? 'review' : 'paid', 'updated_at' => now()]);
                    if (! $current->paid_at) {
                        Audit::record('payment.confirmed', ['order_id' => $order->id, 'attempt_id' => $current->id, 'provider' => $current->provider, 'environment' => $current->environment, 'review' => $review], $tenant);
                    }
                } elseif ($current->paid_at || $review) {
                    DB::table('payment_attempts')->where('id', $current->id)->update(['needs_review' => true, 'message' => 'Situação do pagamento alterada ou divergente. Confira no provedor antes de atender.', 'updated_at' => now()]);
                    DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'review', 'updated_at' => now()]);
                } elseif (now()->gte(Carbon::parse($current->expires_at, 'UTC'))) {
                    DB::table('payment_attempts')->where('id', $current->id)->update(['state' => 'expired', 'message' => 'Prazo de pagamento encerrado. A loja precisa conferir qualquer pagamento posterior.', 'updated_at' => now()]);
                }
            });
            $latest = DB::table('payment_attempts')->find($id);
            if ($latest->paid_at && $latest->provider === 'pagbank' && ! app(PaymentGateway::class)->closeCheckout($latest)) {
                DB::table('payment_attempts')->where('id', $id)->update(['message' => 'Pagamento confirmado, mas o checkout ainda precisa ser inativado no provedor. Confira para evitar pagamentos repetidos.', 'next_check_at' => now()->addMinutes(2)]);
            }
        } finally {
            $lock->release();
        }
    }

    public function requirePaid(object $order): void
    {
        abort_if($this->isOnline($order) && ($order->payment_status !== 'paid' || ! DB::table('payment_attempts')->where('order_id', $order->id)->where('state', 'paid')->where('needs_review', false)->exists()), 409, 'Aguarde a confirmação do pagamento online pelo provedor.');
    }
}
