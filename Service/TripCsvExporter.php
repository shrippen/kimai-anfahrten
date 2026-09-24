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
            'trip.date',
            'trip.departure',
            'trip.arrival',
            'trip.purpose',
            'trip.vehicle',
            'trip.license_plate',
            'trip.start_location',
            'trip.destination',
            'trip.distance',
            'trip.round_trip',
            'trip.total_distance',
            'trip.costs',
            'trip.project',
            'trip.comment',
            'trip.source',
        ]), ';');

        foreach ($trips as $trip) {
            fputcsv($handle, [
                $trip->getDate()?->format('Y-m-d'),
                $trip->getDepartureAt()?->format('H:i'),
                $trip->getArrivalAt()?->format('H:i'),
                $this->translator->trans($trip->getPurpose()->label()),
                $this->translator->trans($trip->getVehicle()->label()),
                $trip->getLicensePlate(),
                $trip->getStartLocation(),
                $trip->getDestination(),
                $this->number($trip->getDistanceKm()),
                $trip->isRoundTrip() ? 'x' : '',
                $this->number($trip->getTotalDistanceKm()),
                $trip->getCosts() !== null ? $this->number($trip->getCosts()) : '',
                $trip->getProject()?->getName(),
                $trip->getComment(),
                $this->translator->trans($trip->getSource()->label()),
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '');
    }
}
