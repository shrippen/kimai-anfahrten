<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * Yearly summary for the German income tax return (Anlage N, Werbungskosten).
 *
 * This is a calculation aid, not tax advice.
 */
class TaxCalculator
{
    public function __construct(private readonly MileageConfiguration $configuration)
    {
    }

    /**
     * @param Trip[] $trips
     * @return array{
     *     commute: array{days: int, km: float, rate: float, amount_car: float, amount_other: float, amount_other_capped: float, public_transport_costs: float, cap: float, capped: bool, amount: float},
     *     business: array<string, array{vehicle: VehicleType, trips: int, km: float, rate: ?float, km_amount: float, costs: float, amount: float}>,
     *     business_total: float,
     *     private: array{trips: int, km: float},
     *     total_km: float,
     *     total: float
     * }
     */
    public function summarize(array $trips): array
    {
        $commute = $this->summarizeCommute(array_filter($trips, static fn (Trip $t) => $t->getPurpose() === TripPurpose::COMMUTE));

        $business = [];
        $private = ['trips' => 0, 'km' => 0.0];
        $totalKm = 0.0;

        foreach ($trips as $trip) {
            $totalKm += $trip->getTotalDistanceKm();

            if ($trip->getPurpose() === TripPurpose::PRIVATE) {
                $private['trips']++;
                $private['km'] += $trip->getTotalDistanceKm();
                continue;
            }

            if ($trip->getPurpose() !== TripPurpose::BUSINESS) {
                continue;
            }

            $vehicle = $trip->getVehicle();
            $key = $vehicle->value;
            $business[$key] ??= [
                'vehicle' => $vehicle,
                'trips' => 0,
                'km' => 0.0,
                'rate' => $this->businessRate($vehicle),
                'km_amount' => 0.0,
                'costs' => 0.0,
                'amount' => 0.0,
            ];

            $business[$key]['trips']++;
            $business[$key]['km'] += $trip->getTotalDistanceKm();
            $business[$key]['costs'] += (float) $trip->getCosts();
        }

        $businessTotal = 0.0;
        foreach ($business as $key => $row) {
            if ($row['vehicle']->isEmployerProvided()) {
                $amount = 0.0;
            } elseif ($row['rate'] !== null) {
                $business[$key]['km_amount'] = round($row['km'] * $row['rate'], 2);
                $amount = $business[$key]['km_amount'];
            } else {
                $amount = $row['costs'];
            }
            $business[$key]['amount'] = round($amount, 2);
            $businessTotal += $business[$key]['amount'];
        }

        ksort($business);

        return [
            'commute' => $commute,
            'business' => $business,
            'business_total' => round($businessTotal, 2),
            'private' => $private,
            'total_km' => round($totalKm, 1),
            'total' => round($commute['amount'] + $businessTotal, 2),
        ];
    }

    public function businessRate(VehicleType $vehicle): ?float
    {
        return match ($vehicle) {
            VehicleType::OWN_CAR => $this->configuration->getBusinessCarRate(),
            VehicleType::MOTORCYCLE => $this->configuration->getBusinessMotorcycleRate(),
            default => null,
        };
    }

    /**
     * Entfernungspauschale: once per workday on the one-way distance, regardless of the vehicle.
     * Several commute entries on the same day count once (the longest one).
     *
     * @param Trip[] $trips
     */
    private function summarizeCommute(array $trips): array
    {
        $rate = $this->configuration->getCommuteRate();
        $cap = $this->configuration->getCommuteCap();

        /** @var array<string, Trip> $perDay */
        $perDay = [];
        $publicTransportCosts = 0.0;
        foreach ($trips as $trip) {
            $day = $trip->getDate()?->format('Y-m-d');
            if ($day === null) {
                continue;
            }
            if (!isset($perDay[$day]) || $trip->getDistanceKm() > $perDay[$day]->getDistanceKm()) {
                $perDay[$day] = $trip;
            }
            if ($trip->getVehicle() === VehicleType::PUBLIC_TRANSPORT) {
                $publicTransportCosts += (float) $trip->getCosts();
            }
        }

        $km = 0.0;
        $amountCar = 0.0;
        $amountOther = 0.0;
        foreach ($perDay as $trip) {
            $distance = floor($trip->getDistanceKm()); // only full kilometres count
            $km += $distance;
            if ($trip->getVehicle()->isCarForCommuteCap()) {
                $amountCar += $distance * $rate;
            } else {
                $amountOther += $distance * $rate;
            }
        }

        $amountOtherCapped = min($amountOther, $cap);
        // Higher actual public transport costs may be deducted instead (§ 9 Abs. 2 Satz 2 EStG).
        $amountOtherCapped = max($amountOtherCapped, $publicTransportCosts);

        return [
            'days' => \count($perDay),
            'km' => $km,
            'rate' => $rate,
            'amount_car' => round($amountCar, 2),
            'amount_other' => round($amountOther, 2),
            'amount_other_capped' => round($amountOtherCapped, 2),
            'public_transport_costs' => round($publicTransportCosts, 2),
            'cap' => $cap,
            'capped' => $amountOther > $cap,
            'amount' => round($amountCar + $amountOtherCapped, 2),
        ];
    }
}
