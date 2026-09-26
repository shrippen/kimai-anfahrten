<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Part of a Dawarich track with the detected transportation mode
 * (walking, running, cycling, driving, bus, train, flying, boat, motorcycle, stationary, unknown).
 */
final class TransportSegment
{
    /**
     * @param int|null $distance metres as computed by Dawarich (null: not known)
     * @param list<GpsPoint> $path the segment's line (the GPS points Dawarich assigned to it, without times)
     */
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly string $mode,
        public readonly ?int $distance = null,
        public readonly array $path = [],
    ) {
    }

    public function overlap(int $from, int $to): int
    {
        return max(0, min($this->end, $to) - max($this->start, $from));
    }

    public function getDuration(): int
    {
        return max(0, $this->end - $this->start);
    }
}
