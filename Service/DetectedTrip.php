<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * A movement between two stops, found in the GPS history.
 */
final class DetectedTrip
{
    /**
     * @param GpsPoint|null $startAnchor centre of the stop the trip left (better for place matching than the edge point)
     * @param GpsPoint|null $endAnchor centre of the stop the trip arrived at
     */
    public function __construct(
        public readonly GpsPoint $start,
        public readonly GpsPoint $end,
        public readonly float $distanceKm,
        public readonly int $pointCount,
        public readonly ?GpsPoint $startAnchor = null,
        public readonly ?GpsPoint $endAnchor = null,
    ) {
    }

    public function getStartLocation(): GpsPoint
    {
        return $this->startAnchor ?? $this->start;
    }

    public function getEndLocation(): GpsPoint
    {
        return $this->endAnchor ?? $this->end;
    }

    public function getStartAt(\DateTimeZone $timezone): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->start->timestamp))->setTimezone($timezone);
    }

    public function getEndAt(\DateTimeZone $timezone): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->end->timestamp))->setTimezone($timezone);
    }

    public function getDurationMinutes(): int
    {
        return (int) round(($this->end->timestamp - $this->start->timestamp) / 60);
    }
}
