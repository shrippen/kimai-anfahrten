<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * System settings (rates, Dawarich defaults) and per-user preferences.
 *
 * Default rates reflect German tax law as of 2026:
 * - Entfernungspauschale 0,38 €/km from the first km (Steueränderungsgesetz 2025)
 * - Dienstreise with own car 0,30 €/km, motorcycle/scooter 0,20 €/km (BRKG)
 * - annual cap of 4.500 € for commutes not done by car
 */
class MileageConfiguration
{
    public const PREF_DAWARICH_URL = 'mileage_dawarich_url';
    public const PREF_DAWARICH_API_KEY = 'mileage_dawarich_api_key';
    public const PREF_HOME_ADDRESS = 'mileage_home_address';
    public const PREF_WORK_ADDRESS = 'mileage_work_address';
    public const PREF_COMMUTE_KM = 'mileage_commute_km';
    public const PREF_DEFAULT_VEHICLE = 'mileage_default_vehicle';
    public const PREF_LICENSE_PLATE = 'mileage_license_plate';

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    public function getCommuteRate(): float
    {
        return $this->float('mileage.rate_commute', 0.38);
    }

    public function getBusinessCarRate(): float
    {
        return $this->float('mileage.rate_business_car', 0.30);
    }

    public function getBusinessMotorcycleRate(): float
    {
        return $this->float('mileage.rate_business_motorcycle', 0.20);
    }

    public function getCommuteCap(): float
    {
        return $this->float('mileage.commute_cap', 4500.0);
    }

    /** GPS points with a worse accuracy (in metres) are ignored for distance calculation. */
    public function getMaxAccuracy(): int
    {
        return (int) $this->float('mileage.dawarich_max_accuracy', 100);
    }

    public function getDawarichUrl(User $user): ?string
    {
        $url = $this->userString($user, self::PREF_DAWARICH_URL)
            ?? $this->nonEmpty($this->configuration->find('mileage.dawarich_url'));

        return $url !== null ? rtrim($url, '/') : null;
    }

    public function getDawarichApiKey(User $user): ?string
    {
        return $this->userString($user, self::PREF_DAWARICH_API_KEY);
    }

    public function isDawarichConfigured(User $user): bool
    {
        return $this->getDawarichUrl($user) !== null && $this->getDawarichApiKey($user) !== null;
    }

    public function getHomeAddress(User $user): ?string
    {
        return $this->userString($user, self::PREF_HOME_ADDRESS);
    }

    public function getWorkAddress(User $user): ?string
    {
        return $this->userString($user, self::PREF_WORK_ADDRESS);
    }

    public function getCommuteKm(User $user): ?float
    {
        $value = $this->userString($user, self::PREF_COMMUTE_KM);

        return $value !== null ? (float) str_replace(',', '.', $value) : null;
    }

    public function getDefaultVehicle(User $user): VehicleType
    {
        return VehicleType::tryFrom((string) $user->getPreferenceValue(self::PREF_DEFAULT_VEHICLE, '')) ?? VehicleType::OWN_CAR;
    }

    public function getLicensePlate(User $user): ?string
    {
        return $this->userString($user, self::PREF_LICENSE_PLATE);
    }

    private function float(string $key, float $default): float
    {
        $value = $this->configuration->find($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    private function userString(User $user, string $name): ?string
    {
        return $this->nonEmpty($user->getPreferenceValue($name));
    }

    private function nonEmpty(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
