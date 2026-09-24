<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\TripSuggestion;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Enum\SuggestionStatus;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\TripSource;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;

/**
 * Turns the Dawarich history into trip suggestions and suggestions into trips.
 */
class SuggestionService
{
    public function __construct(
        private readonly DawarichClient $dawarichClient,
        private readonly TripDetector $tripDetector,
        private readonly PlaceMatcher $placeMatcher,
        private readonly TimesheetMatcher $timesheetMatcher,
        private readonly GeocoderClient $geocoder,
        private readonly MileageConfiguration $configuration,
        private readonly PlaceRepository $placeRepository,
        private readonly TripSuggestionRepository $suggestionRepository,
        private readonly TripRepository $tripRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TripService $tripService,
    ) {
    }

    /**
     * Detects trips day by day (a day is the user's local calendar day).
     *
     * @return int number of new suggestions
     * @throws DawarichException
     */
    public function detect(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $timezone = $this->timezone($user);
        $day = $from->setTimezone($timezone)->setTime(0, 0);
        $last = $to->setTimezone($timezone)->setTime(0, 0);
        $now = new \DateTimeImmutable('now', $timezone);

        $places = $this->placeRepository->findByUser($user);
        $known = $this->suggestionRepository->findKnownStarts($user, $day, $last->modify('+1 day'));
        $created = 0;

        while ($day <= $last && $day <= $now) {
            $next = $day->modify('+1 day');
            $points = $this->dawarichClient->fetchPoints($user, $day, $next);
            $detected = $this->tripDetector->detect(
                $points,
                $this->configuration->getDetectStopRadius(),
                $this->configuration->getDetectStopMinutes(),
                $this->configuration->getDetectMinKm(),
                $this->configuration->getMaxAccuracy(),
            );

            $timesheets = $detected !== [] ? $this->findTimesheets($user, $day, $next) : [];

            foreach ($detected as $trip) {
                if (isset($known[$trip->start->timestamp])) {
                    continue;
                }
                $known[$trip->start->timestamp] = true;

                $this->suggestionRepository->save($this->createSuggestion($user, $trip, $places, $timesheets, $timezone), false);
                $created++;
            }

            $this->suggestionRepository->flush();
            $day = $next;
        }

        return $created;
    }

    /**
     * @param Place[] $places
     * @param Timesheet[] $timesheets
     */
    public function createSuggestion(User $user, DetectedTrip $trip, array $places, array $timesheets, \DateTimeZone $timezone): TripSuggestion
    {
        $from = $trip->getStartLocation();
        $to = $trip->getEndLocation();

        $suggestion = (new TripSuggestion($trip->getStartAt($timezone), $trip->getEndAt($timezone)))
            ->setUser($user)
            ->setStart($from->latitude, $from->longitude)
            ->setEnd($to->latitude, $to->longitude)
            ->setDistanceKm($trip->distanceKm)
            ->setPointCount($trip->pointCount);

        $startPlace = $this->placeMatcher->match($places, $from->latitude, $from->longitude);
        $endPlace = $this->placeMatcher->match($places, $to->latitude, $to->longitude);
        $suggestion->setStartPlace($startPlace)->setEndPlace($endPlace);
        $suggestion->setStartLabel($startPlace?->getLabel() ?? $this->label($from));
        $suggestion->setEndLabel($endPlace?->getLabel() ?? $this->label($to));

        $timesheet = $this->timesheetMatcher->match($timesheets, $suggestion->getStartAt(), $suggestion->getEndAt());
        $suggestion->setTimesheet($timesheet);
        $suggestion->setProject($timesheet?->getProject());
        $suggestion->setPurpose(self::guessPurpose($startPlace, $endPlace, $timesheet !== null));

        return $suggestion;
    }

    public static function guessPurpose(?Place $start, ?Place $end, bool $hasTimesheet): TripPurpose
    {
        $types = [$start?->getType(), $end?->getType()];

        if (\in_array(PlaceType::HOME, $types, true) && \in_array(PlaceType::WORK, $types, true)) {
            return TripPurpose::COMMUTE;
        }

        if ($hasTimesheet || \in_array(PlaceType::CUSTOMER, $types, true) || \in_array(PlaceType::WORK, $types, true)) {
            return TripPurpose::BUSINESS;
        }

        return TripPurpose::PRIVATE;
    }

