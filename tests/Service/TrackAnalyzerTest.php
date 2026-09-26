<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Service\DawarichTrack;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\GpsPoint;
use KimaiPlugin\MileageBundle\Service\TrackAnalyzer;
use KimaiPlugin\MileageBundle\Service\TransportModeFilter;
use KimaiPlugin\MileageBundle\Service\TransportSegment;
use PHPUnit\Framework\TestCase;

class TrackAnalyzerTest extends TestCase
{
    private const STOP = 300;

    private function analyzer(): TrackAnalyzer
    {
        return new TrackAnalyzer(new DistanceCalculator(), new TransportModeFilter());
    }

    /**
     * Segment from $from to $to (minutes) moving east along 52° N from $lon, $metres long.
     */
    private function segment(int $from, int $to, string $mode, ?int $metres, float $lon = 13.0, float $toLon = 13.1): TransportSegment
    {
        return new TransportSegment($from * 60, $to * 60, $mode, $metres, [new GpsPoint(52.0, $lon), new GpsPoint(52.0, ($lon + $toLon) / 2), new GpsPoint(52.0, $toLon)]);
    }

    /**
     * @param list<TransportSegment> $segments
     */
    private function track(array $segments, int $distance = 0): DawarichTrack
    {
        $first = $segments[0];
        $last = $segments[\count($segments) - 1];

        return new DawarichTrack(1, $first->start, $last->end, $distance, 'driving', $segments, [new GpsPoint(52.0, 13.0), new GpsPoint(52.0, 14.0)]);
    }

    public function testWalkBetweenTwoDrivesGivesTwoTrips(): void
    {
        $track = $this->track([
            $this->segment(0, 20, 'driving', 12000, 13.0, 13.2),
            $this->segment(20, 35, 'walking', 1500, 13.2, 13.22),
            $this->segment(35, 50, 'driving', 8000, 13.22, 13.3),
        ]);

        $trips = $this->analyzer()->trips($track, TransportModeFilter::NON_MOTORIZED, self::STOP, 1.0);

        self::assertCount(2, $trips);
        self::assertSame(12.0, $trips[0]->distanceKm);
        self::assertSame(0, $trips[0]->start->timestamp);
        self::assertSame(1200, $trips[0]->end->timestamp);
        self::assertSame(13.2, $trips[0]->end->longitude);
        self::assertSame(8.0, $trips[1]->distanceKm);
        self::assertSame(2100, $trips[1]->start->timestamp);
        self::assertSame(13.22, $trips[1]->start->longitude);
        self::assertSame('driving', $trips[1]->mode);
        self::assertSame(3, $trips[1]->pointCount);
    }

    public function testWithoutExcludedModesTheWalkIsPartOfTheTrip(): void
    {
        $track = $this->track([
            $this->segment(0, 20, 'driving', 12000),
            $this->segment(20, 35, 'walking', 1500),
        ]);

        $trips = $this->analyzer()->trips($track, [], self::STOP, 1.0);

        self::assertCount(1, $trips);
        self::assertSame(13.5, $trips[0]->distanceKm);
        self::assertSame('driving', $trips[0]->mode);
    }

    public function testBikeRideIsNoTrip(): void
    {
        $track = $this->track([$this->segment(0, 40, 'cycling', 15000)]);

        self::assertSame([], $this->analyzer()->trips($track, TransportModeFilter::NON_MOTORIZED, self::STOP, 1.0));
    }

    public function testLongStandstillSplitsShortOneDoesNot(): void
    {
        $track = $this->track([
            $this->segment(0, 10, 'driving', 5000),
            $this->segment(10, 12, 'stationary', 20),     // traffic light / jam
            $this->segment(12, 20, 'driving', 4000),
            $this->segment(20, 60, 'stationary', 50),     // parked
            $this->segment(60, 70, 'bus', 6000),
            $this->segment(70, 80, 'stationary', 10),     // trailing standstill is cut off
        ]);

        $trips = $this->analyzer()->trips($track, TransportModeFilter::NON_MOTORIZED, self::STOP, 1.0);

        self::assertCount(2, $trips);
        self::assertSame(9.0, $trips[0]->distanceKm);
        self::assertSame(1200, $trips[0]->end->timestamp);
        self::assertSame('bus', $trips[1]->mode);
        self::assertSame(4200, $trips[1]->end->timestamp);
    }

