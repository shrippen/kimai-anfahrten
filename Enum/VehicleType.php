<?php

namespace KimaiPlugin\MileageBundle\Enum;

enum VehicleType: string
{
    case OWN_CAR = 'own_car';
    case RENTAL_CAR = 'rental_car';
    case COMPANY_CAR = 'company_car';
    case MOTORCYCLE = 'motorcycle';
    case BICYCLE = 'bicycle';
    case PUBLIC_TRANSPORT = 'public_transport';
    case OTHER = 'other';

    public function label(): string
    {
        return 'trip.vehicle.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::OWN_CAR => 'fas fa-car',
            self::RENTAL_CAR => 'fas fa-car-side',
            self::COMPANY_CAR => 'fas fa-building',
            self::MOTORCYCLE => 'fas fa-motorcycle',
            self::BICYCLE => 'fas fa-bicycle',
            self::PUBLIC_TRANSPORT => 'fas fa-train',
            self::OTHER => 'fas fa-route',
        };
    }

    /**
     * Business trips: vehicles reimbursed with a flat rate per km
     * (Wegstreckenentschädigung, § 9 Abs. 1 Satz 3 Nr. 4a EStG / BRKG).
     * All other vehicles are deducted with their actual costs.
     */
    public function hasKilometerAllowance(): bool
    {
        return $this === self::OWN_CAR || $this === self::MOTORCYCLE;
    }

    /**
     * Commute: the 4,500 € annual cap of the Entfernungspauschale does not apply
     * to an own or provided car (§ 9 Abs. 2 Satz 2 Nr. 4 EStG); a rental car is
     * treated as a car provided for use.
     */
    public function isCarForCommuteCap(): bool
    {
        return $this === self::OWN_CAR || $this === self::COMPANY_CAR || $this === self::RENTAL_CAR;
    }

    /** Actual costs are borne by the employer — nothing to deduct. */
    public function isEmployerProvided(): bool
    {
        return $this === self::COMPANY_CAR;
    }
}
