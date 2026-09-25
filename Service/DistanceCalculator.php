<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Great-circle distances (place matching, fallback for Dawarich segments without a distance).
 */
class DistanceCalculator
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public function haversine(GpsPoint $a, GpsPoint $b): float
    {
        $lat1 = deg2rad($a->latitude);
        $lat2 = deg2rad($b->latitude);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($b->longitude - $a->longitude);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }

    /**
     * Length of a line in km.
     *
     * @param list<GpsPoint> $path
     */
    public function pathKm(array $path): float
    {
        $km = 0.0;
        for ($i = 1, $n = \count($path); $i < $n; $i++) {
            $km += $this->haversine($path[$i - 1], $path[$i]);
        }

        return $km;
    }
}
