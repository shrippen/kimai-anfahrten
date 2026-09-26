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
        $km = $calc->haversine(new GpsPoint(52.5200, 13.4050), new GpsPoint(53.5511, 9.9937));

        self::assertEqualsWithDelta(255.3, $km, 1.0);
    }

    public function testPathKm(): void
    {
        $calc = new DistanceCalculator();

        // 0.02° longitude at 52° N ≈ 1.37 km
        self::assertEqualsWithDelta(1.37, $calc->pathKm([new GpsPoint(52.0, 13.00), new GpsPoint(52.0, 13.01), new GpsPoint(52.0, 13.02)]), 0.01);
        self::assertSame(0.0, $calc->pathKm([new GpsPoint(52.0, 13.0)]));
        self::assertSame(0.0, $calc->pathKm([]));
    }
}
