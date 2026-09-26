<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Service\DateRange;
use KimaiPlugin\MileageBundle\Service\InvalidInputException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    public function testNothingGivenIsNull(): void
    {
        self::assertNull(DateRange::fromQuery(null, null));
        self::assertNull(DateRange::fromQuery('', ''));
    }

    public function testValidRangeIsInclusive(): void
    {
        $range = DateRange::fromQuery('2026-01-01', '2026-12-31');
        self::assertNotNull($range);
        self::assertSame('2026-01-01', $range->from->format('Y-m-d'));
        self::assertSame('2026-12-31', $range->to->format('Y-m-d'));

        // 2024 is a leap year: 366 days is the maximum
        self::assertNotNull(DateRange::fromQuery('2024-01-01', '2024-12-31'));
        self::assertNotNull(DateRange::fromQuery('2026-03-02', '2026-03-02'));
    }

    public function testBoundsCoverWholeDaysInTimezone(): void
    {
        $range = DateRange::fromQuery('2026-03-01', '2026-03-31');
        self::assertNotNull($range);
        [$from, $until] = $range->bounds(new \DateTimeZone('Europe/Berlin'));
        self::assertSame('2026-03-01T00:00:00+01:00', $from->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-04-01T00:00:00+02:00', $until->format(\DateTimeInterface::ATOM));
    }

    /**
     * @return array<string, array{mixed, mixed, array<string, string>}>
     */
    public static function invalid(): array
    {
        return [
            'only from' => ['2026-01-01', null, ['to' => 'expected a date YYYY-MM-DD']],
            'only to' => [null, '2026-01-01', ['from' => 'expected a date YYYY-MM-DD']],
            'german format' => ['01.01.2026', '2026-01-31', ['from' => 'expected a date YYYY-MM-DD']],
            'overflow' => ['2026-02-30', '2026-03-01', ['from' => 'expected a date YYYY-MM-DD']],
            'array' => [['x'], '2026-03-01', ['from' => 'expected a date YYYY-MM-DD']],
            'reversed' => ['2026-03-02', '2026-03-01', ['to' => 'must not be before from']],
            'too long' => ['2026-01-01', '2027-01-02', ['to' => 'the range must not exceed 366 days']],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('invalid')]
    public function testInvalidInput(mixed $from, mixed $to, array $expected): void
    {
        try {
            DateRange::fromQuery($from, $to);
            self::fail('expected an exception');
        } catch (InvalidInputException $e) {
            self::assertSame($expected, $e->errors);
        }
    }
}
