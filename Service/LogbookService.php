<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;

/**
 * Fahrtenbuch checks: odometer continuity and consistency with the trip distance.
 */
class LogbookService
{
    /** Allowed difference between odometer delta and recorded distance. */
    private const TOLERANCE = 0.1;

    /**
     * @param Trip[] $trips trips of one vehicle, any order
     * @return array{
     *     rows: list<array{trip: Trip, warnings: list<string>}>,
     *     gaps: list<array{after: ?Trip, before: Trip, km: int}>,
     *     km: array{business: float, commute: float, private: float, unrecorded: int},
     *     odometer_start: ?int,
     *     odometer_end: ?int
     * }
     */
    public function analyse(Vehicle $vehicle, array $trips, ?int $startOdometer = null): array
    {
        usort($trips, static function (Trip $a, Trip $b): int {
            return [$a->getDate()?->format('Y-m-d'), $a->getOdometerStart() ?? PHP_INT_MAX, $a->getDepartureAt()?->getTimestamp(), $a->getId()]
                <=> [$b->getDate()?->format('Y-m-d'), $b->getOdometerStart() ?? PHP_INT_MAX, $b->getDepartureAt()?->getTimestamp(), $b->getId()];
        });

        $rows = [];
        $gaps = [];
        $km = ['business' => 0.0, 'commute' => 0.0, 'private' => 0.0, 'unrecorded' => 0];
        // For a later year the odometer continues from the previous year's last trip.
        $previousEnd = $startOdometer ?? $vehicle->getInitialOdometer();
        $previousTrip = null;
        $first = null;
        $last = null;

        foreach ($trips as $trip) {
            $warnings = [];
            $start = $trip->getOdometerStart();
            $end = $trip->getOdometerEnd();

            if ($start === null || $end === null) {
                $warnings[] = 'logbook.warning.odometer_missing';
            } else {
                $first ??= $start;
                $last = $end;
                if ($previousEnd !== null && $start !== $previousEnd) {
                    $diff = $start - $previousEnd;
                    $gaps[] = ['after' => $previousTrip, 'before' => $trip, 'km' => $diff];
                    $warnings[] = $diff < 0 ? 'logbook.warning.odometer_overlap' : 'logbook.warning.odometer_gap';
                    if ($diff > 0) {
                        $km['unrecorded'] += $diff;
                    }
                }
                $delta = $end - $start;
                $distance = $trip->getTotalDistanceKm();
                if ($distance > 0 && abs($delta - $distance) > max(2, $distance * self::TOLERANCE)) {
                    $warnings[] = 'logbook.warning.distance_mismatch';
                }
                $previousEnd = $end;
            }

            if ($trip->getPurpose() === TripPurpose::BUSINESS && $trip->getComment() === null && $trip->getProject() === null) {
                $warnings[] = 'logbook.warning.purpose_missing';
            }
            if ($trip->getDestination() === null || $trip->getDestination() === '') {
                $warnings[] = 'logbook.warning.destination_missing';
            }

            $driven = $start !== null && $end !== null ? (float) ($end - $start) : $trip->getTotalDistanceKm();
            $km[$trip->getPurpose()->value] += $driven;

            $rows[] = ['trip' => $trip, 'warnings' => $warnings];
            $previousTrip = $trip;
        }

        return [
            'rows' => $rows,
            'gaps' => $gaps,
            'km' => $km,
            'odometer_start' => $first,
            'odometer_end' => $last,
        ];
    }

    /**
     * Next odometer start: end of the latest earlier trip, else the vehicle's initial reading.
     *
     * @param Trip[] $trips trips of the vehicle
     */
    public function suggestOdometerStart(Vehicle $vehicle, array $trips, \DateTimeInterface $date): ?int
    {
        $best = null;
        $bestKey = null;
        foreach ($trips as $trip) {
            if ($trip->getOdometerEnd() === null || $trip->getDate() === null || $trip->getDate()->format('Y-m-d') > $date->format('Y-m-d')) {
                continue;
            }
            $key = $trip->getDate()->format('Y-m-d') . \sprintf('%012d', $trip->getOdometerEnd());
            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $trip->getOdometerEnd();
            }
        }

        return $best ?? $vehicle->getInitialOdometer();
    }
}
