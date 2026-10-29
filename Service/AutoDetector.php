<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Detects trips from Dawarich without the user pressing anything: once a day per user, for the days that are over.
 *
 * Started by the browser after a page load (see {@see \KimaiPlugin\MileageBundle\EventSubscriber\AutoDetectionSubscriber}),
 * so it never delays a page, or by `kimai:bundle:mileage:suggest --auto` from a cron job.
 *
 * - Only finished days (up to yesterday in the user's timezone): a trip of today may still be under way, and a
 *   suggestion of half a trip would never be corrected (trips already suggested are skipped by their start).
 * - Each run scans from the last scanned day (once more, for points uploaded late) to yesterday; the first run the
 *   last {@see self::FIRST_DAYS} days, after a long break at most {@see self::MAX_DAYS} days.
 * - Months closed with the month lock are skipped.
 * - Results stay suggestions; only the user's confirmation makes them trips.
 * - Errors are stored for the suggestions page and retried after {@see self::RETRY_MINUTES} minutes.
 */
class AutoDetector
{
    public const FIRST_DAYS = 7;
    public const MAX_DAYS = 31;
    public const RETRY_MINUTES = 60;
    /** A run that started longer ago is taken as aborted (PHP time limit, crash). */
    public const STALE_MINUTES = 15;
    public const ERROR_UNEXPECTED = 'mileage.auto_detect.error.unexpected';
    private const NO_TRACKS = 'mileage.dawarich.error.no_tracks';

    public function __construct(
        private readonly MileageConfiguration $configuration,
        private readonly SuggestionService $suggestionService,
        private readonly MonthLockService $lockService,
        private readonly DetectionStatusStore $store,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isEnabled(User $user): bool
    {
        return $this->configuration->isAutoDetectEnabled($user) && $this->configuration->isDawarichConfigured($user);
    }

    public function getStatus(User $user): ?DetectionStatus
    {
        return $this->store->findStatus($user);
    }

    public function isDue(User $user, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->isEnabled($user) && self::due($this->store->findStatus($user), $this->yesterday($user, $now), $now);
    }

    public static function due(?DetectionStatus $status, \DateTimeImmutable $yesterday, \DateTimeImmutable $now): bool
    {
        if ($status === null) {
            return true;
        }
        if ($status->runningSince !== null && $status->runningSince > $now->modify(\sprintf('-%d minutes', self::STALE_MINUTES))) {
            return false;
        }
        if ($status->coveredUntil !== null && $status->coveredUntil >= $yesterday->format('Y-m-d')) {
            return false;
        }

        return $status->error === null || $status->lastRunAt === null
            || $status->lastRunAt <= $now->modify(\sprintf('-%d minutes', self::RETRY_MINUTES));
    }

    /**
     * Runs the detection when it is due. Never throws: errors are stored (see {@see DetectionStatus::$error}).
     *
     * @return int|null number of new suggestions; null when nothing ran or the run failed
     */
    public function run(User $user, ?\DateTimeImmutable $now = null): ?int
    {
        $now ??= new \DateTimeImmutable();
        try {
            if (!$this->isEnabled($user)) {
                return null;
            }
            $status = $this->store->findStatus($user);
            $yesterday = $this->yesterday($user, $now);
            if (!self::due($status, $yesterday, $now)
                || !$this->store->claim($user, $now, $now->modify(\sprintf('-%d minutes', self::STALE_MINUTES)))) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Mileage: automatic trip detection could not start: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }

        try {
            $count = 0;
            foreach (self::months(self::firstDay($status?->coveredUntil, $yesterday), $yesterday) as [$from, $to]) {
                if ($this->lockService->isLocked($user, $from)) {
                    continue;
                }
                try {
                    $count += $this->suggestionService->detect($user, $from, $to);
                } catch (DawarichException $e) {
                    // nothing recorded (e.g. a weekend) is no error
                    if ($e->getMessage() !== self::NO_TRACKS) {
                        throw $e;
                    }
                }
            }
            $this->store->succeed($user, $now, $yesterday->format('Y-m-d'), $count);

            return $count;
        } catch (DawarichException $e) {
            $this->failed($user, $now, $e->getMessage(), $e->getParameters());
        } catch (\Throwable $e) {
            $this->logger?->error('Mileage: automatic trip detection failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $this->failed($user, $now, self::ERROR_UNEXPECTED, []);
        }

        return null;
    }

    /**
     * First day to scan: the last scanned day again (points may arrive late), but at most MAX_DAYS back;
     * FIRST_DAYS back on the first run.
     */
    public static function firstDay(?string $coveredUntil, \DateTimeImmutable $yesterday): \DateTimeImmutable
    {
        $earliest = $yesterday->modify(\sprintf('-%d days', self::MAX_DAYS - 1));
        if ($coveredUntil === null) {
            return $yesterday->modify(\sprintf('-%d days', self::FIRST_DAYS - 1));
        }
        $covered = \DateTimeImmutable::createFromFormat('!Y-m-d', $coveredUntil, $yesterday->getTimezone());
        if ($covered === false) {
            return $earliest;
        }

        return min($yesterday, max($earliest, $covered));
    }

    /**
     * The period split at month boundaries (the month lock is per month).
     *
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    public static function months(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $periods = [];
        while ($from <= $to) {
            $end = min($to, $from->modify('last day of this month'));
            $periods[] = [$from, $end];
            $from = $end->modify('+1 day');
        }

        return $periods;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function failed(User $user, \DateTimeImmutable $now, string $error, array $parameters): void
    {
        try {
            $this->store->fail($user, $now, $error, $parameters);
        } catch (\Throwable $e) {
            $this->logger?->error('Mileage: could not store the detection error: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function yesterday(User $user, \DateTimeImmutable $now): \DateTimeImmutable
    {
        try {
            $timezone = $user->getDateTimezone();
        } catch (\Exception) {
            $timezone = new \DateTimeZone(date_default_timezone_get());
        }

        return $now->setTimezone($timezone)->setTime(0, 0)->modify('-1 day');
    }
}
