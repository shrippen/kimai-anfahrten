<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * State of the automatic trip detection of one user (see {@see AutoDetector}).
 */
final class DetectionStatus
{
    /**
     * @param string|null $coveredUntil last day (Y-m-d, user's calendar) scanned without error
     * @param \DateTimeImmutable|null $lastRunAt end of the last run
     * @param \DateTimeImmutable|null $runningSince start of the run in progress
     * @param string|null $error translation key of the last run's error, null after a successful run
     * @param array<string, string|int> $errorParameters
     */
    public function __construct(
        public readonly ?string $coveredUntil = null,
        public readonly ?\DateTimeImmutable $lastRunAt = null,
        public readonly ?\DateTimeImmutable $runningSince = null,
        public readonly int $lastCount = 0,
        public readonly ?string $error = null,
        public readonly array $errorParameters = [],
    ) {
    }
}
