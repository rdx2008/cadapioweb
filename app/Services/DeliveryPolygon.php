<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class DeliveryPolygon
{
    private const EPSILON = 0.0000000001;

    /** Match the straight edges displayed by the Web Mercator map. */
    private function project(array $point): array
    {
        return [rad2deg(log(tan(M_PI / 4 + deg2rad($point[0]) / 2))), $point[1]];
    }

    private function cross(array $a, array $b, array $p): float
    {
        return ($b[1] - $a[1]) * ($p[0] - $a[0]) - ($b[0] - $a[0]) * ($p[1] - $a[1]);
    }

    private function onSegment(array $a, array $b, array $p): bool
    {
        return abs($this->cross($a, $b, $p)) <= self::EPSILON
            && $p[0] >= min($a[0], $b[0]) - self::EPSILON && $p[0] <= max($a[0], $b[0]) + self::EPSILON
            && $p[1] >= min($a[1], $b[1]) - self::EPSILON && $p[1] <= max($a[1], $b[1]) + self::EPSILON;
    }

    private function intersects(array $a, array $b, array $c, array $d): bool
    {
        $abC = $this->cross($a, $b, $c);
        $abD = $this->cross($a, $b, $d);
        $cdA = $this->cross($c, $d, $a);
        $cdB = $this->cross($c, $d, $b);

        return ($abC * $abD < 0 && $cdA * $cdB < 0) || $this->onSegment($a, $b, $c) || $this->onSegment($a, $b, $d) || $this->onSegment($c, $d, $a) || $this->onSegment($c, $d, $b);
    }

    public function validate(array $points, object $store): array
    {
        $fail = fn () => throw ValidationException::withMessages(['polygon' => 'Desenhe uma área simples com 3 a 64 pontos, sem linhas cruzadas, a até 100 km da loja.']);
        if (count($points) < 3 || count($points) > 64 || $store->latitude === null || $store->longitude === null) {
            $fail();
        }
        $polygon = [];
        $seen = [];
        foreach ($points as $point) {
            if (! is_array($point) || count($point) !== 2 || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                $fail();
            }
            $lat = round((float) $point[0], 6);
            $lon = round((float) $point[1], 6);
            if (! is_finite($lat) || ! is_finite($lon) || abs($lat) > 85 || abs($lon) > 180) {
                $fail();
            }
            $a = sin(deg2rad($lat - $store->latitude) / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($store->latitude)) * sin(deg2rad($lon - $store->longitude) / 2) ** 2;
            if (6371000 * 2 * asin(sqrt(min(1, max(0, $a)))) > 100000 || abs($lon - $store->longitude) > 5 || isset($seen[$lat.':'.$lon])) {
                $fail();
            }
            $seen[$lat.':'.$lon] = true;
            $polygon[] = [$lat, $lon];
        }
        $count = count($polygon);
        $shape = array_map($this->project(...), $polygon);
        $origin = $this->project([(float) $store->latitude, (float) $store->longitude]);
        $area = 0;
        for ($i = 0; $i < $count; $i++) {
            $a = $shape[$i];
            $b = $shape[($i + 1) % $count];
            $area += ($a[1] - $origin[1]) * ($b[0] - $origin[0]) - ($b[1] - $origin[1]) * ($a[0] - $origin[0]);
            $previous = $shape[($i + $count - 1) % $count];
            if (abs($this->cross($previous, $a, $b)) <= self::EPSILON && ($a[0] - $previous[0]) * ($b[0] - $a[0]) + ($a[1] - $previous[1]) * ($b[1] - $a[1]) <= 0) {
                $fail();
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $count - 1)) {
                    continue;
                }
                if ($this->intersects($a, $b, $shape[$j], $shape[($j + 1) % $count])) {
                    $fail();
                }
            }
        }
        if (abs($area) * 111320 ** 2 * cos(deg2rad($store->latitude)) ** 2 / 2 < 25) {
            $fail();
        }

        return $polygon;
    }

    /** Boundary points are included; coordinates are latitude, longitude. */
    public function contains(array $polygon, float $latitude, float $longitude): bool
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 85 || abs($longitude) > 180 || count($polygon) < 3) {
            return false;
        }
        $inside = false;
        $polygon = array_map($this->project(...), $polygon);
        $point = $this->project([$latitude, $longitude]);
        $latitude = $point[0];
        $count = count($polygon);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $a = $polygon[$j];
            $b = $polygon[$i];
            if ($this->onSegment($a, $b, $point)) {
                return true;
            }
            if (($a[0] > $latitude) !== ($b[0] > $latitude) && $longitude < ($b[1] - $a[1]) * ($latitude - $a[0]) / ($b[0] - $a[0]) + $a[1]) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
