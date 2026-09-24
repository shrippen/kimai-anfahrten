<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Enum\TaxProfile;
use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * System settings (rates, Dawarich defaults) and per-user preferences.
 *
 * Per-year rates live in {@see TaxRateSchedule}; the settings here are the
 * rates that did not change for years (and an optional commute override).
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
    public const PREF_TAX_PROFILE = 'mileage_tax_profile';

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    /**
     * Only set when the admin deliberately deviates from the rates of {@see TaxRateSchedule}.
     */
    public function getCommuteRateOverride(): ?float
    {
        $value = $this->configuration->find('mileage.rate_commute');

        return $value === null || $value === '' ? null : (float) str_replace(',', '.', (string) $value);
    }

    public function isApprovalEnabled(): bool
    {
        return (bool) ($this->configuration->find('mileage.approval_enabled') ?? false);
    }

    public function getMealPartial(): float
    {
        return $this->float('mileage.meal_partial', 14.0);
    }

    public function getMealFull(): float
    {
        return $this->float('mileage.meal_full', 28.0);
    }

    public function getTaxProfile(User $user): TaxProfile
    {
        return TaxProfile::tryFrom((string) $user->getPreferenceValue(self::PREF_TAX_PROFILE, '')) ?? TaxProfile::SELF_EMPLOYED;
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

    public function getGeocoderUrl(): ?string
    {
        $url = $this->nonEmpty($this->configuration->find('mileage.geocoder_url'));

        return $url !== null ? rtrim($url, '/') : null;
    }

    /**
     * Tile server for the map previews; an empty setting disables all maps.
     */
    public function getMapTilesUrl(): ?string
    {
        $value = $this->configuration->find('mileage.map_tiles_url');
        if ($value === null) {
            return 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
        }

        return $this->nonEmpty($value);
    }

    public function getMapAttribution(): string
    {
        return $this->nonEmpty($this->configuration->find('mileage.map_attribution')) ?? '© OpenStreetMap contributors';
    }

    public function getDetectStopMinutes(): int
    {
        return max(1, (int) $this->float('mileage.detect_stop_minutes', 5));
    }

    public function getDetectMinKm(): float
    {
        return max(0.1, $this->float('mileage.detect_min_km', 1.0));
    }

    public function getDetectStopRadius(): float
    {
        return max(20.0, $this->float('mileage.detect_stop_radius', 200));
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
