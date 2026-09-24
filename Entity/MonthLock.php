<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
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

    public function __construct(User $user, int $year, int $month, ?User $lockedBy)
    {
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
}
