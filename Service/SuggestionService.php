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
        private readonly TrackAnalyzer $trackAnalyzer,
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
     * Turns the Dawarich tracks of the period (the user's local calendar days, up to now) into suggestions.
     * A trip belongs to the day it starts on; trips already suggested (same start) are skipped.
     *
     * @return int number of new suggestions
     * @throws DawarichException also when Dawarich has no tracks for the period
     */
    public function detect(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $timezone = $this->timezone($user);
        $first = $from->setTimezone($timezone)->setTime(0, 0);
        $last = $to->setTimezone($timezone)->setTime(0, 0)->modify('+1 day');
        $end = min($last, new \DateTimeImmutable('now', $timezone));
        if ($end <= $first) {
            return 0;
        }

        $tracks = $this->dawarichClient->fetchTracksOrFail($user, $first, $end);
        $places = $this->placeRepository->findByUser($user);
        $known = $this->suggestionRepository->findKnownStarts($user, $first, $last);
        $timesheets = [];
        $created = 0;

        foreach ($tracks as $track) {
            foreach ($this->trackAnalyzer->trips(
                $track,
                $this->configuration->getExcludedTransportModes(),
                $this->configuration->getDetectStopMinutes() * 60,
                $this->configuration->getDetectMinKm(),
            ) as $trip) {
                $start = $trip->start->timestamp;
                if ($start < $first->getTimestamp() || $start >= $end->getTimestamp() || isset($known[$start])) {
                    continue;
                }
                $known[$start] = true;

                $day = $trip->getStartAt($timezone)->setTime(0, 0);
                $timesheets[$day->format('Y-m-d')] ??= $this->findTimesheets($user, $day, $day->modify('+1 day'));

                $this->suggestionRepository->save($this->createSuggestion($user, $trip, $places, $timesheets[$day->format('Y-m-d')], $timezone), false);
                $created++;
            }
        }
        $this->suggestionRepository->flush();

        return $created;
    }

    /**
     * @param Place[] $places the user's places; places created for the trip's ends are added
     * @param Timesheet[] $timesheets
     */
    public function createSuggestion(User $user, DetectedTrip $trip, array &$places, array $timesheets, \DateTimeZone $timezone): TripSuggestion
    {
        $from = $trip->getStartLocation();
        $to = $trip->getEndLocation();

        $suggestion = (new TripSuggestion($trip->getStartAt($timezone), $trip->getEndAt($timezone)))
            ->setUser($user)
            ->setStart($from->latitude, $from->longitude)
            ->setEnd($to->latitude, $to->longitude)
            ->setDistanceKm($trip->distanceKm)
            ->setPointCount($trip->pointCount)
            ->setMode($trip->mode)
            ->setVehicle(TransportModeFilter::vehicleFor($trip->mode));

        $startPlace = $this->placeAt($user, $places, $from);
        $endPlace = $this->placeAt($user, $places, $to);
        $suggestion->setStartPlace($startPlace)->setEndPlace($endPlace);
        $suggestion->setStartLabel($startPlace->getLabel());
        $suggestion->setEndLabel($endPlace->getLabel());

        $timesheet = $this->timesheetMatcher->match($timesheets, $suggestion->getStartAt(), $suggestion->getEndAt());
        $suggestion->setTimesheet($timesheet);
        $suggestion->setProject($timesheet?->getProject());
        $suggestion->setPurpose(self::guessPurpose($startPlace, $endPlace, $timesheet !== null));

        return $suggestion;
    }

    /**
     * The nearest place whose radius contains the position. Outside all places a temporary place is created there
     * (named after its address, radius from the settings), so a later trip starting nearby starts at the same place.
     *
     * @param Place[] $places
     */
    private function placeAt(User $user, array &$places, GpsPoint $point): Place
    {
        $place = $this->placeMatcher->match($places, $point->latitude, $point->longitude);
        if ($place !== null) {
            return $place;
        }

        $place = (new Place())
            ->setUser($user)
            ->setName(mb_substr($this->label($user, $point), 0, 100))
            ->setType(PlaceType::OTHER)
            ->setLatitude($point->latitude)
            ->setLongitude($point->longitude)
            ->setRadius($this->configuration->getPlaceRadius())
            ->setTemporary(true);
        $this->placeRepository->save($place, false);
        $places[] = $place;

        return $place;
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
        return $this->acceptTracked($suggestion, $purpose, $vehicle)['trip'];
    }

    /**
     * Same as accept(), also tells whether a new trip was created (false: merged into the commute of the day).
     * $configure can change the new trip before it is saved (not called when merged); an exception thrown there
     * leaves the suggestion open and saves nothing.
     *
     * @param (callable(Trip): void)|null $configure
     * @return array{trip: Trip, created: bool}
     */
    public function acceptTracked(TripSuggestion $suggestion, TripPurpose $purpose, VehicleType $vehicle, ?callable $configure = null): array
    {
        /** @var User $user */
        $user = $suggestion->getUser();

        if ($purpose === TripPurpose::COMMUTE) {
            foreach ($this->tripRepository->findByUserBetween($user, $suggestion->getDate(), $suggestion->getDate()) as $existing) {
                if ($existing->getPurpose() === TripPurpose::COMMUTE) {
                    $suggestion->setStatus(SuggestionStatus::ACCEPTED)->setTrip($existing);
                    $this->entityManager->flush();

                    return ['trip' => $existing, 'created' => false];
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
            ->setStartCoordinates($suggestion->getStartLatitude(), $suggestion->getStartLongitude())
            ->setEndCoordinates($suggestion->getEndLatitude(), $suggestion->getEndLongitude())
            ->setStartPlace($suggestion->getStartPlace())
            ->setEndPlace($suggestion->getEndPlace())
            ->setDistanceKm($distance)
            ->setSource(TripSource::DAWARICH)
            ->setPointCount($suggestion->getPointCount())
            ->setProject($suggestion->getProject())
            ->setTimesheet($suggestion->getTimesheet());
        if ($configure !== null) {
            $configure($trip);
        }

        $this->tripService->prepare($trip);
        $this->tripRepository->save($trip, false);
        $suggestion->setStatus(SuggestionStatus::ACCEPTED)->setTrip($trip);
        $this->entityManager->flush();

        return ['trip' => $trip, 'created' => true];
    }

    public function dismiss(TripSuggestion $suggestion): void
    {
        $suggestion->setStatus(SuggestionStatus::DISMISSED);
        $this->entityManager->flush();
    }

    /**
     * Undo of accept/dismiss: the suggestion is open again. A trip created by the acceptance is removed by the
     * caller before (only when it was not changed since).
     */
    public function reopen(TripSuggestion $suggestion): void
    {
        $suggestion->setStatus(SuggestionStatus::OPEN)->setTrip(null);
        $this->entityManager->flush();
    }

    /**
     * Imports Dawarich areas and places as places (existing imports are updated). Places have no radius in
     * Dawarich, they get the radius from the settings.
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
                ->setRadius(max(10, min(5000, $area['radius'])))
                ->setTemporary(false);

            $this->placeRepository->save($place, false);
            $count++;
        }
        foreach ($this->dawarichClient->fetchPlaces($user) as $row) {
            $place = $this->placeRepository->findOneByDawarichPlace($user, $row['id']) ?? (new Place())
                ->setUser($user)
                ->setDawarichPlaceId($row['id'])
                ->setType(self::guessPlaceType($row['name']))
                ->setRadius($this->configuration->getPlaceRadius());

            $place->setName(mb_substr($row['name'], 0, 100))
                ->setLatitude($row['latitude'])
                ->setLongitude($row['longitude'])
                ->setTemporary(false);

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

    /**
     * Address of a position: Dawarich's reverse geocoder first, then the geocoding server of the plugin settings,
     * otherwise the coordinates. Only called when a place is created, the result is kept as the place name.
     */
    private function label(User $user, GpsPoint $point): string
    {
        return $this->dawarichClient->reverseGeocode($user, $point->latitude, $point->longitude)
            ?? $this->geocoder->reverse($point->latitude, $point->longitude)
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
