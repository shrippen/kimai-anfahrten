<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\PrivateUseMethod;
use KimaiPlugin\MileageBundle\Enum\TaxProfile;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Service\MealAllowanceCalculator;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\RentalCostAllocator;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use KimaiPlugin\MileageBundle\Service\TaxRateSchedule;
use PHPUnit\Framework\TestCase;

class TaxTest extends TestCase
{
    /**
     * @param array<string, string|int|float|bool|null> $settings
     */
    private function schedule(array $settings = []): TaxRateSchedule
    {
        return new TaxRateSchedule(new MileageConfiguration(new SystemConfiguration($settings)));
    }

    private function calculator(array $settings = []): TaxCalculator
    {
        return new TaxCalculator($this->schedule($settings), new RentalCostAllocator(), new MealAllowanceCalculator());
    }

    private function commute(string $date, float $km, VehicleType $vehicle = VehicleType::OWN_CAR): Trip
    {
        return (new Trip())->setDate(new \DateTimeImmutable($date))->setPurpose(TripPurpose::COMMUTE)->setDistanceKm($km)->setVehicle($vehicle);
    }

    public function testCommuteRatesPerYear(): void
    {
        $schedule = $this->schedule();

        // 2025: 20 km × 0.30 + 15 km × 0.38
        self::assertEqualsWithDelta(11.70, $schedule->forYear(2025)->commuteAmount(35.9), 0.001);
        // 2021: 20 × 0.30 + 15 × 0.35
        self::assertEqualsWithDelta(11.25, $schedule->forYear(2021)->commuteAmount(35), 0.001);
        // 2026: 35 × 0.38 from the first km
        self::assertEqualsWithDelta(13.30, $schedule->forYear(2026)->commuteAmount(35), 0.001);
        // years outside the table use the nearest known year
        self::assertSame(0.38, $schedule->forYear(2031)->commuteRate);
        self::assertSame(0.30, $schedule->forYear(2015)->commuteRateFrom21);
    }

    public function testCommuteOverrideFromSettings(): void
    {
        $rates = $this->schedule(['mileage.rate_commute' => '0.42'])->forYear(2024);

        self::assertSame(0.42, $rates->commuteRate);
        self::assertSame(0.42, $rates->commuteRateFrom21);
    }

    public function testCommuteOncePerDayAndCap(): void
    {
        $trips = [$this->commute('2026-01-05', 20.7), $this->commute('2026-01-05', 10), $this->commute('2026-01-06', 20)];

        $summary = $this->calculator()->summarize($trips, 2026);

        self::assertSame(2, $summary['commute']['days']);
        self::assertSame(40.0, $summary['commute']['km']);
        self::assertEqualsWithDelta(15.20, $summary['commute']['amount'], 0.001);

        // 250 days × 60 km by train = 5,700 € → capped at 4,500 €
        $train = [];
        for ($i = 0; $i < 250; $i++) {
            $train[] = $this->commute('2026-01-01 +' . $i . ' days', 60, VehicleType::PUBLIC_TRANSPORT);
        }
        $summary = $this->calculator()->summarize($train, 2026);
        self::assertTrue($summary['commute']['capped']);
        self::assertSame(4500.0, $summary['commute']['amount']);
    }

    public function testBusinessTripsForSelfEmployed(): void
    {
        $asset = (new Vehicle())->setType(VehicleType::OWN_CAR)->setBusinessAsset(true);
        $rental = (new Rental())->setRentalCosts(90)->setFuelCosts(30);
        $trips = [
            // private car: 0.30 €/km + parking
            (new Trip())->setDate(new \DateTimeImmutable('2026-02-02'))->setPurpose(TripPurpose::BUSINESS)->setDistanceKm(50)->setRoundTrip(true)->setCosts(4.5),
            // business asset car: nothing (costs are in the bookkeeping)
            (new Trip())->setDate(new \DateTimeImmutable('2026-02-03'))->setPurpose(TripPurpose::BUSINESS)->setDistanceKm(80)->setAssignedVehicle($asset),
            // rental: 3/4 of 120 € for the business trip
            (new Trip())->setDate(new \DateTimeImmutable('2026-02-04'))->setPurpose(TripPurpose::BUSINESS)->setVehicle(VehicleType::RENTAL_CAR)->setDistanceKm(300)->setRental($rental),
            (new Trip())->setDate(new \DateTimeImmutable('2026-02-05'))->setPurpose(TripPurpose::PRIVATE)->setVehicle(VehicleType::RENTAL_CAR)->setDistanceKm(100)->setRental($rental),
        ];

        $summary = $this->calculator()->summarize($trips, 2026, TaxProfile::SELF_EMPLOYED);

        self::assertSame(34.5, $summary['business']['own_car']['amount']);
        self::assertSame(0.0, $summary['business']['own_car_asset']['amount']);
        self::assertSame(90.0, $summary['business']['rental_car']['amount']);
        self::assertSame(124.5, $summary['business_total']);
    }

