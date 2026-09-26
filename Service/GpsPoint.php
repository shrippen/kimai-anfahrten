<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * A position, with the time it was recorded (0 when unknown).
 */
final class GpsPoint
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly int $timestamp = 0,
    ) {
    }
}
