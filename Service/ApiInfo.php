<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\MonthLock;

/**
 * Discovery answer of GET /api/mileage/ping. Clients check "features" before using an addition to the API, so a
 * client works against an older plugin by leaving the missing feature out.
 */
final class ApiInfo
{
    // Bump together with composer.json's "version".
    public const PLUGIN_VERSION = '0.9.0';
    // The unprefixed /api/mileage endpoints are v1; an incompatible change would get a new prefix.
    public const API_VERSIONS = ['v1'];

    /**
     * Additions to v1 since its first release, in the order they were added:
     * tripTimesheet = "timesheet" on POST/PATCH trips, dateRange = from/to on GET trips and suggestions,
     * acceptFields = project/distanceKm/comment/timesheet on accepting a suggestion (and "timesheet" in its JSON),
     * commuteCheck = a commute without distance and without the profile's commute distance is a 400.
     */
    public const FEATURES = ['tripTimesheet', 'dateRange', 'acceptFields', 'commuteCheck'];

    /**
     * @param array<string, bool> $permissions of the token owner
     * @param array{commuteKm: ?float, defaultVehicle: string, defaultVehicleId: ?int, dawarichConfigured: bool}|null $profile null without the "mileage" permission
     * @param list<string> $lockedMonths "YYYY-MM"
     * @return array<string, mixed>
     */
    public static function ping(array $permissions, ?array $profile, array $lockedMonths): array
    {
        return [
            'installed' => true,
            'pluginVersion' => self::PLUGIN_VERSION,
            'apiVersions' => self::API_VERSIONS,
            'permissions' => $permissions,
            'features' => self::FEATURES,
            'profile' => $profile,
            'lockedMonths' => $lockedMonths,
        ];
    }

    /**
     * Locked months ("YYYY-MM", ascending) out of the month locks of some years.
     *
     * @param iterable<MonthLock> $locks
     * @return list<string>
     */
    public static function lockedMonths(iterable $locks): array
    {
        $months = [];
        foreach ($locks as $lock) {
            if ($lock->isLocked()) {
                $months[] = \sprintf('%04d-%02d', $lock->getYear(), $lock->getMonth());
            }
        }
        sort($months);

        return array_values(array_unique($months));
    }
}
