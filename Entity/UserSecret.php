<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Repository\UserSecretRepository;

/**
 * Per-user secrets (the Dawarich API key). Kept out of Kimai's user preferences on purpose:
 * those are serialized by the user API (`/api/users/me`) and passed to invoice templates.
 */
#[ORM\Entity(repositoryClass: UserSecretRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_user_secret')]
class UserSecret
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'dawarich_api_key', type: Types::STRING, length: 255, nullable: true)]
    private ?string $dawarichApiKey = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDawarichApiKey(): ?string
    {
        return $this->dawarichApiKey;
    }

    public function setDawarichApiKey(?string $dawarichApiKey): self
    {
        $this->dawarichApiKey = $dawarichApiKey;

        return $this;
    }
}
