<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;

/**
 * Where the state of the automatic trip detection lives ({@see \KimaiPlugin\MileageBundle\Repository\DetectionRunRepository}).
 */
interface DetectionStatusStore
{
    public function findStatus(User $user): ?DetectionStatus;

    /**
     * Marks a run as started. False when another run of the user started after $staleBefore and has not finished:
     * then this one must not run (two requests at once).
     */
    public function claim(User $user, \DateTimeImmutable $now, \DateTimeImmutable $staleBefore): bool;

    /**
     * Ends the run: everything up to $coveredUntil (Y-m-d) is scanned, the error is cleared.
     */
    public function succeed(User $user, \DateTimeImmutable $now, string $coveredUntil, int $count): void;

    /**
     * Ends the run with an error; the scanned days stay as they were, so the next run tries them again.
     *
     * @param array<string, string|int> $parameters
     */
    public function fail(User $user, \DateTimeImmutable $now, string $error, array $parameters = []): void;
}
