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

    /**
     * Synthetic drive: 13 points every 30 s, 0.005° longitude apart (≈ 0.34 km at 52° N, ≈ 41 km/h), 4.1 km in total.
     *
     * @return list<GpsPoint>
     */
    private function drive(int $startTime = 100): array
    {
        $points = [];
        for ($i = 0; $i <= 12; $i++) {
            $points[] = new GpsPoint(52.0, 13.0 + $i * 0.005, $startTime + $i * 30);
        }

        return $points;
    }

    public function testLeadingOutlierIsDropped(): void
    {
        $clean = (new DistanceCalculator())->calculate($this->drive());

        // the first fix is a stale position 25 km away, 20 s before the track starts
        $track = [new GpsPoint(52.2, 13.2, 80), ...$this->drive()];
        $result = (new DistanceCalculator())->calculate($track);

        self::assertEqualsWithDelta(4.1, $clean->distanceKm, 0.1);
        self::assertSame($clean->distanceKm, $result->distanceKm);
        self::assertSame(14, $result->pointCount);
        self::assertSame(13, $result->usedPointCount);
        self::assertSame(100, $result->first?->timestamp);
    }

    public function testSeveralLeadingOutliersAreDropped(): void
    {
        // two fixes at the same wrong place (they agree with each other, not with the drive)
        $track = [new GpsPoint(48.1, 11.5, 40), new GpsPoint(48.1, 11.5, 70), ...$this->drive()];
        $result = (new DistanceCalculator())->calculate($track);

        self::assertEqualsWithDelta(4.1, $result->distanceKm, 0.1);
        self::assertSame(13, $result->usedPointCount);
    }

    public function testWithoutTheFilterTheJumpWouldBeCounted(): void
    {
        // the old behaviour: the first point was always kept, so the drive was dropped until enough time had
        // passed and then the jump itself was added (here: 25 km outlier, next points ≥ 5 minutes later)
        $track = [new GpsPoint(52.2, 13.2, 0)];
        foreach ($this->drive(300) as $point) {
            $track[] = $point;
        }
        $result = (new DistanceCalculator())->calculate($track);

        self::assertLessThan(5.0, $result->distanceKm);
        self::assertSame(300, $result->first?->timestamp);
    }

    public function testGoodStartIsKept(): void
    {
        $calc = new DistanceCalculator();

        // a glitch right after the start is handled by the jump filter, the start stays
        $drive = $this->drive();
        $track = [$drive[0], new GpsPoint(52.3, 13.3, 110), ...\array_slice($drive, 1)];
        self::assertSame(100, $calc->dropLeadingOutliers($track)[0]->timestamp);
        self::assertEqualsWithDelta(4.1, $calc->calculate($track)->distanceKm, 0.1);

        // GPS noise: 300 m off within 5 s is fast, but closer than the threshold
        $noisy = [new GpsPoint(52.0027, 13.0, 95), ...$drive];
        self::assertCount(14, $calc->dropLeadingOutliers($noisy));

        // a real, slow start far away (hours before) is not an outlier
        $parked = [new GpsPoint(52.2, 13.2, -36000), ...$drive];
        self::assertCount(14, $calc->dropLeadingOutliers($parked));

        // two points: nothing to compare with
        $two = [new GpsPoint(52.2, 13.2, 80), $drive[0]];
        self::assertCount(2, $calc->dropLeadingOutliers($two));
    }
}
