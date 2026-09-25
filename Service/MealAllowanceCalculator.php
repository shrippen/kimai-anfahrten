<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;

/**
 * Verpflegungsmehraufwand (§ 9 Abs. 4a EStG, for self-employed via § 4 Abs. 5 Nr. 5 EStG).
 *
 * - one-day absence of more than 8 hours: partial rate (several absences on one day add up)
 * - multi-day journey: partial rate on the days of departure and return, full rate in between;
 *   a journey spans several days when a trip ends on a later day or is marked "overnight"
 * - three-month rule: at the same destination the allowance ends after three months,
 *   a break of at least four weeks starts a new period
 *
 * Deliberate simplifications: no reduction for meals provided, destinations are compared by name.
 */
class MealAllowanceCalculator
{
    private const MIN_HOURS = 8.0;
    private const BREAK_DAYS = 28;

    /**
     * @param Trip[] $trips
     * @return array{
     *     days: list<array{date: string, hours: float, kind: string, amount: float, destination: ?string, three_month: bool}>,
     *     partial_days: int,
     *     full_days: int,
     *     excluded_days: int,
     *     missing_times: int,
     *     amount: float
     * }
     */
    public function calculate(array $trips, TaxRates $rates, \DateTimeZone $timezone): array
    {
        $business = [];
        $missing = 0;
        foreach ($trips as $trip) {
            if ($trip->getPurpose() !== TripPurpose::BUSINESS) {
                continue;
            }
            if ($trip->getDepartureAt() === null || $trip->getArrivalAt() === null) {
                $missing++;
                continue;
            }
            $business[] = $trip;
        }
        usort($business, static fn (Trip $a, Trip $b) => $a->getDepartureAt() <=> $b->getDepartureAt());

        /** @var array<string, array{hours: float, kind: ?string, destination: ?string}> $days */
        $days = [];
        $journey = null;

        $close = function (?array $journey) use (&$days, $timezone): void {
            if ($journey === null) {
                return;
            }
            /** @var \DateTimeImmutable $start */
            $start = $journey['start']->setTimezone($timezone);
            /** @var \DateTimeImmutable $end */
            $end = $journey['end']->setTimezone($timezone);
            $first = $start->format('Y-m-d');
            $last = $end->format('Y-m-d');

            if ($first === $last) {
                $days[$first] ??= ['hours' => 0.0, 'kind' => null, 'destination' => $journey['destination']];
                $days[$first]['hours'] += ($end->getTimestamp() - $start->getTimestamp()) / 3600;

                return;
            }

            for ($day = new \DateTimeImmutable($first); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');
                $days[$key] ??= ['hours' => 0.0, 'kind' => null, 'destination' => $journey['destination']];
                $kind = ($key === $first || $key === $last) ? 'travel_day' : 'full_day';
                // a full day beats a travel day beats a plain one-day absence
                if ($days[$key]['kind'] !== 'full_day') {
                    $days[$key]['kind'] = $kind;
                }
                $days[$key]['hours'] = $kind === 'full_day' ? 24.0 : max($days[$key]['hours'], 0.0);
            }
        };

        foreach ($business as $trip) {
            /** @var \DateTimeImmutable $dep */
            $dep = $trip->getDepartureAt();
            /** @var \DateTimeImmutable $arr */
            $arr = $trip->getArrivalAt();

            if ($journey !== null && $journey['open']) {
                $journey['end'] = max($journey['end'], $arr);
                $journey['open'] = $trip->isOvernight();
                continue;
            }

            $close($journey);
            $journey = ['start' => $dep, 'end' => $arr, 'open' => $trip->isOvernight(), 'destination' => $trip->getDestination()];
        }
        $close($journey);

        ksort($days);

        $result = [];
        $streaks = [];
        $totals = ['partial_days' => 0, 'full_days' => 0, 'excluded_days' => 0, 'amount' => 0.0];

        foreach ($days as $date => $day) {
            $kind = $day['kind'] ?? ($day['hours'] > self::MIN_HOURS ? 'absence' : null);
            if ($kind === null) {
                continue;
            }

            $threeMonth = false;
            $destination = self::normalize($day['destination']);
            if ($destination !== null) {
                $current = new \DateTimeImmutable($date);
                $streak = $streaks[$destination] ?? null;
                if ($streak === null || $current > $streak['last']->modify('+' . self::BREAK_DAYS . ' days')) {
                    $streak = ['start' => $current, 'last' => $current];
                }
                $streak['last'] = $current;
                $streaks[$destination] = $streak;
                $threeMonth = $current >= $streak['start']->modify('+3 months');
            }

            // A journey over New Year belongs to two tax years: only count the days of this one.
            if ((int) substr($date, 0, 4) !== $rates->year) {
                continue;
            }

            $amount = $threeMonth ? 0.0 : ($kind === 'full_day' ? $rates->mealFull : $rates->mealPartial);
            if ($threeMonth) {
                $totals['excluded_days']++;
            } elseif ($kind === 'full_day') {
                $totals['full_days']++;
            } else {
                $totals['partial_days']++;
            }
            $totals['amount'] += $amount;

            $result[] = [
                'date' => $date,
                'hours' => round($day['hours'], 1),
                'kind' => $kind,
                'amount' => $amount,
                'destination' => $day['destination'],
                'three_month' => $threeMonth,
            ];
        }

        return ['days' => $result, 'missing_times' => $missing] + $totals;
    }

    private static function normalize(?string $destination): ?string
    {
        if ($destination === null) {
            return null;
        }
        $destination = mb_strtolower(trim(preg_replace('/\s+/', ' ', $destination) ?? ''));

        return $destination === '' ? null : $destination;
    }
}
