<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * "Fahrt erfassen" at a timesheet used to store the whole day as departure and arrival (00:00–23:59 in the user's
 * timezone). Those are no real times; migration Version20261003000000 clears them.
 */
final class PlaceholderTimes
{
    public static function isPlaceholder(\DateTimeInterface $departure, \DateTimeInterface $arrival, \DateTimeZone $timezone): bool
    {
        $departure = \DateTimeImmutable::createFromInterface($departure)->setTimezone($timezone);
        $arrival = \DateTimeImmutable::createFromInterface($arrival)->setTimezone($timezone);

        return $departure->format('H:i:s') === '00:00:00' && $arrival->format('H:i:s') === '23:59:00'
            && $departure->format('Y-m-d') === $arrival->format('Y-m-d');
    }
}
