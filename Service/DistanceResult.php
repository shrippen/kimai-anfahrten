<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Driven distance in a time window, from the Dawarich track segments that overlap it.
 */
final class DistanceResult
{
    public function __construct(
        public readonly float $distanceKm,
        /** Number of (motorized) segments that were counted. */
        public readonly int $segmentCount,
        /** GPS points of those segments (length of their paths), stored as the trip's point count. */
        public readonly int $pointCount,
    ) {
    }
}
