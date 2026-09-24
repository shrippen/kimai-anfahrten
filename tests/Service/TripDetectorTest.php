<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\GpsPoint;
use KimaiPlugin\MileageBundle\Service\TripDetector;
use PHPUnit\Framework\TestCase;

class TripDetectorTest extends TestCase
{
    private const HOME = [52.5200, 13.4050];
    private const OFFICE = [52.5000, 13.3000]; // ~7.5 km west
    private const CUSTOMER = [52.4000, 13.0600]; // Potsdam

    /**
     * @param array{float, float} $at
     * @return GpsPoint[]
     */
    private function stay(array $at, int $from, int $minutes): array
    {
        $points = [];
        for ($t = 0; $t <= $minutes; $t += 2) {
            // small jitter within a few metres
            $points[] = new GpsPoint($at[0] + (($t % 3) - 1) * 0.00002, $at[1], $from + $t * 60, 8);
        }

        return $points;
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @return GpsPoint[]
     */
    private function drive(array $a, array $b, int $from, int $minutes): array
    {
        $points = [];
        for ($s = 1; $s < $minutes; $s++) {
            $f = $s / $minutes;
            $points[] = new GpsPoint($a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f, $from + $s * 60, 10);
        }

        return $points;
    }

    private function detector(): TripDetector
    {
        return new TripDetector(new DistanceCalculator());
    }

    public function testDetectsTwoTripsBetweenThreeStops(): void
    {
        $t = 1_700_000_000;
        $points = array_merge(
            $this->stay(self::HOME, $t, 30),
            $this->drive(self::HOME, self::OFFICE, $t + 30 * 60, 20),
            $this->stay(self::OFFICE, $t + 50 * 60, 240),
            $this->drive(self::OFFICE, self::CUSTOMER, $t + 290 * 60, 30),
            $this->stay(self::CUSTOMER, $t + 320 * 60, 60),
        );
        shuffle($points);

        $trips = $this->detector()->detect($points);

        self::assertCount(2, $trips);
        self::assertEqualsWithDelta(7.5, $trips[0]->distanceKm, 0.6);
        self::assertEqualsWithDelta(18.3, $trips[1]->distanceKm, 1.5);
        self::assertLessThan($trips[1]->start->timestamp, $trips[0]->end->timestamp);
        self::assertEqualsWithDelta(self::HOME[0], $trips[0]->start->latitude, 0.002);
        self::assertEqualsWithDelta(self::OFFICE[1], $trips[0]->end->longitude, 0.003);
        // anchors are the stop centres, much closer than the edge points
        self::assertEqualsWithDelta(self::HOME[0], $trips[0]->getStartLocation()->latitude, 0.0002);
        self::assertEqualsWithDelta(self::HOME[1], $trips[0]->getStartLocation()->longitude, 0.0002);
        self::assertEqualsWithDelta(self::CUSTOMER[1], $trips[1]->getEndLocation()->longitude, 0.0002);
    }

    public function testShortWalksAreIgnored(): void
    {
        $t = 1_700_000_000;
        $points = array_merge(
            $this->stay(self::HOME, $t, 20),
            $this->drive(self::HOME, [52.5230, 13.4050], $t + 20 * 60, 6), // 330 m
            $this->stay([52.5230, 13.4050], $t + 26 * 60, 20),
        );

        self::assertSame([], $this->detector()->detect($points));
    }

    public function testTrackWithoutStopsIsOneTrip(): void
    {
        $points = $this->drive(self::HOME, self::CUSTOMER, 1_700_000_000, 40);

        $trips = $this->detector()->detect($points);

        self::assertCount(1, $trips);
        self::assertGreaterThan(20, $trips[0]->distanceKm);
    }

    public function testRecordingGapWhileParkedIsAStop(): void
    {
        $t = 1_700_000_000;
        $points = array_merge(
            $this->drive(self::HOME, self::OFFICE, $t, 20),
            [new GpsPoint(self::OFFICE[0], self::OFFICE[1], $t + 20 * 60), new GpsPoint(self::OFFICE[0], self::OFFICE[1], $t + 300 * 60)],
            $this->drive(self::OFFICE, self::HOME, $t + 300 * 60, 20),
        );

        self::assertCount(2, $this->detector()->detect($points));
    }

    public function testNotEnoughPoints(): void
    {
        self::assertSame([], $this->detector()->detect([new GpsPoint(1, 1, 1)]));
    }
}
