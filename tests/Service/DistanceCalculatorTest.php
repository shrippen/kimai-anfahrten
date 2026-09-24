<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\GpsPoint;
use PHPUnit\Framework\TestCase;

class DistanceCalculatorTest extends TestCase
{
    public function testHaversineBerlinHamburg(): void
    {
        $calc = new DistanceCalculator();
        $km = $calc->haversine(new GpsPoint(52.5200, 13.4050, 0), new GpsPoint(53.5511, 9.9937, 0));

        self::assertEqualsWithDelta(255.3, $km, 1.0);
    }

    public function testEmptyTrack(): void
    {
        $result = (new DistanceCalculator())->calculate([]);

        self::assertSame(0.0, $result->distanceKm);
        self::assertSame(0, $result->pointCount);
        self::assertNull($result->first);
    }

    public function testSortsByTimestampAndSums(): void
    {
        $points = [
            new GpsPoint(52.0, 13.02, 1200),
            new GpsPoint(52.0, 13.00, 0),
            new GpsPoint(52.0, 13.01, 600),
        ];

        $result = (new DistanceCalculator())->calculate($points);

        // 0.02° longitude at 52° N ≈ 1.37 km
        self::assertEqualsWithDelta(1.4, $result->distanceKm, 0.05);
        self::assertSame(0, $result->first?->timestamp);
        self::assertSame(1200, $result->last?->timestamp);
    }

    public function testDropsInaccuratePoints(): void
    {
        $points = [
            new GpsPoint(52.0, 13.00, 0, 10),
            new GpsPoint(52.5, 13.00, 60, 5000), // far away but inaccurate
            new GpsPoint(52.0, 13.01, 600, 10),
        ];

        $result = (new DistanceCalculator())->calculate($points, 100);

        self::assertSame(3, $result->pointCount);
        self::assertSame(2, $result->usedPointCount);
        self::assertLessThan(1.0, $result->distanceKm);
    }

    public function testDropsGpsJumps(): void
    {
        $points = [
            new GpsPoint(52.0, 13.00, 0),
            new GpsPoint(0.0, 0.0, 10), // would need thousands of km/h
            new GpsPoint(52.0, 13.01, 600),
        ];

        $result = (new DistanceCalculator())->calculate($points);

        self::assertSame(2, $result->usedPointCount);
        self::assertLessThan(1.0, $result->distanceKm);
    }
}
