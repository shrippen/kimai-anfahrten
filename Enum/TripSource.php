<?php

namespace KimaiPlugin\MileageBundle\Enum;

enum TripSource: string
{
    case MANUAL = 'manual';
    case DAWARICH = 'dawarich';

    public function label(): string
    {
        return 'mileage.trip.source.' . $this->value;
    }
}
