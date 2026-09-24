<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\Timesheet;

/**
 * Finds the timesheet a trip belongs to: the one starting shortly after arrival
 * (drive to the customer) or ending shortly before departure (drive back).
 */
class TimesheetMatcher
{
    private const BEFORE_WORK_SECONDS = 2 * 3600;
    private const AFTER_WORK_SECONDS = 2 * 3600;
    /** Tolerance for GPS stop detection vs. manually rounded times. */
    private const TOLERANCE_SECONDS = 30 * 60;

    /**
     * @param Timesheet[] $timesheets
     */
    public function match(array $timesheets, \DateTimeInterface $departure, \DateTimeInterface $arrival): ?Timesheet
    {
        $best = null;
        $bestGap = PHP_INT_MAX;
        $dep = $departure->getTimestamp();
        $arr = $arrival->getTimestamp();

        foreach ($timesheets as $timesheet) {
            $begin = $timesheet->getBegin()?->getTimestamp();
            $end = $timesheet->getEnd()?->getTimestamp();

            // Drive to work: the timesheet begins after arrival.
            if ($begin !== null && $begin >= $arr - self::TOLERANCE_SECONDS && $begin <= $arr + self::BEFORE_WORK_SECONDS) {
                $gap = abs($begin - $arr);
                if ($gap < $bestGap) {
                    $best = $timesheet;
                    $bestGap = $gap;
                }
            }

            // Drive back: the timesheet ended before departure.
            if ($end !== null && $end <= $dep + self::TOLERANCE_SECONDS && $end >= $dep - self::AFTER_WORK_SECONDS) {
                $gap = abs($dep - $end);
                if ($gap < $bestGap) {
                    $best = $timesheet;
                    $bestGap = $gap;
                }
            }
        }

        return $best;
    }
}
