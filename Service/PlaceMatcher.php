<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Place;

class PlaceMatcher
{
    public function __construct(private readonly DistanceCalculator $distanceCalculator)
    {
    }

    /**
     * The nearest place whose radius contains the point; regular places win over temporary ones (created
     * automatically before the user added or imported the real place there).
     *
     * @param Place[] $places
     */
    public function match(array $places, float $latitude, float $longitude): ?Place
    {
        $point = new GpsPoint($latitude, $longitude, 0);
        $best = null;
        $bestRank = [\PHP_INT_MAX, \PHP_FLOAT_MAX];

        foreach ($places as $place) {
            $meters = 1000 * $this->distanceCalculator->haversine($point, new GpsPoint($place->getLatitude(), $place->getLongitude(), 0));
            $rank = [$place->isTemporary() ? 1 : 0, $meters];
            if ($meters <= $place->getRadius() && $rank < $bestRank) {
                $best = $place;
                $bestRank = $rank;
            }
        }

        return $best;
    }
}
