<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Rates of one tax year.
 */
final class TaxRates
{
    public function __construct(
        public readonly int $year,
        /** Entfernungspauschale per km for the first 20 km. */
        public readonly float $commuteRate,
        /** Entfernungspauschale per km from km 21 on. */
        public readonly float $commuteRateFrom21,
        public readonly float $commuteCap,
        public readonly float $businessCarRate,
        public readonly float $businessMotorcycleRate,
        /** Verpflegungsmehraufwand: more than 8 hours away, or day of arrival/departure. */
        public readonly float $mealPartial,
        /** Verpflegungsmehraufwand: full day (24 hours) away. */
        public readonly float $mealFull,
    ) {
    }

    /**
     * Entfernungspauschale for one working day and a one-way distance (full km only).
     */
    public function commuteAmount(float $distanceKm): float
    {
        $km = floor($distanceKm);

        return min($km, 20) * $this->commuteRate + max(0, $km - 20) * $this->commuteRateFrom21;
    }
}
