<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CheckoutPayment
{
    public const LABELS = ['cash' => 'Dinheiro', 'card' => 'Cartão na entrega/retirada', 'pix' => 'Pix na entrega/retirada'];

    public function methods(object $store): array
    {
        $methods = array_filter(self::LABELS, fn ($key) => (bool) $store->{'accepts_'.$key}, ARRAY_FILTER_USE_KEY);
        foreach (app(OnlinePayment::class)->accounts($store->id) as $account) {
            $methods['online_'.$account->provider] = OnlinePayment::LABELS['online_'.$account->provider];
        }

        return $methods;
    }

    public function quote(object $store, array $draft, int $total): array
    {
        $method = $draft['payment_method'] ?? 'cash';
        if (! array_key_exists($method, $this->methods($store))) {
            throw ValidationException::withMessages(['payment_method' => 'Escolha uma forma de pagamento aceita pela loja.']);
        }
        $change = $draft['change_for_cents'] ?? null;
        if ($change !== null && ($method !== 'cash' || $change < $total || $change > 100000000)) {
            throw ValidationException::withMessages(['change_for' => 'Troco é permitido somente para dinheiro. Informe um valor igual ou maior que o total do pedido.']);
        }

        $account = str_starts_with($method, 'online_') ? app(OnlinePayment::class)->accounts($store->id)->firstWhere('provider', substr($method, 7)) : null;

        return ['payment_method' => $method, 'change_for_cents' => $change, 'payment_account_revision' => $account ? hash('sha256', ($account->provider === 'pagbank' ? $account->credential : '').'|'.$account->handle.'|'.$account->environment) : null];
    }
}
