<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * Uses the transportation modes Dawarich detected for its tracks: the mode of a trip suggests the vehicle.
 */
class TransportModeFilter
{
    /** Modes that never are a trip for the tax return (excluded by default, see {@see TrackAnalyzer}). */
    public const NON_MOTORIZED = ['walking', 'running', 'cycling'];

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
