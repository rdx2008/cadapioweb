<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class StoreAvailability
{
    public function isOpen(object $store, ?CarbonInterface $at = null): bool
    {
        if (! $store->open) {
            return false;
        }
        $hours = json_decode($store->weekly_hours ?? 'null', true);
        $exceptions = json_decode($store->special_hours ?? '{}', true);
        $local = Carbon::instance($at ?? now())->setTimezone($store->timezone);
        $today = $local->copy()->startOfDay();
        if (isset($exceptions[$today->toDateString()])) {
            return $this->withinWindow($exceptions[$today->toDateString()], $today, $local);
        }
        if ($hours === null) {
            return true;
        }
        foreach ([$today, $today->copy()->subDay()] as $day) {
            $window = $exceptions[$day->toDateString()] ?? $hours[$day->dayOfWeek] ?? [];
            if ($this->withinWindow($window, $day, $local)) {
                return true;
            }
        }

        return false;
    }

    public function todayLabel(object $store): string
    {
        $hours = json_decode($store->weekly_hours ?? 'null', true);
        $exceptions = json_decode($store->special_hours ?? '{}', true);
        $today = now()->timezone($store->timezone);
        $special = $exceptions[$today->toDateString()] ?? null;
        if ($special === null && $hours === null) {
            return 'Abertura e pausa manual';
        }
        $window = $special ?? $hours[$today->dayOfWeek] ?? null;
        if (! ($window['enabled'] ?? false)) {
            return $special !== null ? 'Fechado nesta data especial' : 'Sem atendimento hoje';
        }
        $label = $special !== null ? $this->periodLabel($window['opens_at'], $window['closes_at']) : $window['opens_at'].' às '.$window['closes_at'];
        if (! empty($window['second_opens_at']) && ! empty($window['second_closes_at'])) {
            $label .= ' · '.($special !== null ? $this->periodLabel($window['second_opens_at'], $window['second_closes_at']) : $window['second_opens_at'].' às '.$window['second_closes_at']);
        }

        return ($special !== null ? 'Horário especial: ' : '').$label;
    }

    private function periodLabel(string $opens, string $closes): string
    {
        return $opens.' às '.$closes.($closes <= $opens ? ' (dia seguinte)' : '');
    }

    private function withinWindow(array $window, CarbonInterface $day, CarbonInterface $local): bool
    {
        if (! ($window['enabled'] ?? false)) {
            return false;
        }
        $periods = [[$window['opens_at'], $window['closes_at']]];
        if (! empty($window['second_opens_at']) && ! empty($window['second_closes_at'])) {
            $periods[] = [$window['second_opens_at'], $window['second_closes_at']];
        }
        foreach ($periods as [$opens, $closes]) {
            $start = $day->copy()->setTimeFromTimeString($opens);
            $end = $day->copy()->setTimeFromTimeString($closes);
            if ($end->lte($start)) {
                $end->addDay();
            }
            if ($local->gte($start) && $local->lt($end)) {
                return true;
            }
        }

        return false;
    }
}
