<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\TripSource;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_trip')]
#[ORM\Index(columns: ['trip_date'], name: 'idx_mileage_trip_date')]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'trip_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    /** Start of the time window (used for the Dawarich lookup). */
    #[ORM\Column(name: 'departure_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $departureAt = null;

    /** End of the time window (used for the Dawarich lookup). */
    #[ORM\Column(name: 'arrival_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Assert\Expression('this.getArrivalAt() === null or this.getDepartureAt() === null or this.getArrivalAt() > this.getDepartureAt()', message: 'trip.error.arrival_before_departure')]
    private ?\DateTimeImmutable $arrivalAt = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: TripPurpose::class)]
    private TripPurpose $purpose = TripPurpose::BUSINESS;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: VehicleType::class)]
    private VehicleType $vehicle = VehicleType::OWN_CAR;

    #[ORM\Column(name: 'start_location', type: Types::STRING, length: 255, nullable: true)]
    private ?string $startLocation = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $destination = null;

    /**
     * Distance of one direction in km.
     * Commute: the one-way distance (einfache Entfernung) used for the Entfernungspauschale.
     * Business/private: the driven distance; doubled when {@see $roundTrip} is set.
     */
    #[ORM\Column(name: 'distance_km', type: Types::FLOAT, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private float $distanceKm = 0.0;

    #[ORM\Column(name: 'round_trip', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $roundTrip = false;

    /** Actual costs in EUR (rental car, fuel for rental, tickets, …). */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $costs = null;

    #[ORM\Column(name: 'license_plate', type: Types::STRING, length: 20, nullable: true)]
    private ?string $licensePlate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: TripSource::class)]
    private TripSource $source = TripSource::MANUAL;

    /** Number of GPS points Dawarich returned for the time window. */
    #[ORM\Column(name: 'point_count', type: Types::INTEGER, nullable: true)]
    private ?int $pointCount = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    /** The concrete vehicle (logbook); {@see $vehicle} keeps the tax category. */
    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(name: 'assigned_vehicle_id', nullable: true, onDelete: 'SET NULL')]
    private ?Vehicle $assignedVehicle = null;

    #[ORM\ManyToOne(targetEntity: Rental::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Rental $rental = null;

    #[ORM\Column(name: 'odometer_start', type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $odometerStart = null;

    #[ORM\Column(name: 'odometer_end', type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Assert\Expression('this.getOdometerEnd() === null or this.getOdometerStart() === null or this.getOdometerEnd() >= this.getOdometerStart()', message: 'odometer.error.order')]
    private ?int $odometerEnd = null;

    #[ORM\ManyToOne(targetEntity: Timesheet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Timesheet $timesheet = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): self
    {
        $this->date = $date?->setTime(0, 0);

        return $this;
    }

    public function getDepartureAt(): ?\DateTimeImmutable
    {
        return $this->departureAt;
    }

    public function setDepartureAt(?\DateTimeImmutable $departureAt): self
    {
        $this->departureAt = $departureAt;

        return $this;
    }

    public function getArrivalAt(): ?\DateTimeImmutable
    {
        return $this->arrivalAt;
    }

    public function setArrivalAt(?\DateTimeImmutable $arrivalAt): self
    {
        $this->arrivalAt = $arrivalAt;

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

    public function getVehicle(): VehicleType
    {
        return $this->vehicle;
    }

    public function setVehicle(VehicleType $vehicle): self
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getStartLocation(): ?string
    {
        return $this->startLocation;
    }

    public function setStartLocation(?string $startLocation): self
    {
        $this->startLocation = $startLocation;

        return $this;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(?string $destination): self
    {
        $this->destination = $destination;

        return $this;
    }

    public function getDistanceKm(): float
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?float $distanceKm): self
    {
        $this->distanceKm = $distanceKm ?? 0.0;

        return $this;
    }

    public function isRoundTrip(): bool
    {
        return $this->roundTrip;
    }

    public function setRoundTrip(bool $roundTrip): self
    {
        $this->roundTrip = $roundTrip;

        return $this;
    }

    /**
     * Kilometres actually travelled (logbook value).
     * A commute always consists of the way there and back.
     */
    public function getTotalDistanceKm(): float
    {
        if ($this->purpose === TripPurpose::COMMUTE || $this->roundTrip) {
            return $this->distanceKm * 2;
        }

        return $this->distanceKm;
    }

    public function getCosts(): ?float
    {
        return $this->costs;
    }

    public function setCosts(?float $costs): self
    {
        $this->costs = $costs;

        return $this;
    }

    public function getLicensePlate(): ?string
    {
        return $this->licensePlate;
    }

    public function setLicensePlate(?string $licensePlate): self
    {
        $this->licensePlate = $licensePlate;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function getSource(): TripSource
    {
        return $this->source;
    }

    public function setSource(TripSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getPointCount(): ?int
    {
        return $this->pointCount;
    }

    public function setPointCount(?int $pointCount): self
    {
        $this->pointCount = $pointCount;

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

    public function getAssignedVehicle(): ?Vehicle
    {
        return $this->assignedVehicle;
    }

    /**
     * Also takes over tax category and license plate of the vehicle.
     */
    public function setAssignedVehicle(?Vehicle $assignedVehicle): self
    {
        $this->assignedVehicle = $assignedVehicle;
        if ($assignedVehicle !== null) {
            $this->vehicle = $assignedVehicle->getType();
            $this->licensePlate = $assignedVehicle->getLicensePlate() ?? $this->licensePlate;
        }

        return $this;
    }

    public function getRental(): ?Rental
    {
        return $this->rental;
    }

    public function setRental(?Rental $rental): self
    {
        $this->rental = $rental;

        return $this;
    }

    public function getOdometerStart(): ?int
    {
        return $this->odometerStart;
    }

    public function setOdometerStart(?int $odometerStart): self
    {
        $this->odometerStart = $odometerStart;

        return $this;
    }

    public function getOdometerEnd(): ?int
    {
        return $this->odometerEnd;
    }

    public function setOdometerEnd(?int $odometerEnd): self
    {
        $this->odometerEnd = $odometerEnd;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
