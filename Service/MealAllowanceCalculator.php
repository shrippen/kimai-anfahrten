<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;

/**
 * Verpflegungsmehraufwand (§ 9 Abs. 4a EStG, for self-employed via § 4 Abs. 5 Nr. 5 EStG).
 *
 * Only the departure and arrival times of the trips count (never the Dawarich measuring window, which is
 * not stored); business trips without both times get no allowance and are reported as `missing_times`.
 *
 * - one-day absence of more than 8 hours: partial rate (several absences on one day add up)
 * - legs that continue where the previous one ended form one journey (e.g. the detected way there and back).
 *   "Where it ended" is the place of the detected trips (the same place, or coordinates within the place radius);
 *   trips entered by hand compare the names
 * - multi-day journey: partial rate on the days of departure and return, full rate in between. A journey stays
 *   open over night when the last leg of a day ends away from home and the regular workplace (places of type
 *   home/work, or the home/work address of the profile) and a later leg starts at that same place; the
 *   "overnight" checkbox of a trip forces it. Unclear cases are not counted but listed for review (`review`):
 *   home/workplace unknown, a gap of more than {@see MileageConfiguration::getJourneyMaxGapDays()} days, or
 *   Dawarich visits showing the night at home (optional $confirmOvernight)
 * - three-month rule: at the same destination place (per name for trips entered by hand) the allowance ends after
 *   three months, a break of at least four weeks starts a new period
 *
 * Deliberate simplification: no reduction for meals provided.
 */
class MealAllowanceCalculator
{
    private const MIN_HOURS = 8.0;
    private const BREAK_DAYS = 28;
    private const DEFAULT_RADIUS = 200;
    private const DEFAULT_MAX_GAP_DAYS = 14;

    public function __construct(
        private readonly ?MileageConfiguration $configuration = null,
        private readonly DistanceCalculator $distanceCalculator = new DistanceCalculator(),
    ) {
    }

    /**
     * @param Trip[] $trips all trips of one user (commutes tell where home and the workplace are)
     * @param (callable(Trip, \DateTimeImmutable, \DateTimeImmutable): ?bool)|null $confirmOvernight
     *        whether the traveller stayed at the place $trip arrived at between the two times (null: unknown)
     * @return array{
     *     days: list<array{date: string, hours: float, kind: string, amount: float, destination: ?string, three_month: bool, overnight: ?string}>,
     *     review: list<array{date: string, reason: string, destination: ?string}>,
     *     hint_home_work: bool,
     *     partial_days: int,
     *     full_days: int,
     *     excluded_days: int,
     *     missing_times: int,
     *     amount: float
     * }
     */
    public function calculate(array $trips, TaxRates $rates, \DateTimeZone $timezone, ?callable $confirmOvernight = null): array
    {
        $anchors = $this->homeAndWork($trips);
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

        /** @var array<string, array{hours: float, kind: ?string, destination: ?string, key: ?string, overnight: ?string}> $days */
        $days = [];
        $review = [];
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
                $days[$first] ??= ['hours' => 0.0, 'kind' => null, 'destination' => $journey['destination'], 'key' => $journey['key'], 'overnight' => null];
                $days[$first]['hours'] += ($end->getTimestamp() - $start->getTimestamp()) / 3600;

                return;
            }

