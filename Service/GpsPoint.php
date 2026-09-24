<?php

namespace KimaiPlugin\MileageBundle\Service;

final class GpsPoint
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly int $timestamp,
        public readonly ?float $accuracy = null,
    ) {
    }
}
