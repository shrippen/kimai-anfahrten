<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * Uses the transportation modes Dawarich detected for its tracks:
 * walks and bike rides are cut out of the GPS track, and the mode of a trip suggests the vehicle.
 */
class TransportModeFilter
{
    /** Modes that never are a trip for the tax return. */
    public const NON_MOTORIZED = ['walking', 'running', 'cycling'];

    /**
     * Removes points inside excluded segments and splits the track there, so a walk
     * neither becomes a trip of its own nor is glued onto the drive before or after it.
     *
     * @param GpsPoint[] $points
     * @param TransportSegment[] $segments
     * @param string[] $excludedModes
     * @return list<list<GpsPoint>>
     */
    public function split(array $points, array $segments, array $excludedModes = self::NON_MOTORIZED): array
    {
        $excluded = array_values(array_filter($segments, static fn (TransportSegment $s) => \in_array($s->mode, $excludedModes, true)));
        usort($points, static fn (GpsPoint $a, GpsPoint $b) => $a->timestamp <=> $b->timestamp);

        if ($excluded === []) {
            return $points === [] ? [] : [$points];
        }

        $chunks = [];
        $current = [];
        foreach ($points as $point) {
            $inside = false;
            foreach ($excluded as $segment) {
                if ($point->timestamp >= $segment->start && $point->timestamp <= $segment->end) {
                    $inside = true;
                    break;
                }
            }
            if ($inside) {
                if ($current !== []) {
                    $chunks[] = $current;
                    $current = [];
                }
                continue;
            }
            $current[] = $point;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Mode that covers most of the time window (ignoring stationary/unknown parts).
     *
     * @param TransportSegment[] $segments
     */
    public function dominantMode(array $segments, int $from, int $to): ?string
    {
        $durations = [];
        foreach ($segments as $segment) {
            if (\in_array($segment->mode, ['stationary', 'unknown'], true)) {
                continue;
            }
            $overlap = $segment->overlap($from, $to);
            if ($overlap > 0) {
                $durations[$segment->mode] = ($durations[$segment->mode] ?? 0) + $overlap;
            }
        }
        if ($durations === []) {
            return null;
        }
        arsort($durations);

        return (string) array_key_first($durations);
    }

    /**
     * Vehicle for a detected mode; null means "use the user's default vehicle".
     */
    public static function vehicleFor(?string $mode): ?VehicleType
    {
        return match ($mode) {
            'bus', 'train' => VehicleType::PUBLIC_TRANSPORT,
            'motorcycle' => VehicleType::MOTORCYCLE,
            'cycling' => VehicleType::BICYCLE,
            'flying', 'boat' => VehicleType::OTHER,
            default => null,
        };
    }
}
