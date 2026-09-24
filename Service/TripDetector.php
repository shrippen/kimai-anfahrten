<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Splits a GPS track into stops and the movements between them (stay-point detection).
 *
 * A stop is a sequence of points that stays within {@see $stopRadiusMeters} for at least
 * {@see $minStopMinutes}. Everything between two stops is a trip; short hops (walking
 * around a building, GPS drift) are dropped by {@see $minTripKm}.
 */
class TripDetector
{
    public function __construct(
        private readonly DistanceCalculator $distanceCalculator,
    ) {
    }

    /**
     * @param GpsPoint[] $points
     * @return DetectedTrip[]
     */
    public function detect(
        array $points,
        float $stopRadiusMeters = 200.0,
        int $minStopMinutes = 5,
        float $minTripKm = 1.0,
        ?int $maxAccuracy = null,
    ): array {
        if ($maxAccuracy !== null && $maxAccuracy > 0) {
            $points = array_filter($points, static fn (GpsPoint $p) => $p->accuracy === null || $p->accuracy <= $maxAccuracy);
        }
        usort($points, static fn (GpsPoint $a, GpsPoint $b) => $a->timestamp <=> $b->timestamp);
        $count = \count($points);

        if ($count < 2) {
            return [];
        }

        $radiusKm = $stopRadiusMeters / 1000;
        $minStopSeconds = $minStopMinutes * 60;

        /** @var array<int, array{0: int, 1: int}> $stops index ranges [first, last] */
        $stops = [];
        $i = 0;
        while ($i < $count) {
            $j = $i + 1;
            while ($j < $count && $this->distanceCalculator->haversine($points[$i], $points[$j]) <= $radiusKm) {
                $j++;
            }
            $last = $j - 1;

            // A gap in the recording inside the radius also counts as a stop (phone asleep while parked).
            if ($points[$last]->timestamp - $points[$i]->timestamp >= $minStopSeconds) {
                $stops[] = [$i, $last];
                $i = $last + 1;
            } else {
                $i++;
            }
        }

        // Track start and end are boundaries even without a detected stop.
        if ($stops === [] || $stops[0][0] > 0) {
            array_unshift($stops, [0, 0]);
        }
        if ($stops[\count($stops) - 1][1] < $count - 1) {
            $stops[] = [$count - 1, $count - 1];
        }

        $trips = [];
        for ($k = 1, $n = \count($stops); $k < $n; $k++) {
            $from = $stops[$k - 1][1];
            $to = $stops[$k][0];
            if ($to <= $from) {
                continue;
            }

            $segment = \array_slice($points, $from, $to - $from + 1);
            $result = $this->distanceCalculator->calculate($segment);

            if ($result->distanceKm < $minTripKm || $result->first === null || $result->last === null) {
                continue;
            }

            $trips[] = new DetectedTrip(
                $result->first,
                $result->last,
                $result->distanceKm,
                $result->pointCount,
                $this->centroid(\array_slice($points, $stops[$k - 1][0], $stops[$k - 1][1] - $stops[$k - 1][0] + 1)),
                $this->centroid(\array_slice($points, $stops[$k][0], $stops[$k][1] - $stops[$k][0] + 1)),
            );
        }

        return $trips;
    }

    /**
     * @param GpsPoint[] $points
     */
    private function centroid(array $points): GpsPoint
    {
        $lat = 0.0;
        $lon = 0.0;
        foreach ($points as $point) {
            $lat += $point->latitude;
            $lon += $point->longitude;
        }
        $n = \count($points);

        return new GpsPoint($lat / $n, $lon / $n, $points[0]->timestamp);
    }
}
