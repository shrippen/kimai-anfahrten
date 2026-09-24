<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\MonthLock;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;

class MonthLockService
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly MonthLockRepository $repository)
    {
    }

    public function isLocked(User $user, \DateTimeInterface $date): bool
    {
        $key = $user->getId() . '-' . $date->format('Y-n');

        return $this->cache[$key] ??= $this->repository->findLock($user, (int) $date->format('Y'), (int) $date->format('n')) !== null;
    }

    public function isTripLocked(Trip $trip): bool
    {
        $user = $trip->getUser();
        $date = $trip->getDate();

        return $user !== null && $date !== null && $this->isLocked($user, $date);
    }

    public function lock(User $user, int $year, int $month, ?User $by): void
    {
        if ($this->repository->findLock($user, $year, $month) === null) {
            $this->repository->save(new MonthLock($user, $year, $month, $by));
        }
        $this->cache = [];
    }

    public function unlock(User $user, int $year, int $month): void
    {
        $lock = $this->repository->findLock($user, $year, $month);
        if ($lock !== null) {
            $this->repository->remove($lock);
        }
        $this->cache = [];
    }
}
