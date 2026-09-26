<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Service\TransportModeFilter;
use KimaiPlugin\MileageBundle\Service\TransportSegment;
use PHPUnit\Framework\TestCase;

class TransportModeFilterTest extends TestCase
{
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
