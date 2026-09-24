<?php

namespace KimaiPlugin\MileageBundle\Service;

final class DistanceResult
{
    public function __construct(
        public readonly float $distanceKm,
        public readonly int $pointCount,
        public readonly int $usedPointCount,
        public readonly ?GpsPoint $first = null,
        public readonly ?GpsPoint $last = null,
    ) {
    }
}
