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
            $start = $day->copy()->setTimeFromTimeString($window['opens_at']);
            $end = $day->copy()->setTimeFromTimeString($window['closes_at']);
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
