<?php

namespace KimaiPlugin\MileageBundle\Enum;

enum TaxProfile: string
{
    /** Selbstständig / Freiberufler: Betriebsausgaben in der EÜR. */
    case SELF_EMPLOYED = 'self_employed';
    /** Arbeitnehmer: Werbungskosten in Anlage N. */
    case EMPLOYEE = 'employee';

    public function label(): string
    {
        return 'mileage.tax.profile.' . $this->value;
    }
}
