<?php

namespace App\Http\Controllers;

use App\Services\OnlinePayment;
use App\Services\PaymentGateway;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function configure(Request $request, int $id, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['infinitepay', 'pagbank'], true), 404);
        $store = DB::table('stores')->where('tenant_id', $request->user()->tenant_id)->where('id', $id)->firstOrFail();
        $data = $request->validate(['enabled' => 'required|boolean', 'environment' => $provider === 'infinitepay' ? 'required|in:production' : 'required|in:sandbox,production', 'credential' => ['nullable', 'string', 'max:4096', 'regex:/^[!-~]{8,4096}$/D'], 'handle' => $provider === 'infinitepay' ? ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/D'] : 'nullable|string|max:100']);
        if ($data['enabled']) {
            $root = config('app.url');
            $parts = parse_url($root);
            if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment']) || ($provider === 'pagbank' && strlen(rtrim($root, '/').'/wp/p') > 100)) {
                throw ValidationException::withMessages(['enabled' => 'Configure APP_URL com o domínio público HTTPS. No PagBank, domínio + /wp/p deve ter até 100 caracteres.']);
            }
        }
        DB::transaction(function () use ($store, $provider, $data): void {
            DB::table('stores')->where('id', $store->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('store_payment_accounts')->where('store_id', $store->id)->where('provider', $provider)->first();
            if (! $data['enabled'] && ! $store->accepts_cash && ! $store->accepts_card && ! $store->accepts_pix && app(OnlinePayment::class)->accounts($store->id)->where('provider', '!=', $provider)->isEmpty()) {
                throw ValidationException::withMessages(['enabled' => 'Habilite outra forma de pagamento antes de desligar este provedor.']);
            }
            if (empty($data['credential']) && ! $existing) {
                throw ValidationException::withMessages(['credential' => 'Cadastre a credencial desta loja diretamente neste campo.']);
            }
            if ($existing && $existing->environment !== $data['environment'] && empty($data['credential'])) {
                throw ValidationException::withMessages(['credential' => 'Ao trocar de ambiente, informe a credencial correspondente.']);
            }
            $values = ['enabled' => (bool) $data['enabled'], 'environment' => $data['environment'], 'handle' => $provider === 'infinitepay' ? $data['handle'] : null, 'updated_at' => now()];
            if (! empty($data['credential'])) {
                $values['credential'] = Crypt::encryptString($data['credential']);
            }
            if ($existing) {
                DB::table('store_payment_accounts')->where('id', $existing->id)->update($values);
            } else {
                DB::table('store_payment_accounts')->insert($values + ['store_id' => $store->id, 'provider' => $provider, 'webhook_secret' => Str::random(32), 'created_at' => now()]);
            }
            Audit::record('payment.account.updated', ['store_id' => $store->id, 'provider' => $provider, 'environment' => $data['environment'], 'enabled' => (bool) $data['enabled']], $store->tenant_id);
        });

        return back()->with('success', 'Configuração salva. A ativação depende de conta/API liberada e de validação real com o provedor.');
    }

    public function pay(string $id, OnlinePayment $payments): RedirectResponse
    {
        $order = DB::table('orders')->where('public_id', $id)->firstOrFail();
        $url = $payments->start($order);

        return $url ? redirect()->away($url) : redirect('/pedido/'.$id)->with('success', 'Consulte a situação do pagamento abaixo. Não faça outra cobrança enquanto houver resultado pendente.');
    }

    public function check(string $id, OnlinePayment $payments): RedirectResponse
    {
        $order = DB::table('orders')->where('public_id', $id)->firstOrFail();
        $attempt = DB::table('payment_attempts')->where('order_id', $order->id)->firstOrFail();
        $payments->sync($attempt->id);

        return redirect('/pedido/'.$id);
    }

    public function ownerCheck(Request $request, int $id, OnlinePayment $payments): RedirectResponse
    {
        $order = DB::table('orders')->where('tenant_id', $request->user()->tenant_id)->where('id', $id)->firstOrFail();
        $attempt = DB::table('payment_attempts')->where('order_id', $order->id)->firstOrFail();
        $payments->sync($attempt->id);

        return back()->with('success', 'Consulta solicitada. Consultas repetidas respeitam o intervalo do provedor.');
    }

    public function returned(Request $request, string $reference, OnlinePayment $payments): RedirectResponse
    {
        $attempt = DB::table('payment_attempts')->where('reference', $reference)->firstOrFail();
        if ($attempt->provider === 'infinitepay' && $request->input('order_nsu') === $attempt->reference) {
            $payments->hints($attempt, $request->only(['transaction_nsu', 'slug']));
        }
        $order = DB::table('orders')->where('id', $attempt->order_id)->firstOrFail();

        return redirect('/pedido/'.$order->public_id);
    }

    public function webhook(Request $request, string $provider, ?string $secret = null): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 32768, 413);
        $payload = json_decode($request->getContent(), true);
        abort_unless(is_array($payload), 400);
        if ($provider === 'i') {
            $account = DB::table('store_payment_accounts')->where('provider', 'infinitepay')->where('webhook_secret', $secret)->first();
            abort_unless($account && hash_equals($account->webhook_secret, (string) $secret), 401);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            $reference = $data['order_nsu'] ?? $payload['order_nsu'] ?? null;
            abort_unless(is_string($reference) && Str::isUuid($reference), 400);
            $attempt = DB::table('payment_attempts')->where('reference', $reference)->where('account_id', $account->id)->where('provider', 'infinitepay')->firstOrFail();
            $hints = ['transaction_nsu' => $data['transaction_nsu'] ?? null, 'slug' => $data['invoice_slug'] ?? $data['slug'] ?? null, 'adverse' => in_array($payload['event'] ?? $payload['type'] ?? '', ['refunded', 'canceled'], true)];
        } else {
            abort_unless($provider === 'p', 404);
            $reference = $payload['reference_id'] ?? null;
            $external = $payload['id'] ?? null;
            abort_unless(is_string($reference) && Str::isUuid($reference) && is_string($external), 400);
            $attempt = DB::table('payment_attempts')->where('provider', 'pagbank')->where('reference', $reference)->firstOrFail();
            $headers = ['x-payload-signature' => $request->header('x-payload-signature', ''), 'x-authenticity-token' => $request->header('x-authenticity-token', '')];
            abort_unless(app(PaymentGateway::class)->authenticPagbank($attempt, $request->getContent(), $headers), 401);
            $hints = preg_match('/^ORDE_[A-Za-z0-9-]{5,100}$/D', $external) ? ['order_id' => $external] : [];
        }
        app(OnlinePayment::class)->hints($attempt, $hints, true);

        return response()->json(['received' => true]);
    }
}
