<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Sums the great-circle distance along a GPS track, dropping inaccurate
 * points and obvious GPS jumps so the result is usable as logbook value.
 */
class DistanceCalculator
{
    private const EARTH_RADIUS_KM = 6371.0088;

    /** Segments implying a higher speed are treated as GPS glitches. */
    private const MAX_SPEED_KMH = 300.0;

    /**
     * @param GpsPoint[] $points
     */
    public function calculate(array $points, ?int $maxAccuracy = null): DistanceResult
    {
        $total = \count($points);
        usort($points, static fn (GpsPoint $a, GpsPoint $b) => $a->timestamp <=> $b->timestamp);

        if ($maxAccuracy !== null && $maxAccuracy > 0) {
            $points = array_values(array_filter(
                $points,
                static fn (GpsPoint $p) => $p->accuracy === null || $p->accuracy <= $maxAccuracy
            ));
        }

        $distance = 0.0;
        $used = [];
        $previous = null;

        foreach ($points as $point) {
            if ($previous === null) {
                $previous = $point;
                $used[] = $point;
                continue;
            }

            $segment = $this->haversine($previous, $point);
            $hours = max(1, $point->timestamp - $previous->timestamp) / 3600;

            if ($segment / $hours > self::MAX_SPEED_KMH) {
                continue;
            }

            $distance += $segment;
            $previous = $point;
            $used[] = $point;
        }

        return new DistanceResult(
            round($distance, 1),
            $total,
            \count($used),
            $used[0] ?? null,
            $used !== [] ? $used[\count($used) - 1] : null,
        );
    }

    public function haversine(GpsPoint $a, GpsPoint $b): float
    {
        $lat1 = deg2rad($a->latitude);
        $lat2 = deg2rad($b->latitude);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($b->longitude - $a->longitude);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }
}
