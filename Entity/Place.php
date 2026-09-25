<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\Customer;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named location of a user (home, place of work, customer site) used to label detected trips and to link
 * the legs of a journey (see MealAllowanceCalculator).
 */
#[ORM\Entity(repositoryClass: PlaceRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_place')]
class Place
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

    #[ORM\Column(type: Types::STRING, length: 16, enumType: PlaceType::class)]
    private PlaceType $type = PlaceType::OTHER;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $address = null;

    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\Range(min: -90, max: 90)]
    private float $latitude = 0.0;

    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\Range(min: -180, max: 180)]
    private float $longitude = 0.0;

    /** Matching radius in metres. */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 10, max: 5000)]
    private int $radius = 150;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    /** Id of the Dawarich area this place was imported from. */
    #[ORM\Column(name: 'dawarich_area_id', type: Types::INTEGER, nullable: true)]
    private ?int $dawarichAreaId = null;

    /** Id of the Dawarich place this place was imported from. */
    #[ORM\Column(name: 'dawarich_place_id', type: Types::INTEGER, nullable: true)]
    private ?int $dawarichPlaceId = null;

    /**
     * Created automatically where a detected trip started or ended outside all places, so the next trip from
     * there starts at the same place. Saving it in the form makes it a regular place.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $temporary = false;

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

    public function getType(): PlaceType
    {
        return $this->type;
    }

    public function setType(PlaceType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude ?? 0.0;

        return $this;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude ?? 0.0;

        return $this;
    }

    public function getRadius(): int
    {
        return $this->radius;
    }

    public function setRadius(?int $radius): self
    {
        $this->radius = $radius ?? 150;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getDawarichAreaId(): ?int
    {
        return $this->dawarichAreaId;
    }

    public function setDawarichAreaId(?int $dawarichAreaId): self
    {
        $this->dawarichAreaId = $dawarichAreaId;

        return $this;
    }

    public function getDawarichPlaceId(): ?int
    {
        return $this->dawarichPlaceId;
    }

    public function setDawarichPlaceId(?int $dawarichPlaceId): self
    {
        $this->dawarichPlaceId = $dawarichPlaceId;

        return $this;
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    public function setTemporary(bool $temporary): self
    {
        $this->temporary = $temporary;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->address !== null && $this->address !== '' ? $this->name . ', ' . $this->address : (string) $this->name;
    }
}
