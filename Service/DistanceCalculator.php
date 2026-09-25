<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Sums the great-circle distance along a GPS track, dropping inaccurate
 * points and obvious GPS jumps so the result is usable as logbook value.
 *
 * Jumps are segments faster than {@see MAX_SPEED_KMH}: the point is skipped and the next one is compared with
 * the last good point. That only works when the first point is good, so leading outliers are removed first
 * (see {@see dropLeadingOutliers()}).
 */
class DistanceCalculator
{
    private const EARTH_RADIUS_KM = 6371.0088;

    /** Segments implying a higher speed are treated as GPS glitches. */
    private const MAX_SPEED_KMH = 300.0;

    /**
     * A leading point is an outlier when it is more than {@see LEADING_OUTLIER_KM} away from most of the
     * following points and reaching them would need more than {@see LEADING_MAX_SPEED_KMH} on average — e.g. a
     * stale or network fix before the GPS lock. No trip starts with 200 km/h; the limit is lower than
     * {@see MAX_SPEED_KMH} because the stale fix is often some minutes older than the track, which lowers
     * the implied speed. Closer jumps are left alone: GPS noise of a few hundred metres within seconds looks
     * fast, too.
     */
    private const LEADING_OUTLIER_KM = 1.0;
    private const LEADING_MAX_SPEED_KMH = 200.0;

    /** Number of following points the start is compared with (the "following cluster"). */
    private const LEADING_CLUSTER = 4;

    /** At most this many points are dropped at the start. */
    private const MAX_LEADING_OUTLIERS = 10;

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

        $points = $this->dropLeadingOutliers($points);

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

    /**
     * Drops points at the start of the track that do not fit to the points after them: a point is dropped
     * while it is implausible (farther than {@see LEADING_OUTLIER_KM} and faster than {@see LEADING_MAX_SPEED_KMH})
     * compared with more than half of the next {@see LEADING_CLUSTER} points. A glitch right after a good start
     * point is not affected here; the jump filter of {@see calculate()} skips it.
     *
     * @param list<GpsPoint> $points sorted by time
     * @return list<GpsPoint>
     */
    public function dropLeadingOutliers(array $points): array
    {
        $start = 0;
        while ($start < self::MAX_LEADING_OUTLIERS && $this->isLeadingOutlier($points, $start)) {
            $start++;
        }

        return \array_slice($points, $start);
    }

    /**
     * @param list<GpsPoint> $points
     */
    private function isLeadingOutlier(array $points, int $index): bool
    {
        $cluster = \array_slice($points, $index + 1, self::LEADING_CLUSTER);
        // with fewer than two following points there is no cluster to compare with
        if (\count($cluster) < 2) {
            return false;
        }
        $implausible = \count(array_filter($cluster, fn (GpsPoint $point) => $this->isLeadingJump($points[$index], $point)));

        return $implausible * 2 > \count($cluster);
    }

    private function isLeadingJump(GpsPoint $a, GpsPoint $b): bool
    {
        $km = $this->haversine($a, $b);
        $hours = max(1, abs($b->timestamp - $a->timestamp)) / 3600;

        return $km > self::LEADING_OUTLIER_KM && $km / $hours > self::LEADING_MAX_SPEED_KMH;
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
