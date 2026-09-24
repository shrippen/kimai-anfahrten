<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Enum\SuggestionStatus;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;

/**
 * A trip found in the Dawarich history that waits for the user's confirmation.
 */
#[ORM\Entity(repositoryClass: TripSuggestionRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_suggestion')]
#[ORM\UniqueConstraint(name: 'uniq_mileage_suggestion_start', columns: ['user_id', 'start_at'])]
#[ORM\Index(columns: ['status'], name: 'idx_mileage_suggestion_status')]
class TripSuggestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'start_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(name: 'end_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(name: 'start_lat', type: Types::FLOAT)]
    private float $startLatitude = 0.0;

    #[ORM\Column(name: 'start_lon', type: Types::FLOAT)]
    private float $startLongitude = 0.0;

    #[ORM\Column(name: 'end_lat', type: Types::FLOAT)]
    private float $endLatitude = 0.0;

    #[ORM\Column(name: 'end_lon', type: Types::FLOAT)]
    private float $endLongitude = 0.0;

    #[ORM\Column(name: 'start_label', type: Types::STRING, length: 255, nullable: true)]
    private ?string $startLabel = null;

    #[ORM\Column(name: 'end_label', type: Types::STRING, length: 255, nullable: true)]
    private ?string $endLabel = null;

    #[ORM\ManyToOne(targetEntity: Place::class)]
    #[ORM\JoinColumn(name: 'start_place_id', nullable: true, onDelete: 'SET NULL')]
    private ?Place $startPlace = null;

    #[ORM\ManyToOne(targetEntity: Place::class)]
    #[ORM\JoinColumn(name: 'end_place_id', nullable: true, onDelete: 'SET NULL')]
    private ?Place $endPlace = null;

    #[ORM\Column(name: 'distance_km', type: Types::FLOAT)]
    private float $distanceKm = 0.0;

    #[ORM\Column(name: 'point_count', type: Types::INTEGER)]
    private int $pointCount = 0;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: TripPurpose::class)]
    private TripPurpose $purpose = TripPurpose::PRIVATE;

    #[ORM\ManyToOne(targetEntity: Timesheet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Timesheet $timesheet = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    /** Dawarich transportation mode (driving, train, …). */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $mode = null;

    /** Vehicle derived from the mode; null = the user's default vehicle. */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: VehicleType::class)]
    private ?VehicleType $vehicle = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: SuggestionStatus::class)]
    private SuggestionStatus $status = SuggestionStatus::OPEN;

    #[ORM\ManyToOne(targetEntity: Trip::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Trip $trip = null;

    public function __construct(\DateTimeImmutable $startAt, \DateTimeImmutable $endAt)
    {
        $this->startAt = $startAt;
        $this->endAt = $endAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function getEndAt(): \DateTimeImmutable
    {
        return $this->endAt;
    }

    /**
     * Calendar day in the user's timezone (Kimai loads datetimes as UTC).
     */
    public function getDate(): \DateTimeImmutable
    {
        $local = $this->user !== null ? $this->startAt->setTimezone($this->user->getDateTimezone()) : $this->startAt;

        return new \DateTimeImmutable($local->format('Y-m-d'));
    }

    public function getStartLatitude(): float
    {
        return $this->startLatitude;
    }

    public function getStartLongitude(): float
    {
        return $this->startLongitude;
    }

    public function setStart(float $latitude, float $longitude): self
    {
        $this->startLatitude = $latitude;
        $this->startLongitude = $longitude;

        return $this;
    }

    public function getEndLatitude(): float
    {
        return $this->endLatitude;
    }

    public function getEndLongitude(): float
    {
        return $this->endLongitude;
    }

    public function setEnd(float $latitude, float $longitude): self
    {
        $this->endLatitude = $latitude;
        $this->endLongitude = $longitude;

        return $this;
    }

    public function getStartLabel(): ?string
    {
        return $this->startLabel;
    }

    public function setStartLabel(?string $startLabel): self
    {
        $this->startLabel = $startLabel !== null ? mb_substr($startLabel, 0, 255) : null;

        return $this;
    }

    public function getEndLabel(): ?string
    {
        return $this->endLabel;
    }

    public function setEndLabel(?string $endLabel): self
    {
        $this->endLabel = $endLabel !== null ? mb_substr($endLabel, 0, 255) : null;

        return $this;
    }

    public function getStartPlace(): ?Place
    {
        return $this->startPlace;
    }

    public function setStartPlace(?Place $startPlace): self
    {
        $this->startPlace = $startPlace;

        return $this;
    }

    public function getEndPlace(): ?Place
    {
        return $this->endPlace;
    }

    public function setEndPlace(?Place $endPlace): self
    {
        $this->endPlace = $endPlace;

        return $this;
    }

    public function getDistanceKm(): float
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(float $distanceKm): self
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    public function getPointCount(): int
    {
        return $this->pointCount;
    }

    public function setPointCount(int $pointCount): self
    {
        $this->pointCount = $pointCount;

        return $this;
    }

    public function getPurpose(): TripPurpose
    {
        return $this->purpose;
    }

    public function setPurpose(TripPurpose $purpose): self
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function getTimesheet(): ?Timesheet
    {
        return $this->timesheet;
    }

    public function setTimesheet(?Timesheet $timesheet): self
    {
        $this->timesheet = $timesheet;

        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getVehicle(): ?VehicleType
    {
        return $this->vehicle;
    }

    public function setVehicle(?VehicleType $vehicle): self
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getStatus(): SuggestionStatus
    {
        return $this->status;
    }

    public function setStatus(SuggestionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): self
    {
        $this->trip = $trip;

        return $this;
    }
}
