<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\PrivateUseMethod;
use KimaiPlugin\MileageBundle\Enum\TaxProfile;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * Yearly summary for the German tax return.
 *
 * - employee: Werbungskosten (Anlage N)
 * - self-employed: Betriebsausgaben (Anlage EÜR); vehicles in the business assets are
 *   deducted with their actual costs in the bookkeeping, so the report shows their
 *   private use (1 % rule or logbook share) and the non-deductible commute part instead.
 *
 * This is a calculation aid, not tax advice.
 */
class TaxCalculator
{
    public function __construct(
        private readonly TaxRateSchedule $rateSchedule,
        private readonly RentalCostAllocator $rentalCostAllocator,
        private readonly MealAllowanceCalculator $mealCalculator,
    ) {
    }

    /**
     * @param Trip[] $trips trips of one user in one year
     * @return array<string, mixed>
     */
    public function summarize(array $trips, int $year, TaxProfile $profile = TaxProfile::SELF_EMPLOYED, ?\DateTimeZone $timezone = null): array
    {
        $rates = $this->rateSchedule->forYear($year);
        $timezone ??= new \DateTimeZone(date_default_timezone_get());
        $allocation = $this->rentalCostAllocator->allocateAll($trips);

        $commute = $this->summarizeCommute(array_filter($trips, static fn (Trip $t) => $t->getPurpose() === TripPurpose::COMMUTE), $rates, $profile);

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
            $businessAsset = $profile === TaxProfile::SELF_EMPLOYED && $trip->getAssignedVehicle()?->isBusinessAsset() === true;
            $key = $vehicle->value . ($businessAsset ? '_asset' : '');
            $business[$key] ??= [
                'vehicle' => $vehicle,
                'business_asset' => $businessAsset,
                'trips' => 0,
                'km' => 0.0,
                'rate' => $businessAsset ? null : $this->businessRate($vehicle, $rates),
                'km_amount' => 0.0,
                'costs' => 0.0,
                'amount' => 0.0,
            ];

            $business[$key]['trips']++;
            $business[$key]['km'] += $trip->getTotalDistanceKm();
            $business[$key]['costs'] += (float) $trip->getCosts() + ($allocation[spl_object_id($trip)] ?? 0.0);
        }

        $businessTotal = 0.0;
        foreach ($business as $key => $row) {
            if ($row['business_asset'] || ($row['vehicle']->isEmployerProvided() && $profile === TaxProfile::EMPLOYEE)) {
                // costs are borne by the business (bookkeeping) or the employer
                $amount = 0.0;
            } elseif ($row['rate'] !== null) {
                $business[$key]['km_amount'] = round($row['km'] * $row['rate'], 2);
                // Parking/tolls on top of the per-km rate are deductible as well.
                $amount = $business[$key]['km_amount'] + $row['costs'];
            } else {
                $amount = $row['costs'];
            }
            $business[$key]['costs'] = round($row['costs'], 2);
            $business[$key]['amount'] = round($amount, 2);
            $businessTotal += $business[$key]['amount'];
        }
        ksort($business);

        $meals = $this->mealCalculator->calculate($trips, $rates, $timezone);
        $privateUse = $profile === TaxProfile::SELF_EMPLOYED ? $this->privateUse($trips, $year, $rates) : [];

