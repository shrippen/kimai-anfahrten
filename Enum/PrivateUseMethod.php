<?php

namespace KimaiPlugin\MileageBundle\Enum;

/**
 * How private use of a business vehicle is taxed (self-employed / company car).
 */
enum PrivateUseMethod: string
{
    /** No private use or vehicle is not business property. */
    case NONE = 'none';
    /** 1 % of the gross list price per month (§ 6 Abs. 1 Nr. 4 EStG). */
    case ONE_PERCENT = 'one_percent';
    /** Actual share from a proper logbook (Fahrtenbuchmethode). */
    case LOGBOOK = 'logbook';

    public function label(): string
    {
        return 'mileage.vehicle.private_use.' . $this->value;
    }
}
