<?php

namespace KimaiPlugin\MileageBundle\Enum;

enum PlaceType: string
{
    case HOME = 'home';
    /** Erste Tätigkeitsstätte / Betriebsstätte. */
    case WORK = 'work';
    case CUSTOMER = 'customer';
    case OTHER = 'other';

    public function label(): string
    {
        return 'mileage.place.type.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::HOME => 'fas fa-house',
            self::WORK => 'fas fa-building',
            self::CUSTOMER => 'fas fa-handshake',
            self::OTHER => 'fas fa-location-dot',
        };
    }
}
