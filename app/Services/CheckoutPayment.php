<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CheckoutPayment
{
    public const LABELS = ['cash' => 'Dinheiro', 'card' => 'Cartão na entrega/retirada', 'pix' => 'Pix na entrega/retirada'];

    public function methods(object $store): array
    {
        return array_filter(self::LABELS, fn ($key) => (bool) $store->{'accepts_'.$key}, ARRAY_FILTER_USE_KEY);
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

        return ['payment_method' => $method, 'change_for_cents' => $change];
    }
}
