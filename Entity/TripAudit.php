<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Repository\TripAuditRepository;

/**
 * Change history of trips (required for an electronic logbook: changes must stay visible).
 * The trip id is kept as plain integer so the history survives deletion.
 */
#[ORM\Entity(repositoryClass: TripAuditRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_audit')]
#[ORM\Index(columns: ['trip_id'], name: 'idx_mileage_audit_trip')]
class TripAudit
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    /** A receipt was added to / deleted from the trip. */
    public const RECEIPT_ADD = 'receipt_add';
    public const RECEIPT_DELETE = 'receipt_delete';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'trip_id', type: Types::INTEGER)]
    private int $tripId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $owner;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'changed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $changedBy;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $action;

    /** @var array<string, array{0: mixed, 1: mixed}> field => [old, new] */
    #[ORM\Column(type: Types::JSON)]
    private array $changes;

    /** Change happened in a closed month. */
    #[ORM\Column(name: 'locked_month', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $lockedMonth;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public function __construct(int $tripId, ?User $owner, ?User $changedBy, string $action, array $changes, bool $lockedMonth)
    {
        $this->tripId = $tripId;
        $this->owner = $owner;
        $this->changedBy = $changedBy;
        $this->action = $action;
        $this->changes = $changes;
        $this->lockedMonth = $lockedMonth;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTripId(): int
    {
        return $this->tripId;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function isLockedMonth(): bool
    {
        return $this->lockedMonth;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
