<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\PlausibilityChecker;
use PHPUnit\Framework\TestCase;

class PlausibilityCheckerTest extends TestCase
{
    public function testFindings(): void
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_COMMUTE_KM, '20');

        $commute = static fn (string $date, float $km = 20) => (new Trip())->setDate(new \DateTimeImmutable($date))->setPurpose(TripPurpose::COMMUTE)->setDistanceKm($km);
        $trips = [
            $commute('2026-03-02'),
            $commute('2026-03-02'),            // twice
            $commute('2026-03-03', 35),        // other distance, no timesheet
            $commute('2026-03-07'),            // Saturday
            $commute('2026-03-09'),            // on vacation
            (new Trip())->setDate(new \DateTimeImmutable('2026-03-10'))->setPurpose(TripPurpose::BUSINESS)->setVehicle(VehicleType::RENTAL_CAR),
        ];
        $workdays = ['2026-03-02' => true, '2026-03-07' => true, '2026-03-09' => true];
        $absences = ['2026-03-09' => true];

        $checker = new PlausibilityChecker(
            $this->createMock(EntityManagerInterface::class),
            new MileageConfiguration(new SystemConfiguration()),
            $this->createMock(MonthLockRepository::class),
            $this->createMock(TripSuggestionRepository::class),
        );
        $findings = array_column($checker->evaluate($user, 2026, $trips, $workdays, $absences), null, 'key');

        self::assertSame(1, $findings['plausibility.more_commutes_than_workdays']['count']);
        self::assertSame(['2026-03-03'], $findings['plausibility.commute_without_timesheet']['dates']);
        self::assertSame('danger', $findings['plausibility.commute_on_absence']['level']);
        self::assertSame(['2026-03-09'], $findings['plausibility.commute_on_absence']['dates']);
        self::assertSame(['2026-03-07'], $findings['plausibility.commute_on_weekend']['dates']);
        self::assertSame(['2026-03-02'], $findings['plausibility.multiple_commutes']['dates']);
        self::assertSame(['2026-03-03'], $findings['plausibility.commute_distance_deviates']['dates']);
        self::assertSame(['2026-03-10'], $findings['plausibility.rental_without_costs']['dates']);
        self::assertSame(['2026-03-10'], $findings['plausibility.business_without_purpose']['dates']);
        self::assertArrayNotHasKey('plausibility.no_absence_data', $findings);

        $withoutHoliday = array_column($checker->evaluate($user, 2026, [], [], null), 'key');
        self::assertContains('plausibility.no_absence_data', $withoutHoliday);
    }
}
