<?php

namespace KimaiPlugin\MileageBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\MileageBundle\Repository\DetectionRunRepository;

/**
 * State of the automatic trip detection per user ({@see \KimaiPlugin\MileageBundle\Service\AutoDetector}): up to
 * which day Dawarich was scanned, when it last ran, the last error and a claim so two requests never run at once.
 * Only mapped for the schema; read and written with plain SQL by {@see DetectionRunRepository}.
 */
#[ORM\Entity(repositoryClass: DetectionRunRepository::class)]
#[ORM\Table(name: 'kimai2_ext_mileage_detection_run')]
class DetectionRun
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Last day (user's calendar) scanned without error. */
    #[ORM\Column(name: 'covered_until', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $coveredUntil = null;

    /** End of the last run (UTC). */
    #[ORM\Column(name: 'last_run_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    /** Start of the run in progress (UTC); null when none runs. */
    #[ORM\Column(name: 'running_since', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $runningSince = null;

    /** New suggestions of the last successful run. */
    #[ORM\Column(name: 'last_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $lastCount = 0;

    /** Translation key of the last run's error; null after a successful run. */
    #[ORM\Column(name: 'error', type: Types::STRING, length: 255, nullable: true)]
    private ?string $error = null;

    /** Translation parameters of the error, JSON. */
    #[ORM\Column(name: 'error_parameters', type: Types::TEXT, nullable: true)]
    private ?string $errorParameters = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCoveredUntil(): ?\DateTimeImmutable
    {
        return $this->coveredUntil;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getRunningSince(): ?\DateTimeImmutable
    {
        return $this->runningSince;
    }

    public function getLastCount(): int
    {
        return $this->lastCount;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorParameters(): ?string
    {
        return $this->errorParameters;
    }
}
