<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Part of a Dawarich track with the detected transportation mode
 * (walking, running, cycling, driving, bus, train, flying, boat, motorcycle, stationary, unknown).
 */
final class TransportSegment
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly string $mode,
    ) {
    }

    public function overlap(int $from, int $to): int
    {
        return max(0, min($this->end, $to) - max($this->start, $from));
    }
}
