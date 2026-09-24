<?php

namespace KimaiPlugin\MileageBundle\Enum;

/**
 * Tax category of a trip (German income tax).
 */
enum TripPurpose: string
{
    /** Wohnung – erste Tätigkeitsstätte: Entfernungspauschale per workday on the one-way distance. */
    case COMMUTE = 'commute';
    /** Auswärtstätigkeit / Dienstreise: km-Pauschale or actual costs on the driven distance. */
    case BUSINESS = 'business';
    /** Private trip: logbook only, not deductible. */
    case PRIVATE = 'private';

    public function label(): string
    {
        return 'trip.purpose.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::COMMUTE => 'fas fa-house-laptop',
            self::BUSINESS => 'fas fa-briefcase',
            self::PRIVATE => 'fas fa-user',
        };
    }
}
