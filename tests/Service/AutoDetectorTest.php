<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Service\AutoDetector;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\DawarichKeyStore;
use KimaiPlugin\MileageBundle\Service\DetectionStatus;
use KimaiPlugin\MileageBundle\Service\DetectionStatusStore;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use PHPUnit\Framework\TestCase;

class AutoDetectorTest extends TestCase
{
    /** @var list<array{string, string}> periods passed to detect() (Y-m-d) */
    private array $calls = [];
    /** @var list<int|\Throwable> what detect() does on each call (default: 1 new suggestion) */
    private array $results = [];
    /** @var string[] locked months (Y-m) */
    private array $locked = [];
    private MemoryStatusStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryStatusStore();
    }

    /**
     * @param array<string, string|int|bool|float|null> $settings
     */
    private function detector(array $settings = [], ?string $key = 'secret'): AutoDetector
    {
        $keys = new class ($key) implements DawarichKeyStore {
            public function __construct(private readonly ?string $key)
            {
            }

            public function getDawarichApiKey(User $user): ?string
            {
                return $this->key;
            }

            public function setDawarichApiKey(User $user, ?string $key): void
            {
            }
        };
        $config = new MileageConfiguration(new SystemConfiguration($settings + ['mileage.dawarich_url' => 'https://dawarich.example']), $keys);

        $suggestions = $this->createMock(SuggestionService::class);
        $suggestions->method('detect')->willReturnCallback(function (User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): int {
            $this->calls[] = [$from->format('Y-m-d'), $to->format('Y-m-d')];
            $result = array_shift($this->results) ?? 1;
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        });
        $locks = $this->createMock(MonthLockService::class);
        $locks->method('isLocked')->willReturnCallback(fn (User $user, \DateTimeInterface $date): bool => \in_array($date->format('Y-m'), $this->locked, true));

        return new AutoDetector($config, $suggestions, $locks, $this->store);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testFirstRunScansTheLastSevenFinishedDays(): void
    {
        $user = new User(1);

        self::assertSame(1, $this->detector()->run($user, self::at('2026-10-29 10:00')));
        self::assertSame([['2026-10-22', '2026-10-28']], $this->calls);
        self::assertSame('2026-10-28', $this->store->status?->coveredUntil);
        self::assertNull($this->store->status->runningSince);
        self::assertSame(1, $this->store->status->lastCount);
    }

    public function testRunsOncePerDayAndScansTheLastDayAgain(): void
    {
        $user = new User(1);
        $detector = $this->detector();
        $detector->run($user, self::at('2026-10-29 10:00'));

        self::assertNull($detector->run($user, self::at('2026-10-29 18:00')), 'not due the same day');
        self::assertFalse($detector->isDue($user, self::at('2026-10-29 18:00')));
        self::assertTrue($detector->isDue($user, self::at('2026-10-30 08:00')));

        self::assertSame(1, $detector->run($user, self::at('2026-10-30 08:00')));
        self::assertSame([['2026-10-22', '2026-10-28'], ['2026-10-28', '2026-10-29']], $this->calls);
        self::assertSame('2026-10-29', $this->store->status?->coveredUntil);
    }

    public function testTodayIsTheUsersCalendarDay(): void
    {
        $user = new User(1);
        $user->setPreferenceValue('timezone', 'Europe/Berlin');

        // 23:30 UTC on the 29th is 00:30 on the 30th in Berlin: the 29th is over
        $this->detector()->run($user, self::at('2026-10-29 23:30'));

        self::assertSame([['2026-10-23', '2026-10-29']], $this->calls);
    }

    public function testLongBreakIsCapped(): void
    {
        $user = new User(1);
        $this->store->status = new DetectionStatus('2026-01-15', self::at('2026-01-16 08:00'));

        $this->detector()->run($user, self::at('2026-10-29 10:00'));

        self::assertSame([['2026-09-28', '2026-09-30'], ['2026-10-01', '2026-10-28']], $this->calls);
    }

    public function testClosedMonthsAreSkipped(): void
    {
        $user = new User(1);
        $this->store->status = new DetectionStatus('2026-09-28', self::at('2026-09-29 08:00'));
        $this->locked = ['2026-09'];

        self::assertSame(1, $this->detector()->run($user, self::at('2026-10-03 10:00')));
        self::assertSame([['2026-10-01', '2026-10-02']], $this->calls);
        self::assertSame('2026-10-02', $this->store->status?->coveredUntil);
    }

    public function testDaysWithoutTracksAreNoError(): void
    {
        $user = new User(1);
        $this->results = [new DawarichException('mileage.dawarich.error.no_tracks')];

        self::assertSame(0, $this->detector()->run($user, self::at('2026-10-29 10:00')));
        self::assertNull($this->store->status?->error);
        self::assertSame('2026-10-28', $this->store->status?->coveredUntil);
    }

    public function testDawarichErrorIsStoredAndRetriedAfterAnHour(): void
    {
        $user = new User(1);
        $this->store->status = new DetectionStatus('2026-10-27', self::at('2026-10-28 08:00'), null, 3);
        $this->results = [new DawarichException('mileage.dawarich.error.http', ['%status%' => 502])];
        $detector = $this->detector();

        self::assertNull($detector->run($user, self::at('2026-10-29 10:00')));
        $status = $this->store->status;
        self::assertSame('mileage.dawarich.error.http', $status?->error);
        self::assertSame(['%status%' => 502], $status->errorParameters);
        self::assertSame('2026-10-27', $status->coveredUntil, 'failed days are scanned again');
        self::assertSame(3, $status->lastCount);
        self::assertNull($status->runningSince);

        self::assertFalse($detector->isDue($user, self::at('2026-10-29 10:30')));
        self::assertNull($detector->run($user, self::at('2026-10-29 10:30')));
        self::assertCount(1, $this->calls);

        self::assertSame(1, $detector->run($user, self::at('2026-10-29 11:00')));
        self::assertSame(['2026-10-27', '2026-10-28'], $this->calls[1]);
        self::assertNull($this->store->status?->error);
    }

    public function testUnexpectedErrorsNeverEscape(): void
    {
        $user = new User(1);
        $this->results = [new \RuntimeException('database gone')];

        self::assertNull($this->detector()->run($user, self::at('2026-10-29 10:00')));
        self::assertSame(AutoDetector::ERROR_UNEXPECTED, $this->store->status?->error);
        self::assertNull($this->store->status->coveredUntil);
    }

    public function testNoSecondRunWhileOneIsInProgress(): void
    {
        $user = new User(1);
        $this->store->status = new DetectionStatus('2026-10-20', null, self::at('2026-10-29 09:55'));
        $detector = $this->detector();

        self::assertFalse($detector->isDue($user, self::at('2026-10-29 10:00')));
        self::assertNull($detector->run($user, self::at('2026-10-29 10:00')));
        self::assertSame([], $this->calls);

        // an aborted run (time limit) does not block for ever
        self::assertSame(1, $detector->run($user, self::at('2026-10-29 10:30')));
    }

    public function testClaimLostToAParallelRequest(): void
    {
        $user = new User(1);
        $this->store->claimable = false;

        self::assertNull($this->detector()->run($user, self::at('2026-10-29 10:00')));
        self::assertSame([], $this->calls);
    }

    /**
     * @return array<string, array{array<string, string|int|bool|float|null>, mixed, ?string}>
     */
    public static function disabled(): array
    {
        return [
            'system setting off' => [['mileage.auto_detect' => false], null, 'secret'],
            'system setting saved empty' => [['mileage.auto_detect' => ''], null, 'secret'],
            'user preference off' => [[], false, 'secret'],
            'user preference "0"' => [[], '0', 'secret'],
            'no Dawarich key' => [[], null, null],
        ];
    }

    /**
     * @dataProvider disabled
     *
     * @param array<string, string|int|bool|float|null> $settings
     */
    public function testDisabled(array $settings, mixed $preference, ?string $key): void
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_AUTO_DETECT, $preference);
        $detector = $this->detector($settings, $key);

        self::assertFalse($detector->isEnabled($user));
        self::assertFalse($detector->isDue($user, self::at('2026-10-29 10:00')));
        self::assertNull($detector->run($user, self::at('2026-10-29 10:00')));
        self::assertSame([], $this->calls);
        self::assertNull($this->store->status);
    }

    public function testEnabledByDefaultAndWithTheBoxesTicked(): void
    {
        $user = new User(1);
        self::assertTrue($this->detector()->isEnabled($user));

        $user->setPreferenceValue(MileageConfiguration::PREF_AUTO_DETECT, true);
        self::assertTrue($this->detector(['mileage.auto_detect' => '1'])->isEnabled($user));
    }

    public function testMonths(): void
    {
        $periods = AutoDetector::months(new \DateTimeImmutable('2026-12-30'), new \DateTimeImmutable('2027-02-02'));

        self::assertSame(
            [['2026-12-30', '2026-12-31'], ['2027-01-01', '2027-01-31'], ['2027-02-01', '2027-02-02']],
            array_map(static fn (array $p) => [$p[0]->format('Y-m-d'), $p[1]->format('Y-m-d')], $periods)
        );
    }
}

/**
 * The store as the repository behaves, in memory.
 */
class MemoryStatusStore implements DetectionStatusStore
{
    public ?DetectionStatus $status = null;
    public bool $claimable = true;

    public function findStatus(User $user): ?DetectionStatus
    {
        return $this->status;
    }

    public function claim(User $user, \DateTimeImmutable $now, \DateTimeImmutable $staleBefore): bool
    {
        $s = $this->status;
        if (!$this->claimable || ($s?->runningSince !== null && $s->runningSince >= $staleBefore)) {
            return false;
        }
        $this->status = new DetectionStatus($s?->coveredUntil, $s?->lastRunAt, $now, $s->lastCount ?? 0, $s?->error, $s->errorParameters ?? []);

        return true;
    }

    public function succeed(User $user, \DateTimeImmutable $now, string $coveredUntil, int $count): void
    {
        $this->status = new DetectionStatus($coveredUntil, $now, null, $count);
    }

    public function fail(User $user, \DateTimeImmutable $now, string $error, array $parameters = []): void
    {
        $s = $this->status;
        $this->status = new DetectionStatus($s?->coveredUntil, $now, null, $s->lastCount ?? 0, $error, $parameters);
    }
}