    public function testShortTripsAreDropped(): void
    {
        $track = $this->track([$this->segment(0, 5, 'driving', 600)]);

        self::assertSame([], $this->analyzer()->trips($track, [], self::STOP, 1.0));
    }

    public function testRecordingGapEndsTheTrip(): void
    {
        $track = $this->track([$this->segment(0, 10, 'driving', 5000), $this->segment(30, 40, 'driving', 5000)]);

        self::assertCount(2, $this->analyzer()->trips($track, [], self::STOP, 1.0));
    }

    public function testMissingSegmentDataFallsBack(): void
    {
        // distance from the segment line (0.1° at 52° N ≈ 6.8 km)
        $track = $this->track([$this->segment(0, 10, 'driving', null)]);
        self::assertSame(6.8, $this->analyzer()->trips($track, [], self::STOP, 1.0)[0]->distanceKm);

        // no line either: share of the track distance, position on the track line
        $track = new DawarichTrack(1, 0, 1200, 20000, 'driving', [
            new TransportSegment(0, 600, 'walking', null),
            new TransportSegment(600, 1200, 'driving', null),
        ], [new GpsPoint(52.0, 13.0), new GpsPoint(52.0, 13.5), new GpsPoint(52.0, 14.0)]);
        $trips = $this->analyzer()->trips($track, TransportModeFilter::NON_MOTORIZED, self::STOP, 1.0);
        self::assertCount(1, $trips);
        self::assertSame(10.0, $trips[0]->distanceKm);
        self::assertSame(13.5, $trips[0]->start->longitude);
        self::assertSame(14.0, $trips[0]->end->longitude);
    }

    public function testTrackWithoutSegmentsIsOneTrip(): void
    {
        $track = new DawarichTrack(1, 0, 1200, 20000, null, [], [new GpsPoint(52.0, 13.0), new GpsPoint(52.0, 14.0)]);
        $trips = $this->analyzer()->trips($track, TransportModeFilter::NON_MOTORIZED, self::STOP, 1.0);

        self::assertCount(1, $trips);
        self::assertSame(20.0, $trips[0]->distanceKm);
        self::assertNull($trips[0]->mode);
    }

    public function testMeasureClipsByTimeAndSkipsWalksAndParking(): void
    {
        $tracks = [$this->track([
            $this->segment(0, 20, 'driving', 12000),
            $this->segment(20, 35, 'walking', 1500),
            $this->segment(35, 50, 'driving', 8000),
            $this->segment(50, 120, 'stationary', 300),
        ])];
        $analyzer = $this->analyzer();

        $all = $analyzer->measure($tracks, 0, 7200, TransportModeFilter::NON_MOTORIZED, self::STOP);
        self::assertSame(20.0, $all->distanceKm);
        self::assertSame(2, $all->segmentCount);
        self::assertSame(6, $all->pointCount);

        // window from minute 10: half of the first drive
        self::assertSame(14.0, $analyzer->measure($tracks, 600, 7200, TransportModeFilter::NON_MOTORIZED, self::STOP)->distanceKm);

        $none = $analyzer->measure($tracks, 1300, 2000, TransportModeFilter::NON_MOTORIZED, self::STOP);
        self::assertSame(0.0, $none->distanceKm);
        self::assertSame(0, $none->segmentCount);
    }

    public function testLinesOfDrivenSegments(): void
    {
        $tracks = [$this->track([
            $this->segment(0, 20, 'driving', 12000),
            $this->segment(20, 22, 'stationary', 10),
            $this->segment(22, 30, 'driving', 3000),
            $this->segment(30, 45, 'walking', 1500),
            $this->segment(45, 50, 'driving', 3000),
        ])];

        $lines = $this->analyzer()->lines($tracks, 0, 2400, TransportModeFilter::NON_MOTORIZED, self::STOP);

        self::assertCount(1, $lines, 'the walk and everything after the window are left out');
        self::assertCount(9, $lines[0]);
    }
}