    public function testCompanyCarDiffersByProfile(): void
    {
        $trip = (new Trip())->setDate(new \DateTimeImmutable('2026-02-02'))->setPurpose(TripPurpose::BUSINESS)->setDistanceKm(100)->setVehicle(VehicleType::COMPANY_CAR)->setCosts(12);

        self::assertSame(0.0, $this->calculator()->summarize([$trip], 2026, TaxProfile::EMPLOYEE)['business_total']);
        self::assertSame(12.0, $this->calculator()->summarize([$trip], 2026, TaxProfile::SELF_EMPLOYED)['business_total']);
    }

    public function testOnePercentRuleWithCommuteAddition(): void
    {
        $vehicle = (new Vehicle())->setType(VehicleType::OWN_CAR)->setBusinessAsset(true)
            ->setPrivateUse(PrivateUseMethod::ONE_PERCENT)->setListPrice(45_678.90)
            ->setValidFrom(new \DateTimeImmutable('2026-04-15'));
        $trips = [];
        foreach (['2026-05-04', '2026-05-05'] as $date) {
            $trips[] = $this->commute($date, 15)->setAssignedVehicle($vehicle);
        }
        $trips[] = (new Trip())->setDate(new \DateTimeImmutable('2026-06-06'))->setPurpose(TripPurpose::PRIVATE)->setDistanceKm(70)->setAssignedVehicle($vehicle);

        $use = $this->calculator()->summarize($trips, 2026, TaxProfile::SELF_EMPLOYED)['private_use'][0];

        // April–December = 9 months; list price rounded down to 45,600 €
        self::assertSame(9, $use['months']);
        self::assertSame(4104.0, $use['one_percent']);
        // 45,600 × 0.03 % × 15 km × 9 − 2 × 15 × 0.38 = 1,846.80 − 11.40
        self::assertSame(1835.4, $use['commute_addition']);
        self::assertSame(53.85, $use['private_share']);
    }

    public function testElectricCarQuarterBase(): void
    {
        $vehicle = (new Vehicle())->setBusinessAsset(true)->setPrivateUse(PrivateUseMethod::ONE_PERCENT)->setListPrice(60_000)->setListPriceFactor(0.25);
        $trip = (new Trip())->setDate(new \DateTimeImmutable('2026-01-02'))->setPurpose(TripPurpose::PRIVATE)->setDistanceKm(10)->setAssignedVehicle($vehicle);

        // 15,000 × 1 % × 12
        self::assertSame(1800.0, $this->calculator()->summarize([$trip], 2026)['private_use'][0]['one_percent']);
    }

    public function testNoPrivateUseForEmployees(): void
    {
        $vehicle = (new Vehicle())->setBusinessAsset(true)->setPrivateUse(PrivateUseMethod::ONE_PERCENT)->setListPrice(30_000);
        $trip = (new Trip())->setDate(new \DateTimeImmutable('2026-01-02'))->setPurpose(TripPurpose::PRIVATE)->setAssignedVehicle($vehicle);

        self::assertSame([], $this->calculator()->summarize([$trip], 2026, TaxProfile::EMPLOYEE)['private_use']);
    }

    public function testTotalIncludesMeals(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $trip = (new Trip())->setUser(new User(1))->setDate(new \DateTimeImmutable('2026-03-02'))->setPurpose(TripPurpose::BUSINESS)->setDistanceKm(100)
            ->setDepartureAt(new \DateTimeImmutable('2026-03-02 07:00', $tz))->setArrivalAt(new \DateTimeImmutable('2026-03-02 17:30', $tz));

        $summary = $this->calculator()->summarize([$trip], 2026, TaxProfile::SELF_EMPLOYED, $tz);

        self::assertSame(14.0, $summary['meals']['amount']);
        self::assertSame(30.0 + 14.0, $summary['total']);
    }
}