            for ($day = new \DateTimeImmutable($first); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');
                $days[$key] ??= ['hours' => 0.0, 'kind' => null, 'destination' => $journey['destination'], 'key' => $journey['key'], 'overnight' => null];
                $days[$key]['overnight'] ??= $journey['overnight'];
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

            $link = $journey === null ? false : ($journey['open'] ? 'manual' : $this->link($journey, $trip, $timezone, $anchors, $confirmOvernight));
            if (\is_string($link) && str_starts_with($link, 'review:')) {
                $review[] = ['date' => $dep->setTimezone($timezone)->format('Y-m-d'), 'reason' => substr($link, 7), 'destination' => $trip->getStartLocation()];
            } elseif ($journey !== null && $link !== false) {
                $journey['end'] = max($journey['end'], $arr);
                $journey['open'] = $trip->isOvernight();
                $journey['last'] = $trip;
                // how the journey got over night: the checkbox, inferred from the places, confirmed by Dawarich visits
                if (\is_string($link) && $journey['overnight'] !== 'manual') {
                    $journey['overnight'] = $link;
                }
                continue;
            }

            $close($journey);
            $journey = [
                'start' => $dep,
                'end' => $arr,
                'open' => $trip->isOvernight(),
                'destination' => $trip->getDestination(),
                'key' => self::destinationKey($trip),
                'last' => $trip,
                'overnight' => null,
            ];
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
            $destination = $day['key'];
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
                'overnight' => $day['overnight'],
            ];
        }

        return [
            'days' => $result,
            'review' => $review,
            'hint_home_work' => \in_array('no_home', array_column($review, 'reason'), true),
            'missing_times' => $missing,
        ] + $totals;
    }

    /**
     * Whether the trip continues the journey: it starts where the journey's last leg arrived, after it. On the same
     * day that is one absence (true); on a later day it is an overnight stay ("inferred"/"confirmed") unless the leg
     * arrived at home or the workplace (false). Unclear cases give "review:<reason>" and are not linked.
     *
     * @param array{end: \DateTimeImmutable, last: Trip} $journey
     * @param array{known: bool, places: list<Place>, names: list<string>} $anchors
     * @param (callable(Trip, \DateTimeImmutable, \DateTimeImmutable): ?bool)|null $confirmOvernight
     */
    private function link(array $journey, Trip $trip, \DateTimeZone $timezone, array $anchors, ?callable $confirmOvernight): bool|string
    {
        $departure = $trip->getDepartureAt();
        if ($departure === null || $departure < $journey['end'] || !$this->startsWhereArrived($journey['last'], $trip)) {
            return false;
        }

        $arrived = \DateTimeImmutable::createFromInterface($journey['end'])->setTimezone($timezone)->setTime(0, 0);
        $leaves = $departure->setTimezone($timezone)->setTime(0, 0);
        if ($arrived == $leaves) {
            return true;
        }

        $home = $this->arrivedHome($journey['last'], $anchors);
        if ($home === true) {
            return false;
        }
        if ($home === null) {
            return 'review:no_home';
        }
        if ((int) $arrived->diff($leaves)->days > ($this->configuration?->getJourneyMaxGapDays() ?? self::DEFAULT_MAX_GAP_DAYS)) {
            return 'review:gap';
        }

        $confirmed = $confirmOvernight !== null ? $confirmOvernight($journey['last'], \DateTimeImmutable::createFromInterface($journey['end']), $departure) : null;
        if ($confirmed === false) {
            return 'review:visit';
        }

        return $confirmed === true ? 'confirmed' : 'inferred';
    }

    /**
     * Whether the leg ended at home or the regular workplace; null when neither is known.
     *
     * @param array{known: bool, places: list<Place>, names: list<string>} $anchors
     */
    private function arrivedHome(Trip $leg, array $anchors): ?bool
    {
        $type = $leg->getEndPlace()?->getType();
        if ($type === PlaceType::HOME || $type === PlaceType::WORK) {
            return true;
        }
        if (!$anchors['known']) {
            return null;
        }
        if ($type !== null) {
            return false;
        }

        $end = $leg->getEndCoordinates();
        if ($end !== null) {
            foreach ($anchors['places'] as $place) {
                if (1000 * $this->distanceCalculator->haversine(new GpsPoint($end[0], $end[1]), new GpsPoint($place->getLatitude(), $place->getLongitude())) <= $place->getRadius()) {
                    return true;
                }
            }

            return false;
        }

        return \in_array(self::normalize($leg->getDestination()), $anchors['names'], true);
    }

    /**
     * Home and regular workplace: places of type home/work used by the trips, and the addresses of the profile.
     *
     * @param Trip[] $trips
     * @return array{known: bool, places: list<Place>, names: list<string>}
     */
    private function homeAndWork(array $trips): array
    {
        $places = [];
        $names = [];
        foreach ($trips as $trip) {
            foreach ([$trip->getStartPlace(), $trip->getEndPlace()] as $place) {
                if ($place !== null && \in_array($place->getType(), [PlaceType::HOME, PlaceType::WORK], true)) {
                    $places[spl_object_id($place)] = $place;
                    $names[] = self::normalize($place->getName());
                    $names[] = self::normalize($place->getLabel());
                }
            }
        }
        $user = ($trips[array_key_first($trips) ?? 0] ?? null)?->getUser();
        if ($user !== null && $this->configuration !== null) {
            $names[] = self::normalize($this->configuration->getHomeAddress($user));
            $names[] = self::normalize($this->configuration->getWorkAddress($user));
        }
        $names = array_values(array_unique(array_filter($names, static fn (?string $name) => $name !== null)));

        return ['known' => $places !== [] || $names !== [], 'places' => array_values($places), 'names' => $names];
    }

    /**
     * Same place, otherwise coordinates within the place radius; names only when a trip has no coordinates.
     */
    private function startsWhereArrived(Trip $previous, Trip $next): bool
    {
        $arrived = $previous->getEndPlace();
        $starts = $next->getStartPlace();
        if ($arrived !== null && ($arrived === $starts || ($arrived->getId() !== null && $arrived->getId() === $starts?->getId()))) {
            return true;
        }

        $to = $previous->getEndCoordinates();
        $from = $next->getStartCoordinates();
        if ($to !== null && $from !== null) {
            $metres = 1000 * $this->distanceCalculator->haversine(new GpsPoint($to[0], $to[1]), new GpsPoint($from[0], $from[1]));

            return $metres <= ($this->configuration?->getPlaceRadius() ?? self::DEFAULT_RADIUS);
        }

        $place = self::normalize($next->getStartLocation());

        return $place !== null && $place === self::normalize($previous->getDestination());
    }

    /**
     * Identity of a trip's destination for the three-month rule.
     */
    private static function destinationKey(Trip $trip): ?string
    {
        $id = $trip->getEndPlace()?->getId();

        return $id !== null ? 'place:' . $id : self::normalize($trip->getDestination());
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
