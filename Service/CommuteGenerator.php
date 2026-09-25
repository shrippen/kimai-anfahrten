<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Repository\TripRepository;

/**
 * Suggests commute entries (Arbeitsweg) for every day of a month with recorded
 * working time, so the Entfernungspauschale does not have to be typed day by day.
 */
class CommuteGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TripRepository $tripRepository,
        private readonly MileageConfiguration $configuration,
        private readonly TripService $tripService,
        private readonly MonthLockService $lockService,
    ) {
    }

    /**
     * @return array<string, array{date: \DateTimeImmutable, hours: float, has_commute: bool}> keyed by Y-m-d
     */
    public function suggestDays(User $user, int $year, int $month): array
    {
        $from = new \DateTimeImmutable(\sprintf('%d-%02d-01 00:00:00', $year, $month));
        $to = $from->modify('first day of next month');

        /** @var Timesheet[] $timesheets */
        $timesheets = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->andWhere('t.user = :user')
            ->andWhere('t.begin >= :from')
            ->andWhere('t.begin < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('t.begin', 'ASC')
            ->getQuery()
            ->getResult();

        $days = [];
        foreach ($timesheets as $timesheet) {
            $begin = $timesheet->getBegin();
            if ($begin === null) {
                continue;
            }
            $key = $begin->format('Y-m-d');
            $days[$key] ??= [
                'date' => new \DateTimeImmutable($key),
                'hours' => 0.0,
                'has_commute' => false,
            ];
            $days[$key]['hours'] += ($timesheet->getDuration() ?? 0) / 3600;
        }

        foreach ($this->tripRepository->findByUserBetween($user, $from, $to->modify('-1 day')) as $trip) {
            $key = $trip->getDate()?->format('Y-m-d');
            if ($key !== null && isset($days[$key]) && $trip->getPurpose() === TripPurpose::COMMUTE) {
                $days[$key]['has_commute'] = true;
            }
        }

        return $days;
    }

    /**
     * Creates commutes for the given days of one month. Days outside the month, days that already
     * have a commute and days of closed months (unless $mayEditLocked) are skipped.
     *
     * @param string[] $dates Y-m-d
     * @return array{created: int, locked: int} number of created trips and of days skipped because the month is closed
     */
    public function create(User $user, array $dates, float $distanceKm, int $year, int $month, bool $mayEditLocked = false): array
    {
        $count = 0;
        $locked = 0;
        $existing = [];
        $first = new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month));
        foreach ($this->tripRepository->findByUserBetween($user, $first, $first->modify('last day of this month')) as $trip) {
            if ($trip->getPurpose() === TripPurpose::COMMUTE && $trip->getDate() !== null) {
                $existing[$trip->getDate()->format('Y-m-d')] = true;
            }
        }

        foreach (array_unique($dates) as $date) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($day === false || $day->format('Y-m-d') !== $date || $day->format('Y-n') !== $year . '-' . $month || isset($existing[$date])) {
                continue;
            }
            if (!$mayEditLocked && $this->lockService->isLocked($user, $day)) {
                $locked++;
                continue;
            }
            $existing[$date] = true;

            $trip = $this->tripService->createTrip($user, $day)
                ->setPurpose(TripPurpose::COMMUTE)
                ->setStartLocation($this->configuration->getHomeAddress($user))
                ->setDestination($this->configuration->getWorkAddress($user))
                ->setDistanceKm($distanceKm);

            $this->tripService->prepare($trip);
            $this->tripRepository->save($trip, false);
            $count++;
        }

        $this->entityManager->flush();

        return ['created' => $count, 'locked' => $locked];
    }
}
