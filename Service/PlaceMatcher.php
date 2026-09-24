<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Place;

class PlaceMatcher
{
    public function __construct(private readonly DistanceCalculator $distanceCalculator)
    {
    }

    /**
     * The nearest place whose radius contains the point.
     *
     * @param Place[] $places
     */
    public function match(array $places, float $latitude, float $longitude): ?Place
    {
        $point = new GpsPoint($latitude, $longitude, 0);
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($places as $place) {
            $meters = 1000 * $this->distanceCalculator->haversine($point, new GpsPoint($place->getLatitude(), $place->getLongitude(), 0));
            if ($meters <= $place->getRadius() && $meters < $bestDistance) {
                $best = $place;
                $bestDistance = $meters;
            }
        }

        return $best;
    }
}
