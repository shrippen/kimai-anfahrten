<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;

/**
 * Consistency checks before the data goes into the tax return.
 */
class PlausibilityChecker
{
    /** Optional integration with the HolidayBundle (same author); only used when installed. */
    private const ABSENCE_ENTITY = 'KimaiPlugin\HolidayBundle\Entity\Absence';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MileageConfiguration $configuration,
        private readonly MonthLockRepository $lockRepository,
        private readonly TripSuggestionRepository $suggestionRepository,
    ) {
    }

    /**
     * @param Trip[] $trips trips of the user in that year
     * @return list<array{level: string, key: string, count: int, dates: list<string>}>
     */
    public function check(User $user, int $year, array $trips): array
    {
        $workdays = $this->timesheetDays($user, $year);
        $absences = $this->absenceDays($user, $year);

        return $this->evaluate($user, $year, $trips, $workdays, $absences);
    }

    /**
     * Pure part of the checks (testable without database).
     *
     * @param Trip[] $trips
     * @param array<string, true> $workdays days with recorded working time
     * @param array<string, true>|null $absences approved absence days, null when the HolidayBundle is not installed
     * @return list<array{level: string, key: string, count: int, dates: list<string>}>
     */
    public function evaluate(User $user, int $year, array $trips, array $workdays, ?array $absences): array
    {
        $findings = [];
        $add = static function (string $level, string $key, array $dates = [], ?int $count = null) use (&$findings): void {
            $dates = array_values(array_unique($dates));
            sort($dates);
            $findings[] = ['level' => $level, 'key' => $key, 'count' => $count ?? \count($dates), 'dates' => \array_slice($dates, 0, 31)];
        };

        $commuteDays = [];
        $withoutTimesheet = [];
        $onAbsence = [];
        $onWeekend = [];
        $multiple = [];
        $businessWithoutPurpose = [];
        $businessWithoutTimes = [];
        $rentalWithoutCosts = [];
        $deviating = [];
        $commuteKm = $this->configuration->getCommuteKm($user);

        foreach ($trips as $trip) {
            $date = $trip->getDate()?->format('Y-m-d');
            if ($date === null) {
                continue;
            }

            if ($trip->getPurpose() === TripPurpose::COMMUTE) {
                if (isset($commuteDays[$date])) {
                    $multiple[] = $date;
                }
                $commuteDays[$date] = true;
                if (!isset($workdays[$date])) {
                    $withoutTimesheet[] = $date;
                }
                if ($absences !== null && isset($absences[$date])) {
                    $onAbsence[] = $date;
                }
                if ((int) $trip->getDate()?->format('N') >= 6) {
                    $onWeekend[] = $date;
                }
                if ($commuteKm !== null && abs($trip->getDistanceKm() - $commuteKm) >= 1) {
                    $deviating[] = $date;
                }
            }

            if ($trip->getPurpose() === TripPurpose::BUSINESS) {
                if (($trip->getComment() === null || trim($trip->getComment()) === '') && $trip->getProject() === null) {
                    $businessWithoutPurpose[] = $date;
                }
                if ($trip->getDepartureAt() === null || $trip->getArrivalAt() === null) {
                    $businessWithoutTimes[] = $date;
                }
            }

            if ($trip->getVehicle() === VehicleType::RENTAL_CAR && $trip->getRental() === null && $trip->getCosts() === null && $trip->getPurpose() !== TripPurpose::PRIVATE) {
                $rentalWithoutCosts[] = $date;
            }
        }

        if (\count($commuteDays) > \count($workdays) && $workdays !== []) {
            $add('warning', 'plausibility.more_commutes_than_workdays', [], \count($commuteDays) - \count($workdays));
        }
        if ($withoutTimesheet !== []) {
            $add('warning', 'plausibility.commute_without_timesheet', $withoutTimesheet);
        }
        if ($onAbsence !== []) {
            $add('danger', 'plausibility.commute_on_absence', $onAbsence);
        }
        if ($rentalWithoutCosts !== []) {
            $add('warning', 'plausibility.rental_without_costs', $rentalWithoutCosts);
        }
        if ($businessWithoutPurpose !== []) {
            $add('warning', 'plausibility.business_without_purpose', $businessWithoutPurpose);
        }
        if ($onWeekend !== []) {
            $add('info', 'plausibility.commute_on_weekend', $onWeekend);
        }
        if ($multiple !== []) {
            $add('info', 'plausibility.multiple_commutes', $multiple);
        }
        if ($deviating !== []) {
            $add('info', 'plausibility.commute_distance_deviates', $deviating);
        }
        if ($businessWithoutTimes !== []) {
            $add('info', 'plausibility.business_without_times', $businessWithoutTimes);
        }
        if ($absences === null) {
            $add('info', 'plausibility.no_absence_data', [], 0);
        }

        return $findings;
    }

    /**
     * Additional findings that need the database (suggestions, month closing).
     *
     * @param Trip[] $trips
     * @return list<array{level: string, key: string, count: int, dates: list<string>}>
     */
    public function checkBookkeeping(User $user, int $year, array $trips): array
    {
        $findings = [];

        $open = 0;
        foreach ($this->suggestionRepository->findOpen($user) as $suggestion) {
            if ((int) $suggestion->getDate()->format('Y') === $year) {
                $open++;
            }
        }
        if ($open > 0) {
            $findings[] = ['level' => 'warning', 'key' => 'plausibility.open_suggestions', 'count' => $open, 'dates' => []];
        }

        $months = [];
        foreach ($trips as $trip) {
            $months[(int) $trip->getDate()?->format('n')] = true;
        }
        $locks = $this->lockRepository->findByUserAndYear($user, $year);
        $now = new \DateTimeImmutable();
        $unlocked = [];
        foreach (array_keys($months) as $month) {
            $end = (new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month)))->modify('last day of this month');
            if (!isset($locks[$month]) && $end < $now) {
                $unlocked[] = \sprintf('%d-%02d', $year, $month);
            }
        }
        if ($unlocked !== []) {
            sort($unlocked);
            $findings[] = ['level' => 'info', 'key' => 'plausibility.months_not_closed', 'count' => \count($unlocked), 'dates' => $unlocked];
        }

        return $findings;
    }

    /**
     * @return array<string, true>
     */
    private function timesheetDays(User $user, int $year): array
    {
        $timezone = $user->getDateTimezone();
        $from = new \DateTimeImmutable(\sprintf('%d-01-01 00:00:00', $year), $timezone);
        $to = $from->modify('+1 year');

        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.begin')
            ->from(Timesheet::class, 't')
            ->andWhere('t.user = :user')
            ->andWhere('t.begin >= :from')
            ->andWhere('t.begin < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        $days = [];
        foreach ($rows as $row) {
            if ($row['begin'] instanceof \DateTimeInterface) {
                $days[\DateTimeImmutable::createFromInterface($row['begin'])->setTimezone($timezone)->format('Y-m-d')] = true;
            }
        }

        return $days;
    }

    /**
     * Approved absences from the HolidayBundle, if installed.
     *
     * @return array<string, true>|null
     */
    private function absenceDays(User $user, int $year): ?array
    {
        if (!class_exists(self::ABSENCE_ENTITY)) {
            return null;
        }
        $metadata = null;
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $candidate) {
            if ($candidate->getName() === self::ABSENCE_ENTITY) {
                $metadata = $candidate;
                break;
            }
        }
        if ($metadata === null) {
            return null;
        }

        $absences = $this->entityManager->createQueryBuilder()
            ->select('a.startDate', 'a.endDate')
            ->from($metadata->getName(), 'a')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            // on a half day off the commute is still plausible
            ->andWhere('a.halfDay = false')
            ->andWhere('a.startDate <= :end')
            ->andWhere('a.endDate >= :start')
            ->setParameter('user', $user)
            ->setParameter('status', 'approved')
            ->setParameter('start', new \DateTimeImmutable(\sprintf('%d-01-01', $year)), Types::DATE_IMMUTABLE)
            ->setParameter('end', new \DateTimeImmutable(\sprintf('%d-12-31', $year)), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        $days = [];
        foreach ($absences as $absence) {
            for ($day = \DateTimeImmutable::createFromInterface($absence['startDate']); $day <= $absence['endDate']; $day = $day->modify('+1 day')) {
                $days[$day->format('Y-m-d')] = true;
            }
        }

        return $days;
    }
}
