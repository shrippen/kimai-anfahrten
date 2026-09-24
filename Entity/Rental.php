<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Repository\RentalRepository;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A car rental: all rental-car trips in the period belong to it and share its costs.
 */
#[ORM\Entity(repositoryClass: RentalRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_rental')]
class Rental
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    private ?string $provider = null;

    #[ORM\Column(name: 'license_plate', type: Types::STRING, length: 20, nullable: true)]
    private ?string $licensePlate = null;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\Expression('this.getEndDate() === null or this.getStartDate() === null or this.getEndDate() >= this.getStartDate()', message: 'vehicle.error.period')]
    private ?\DateTimeImmutable $endDate = null;

    /** Rent incl. insurance and fees in EUR. */
    #[ORM\Column(name: 'rental_costs', type: Types::FLOAT, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private float $rentalCosts = 0.0;

    #[ORM\Column(name: 'fuel_costs', type: Types::FLOAT, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private float $fuelCosts = 0.0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

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

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): self
    {
        $this->provider = $provider;

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

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getRentalCosts(): float
    {
        return $this->rentalCosts;
    }

    public function setRentalCosts(?float $rentalCosts): self
    {
        $this->rentalCosts = $rentalCosts ?? 0.0;

        return $this;
    }

    public function getFuelCosts(): float
    {
        return $this->fuelCosts;
    }

    public function setFuelCosts(?float $fuelCosts): self
    {
        $this->fuelCosts = $fuelCosts ?? 0.0;

        return $this;
    }

    public function getTotalCosts(): float
    {
        return $this->rentalCosts + $this->fuelCosts;
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

    public function covers(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->startDate !== null && $this->endDate !== null
            && $this->startDate->format('Y-m-d') <= $day && $this->endDate->format('Y-m-d') >= $day;
    }

    public function getLabel(): string
    {
        return \sprintf('%s %s–%s', $this->provider, $this->startDate?->format('d.m.'), $this->endDate?->format('d.m.Y'));
    }
}
