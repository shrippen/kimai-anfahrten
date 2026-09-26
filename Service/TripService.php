<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\RentalRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Repository\VehicleRepository;

/**
 * Defaults and links that every newly created trip gets, no matter where it comes from.
 */
class TripService
{
    public function __construct(
        private readonly VehicleRepository $vehicleRepository,
        private readonly RentalRepository $rentalRepository,
        private readonly TripRepository $tripRepository,
        private readonly LogbookService $logbookService,
        private readonly MileageConfiguration $configuration,
        private readonly MonthLockService $lockService,
    ) {
    }

    /**
     * New trip for a user with vehicle defaults applied.
     */
    public function createTrip(User $user, \DateTimeImmutable $date): Trip
    {
        $trip = (new Trip())
            ->setUser($user)
            ->setDate($date)
            ->setVehicle($this->configuration->getDefaultVehicle($user))
            ->setLicensePlate($this->configuration->getLicensePlate($user));

        $vehicle = $this->vehicleRepository->findDefaultFor($user, $date);
        if ($vehicle !== null) {
            $trip->setAssignedVehicle($vehicle);
        }

        return $trip;
    }

    /**
     * Fills what can be derived before saving: rental link and odometer start.
     */
    public function prepare(Trip $trip): void
    {
        $user = $trip->getUser();
        $date = $trip->getDate();
        if ($user === null || $date === null) {
            return;
        }

        if ($trip->getVehicle() === VehicleType::RENTAL_CAR && $trip->getRental() === null) {
            $trip->setRental($this->rentalRepository->findCovering($user, $date));
        }
        if ($trip->getVehicle() !== VehicleType::RENTAL_CAR) {
            $trip->setRental(null);
        }
        // A rental car has the rental's plate, not the one of the user's default vehicle.
        if (($rental = $trip->getRental()) !== null && $trip->getAssignedVehicle() === null && $rental->getLicensePlate() !== null) {
            $trip->setLicensePlate($rental->getLicensePlate());
        }

        $vehicle = $trip->getAssignedVehicle();
        if ($vehicle !== null && $trip->getOdometerStart() === null && $trip->getId() === null) {
            $start = $this->logbookService->suggestOdometerStart($vehicle, $this->tripRepository->findByVehicle($vehicle), $date);
            if ($start !== null) {
                $trip->setOdometerStart($start);
                if ($trip->getOdometerEnd() === null && $trip->getTotalDistanceKm() > 0) {
                    $trip->setOdometerEnd($start + (int) round($trip->getTotalDistanceKm()));
                }
            }
        }
    }

    /**
     * Links all rental-car trips in the rental period that have no rental yet.
     *
     * @return int number of linked trips
     */
    public function linkRentalTrips(Rental $rental): int
    {
        $user = $rental->getUser();
        if ($user === null || $rental->getStartDate() === null || $rental->getEndDate() === null) {
            return 0;
        }

        // Trips of closed months stay as they are (changing them would be refused when saving).
        $trips = array_values(array_filter(
            $this->tripRepository->findUnlinkedRentalTrips($user, $rental->getStartDate(), $rental->getEndDate()),
            fn (Trip $trip) => !$this->lockService->isTripLocked($trip)
        ));
        foreach ($trips as $trip) {
            $trip->setRental($rental);
            if ($trip->getAssignedVehicle() === null && $rental->getLicensePlate() !== null) {
                $trip->setLicensePlate($rental->getLicensePlate());
            }
        }

        return \count($trips);
    }
}
