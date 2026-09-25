<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Logbook-style CSV export (semicolon separated, UTF-8 BOM for Excel).
 */
class TripCsvExporter
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param Trip[] $trips
     */
    public function export(array $trips): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, array_map(fn (string $key) => $this->translator->trans($key), [
            'mileage.trip.date',
            'mileage.trip.departure',
            'mileage.trip.arrival',
            'mileage.trip.purpose',
            'mileage.trip.vehicle',
            'mileage.trip.license_plate',
            'mileage.trip.start_location',
            'mileage.trip.destination',
            'mileage.trip.distance',
            'mileage.trip.round_trip',
            'mileage.trip.total_distance',
            'mileage.trip.costs',
            'mileage.trip.project',
            'mileage.trip.comment',
            'mileage.trip.source',
        ]), ';', '"', '');

        foreach ($trips as $trip) {
            fputcsv($handle, [
                $trip->getDate()?->format('Y-m-d'),
                $this->time($trip, $trip->getDepartureAt()),
                $this->time($trip, $trip->getArrivalAt()),
                $this->translator->trans($trip->getPurpose()->label()),
                $this->translator->trans($trip->getVehicle()->label()),
                CsvSafe::cell($trip->getLicensePlate()),
                CsvSafe::cell($trip->getStartLocation()),
                CsvSafe::cell($trip->getDestination()),
                $this->number($trip->getDistanceKm()),
                $trip->isRoundTrip() ? 'x' : '',
                $this->number($trip->getTotalDistanceKm()),
                $trip->getCosts() !== null ? $this->number($trip->getCosts()) : '',
                CsvSafe::cell($trip->getProject()?->getName()),
                CsvSafe::cell($trip->getComment()),
                $this->translator->trans($trip->getSource()->label()),
            ], ';', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Kimai loads datetimes as UTC, the logbook shows the user's local time.
     */
    private function time(Trip $trip, ?\DateTimeImmutable $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $timezone = $trip->getUser()?->getDateTimezone();

        return ($timezone !== null ? $value->setTimezone($timezone) : $value)->format('H:i');
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '');
    }
}
