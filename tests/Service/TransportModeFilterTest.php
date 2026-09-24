<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\GpsPoint;
use KimaiPlugin\MileageBundle\Service\TransportModeFilter;
use KimaiPlugin\MileageBundle\Service\TransportSegment;
use KimaiPlugin\MileageBundle\Service\TripDetector;
use PHPUnit\Framework\TestCase;

class TransportModeFilterTest extends TestCase
{
    /**
     * Points every minute from $from to $to, moving east by $kmPerMinute.
     *
     * @return GpsPoint[]
     */
    private function line(int $from, int $to, float $startLon, float $kmPerMinute): array
    {
        $points = [];
        for ($t = $from, $i = 0; $t <= $to; $t += 60, $i++) {
            $points[] = new GpsPoint(52.5, $startLon + $i * $kmPerMinute / 67.8, $t, 5);
        }

        return $points;
    }

    public function testWalkIsCutOutAndSplitsTheTrack(): void
    {
        $points = array_merge(
            $this->line(0, 1200, 13.0, 1.0),           // 20 min driving
            $this->line(1260, 2400, 13.3, 0.08),       // 19 min walking
            $this->line(2460, 3000, 13.4, 1.0),        // 9 min driving
        );
        $segments = [
            new TransportSegment(0, 1200, 'driving'),
            new TransportSegment(1230, 2430, 'walking'),
            new TransportSegment(2430, 3000, 'driving'),
        ];

        $chunks = (new TransportModeFilter())->split($points, $segments);

        self::assertCount(2, $chunks);
        self::assertSame(1200, end($chunks[0])->timestamp);
        self::assertSame(2460, $chunks[1][0]->timestamp);
    }

    public function testBikeRideIsNotDetectedAsTrip(): void
    {
        // a 15 km bike ride between two long stops
        $points = array_merge(
            $this->line(0, 1800, 13.0, 0.0),
            $this->line(1860, 4500, 13.0, 0.34),
            $this->line(4560, 7200, 13.0 + 44 * 0.34 / 67.8, 0.0),
        );
        $segments = [new TransportSegment(1830, 4530, 'cycling')];
        $detector = new TripDetector(new DistanceCalculator());

        self::assertCount(1, $detector->detect($points));

        $trips = [];
        foreach ((new TransportModeFilter())->split($points, $segments) as $chunk) {
            array_push($trips, ...$detector->detect($chunk));
        }
        self::assertSame([], $trips);
    }

    public function testWithoutSegmentsNothingChanges(): void
    {
        $points = $this->line(0, 600, 13.0, 1.0);

        self::assertSame([$points], (new TransportModeFilter())->split($points, []));
        self::assertSame([$points], (new TransportModeFilter())->split($points, [new TransportSegment(0, 600, 'driving')]));
        self::assertSame([], (new TransportModeFilter())->split([], []));
    }

    public function testDominantModeAndVehicle(): void
    {
        $filter = new TransportModeFilter();
        $segments = [
            new TransportSegment(0, 300, 'walking'),
            new TransportSegment(300, 2400, 'train'),
            new TransportSegment(2400, 2700, 'stationary'),
        ];

        self::assertSame('train', $filter->dominantMode($segments, 0, 2700));
        self::assertNull($filter->dominantMode($segments, 5000, 6000));
        self::assertSame(VehicleType::PUBLIC_TRANSPORT, TransportModeFilter::vehicleFor('train'));
        self::assertSame(VehicleType::MOTORCYCLE, TransportModeFilter::vehicleFor('motorcycle'));
        self::assertNull(TransportModeFilter::vehicleFor('driving'));
        self::assertNull(TransportModeFilter::vehicleFor(null));
    }
}
