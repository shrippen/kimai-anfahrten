<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;

/**
 * A closed logbook month: trips can only be changed with a special permission, and every change is logged.
 */
#[ORM\Entity(repositoryClass: MonthLockRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_month_lock')]
#[ORM\UniqueConstraint(name: 'uniq_mileage_month_lock', columns: ['user_id', 'year', 'month'])]
class MonthLock
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $year;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $month;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'locked_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $lockedBy;

    #[ORM\Column(name: 'locked_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lockedAt;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: MonthStatus::class, options: ['default' => 'closed'])]
    private MonthStatus $status = MonthStatus::CLOSED;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /** Reason of a rejection. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    public function __construct(User $user, int $year, int $month, ?User $lockedBy, MonthStatus $status = MonthStatus::CLOSED)
    {
        $this->status = $status;
        $this->user = $user;
        $this->year = $year;
        $this->month = $month;
        $this->lockedBy = $lockedBy;
        $this->lockedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function getLockedBy(): ?User
    {
        return $this->lockedBy;
    }

    public function getLockedAt(): \DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getStatus(): MonthStatus
    {
        return $this->status;
    }

    public function setStatus(MonthStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function review(MonthStatus $status, ?User $by, ?string $comment = null): self
    {
        $this->status = $status;
        $this->reviewedBy = $by;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->comment = $comment;

        return $this;
    }

    /**
     * Re-submission after a rejection.
     */
    public function resubmit(MonthStatus $status, ?User $by): self
    {
        $this->status = $status;
        $this->lockedBy = $by;
        $this->lockedAt = new \DateTimeImmutable();
        $this->reviewedBy = null;
        $this->reviewedAt = null;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Undo of unlock/review within the undo window: puts back the state before the action.
     */
    public function restore(MonthStatus $status, ?User $lockedBy, \DateTimeImmutable $lockedAt, ?User $reviewedBy, ?\DateTimeImmutable $reviewedAt, ?string $comment): self
    {
        $this->status = $status;
        $this->lockedBy = $lockedBy;
        $this->lockedAt = $lockedAt;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $reviewedAt;
        $this->comment = $comment;

        return $this;
    }
}
