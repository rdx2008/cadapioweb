<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class DeliveryConfirmation
{
    public function generate(): string
    {
        return Crypt::encryptString(str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT));
    }

    public function forCustomer(?object $ride): ?string
    {
        if (! $ride || ! $ride->confirmation_required || $ride->status !== 'collected' || ! $ride->confirmation_cipher) {
            return null;
        }
        try {
            return Crypt::decryptString($ride->confirmation_cipher);
        } catch (DecryptException) {
            return null;
        }
    }

    public function verify(object $ride, ?string $code, int $round): void
    {
        if (! $ride->confirmation_required) {
            return;
        }
        $expected = $this->forCustomer($ride);
        if (! $expected || ! $code || $round !== $ride->dispatch_round || ! hash_equals($expected, $code)) {
            throw ValidationException::withMessages(['delivery_code' => 'Código inválido ou de outra tentativa. Peça ao cliente o código atual no acompanhamento do pedido.']);
        }
    }
}
