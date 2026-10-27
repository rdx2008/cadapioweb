<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OrderScheduling
{
    private array $openMinutes = [];

    public function slots(object $store): array
    {
        $this->openMinutes = [];
        if (! $store->open || ! $store->scheduling_enabled) {
            return [];
        }
        $slots = [];
        $start = now()->timezone($store->timezone)->startOfMinute();
        $start->addMinutes(15 - $start->minute % 15);
        $end = now()->addDays($store->scheduling_days);
        for ($at = $start; $at->lte($end); $at->addMinutes(15)) {
            if ($this->allowed($store, $at)) {
                $slots[$at->format('Y-m-d\TH:i')] = $at->format('d/m H:i');
            }
        }

        return $slots;
    }

    private function allowed(object $store, Carbon $at): bool
    {
        if (! $store->open || ! $store->scheduling_enabled || $at->lt(now()->addMinutes($store->scheduling_lead_minutes)) || $at->gt(now()->addDays($store->scheduling_days)) || $at->minute % 15 !== 0) {
            return false;
        }
        $availability = app(StoreAvailability::class);
        for ($cursor = $at->copy()->subMinutes($store->scheduling_prep_minutes); $cursor->lte($at); $cursor->addMinute()) {
            $key = $cursor->timestamp;
            $this->openMinutes[$key] ??= $availability->isOpen($store, $cursor);
            if (! $this->openMinutes[$key]) {
                return false;
            }
        }

        return true;
    }

    public function quote(object $store, ?string $value): array
    {
        $this->openMinutes = [];
        if (! $value) {
            if (! app(StoreAvailability::class)->isOpen($store)) {
                throw ValidationException::withMessages(['cart' => 'Esta loja está fechada ou pausada. Escolha um horário disponível, se houver agendamento.']);
            }

            return ['scheduled_at' => null, 'prepare_at' => null, 'scheduled_timezone' => null];
        }
        try {
            $at = Carbon::createFromFormat('!Y-m-d\TH:i', $value, $store->timezone);
        } catch (\Throwable) {
            $at = null;
        }
        if (! $at || $at->format('Y-m-d\TH:i') !== $value || ! $this->allowed($store, $at)) {
            throw ValidationException::withMessages(['scheduled_for' => 'Este horário não está disponível. Escolha outro horário e revise o pedido.']);
        }

        return ['scheduled_at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'prepare_at' => $at->copy()->subMinutes($store->scheduling_prep_minutes)->utc()->format('Y-m-d H:i:s'), 'scheduled_timezone' => $store->timezone];
    }

    public function canPrepare(object $order): bool
    {
        return empty($order->prepare_at) || Carbon::parse($order->prepare_at, 'UTC')->lte(now());
    }

    public function label(object $order): ?string
    {
        return empty($order->scheduled_at) ? null : Carbon::parse($order->scheduled_at, 'UTC')->timezone($order->scheduled_timezone)->format('d/m H:i').' ('.$order->scheduled_timezone.')';
    }
}
