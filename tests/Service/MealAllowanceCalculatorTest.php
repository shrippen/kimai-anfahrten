<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Service\MealAllowanceCalculator;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\TaxRates;
use KimaiPlugin\MileageBundle\Service\TaxRateSchedule;
use PHPUnit\Framework\TestCase;

class MealAllowanceCalculatorTest extends TestCase
{
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->tz = new \DateTimeZone('Europe/Berlin');
    }

    private function rates(): TaxRates
    {
        return (new TaxRateSchedule(new MileageConfiguration(new SystemConfiguration())))->forYear(2026);
    }

    private function trip(string $from, string $to, string $destination = 'Kunde A', bool $overnight = false, TripPurpose $purpose = TripPurpose::BUSINESS, ?string $start = null): Trip
    {
        return (new Trip())
            ->setStartLocation($start)
            ->setDate(new \DateTimeImmutable(substr($from, 0, 10)))
            ->setPurpose($purpose)
            ->setDestination($destination)
            ->setOvernight($overnight)
            ->setDepartureAt(new \DateTimeImmutable($from, $this->tz))
            // Kimai hands out UTC objects — the calculator must not depend on the object timezone
            ->setArrivalAt((new \DateTimeImmutable($to, $this->tz))->setTimezone(new \DateTimeZone('UTC')));
    }

    public function testOneDayOverEightHours(): void
    {
        $result = (new MealAllowanceCalculator())->calculate([$this->trip('2026-03-02 07:00', '2026-03-02 15:30')], $this->rates(), $this->tz);

        self::assertSame(1, $result['partial_days']);
        self::assertSame(14.0, $result['amount']);
        self::assertSame(8.5, $result['days'][0]['hours']);
    }

    public function testOnlyDaysOfTheTaxYearCount(): void
    {
        // New Year journey: 31.12.2025 (travel day) belongs to 2025, 1.1. and 2.1.2026 to 2026
        $trips = [$this->trip('2025-12-31 09:00', '2026-01-02 18:00', 'Kunde A', true)];
        $result = (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz);

        self::assertSame(['2026-01-01', '2026-01-02'], array_column($result['days'], 'date'));
        self::assertSame(28.0 + 14.0, $result['amount']);
    }

    public function testEightHoursExactlyIsNotEnough(): void
    {
        $result = (new MealAllowanceCalculator())->calculate([$this->trip('2026-03-02 08:00', '2026-03-02 16:00')], $this->rates(), $this->tz);

        self::assertSame(0.0, $result['amount']);
    }

    public function testSeveralAbsencesOnOneDayAddUp(): void
    {
        $trips = [$this->trip('2026-03-02 07:00', '2026-03-02 11:30'), $this->trip('2026-03-02 13:00', '2026-03-02 17:00', 'Kunde B')];

        self::assertSame(14.0, (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz)['amount']);
    }

    public function testMultiDayJourneyOverOneEntry(): void
    {
        // Monday 08:00 to Wednesday 18:00: 14 + 28 + 14
        $result = (new MealAllowanceCalculator())->calculate([$this->trip('2026-03-02 08:00', '2026-03-04 18:00')], $this->rates(), $this->tz);

        self::assertSame(2, $result['partial_days']);
        self::assertSame(1, $result['full_days']);
        self::assertSame(56.0, $result['amount']);
    }

    public function testMultiDayJourneyWithOvernightFlag(): void
    {
        $trips = [
            $this->trip('2026-03-02 15:00', '2026-03-02 19:00', 'Hamburg', true),
            $this->trip('2026-03-03 09:00', '2026-03-03 10:00', 'Hamburg Kunde', true),
            $this->trip('2026-03-04 16:00', '2026-03-04 20:00', 'Zuhause'),
        ];

        $result = (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz);

        self::assertSame(['travel_day', 'full_day', 'travel_day'], array_column($result['days'], 'kind'));
        self::assertSame(56.0, $result['amount']);
    }

    public function testThreeMonthRule(): void
    {
        $trips = [];
        // weekly at the same customer from January to May
        for ($day = new \DateTimeImmutable('2026-01-05'); $day < new \DateTimeImmutable('2026-05-31'); $day = $day->modify('+7 days')) {
            $trips[] = $this->trip($day->format('Y-m-d') . ' 07:00', $day->format('Y-m-d') . ' 17:00', 'Baustelle Nord');
        }
        // break of more than four weeks, then again
        $trips[] = $this->trip('2026-07-06 07:00', '2026-07-06 17:00', 'baustelle  nord');

        $result = (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz);
        $byDate = array_column($result['days'], 'three_month', 'date');

        self::assertFalse($byDate['2026-03-30']);
        self::assertTrue($byDate['2026-04-06']);
        self::assertFalse($byDate['2026-07-06']);
        self::assertGreaterThan(0, $result['excluded_days']);
    }

    public function testIgnoresOtherPurposesAndCountsMissingTimes(): void
    {
        $trips = [
            $this->trip('2026-03-02 07:00', '2026-03-02 18:00', 'Büro', false, TripPurpose::COMMUTE),
            (new Trip())->setDate(new \DateTimeImmutable('2026-03-03'))->setPurpose(TripPurpose::BUSINESS),
        ];

        $result = (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz);

        self::assertSame(0.0, $result['amount']);
        self::assertSame(1, $result['missing_times']);
    }

    public function testWayThereAndBackFormOneAbsence(): void
    {
        // detected legs (suggestions): office → customer, later customer → office; absent 08:00–18:00
        $trips = [
            $this->trip('2026-03-02 17:00', '2026-03-02 18:00', 'Büro', false, TripPurpose::BUSINESS, 'Kunde A'),
            $this->trip('2026-03-02 08:00', '2026-03-02 09:00', 'Kunde A', false, TripPurpose::BUSINESS, 'Büro'),
        ];
        $result = (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz);

        self::assertSame(10.0, $result['days'][0]['hours']);
        self::assertSame(14.0, $result['amount']);
        self::assertSame('Kunde A', $result['days'][0]['destination']);

        // unrelated legs (the second does not start where the first ended) still only add up their own times
        $trips[0]->setStartLocation('Kunde B');
        self::assertSame(0.0, (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz)['amount']);
    }

    public function testLegsOfDifferentDaysAreNotJoined(): void
    {
        $trips = [
            $this->trip('2026-03-02 15:00', '2026-03-02 17:00', 'Kunde A', false, TripPurpose::BUSINESS, 'Büro'),
            $this->trip('2026-03-03 08:00', '2026-03-03 10:00', 'Büro', false, TripPurpose::BUSINESS, 'Kunde A'),
        ];

        self::assertSame(0.0, (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz)['amount']);
    }

    public function testOnlyTheTripTimesCount(): void
    {
        // "Fahrt erfassen" at a timesheet used to store the Dawarich window 00:00–23:59 as departure/arrival
        // (always 14 €); now such a trip has no times until the real ones are entered
        $withoutTimes = (new Trip())->setDate(new \DateTimeImmutable('2026-03-02'))->setPurpose(TripPurpose::BUSINESS)->setDestination('Kunde A');
        $result = (new MealAllowanceCalculator())->calculate([$withoutTimes], $this->rates(), $this->tz);
        self::assertSame(0.0, $result['amount']);
        self::assertSame(1, $result['missing_times']);

        $withoutTimes->setDepartureAt(new \DateTimeImmutable('2026-03-02 10:00', $this->tz))->setArrivalAt(new \DateTimeImmutable('2026-03-02 13:00', $this->tz));
        self::assertSame(0.0, (new MealAllowanceCalculator())->calculate([$withoutTimes], $this->rates(), $this->tz)['amount']);
    }

    public function testMultiDayJourneyOverNewYearWithLegs(): void
    {
        // outbound 30.12.2025 (overnight), local leg on 31.12., return 2.1.2026: 2026 gets 1.1. (full) and 2.1. (travel day)
        $trips = [
            $this->trip('2025-12-30 07:00', '2025-12-30 12:00', 'Hamburg', true, TripPurpose::BUSINESS, 'Zuhause'),
            $this->trip('2025-12-31 09:00', '2025-12-31 09:30', 'Hamburg Kunde', true, TripPurpose::BUSINESS, 'Hamburg'),
            $this->trip('2026-01-02 14:00', '2026-01-02 19:00', 'Zuhause', false, TripPurpose::BUSINESS, 'Hamburg'),
        ];
        $result = (new MealAllowanceCalculator())->calculate($trips, $this->rates(), $this->tz);

        self::assertSame(['2026-01-01' => 'full_day', '2026-01-02' => 'travel_day'], array_column($result['days'], 'kind', 'date'));
        self::assertSame(1, $result['full_days']);
        self::assertSame(1, $result['partial_days']);
        self::assertSame(42.0, $result['amount']);
    }
}
