<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Enum\PrivateUseMethod;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\VehicleRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_vehicle')]
class Vehicle
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
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: VehicleType::class)]
    private VehicleType $type = VehicleType::OWN_CAR;

    #[ORM\Column(name: 'license_plate', type: Types::STRING, length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $licensePlate = null;

    /** Halter (owner as registered). */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $holder = null;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\Expression('this.getValidTo() === null or this.getValidFrom() === null or this.getValidTo() >= this.getValidFrom()', message: 'vehicle.error.period')]
    private ?\DateTimeImmutable $validTo = null;

    /** Odometer reading when the vehicle was added. */
    #[ORM\Column(name: 'initial_odometer', type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(Trip::MAX_ODOMETER)]
    private ?int $initialOdometer = null;

    /** Part of the business assets (Betriebsvermögen) — self-employed only. */
    #[ORM\Column(name: 'business_asset', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $businessAsset = false;

    #[ORM\Column(name: 'private_use', type: Types::STRING, length: 16, enumType: PrivateUseMethod::class, options: ['default' => 'none'])]
    private PrivateUseMethod $privateUse = PrivateUseMethod::NONE;

    /** Bruttolistenpreis in EUR, needed for the 1 % method. */
    #[ORM\Column(name: 'list_price', type: Types::FLOAT, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(10000000)]
    private ?float $listPrice = null;

    /** Electric / hybrid vehicles use a reduced base (0.25 % / 0.5 %). */
    #[ORM\Column(name: 'list_price_factor', type: Types::FLOAT, options: ['default' => 1])]
    #[Assert\Choice(choices: [1.0, 0.5, 0.25])]
    private float $listPriceFactor = 1.0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): VehicleType
    {
        return $this->type;
    }

    public function setType(VehicleType $type): self
    {
        $this->type = $type;

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

    public function getHolder(): ?string
    {
        return $this->holder;
    }

    public function setHolder(?string $holder): self
    {
        $this->holder = $holder;

        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): self
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function getInitialOdometer(): ?int
    {
        return $this->initialOdometer;
    }

    public function setInitialOdometer(?int $initialOdometer): self
    {
        $this->initialOdometer = $initialOdometer;

        return $this;
    }

    public function isBusinessAsset(): bool
    {
        return $this->businessAsset;
    }

    public function setBusinessAsset(bool $businessAsset): self
    {
        $this->businessAsset = $businessAsset;

        return $this;
    }

    public function getPrivateUse(): PrivateUseMethod
    {
        return $this->privateUse;
    }

    public function setPrivateUse(PrivateUseMethod $privateUse): self
    {
        $this->privateUse = $privateUse;

        return $this;
    }

    public function getListPrice(): ?float
    {
        return $this->listPrice;
    }

    public function setListPrice(?float $listPrice): self
    {
        $this->listPrice = $listPrice;

        return $this;
    }

    public function getListPriceFactor(): float
    {
        return $this->listPriceFactor;
    }

    public function setListPriceFactor(?float $listPriceFactor): self
    {
        $this->listPriceFactor = $listPriceFactor ?? 1.0;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function isValidOn(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return ($this->validFrom === null || $this->validFrom->format('Y-m-d') <= $day)
            && ($this->validTo === null || $this->validTo->format('Y-m-d') >= $day);
    }

    public function getLabel(): string
    {
        return $this->licensePlate !== null && $this->licensePlate !== '' ? $this->name . ' (' . $this->licensePlate . ')' : (string) $this->name;
    }
}