        return [
            'profile' => $profile,
            'year' => $year,
            'rates' => $rates,
            'commute' => $commute,
            'business' => $business,
            'business_total' => round($businessTotal, 2),
            'meals' => $meals,
            'private' => $private,
            'private_use' => $privateUse,
            'total_km' => round($totalKm, 1),
            'total' => round($commute['amount'] + $businessTotal + $meals['amount'], 2),
        ];
    }

    public function businessRate(VehicleType $vehicle, TaxRates $rates): ?float
    {
        return match ($vehicle) {
            VehicleType::OWN_CAR => $rates->businessCarRate,
            VehicleType::MOTORCYCLE => $rates->businessMotorcycleRate,
            default => null,
        };
    }

    /**
     * Entfernungspauschale: once per workday on the one-way distance, regardless of the vehicle.
     * Several commute entries on the same day count once (the longest one).
     *
     * @param Trip[] $trips
     * @return array<string, mixed>
     */
    private function summarizeCommute(array $trips, TaxRates $rates, TaxProfile $profile): array
    {
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
            $km += floor($trip->getDistanceKm());
            $amount = $rates->commuteAmount($trip->getDistanceKm());
            if ($trip->getVehicle()->isCarForCommuteCap()) {
                $amountCar += $amount;
            } else {
                $amountOther += $amount;
            }
        }

        $amountOtherCapped = min($amountOther, $rates->commuteCap);
        // Higher actual public transport costs may be deducted instead (§ 9 Abs. 2 Satz 2 EStG).
        $amountOtherCapped = max($amountOtherCapped, $publicTransportCosts);

        return [
            'days' => \count($perDay),
            'km' => $km,
            'rate' => $rates->commuteRate,
            'rate_from21' => $rates->commuteRateFrom21,
            'amount_car' => round($amountCar, 2),
            'amount_other' => round($amountOther, 2),
            'amount_other_capped' => round($amountOtherCapped, 2),
            'public_transport_costs' => round($publicTransportCosts, 2),
            'cap' => $rates->commuteCap,
            'capped' => $amountOther > $rates->commuteCap,
            'amount' => round($amountCar + $amountOtherCapped, 2),
        ];
    }

    /**
     * Private use of vehicles in the business assets (self-employed).
     *
     * @param Trip[] $trips
     * @return list<array<string, mixed>>
     */
    private function privateUse(array $trips, int $year, TaxRates $rates): array
    {
        /** @var array<int, array{vehicle: Vehicle, trips: Trip[]}> $vehicles */
        $vehicles = [];
        foreach ($trips as $trip) {
            $vehicle = $trip->getAssignedVehicle();
            if ($vehicle !== null && $vehicle->isBusinessAsset() && $vehicle->getPrivateUse() !== PrivateUseMethod::NONE) {
                $vehicles[spl_object_id($vehicle)] ??= ['vehicle' => $vehicle, 'trips' => []];
                $vehicles[spl_object_id($vehicle)]['trips'][] = $trip;
            }
        }

        $result = [];
        foreach ($vehicles as ['vehicle' => $vehicle, 'trips' => $vehicleTrips]) {
            $km = ['business' => 0.0, 'commute' => 0.0, 'private' => 0.0];
            $commuteDays = [];
            foreach ($vehicleTrips as $trip) {
                $km[$trip->getPurpose()->value] += $trip->getTotalDistanceKm();
                if ($trip->getPurpose() === TripPurpose::COMMUTE && ($day = $trip->getDate()?->format('Y-m-d')) !== null) {
                    $commuteDays[$day] = max($commuteDays[$day] ?? 0.0, $trip->getDistanceKm());
                }
            }
            $total = array_sum($km);
            $months = self::monthsInUse($vehicle, $year);
            $row = [
                'vehicle' => $vehicle,
                'method' => $vehicle->getPrivateUse(),
                'months' => $months,
                'km' => $km,
                'private_share' => $total > 0 ? round(100 * $km['private'] / $total, 2) : 0.0,
                'one_percent' => null,
                'commute_addition' => null,
            ];

            if ($vehicle->getPrivateUse() === PrivateUseMethod::ONE_PERCENT && $vehicle->getListPrice() !== null) {
                // list price rounded down to full hundred euros (R 8.1 Abs. 9 Nr. 1 LStR)
                $base = floor($vehicle->getListPrice() * $vehicle->getListPriceFactor() / 100) * 100;
                $row['one_percent'] = round($base * 0.01 * $months, 2);

                if ($commuteDays !== []) {
                    // § 4 Abs. 5 Satz 1 Nr. 6 Satz 3 EStG: 0.03 % × list price × distance × months,
                    // minus the Entfernungspauschale, is not deductible.
                    $distance = floor(max($commuteDays));
                    $pauschale = array_sum(array_map(static fn (float $d) => $rates->commuteAmount($d), $commuteDays));
                    $row['commute_addition'] = round(max(0.0, $base * 0.0003 * $distance * $months - $pauschale), 2);
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    public static function monthsInUse(Vehicle $vehicle, int $year): int
    {
        $from = max(new \DateTimeImmutable(\sprintf('%d-01-01', $year)), $vehicle->getValidFrom() ?? new \DateTimeImmutable('1970-01-01'));
        $to = min(new \DateTimeImmutable(\sprintf('%d-12-31', $year)), $vehicle->getValidTo() ?? new \DateTimeImmutable('9999-12-31'));
        if ($to < $from) {
            return 0;
        }

        // every started calendar month counts
        return ((int) $to->format('Y') - (int) $from->format('Y')) * 12 + (int) $to->format('n') - (int) $from->format('n') + 1;
    }
}
