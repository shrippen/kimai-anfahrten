<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * German rates per tax year. Always the year of the trip counts.
 *
 * Sources: § 9 Abs. 2 EStG (Entfernungspauschale, incl. Steueränderungsgesetz 2025),
 * § 9 Abs. 4a EStG (Verpflegungsmehraufwand), BRKG § 5 (Wegstreckenentschädigung).
 * A value set in the system settings overrides the built-in value for all years.
 */
class TaxRateSchedule
{
    /**
     * year => [commute first 20 km, commute from km 21]
     */
    private const COMMUTE = [
        2020 => [0.30, 0.30],
        2021 => [0.30, 0.35],
        2022 => [0.30, 0.38],
        2023 => [0.30, 0.38],
        2024 => [0.30, 0.38],
        2025 => [0.30, 0.38],
        2026 => [0.38, 0.38],
    ];

    public function __construct(private readonly MileageConfiguration $configuration)
    {
    }

    public function forYear(int $year): TaxRates
    {
        $years = array_keys(self::COMMUTE);
        $known = max(min($year, max($years)), min($years));
        [$first20, $from21] = self::COMMUTE[$known];

        $override = $this->configuration->getCommuteRateOverride();
        if ($override !== null) {
            $first20 = $from21 = $override;
        }

        return new TaxRates(
            $year,
            $first20,
            $from21,
            $this->configuration->getCommuteCap(),
            $this->configuration->getBusinessCarRate(),
            $this->configuration->getBusinessMotorcycleRate(),
            $this->configuration->getMealPartial(),
            $this->configuration->getMealFull(),
        );
    }
}
