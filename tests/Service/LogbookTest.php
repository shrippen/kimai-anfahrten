<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Service\LogbookService;
use KimaiPlugin\MileageBundle\Service\RentalCostAllocator;
use PHPUnit\Framework\TestCase;

class LogbookTest extends TestCase
{
    private function trip(string $date, ?int $start, ?int $end, TripPurpose $purpose = TripPurpose::BUSINESS, float $km = 0): Trip
    {
        return (new Trip())
            ->setDate(new \DateTimeImmutable($date))
            ->setPurpose($purpose)
            ->setOdometerStart($start)
            ->setOdometerEnd($end)
            ->setDistanceKm($km ?: (float) (($end ?? 0) - ($start ?? 0)))
            ->setDestination('Somewhere')
            ->setComment('Meeting');
    }

    public function testLaterYearContinuesFromPreviousYear(): void
    {
        $vehicle = (new Vehicle())->setInitialOdometer(1000);
        $service = new LogbookService();
        $all = [$this->trip('2025-12-20', 1000, 1500), $this->trip('2026-01-05', 1500, 1600)];

        $start = $service->suggestOdometerStart($vehicle, $all, new \DateTimeImmutable('2025-12-31'));
        $result = $service->analyse($vehicle, [$all[1]], $start);

        self::assertSame(1500, $start);
        self::assertSame([], $result['gaps']);
        self::assertSame(0, $result['km']['unrecorded']);
        // without the start value the first trip of 2026 looks like a 500 km gap
        self::assertSame(500, $service->analyse($vehicle, [$all[1]])['km']['unrecorded']);
    }

    public function testContinuousLogbookHasNoWarnings(): void
    {
        $vehicle = (new Vehicle())->setInitialOdometer(1000);
        $trips = [
            $this->trip('2026-01-03', 1040, 1100),
            $this->trip('2026-01-02', 1000, 1040, TripPurpose::PRIVATE),
        ];

        $result = (new LogbookService())->analyse($vehicle, $trips);

        self::assertSame([], $result['gaps']);
        self::assertSame([[], []], array_map(static fn ($r) => $r['warnings'], $result['rows']));
        self::assertSame(1000, $result['odometer_start']);
        self::assertSame(1100, $result['odometer_end']);
        self::assertSame(60.0, $result['km']['business']);
        self::assertSame(40.0, $result['km']['private']);
    }

    public function testDetectsGapOverlapAndMismatch(): void
    {
        $vehicle = (new Vehicle())->setInitialOdometer(1000);
        $trips = [
            $this->trip('2026-01-02', 1000, 1040),
            $this->trip('2026-01-03', 1055, 1100),       // gap of 15 km
            $this->trip('2026-01-04', 1090, 1120),       // overlap
            $this->trip('2026-01-05', 1120, 1200, TripPurpose::BUSINESS, 20), // 80 on the clock, 20 recorded
            $this->trip('2026-01-06', null, null, TripPurpose::BUSINESS, 5),
        ];

        $result = (new LogbookService())->analyse($vehicle, $trips);

        self::assertCount(2, $result['gaps']);
        self::assertSame(15, $result['km']['unrecorded']);
        self::assertContains('mileage.logbook.warning.odometer_gap', $result['rows'][1]['warnings']);
        self::assertContains('mileage.logbook.warning.odometer_overlap', $result['rows'][2]['warnings']);
        self::assertContains('mileage.logbook.warning.distance_mismatch', $result['rows'][3]['warnings']);
        self::assertContains('mileage.logbook.warning.odometer_missing', $result['rows'][4]['warnings']);
    }

    public function testSuggestOdometerStart(): void
    {
        $vehicle = (new Vehicle())->setInitialOdometer(500);
        $service = new LogbookService();
        $trips = [$this->trip('2026-01-02', 500, 540), $this->trip('2026-01-10', 540, 600)];

        self::assertSame(500, $service->suggestOdometerStart($vehicle, [], new \DateTimeImmutable('2026-01-01')));
        self::assertSame(540, $service->suggestOdometerStart($vehicle, $trips, new \DateTimeImmutable('2026-01-05')));
        self::assertSame(600, $service->suggestOdometerStart($vehicle, $trips, new \DateTimeImmutable('2026-02-01')));
    }

    public function testRentalCostsAreSharedByKilometres(): void
    {
        $rental = (new Rental())->setRentalCosts(200)->setFuelCosts(40);
        $business = (new Trip())->setPurpose(TripPurpose::BUSINESS)->setVehicle(VehicleType::RENTAL_CAR)->setDistanceKm(150)->setRental($rental);
        $private = (new Trip())->setPurpose(TripPurpose::PRIVATE)->setVehicle(VehicleType::RENTAL_CAR)->setDistanceKm(50)->setRental($rental);
        $other = (new Trip())->setDistanceKm(999);

        $allocation = (new RentalCostAllocator())->allocateAll([$business, $private, $other]);

        self::assertSame(180.0, $allocation[spl_object_id($business)]);
        self::assertSame(60.0, $allocation[spl_object_id($private)]);
        self::assertArrayNotHasKey(spl_object_id($other), $allocation);
    }

    public function testRentalWithoutKilometresSplitsEvenly(): void
    {
        $rental = (new Rental())->setRentalCosts(90);
        $a = (new Trip())->setRental($rental);
        $b = (new Trip())->setRental($rental);

        $allocation = (new RentalCostAllocator())->allocate($rental, [$a, $b]);

        self::assertSame(45.0, $allocation[spl_object_id($a)]);
    }

    public function testAssignedVehicleSetsTypeAndPlate(): void
    {
        $vehicle = (new Vehicle())->setType(VehicleType::COMPANY_CAR)->setLicensePlate('M-XY 1');
        $trip = (new Trip())->setAssignedVehicle($vehicle);

        self::assertSame(VehicleType::COMPANY_CAR, $trip->getVehicle());
        self::assertSame('M-XY 1', $trip->getLicensePlate());
    }

    public function testVehicleValidity(): void
    {
        $vehicle = (new Vehicle())->setValidFrom(new \DateTimeImmutable('2026-02-01'))->setValidTo(new \DateTimeImmutable('2026-02-28'));

        self::assertFalse($vehicle->isValidOn(new \DateTimeImmutable('2026-01-31')));
        self::assertTrue($vehicle->isValidOn(new \DateTimeImmutable('2026-02-28 18:00')));
        self::assertFalse($vehicle->isValidOn(new \DateTimeImmutable('2026-03-01')));
    }
}