    /**
     * Creates the trip. A second commute on the same day (the way back) is merged into
     * the existing one, because the Entfernungspauschale is granted once per day.
     */
    public function accept(TripSuggestion $suggestion, TripPurpose $purpose, VehicleType $vehicle): Trip
    {
        /** @var User $user */
        $user = $suggestion->getUser();

        if ($purpose === TripPurpose::COMMUTE) {
            foreach ($this->tripRepository->findByUserBetween($user, $suggestion->getDate(), $suggestion->getDate()) as $existing) {
                if ($existing->getPurpose() === TripPurpose::COMMUTE) {
                    $suggestion->setStatus(SuggestionStatus::ACCEPTED)->setTrip($existing);
                    $this->entityManager->flush();

                    return $existing;
                }
            }
        }

        $distance = $suggestion->getDistanceKm();
        if ($purpose === TripPurpose::COMMUTE && ($commuteKm = $this->configuration->getCommuteKm($user)) !== null) {
            // Tax law uses the shortest road connection, not the driven distance.
            $distance = $commuteKm;
        }

        $trip = $this->tripService->createTrip($user, $suggestion->getDate());
        if ($trip->getAssignedVehicle()?->getType() !== $vehicle) {
            // The user picked another kind of vehicle than the default one.
            $trip->setAssignedVehicle(null);
            $trip->setVehicle($vehicle);
        }

        $trip
            ->setDepartureAt($suggestion->getStartAt())
            ->setArrivalAt($suggestion->getEndAt())
            ->setPurpose($purpose)
            ->setStartLocation($suggestion->getStartLabel())
            ->setDestination($suggestion->getEndLabel())
            ->setDistanceKm($distance)
            ->setSource(TripSource::DAWARICH)
            ->setPointCount($suggestion->getPointCount())
            ->setProject($suggestion->getProject())
            ->setTimesheet($suggestion->getTimesheet());

        $this->tripService->prepare($trip);
        $this->tripRepository->save($trip, false);
        $suggestion->setStatus(SuggestionStatus::ACCEPTED)->setTrip($trip);
        $this->entityManager->flush();

        return $trip;
    }

    public function dismiss(TripSuggestion $suggestion): void
    {
        $suggestion->setStatus(SuggestionStatus::DISMISSED);
        $this->entityManager->flush();
    }

    /**
     * Imports Dawarich areas as places (existing imports are updated).
     *
     * @return int number of imported or updated places
     * @throws DawarichException
     */
    public function importAreas(User $user): int
    {
        $count = 0;
        foreach ($this->dawarichClient->fetchAreas($user) as $area) {
            $place = $this->placeRepository->findOneByDawarichArea($user, $area['id']) ?? (new Place())
                ->setUser($user)
                ->setDawarichAreaId($area['id'])
                ->setType(self::guessPlaceType($area['name']));

            $place->setName(mb_substr($area['name'], 0, 100))
                ->setLatitude($area['latitude'])
                ->setLongitude($area['longitude'])
                ->setRadius(max(10, min(5000, $area['radius'])));

            $this->placeRepository->save($place, false);
            $count++;
        }
        $this->entityManager->flush();

        return $count;
    }

    public static function guessPlaceType(string $name): PlaceType
    {
        $name = mb_strtolower($name);

        return match (true) {
            (bool) preg_match('/\b(home|zuhause|daheim|wohnung)\b/u', $name) => PlaceType::HOME,
            (bool) preg_match('/\b(work|office|büro|buero|arbeit|firma)\b/u', $name) => PlaceType::WORK,
            default => PlaceType::OTHER,
        };
    }

    private function label(GpsPoint $point): string
    {
        return $this->geocoder->reverse($point->latitude, $point->longitude)
            ?? \sprintf('%.5f, %.5f', $point->latitude, $point->longitude);
    }

    private function timezone(User $user): \DateTimeZone
    {
        try {
            return $user->getDateTimezone();
        } catch (\Exception) {
            return new \DateTimeZone(date_default_timezone_get());
        }
    }

    /**
     * @return Timesheet[]
     */
    private function findTimesheets(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->andWhere('t.user = :user')
            ->andWhere('t.begin < :to')
            ->andWhere('t.end IS NULL OR t.end > :from')
            ->setParameter('user', $user)
            ->setParameter('from', $from->modify('-3 hours'))
            ->setParameter('to', $to->modify('+3 hours'))
            ->getQuery()
            ->getResult();
    }
}
