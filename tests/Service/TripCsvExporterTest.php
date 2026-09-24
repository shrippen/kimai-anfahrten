<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Service\TripCsvExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

class TripCsvExporterTest extends TestCase
{
    public function testExport(): void
    {
        $trip = (new Trip())
            ->setDate(new \DateTimeImmutable('2026-03-02'))
            ->setPurpose(TripPurpose::BUSINESS)
            ->setStartLocation('Home')
            ->setDestination('Kunde; "A"')
            ->setDistanceKm(12.5)
            ->setRoundTrip(true)
            ->setCosts(3.2);

        $csv = (new TripCsvExporter(new IdentityTranslator()))->export([$trip]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\n", trim(substr($csv, 3)));
        self::assertCount(2, $lines);
        self::assertStringContainsString('trip.date;', $lines[0]);
        self::assertStringContainsString('2026-03-02', $lines[1]);
        self::assertStringContainsString('"Kunde; ""A"""', $lines[1]);
        self::assertStringContainsString(';12,50;x;25,00;3,20;', $lines[1]);
    }
}
