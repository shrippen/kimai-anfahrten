<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;

/**
 * Distributes the costs of a rental over its trips by driven kilometres.
 * Only the share of business trips is deductible; private kilometres keep their share.
 */
class RentalCostAllocator
{
    /**
     * @param Trip[] $trips all trips of this rental (any purpose)
     * @return array<int, float> allocated costs keyed by spl_object_id of the trip
     */
    public function allocate(Rental $rental, array $trips): array
    {
        $trips = array_values(array_filter($trips, static fn (Trip $t) => $t->getRental() === $rental));
        if ($trips === []) {
            return [];
        }

        $total = $rental->getTotalCosts();
        $km = array_sum(array_map(static fn (Trip $t) => $t->getTotalDistanceKm(), $trips));
        $result = [];

        foreach ($trips as $trip) {
            $share = $km > 0 ? $trip->getTotalDistanceKm() / $km : 1 / \count($trips);
            $result[spl_object_id($trip)] = round($total * $share, 2);
        }

        return $result;
    }

    /**
     * Allocations for many trips, grouped by their rentals.
     *
     * @param Trip[] $trips
     * @return array<int, float> keyed by spl_object_id
     */
    public function allocateAll(array $trips): array
    {
        $byRental = [];
        foreach ($trips as $trip) {
            if (($rental = $trip->getRental()) !== null) {
                $byRental[spl_object_id($rental)] ??= ['rental' => $rental, 'trips' => []];
                $byRental[spl_object_id($rental)]['trips'][] = $trip;
            }
        }

        $result = [];
        foreach ($byRental as $group) {
            $result += $this->allocate($group['rental'], $group['trips']);
        }

        return $result;
    }
}
