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
        if ($hours === null) {
            return true;
        }
        $local = Carbon::instance($at ?? now())->setTimezone($store->timezone);
        foreach ([$local->copy()->startOfDay(), $local->copy()->subDay()->startOfDay()] as $day) {
            $window = $hours[$day->dayOfWeek] ?? null;
            if (! $window || ! ($window['enabled'] ?? false)) {
                continue;
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
        }

        return false;
    }

    public function todayLabel(object $store): string
    {
        $hours = json_decode($store->weekly_hours ?? 'null', true);
        if ($hours === null) {
            return 'Abertura e pausa manual';
        }
        $window = $hours[now()->timezone($store->timezone)->dayOfWeek] ?? null;
        if (! ($window['enabled'] ?? false)) {
            return 'Sem atendimento hoje';
        }
        $label = $window['opens_at'].' às '.$window['closes_at'];
        if (! empty($window['second_opens_at']) && ! empty($window['second_closes_at'])) {
            $label .= ' · '.$window['second_opens_at'].' às '.$window['second_closes_at'];
        }

        return $label;
    }
}
