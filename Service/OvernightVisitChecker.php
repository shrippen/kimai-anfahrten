<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;

/**
 * Checks an overnight stay the meal allowance inferred from the places against the visits Dawarich detected:
 * a visit over midnight at the place the leg arrived at confirms it, one at home or the workplace contradicts it.
 * Only a confirmation or a flag — the stay itself comes from the trips.
 */
class OvernightVisitChecker
{
    public function __construct(
        private readonly DawarichClient $dawarichClient,
        private readonly MileageConfiguration $configuration,
        private readonly PlaceRepository $placeRepository,
        private readonly DistanceCalculator $distanceCalculator,
    ) {
    }

    /**
     * @return (callable(Trip, \DateTimeImmutable, \DateTimeImmutable): ?bool)|null null without Dawarich
     */
    public function forUser(User $user): ?callable
    {
        if (!$this->configuration->isDawarichConfigured($user)) {
            return null;
        }
        $home = null;

        return function (Trip $leg, \DateTimeImmutable $arrival, \DateTimeImmutable $departure) use ($user, &$home): ?bool {
            $home ??= array_values(array_filter(
                $this->placeRepository->findByUser($user),
                static fn (Place $place) => \in_array($place->getType(), [PlaceType::HOME, PlaceType::WORK], true),
            ));

            return $this->check($user, $leg, $arrival, $departure, $home);
        };
    }

    /**
     * @param Place[] $homeAndWork
     */
    public function check(User $user, Trip $leg, \DateTimeImmutable $arrival, \DateTimeImmutable $departure, array $homeAndWork): ?bool
    {
        $at = $leg->getEndCoordinates() ?? ($leg->getEndPlace() !== null ? [$leg->getEndPlace()->getLatitude(), $leg->getEndPlace()->getLongitude()] : null);
        if ($at === null) {
            return null;
        }

        try {
            $visits = $this->dawarichClient->fetchVisits($user, $arrival->modify('-1 hour'), $departure);
            $timezone = $user->getDateTimezone();
        } catch (\Exception) {
            return null;
        }

        $confirmed = null;
        foreach ($visits as $visit) {
            $start = (new \DateTimeImmutable('@' . $visit['start']))->setTimezone($timezone);
            $end = (new \DateTimeImmutable('@' . $visit['end']))->setTimezone($timezone);
            if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
                continue; // not over night
            }
            $point = new GpsPoint($visit['latitude'], $visit['longitude']);
            foreach ($homeAndWork as $place) {
                if (1000 * $this->distanceCalculator->haversine($point, new GpsPoint($place->getLatitude(), $place->getLongitude())) <= $place->getRadius()) {
                    return false;
                }
            }
            if (1000 * $this->distanceCalculator->haversine($point, new GpsPoint($at[0], $at[1])) <= $this->configuration->getPlaceRadius()) {
                $confirmed = true;
            }
        }

        return $confirmed;
    }
}
