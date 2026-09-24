<?php

namespace KimaiPlugin\MileageBundle\Tests\Entity;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use PHPUnit\Framework\TestCase;

class TripTest extends TestCase
{
    public function testCommuteCountsBothWays(): void
    {
        $trip = (new Trip())->setPurpose(TripPurpose::COMMUTE)->setDistanceKm(20);

        self::assertSame(40.0, $trip->getTotalDistanceKm());
    }

    public function testRoundTripDoubles(): void
    {
        $trip = (new Trip())->setPurpose(TripPurpose::BUSINESS)->setDistanceKm(15)->setRoundTrip(true);

        self::assertSame(30.0, $trip->getTotalDistanceKm());
        self::assertSame(15.0, $trip->setRoundTrip(false)->getTotalDistanceKm());
    }

    public function testDateIsNormalizedToMidnight(): void
    {
        $trip = (new Trip())->setDate(new \DateTimeImmutable('2026-05-05 17:45'));

        self::assertSame('2026-05-05 00:00', $trip->getDate()?->format('Y-m-d H:i'));
    }
}
