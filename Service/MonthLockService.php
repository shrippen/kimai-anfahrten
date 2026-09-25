<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\MonthLock;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
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

        return $this->cache[$key] ??= $this->repository->findLock($user, (int) $date->format('Y'), (int) $date->format('n'))?->isLocked() === true;
    }

    public function isTripLocked(Trip $trip): bool
    {
        $user = $trip->getUser();
        $date = $trip->getDate();

        return $user !== null && $date !== null && $this->isLocked($user, $date);
    }

    /**
     * Closes a month (status CLOSED) or hands it in for approval (status SUBMITTED).
     */
    public function lock(User $user, int $year, int $month, ?User $by, MonthStatus $status = MonthStatus::CLOSED): void
    {
        $lock = $this->repository->findLock($user, $year, $month);
        if ($lock === null) {
            $this->repository->save(new MonthLock($user, $year, $month, $by, $status));
        } elseif (!$lock->isLocked()) {
            $this->repository->save($lock->resubmit($status, $by));
        }
        $this->cache = [];
    }

    public function review(MonthLock $lock, bool $approve, ?User $by, ?string $comment = null): void
    {
        $lock->review($approve ? MonthStatus::APPROVED : MonthStatus::REJECTED, $by, $comment);
        $this->repository->save($lock);
        $this->cache = [];
    }

    /**
     * State of a lock for an undo (MonthLockService::restore()).
     *
     * @return array{status: string, locked_by: ?int, locked_at: string, reviewed_by: ?int, reviewed_at: ?string, comment: ?string}
     */
    public static function snapshot(MonthLock $lock): array
    {
        return [
            'status' => $lock->getStatus()->value,
            'locked_by' => $lock->getLockedBy()?->getId(),
            'locked_at' => $lock->getLockedAt()->format(\DateTimeInterface::ATOM),
            'reviewed_by' => $lock->getReviewedBy()?->getId(),
            'reviewed_at' => $lock->getReviewedAt()?->format(\DateTimeInterface::ATOM),
            'comment' => $lock->getComment(),
        ];
    }

    /**
     * Puts back a snapshot (undo of unlock or review). $lock null = the month has no lock (any more) and gets one.
     *
     * @param array{status: string, locked_by: ?int, locked_at: string, reviewed_by: ?int, reviewed_at: ?string, comment: ?string} $snapshot
     * @param callable(int): ?User $findUser
     */
    public function restore(User $user, int $year, int $month, ?MonthLock $lock, array $snapshot, callable $findUser): MonthLock
    {
        $lockedBy = $snapshot['locked_by'] !== null ? $findUser($snapshot['locked_by']) : null;
        $lock ??= new MonthLock($user, $year, $month, $lockedBy);
        $lock->restore(
            MonthStatus::from($snapshot['status']),
            $lockedBy,
            new \DateTimeImmutable($snapshot['locked_at']),
            $snapshot['reviewed_by'] !== null ? $findUser($snapshot['reviewed_by']) : null,
            $snapshot['reviewed_at'] !== null ? new \DateTimeImmutable($snapshot['reviewed_at']) : null,
            $snapshot['comment'],
        );
        $this->repository->save($lock);
        $this->cache = [];

        return $lock;
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
