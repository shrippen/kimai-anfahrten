<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Builds trips and driven distances from the tracks Dawarich computed, using its transportation-mode segments.
 *
 * A segment is driven unless its mode is excluded (walking, running, cycling by default) or it is a standstill of
 * at least the stop time (parked). Consecutive driven segments of a track form one trip; an excluded segment or a
 * long standstill ends it, so a walk between two drives gives two trips. Short standstills (traffic lights, jams)
 * stay part of the trip, a trip never starts or ends standing.
 *
 * Distances are Dawarich's segment distances (sum of the point-to-point distances of the segment's GPS points).
 * Segments without a distance fall back to the length of their line, and without a line to the track's distance
 * in proportion to the time.
 */
class TrackAnalyzer
{
    public function __construct(
        private readonly DistanceCalculator $distanceCalculator,
        private readonly TransportModeFilter $modeFilter,
    ) {
    }

    /**
     * @param string[] $excludedModes
     * @return list<DetectedTrip>
     */
    public function trips(DawarichTrack $track, array $excludedModes, int $stopSeconds, float $minKm): array
    {
        $trips = [];
        $current = [];
        $previous = null;
        foreach ($track->getSegments() as $segment) {
            $driven = $this->isDriven($segment, $excludedModes, $stopSeconds);
            // a recording gap as long as a stop also ends the trip
            if (!$driven || ($previous !== null && $segment->start - $previous->end >= $stopSeconds)) {
                $trips[] = $this->trip($track, $current, $minKm);
                $current = [];
            }
            if ($driven) {
                $current[] = $segment;
            }
            $previous = $segment;
        }
        $trips[] = $this->trip($track, $current, $minKm);

        return array_values(array_filter($trips));
    }

    /**
     * Driven distance between $from and $to. A segment that only partly overlaps the window counts in proportion
     * to the overlapping time (segments have one mode each, so the speed within one is roughly even).
     *
     * @param DawarichTrack[] $tracks
     * @param string[] $excludedModes
     */
    public function measure(array $tracks, int $from, int $to, array $excludedModes, int $stopSeconds): DistanceResult
    {
        $km = 0.0;
        $segments = 0;
        $points = 0;
        foreach ($tracks as $track) {
            foreach ($track->getSegments() as $segment) {
                $share = $this->share($segment, $from, $to);
                if ($share <= 0 || !$this->isDriven($segment, $excludedModes, $stopSeconds)) {
                    continue;
                }
                $km += $this->segmentKm($track, $segment) * $share;
                $points += (int) round(\count($segment->path) * $share);
                $segments++;
            }
        }

        return new DistanceResult(round($km, 1), $segments, $points);
    }

    /**
     * Lines of the driven segments touching the window (for the map), consecutive segments joined.
     *
     * @param DawarichTrack[] $tracks
     * @param string[] $excludedModes
     * @return list<list<GpsPoint>>
     */
    public function lines(array $tracks, int $from, int $to, array $excludedModes, int $stopSeconds): array
    {
        $lines = [];
        foreach ($tracks as $track) {
            $line = [];
            foreach ($track->getSegments() as $segment) {
                if ($this->share($segment, $from, $to) <= 0 || !$this->isDriven($segment, $excludedModes, $stopSeconds)) {
                    $lines[] = $line;
                    $line = [];
                    continue;
                }
                array_push($line, ...$segment->path);
            }
            $lines[] = $line;
        }

        return array_values(array_filter($lines, static fn (array $line) => \count($line) > 1));
    }

    /**
     * @param string[] $excludedModes
     */
    public function isDriven(TransportSegment $segment, array $excludedModes, int $stopSeconds): bool
    {
        return !\in_array($segment->mode, $excludedModes, true)
            && !($segment->mode === 'stationary' && $segment->getDuration() >= $stopSeconds);
    }

    /**
     * Part of the segment inside the window (0…1).
     */
    private function share(TransportSegment $segment, int $from, int $to): float
    {
        $duration = $segment->getDuration();
        if ($duration === 0) {
            return $segment->start >= $from && $segment->start <= $to ? 1.0 : 0.0;
        }

        return $segment->overlap($from, $to) / $duration;
    }

    /**
     * @param list<TransportSegment> $segments
     */
    private function trip(DawarichTrack $track, array $segments, float $minKm): ?DetectedTrip
    {
        while ($segments !== [] && $segments[0]->mode === 'stationary') {
            array_shift($segments);
        }
        while ($segments !== [] && $segments[\count($segments) - 1]->mode === 'stationary') {
            array_pop($segments);
        }
        if ($segments === []) {
            return null;
        }

        $km = 0.0;
        $points = 0;
        foreach ($segments as $segment) {
            $km += $this->segmentKm($track, $segment);
            $points += \count($segment->path);
        }
        $first = $segments[0];
        $last = $segments[\count($segments) - 1];
        $start = $this->locate($track, $first, $first->start, true);
        $end = $this->locate($track, $last, $last->end, false);
        if ($km < $minKm || $start === null || $end === null) {
            return null;
        }

        return new DetectedTrip($start, $end, round($km, 1), $points, mode: $this->modeFilter->dominantMode($segments, $first->start, $last->end));
    }

    private function segmentKm(DawarichTrack $track, TransportSegment $segment): float
    {
        if ($segment->distance !== null) {
            return $segment->distance / 1000;
        }
        if (\count($segment->path) > 1) {
            return $this->distanceCalculator->pathKm($segment->path);
        }
        $duration = $track->end - $track->start;

        return $duration > 0 ? $track->distance / 1000 * $segment->getDuration() / $duration : 0.0;
    }

    /**
     * Where the trip starts or ends: the first/last point of the segment's line, otherwise the point of the track's
     * line at the same share of the time.
     */
    private function locate(DawarichTrack $track, TransportSegment $segment, int $time, bool $atStart): ?GpsPoint
    {
        if ($segment->path !== []) {
            $point = $atStart ? $segment->path[0] : $segment->path[\count($segment->path) - 1];
        } elseif ($track->path !== []) {
            $share = $track->end > $track->start ? ($time - $track->start) / ($track->end - $track->start) : 0.0;
            $point = $track->path[(int) round(max(0.0, min(1.0, $share)) * (\count($track->path) - 1))];
        } else {
            return null;
        }

        return new GpsPoint($point->latitude, $point->longitude, $time);
    }
}
