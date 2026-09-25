<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Inclusive range of calendar days from the query parameters "from" and "to" (YYYY-MM-DD).
 */
final class DateRange
{
    public const MAX_DAYS = 366;

    private function __construct(public readonly \DateTimeImmutable $from, public readonly \DateTimeImmutable $to)
    {
    }

    /**
     * Null when neither parameter is given (the caller falls back to year/month).
     *
     * @throws InvalidInputException
     */
    public static function fromQuery(mixed $from, mixed $to, int $maxDays = self::MAX_DAYS): ?self
    {
        if (($from === null || $from === '') && ($to === null || $to === '')) {
            return null;
        }

        $errors = [];
        $start = self::day($from);
        $end = self::day($to);
        if ($start === null) {
            $errors['from'] = 'expected a date YYYY-MM-DD';
        }
        if ($end === null) {
            $errors['to'] = 'expected a date YYYY-MM-DD';
        }
        if ($start !== null && $end !== null) {
            if ($end < $start) {
                $errors['to'] = 'must not be before from';
            } elseif ($start->diff($end)->days + 1 > $maxDays) {
                $errors['to'] = 'the range must not exceed ' . $maxDays . ' days';
            }
        }
        if ($errors !== [] || $start === null || $end === null) {
            throw new InvalidInputException($errors);
        }

        return new self($start, $end);
    }

    /**
     * Start of the first and end of the last day in the given timezone (for datetime columns).
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable} [start inclusive, end exclusive]
     */
    public function bounds(\DateTimeZone $timezone): array
    {
        return [
            new \DateTimeImmutable($this->from->format('Y-m-d'), $timezone),
            new \DateTimeImmutable($this->to->modify('+1 day')->format('Y-m-d'), $timezone),
        ];
    }

    private static function day(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
