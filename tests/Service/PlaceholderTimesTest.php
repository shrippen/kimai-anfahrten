<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Service\PlaceholderTimes;
use PHPUnit\Framework\TestCase;

class PlaceholderTimesTest extends TestCase
{
    public function testWholeDayInTheUserTimezone(): void
    {
        $utc = new \DateTimeZone('UTC');
        $berlin = new \DateTimeZone('Europe/Berlin');
        // stored in UTC: 00:00–23:59 in Berlin (summer time)
        $departure = new \DateTimeImmutable('2026-06-30 22:00', $utc);
        $arrival = new \DateTimeImmutable('2026-07-01 21:59', $utc);

        self::assertTrue(PlaceholderTimes::isPlaceholder($departure, $arrival, $berlin));
        self::assertFalse(PlaceholderTimes::isPlaceholder($departure, $arrival, $utc));
        // day of the clock change (23 hours)
        self::assertTrue(PlaceholderTimes::isPlaceholder(new \DateTimeImmutable('2026-03-28 23:00', $utc), new \DateTimeImmutable('2026-03-29 21:59', $utc), $berlin));
        // real times, other days, one minute off
        self::assertFalse(PlaceholderTimes::isPlaceholder(new \DateTimeImmutable('2026-07-01 00:00', $utc), new \DateTimeImmutable('2026-07-01 23:58', $utc), $utc));
        self::assertFalse(PlaceholderTimes::isPlaceholder(new \DateTimeImmutable('2026-07-01 00:00', $utc), new \DateTimeImmutable('2026-07-02 23:59', $utc), $utc));
        self::assertFalse(PlaceholderTimes::isPlaceholder(new \DateTimeImmutable('2026-07-01 07:00', $utc), new \DateTimeImmutable('2026-07-01 17:00', $utc), $utc));
    }
}
