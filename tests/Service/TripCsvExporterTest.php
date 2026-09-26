<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Service\CsvSafe;
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
        self::assertStringContainsString('mileage.trip.date;', $lines[0]);
        self::assertStringContainsString('2026-03-02', $lines[1]);
        self::assertStringContainsString('"Kunde; ""A"""', $lines[1]);
        self::assertStringContainsString(';12,50;x;25,00;3,20;', $lines[1]);
    }

    public function testFormulasAreEscaped(): void
    {
        $trip = (new Trip())
            ->setDate(new \DateTimeImmutable('2026-03-02'))
            ->setStartLocation('@SUM(1)')
            ->setDestination('=HYPERLINK("http://evil","x")')
            ->setComment('+cmd|calc')
            ->setLicensePlate('-1')
            ->setDistanceKm(3);

        $csv = (new TripCsvExporter(new IdentityTranslator()))->export([$trip]);

        self::assertStringContainsString(";'-1;'@SUM(1);\"'=HYPERLINK(\"\"http://evil\"\",\"\"x\"\")\";3,00;", $csv);
        self::assertStringContainsString(";'+cmd|calc;", $csv);
        self::assertSame('=a', CsvSafe::unescape(CsvSafe::cell('=a')));
        self::assertSame("'normal", CsvSafe::unescape(CsvSafe::cell("'normal")));
        self::assertSame('Kunde', CsvSafe::cell('Kunde'));
    }

    public function testTimesAreShownInUserTimezone(): void
    {
        $user = new \App\Entity\User(1);
        $user->setPreferenceValue('timezone', 'Europe/Berlin');
        $utc = new \DateTimeZone('UTC');
        $trip = (new Trip())
            ->setUser($user)
            ->setDate(new \DateTimeImmutable('2026-07-01'))
            ->setDepartureAt(new \DateTimeImmutable('2026-07-01 06:15', $utc))
            ->setArrivalAt(new \DateTimeImmutable('2026-07-01 07:00', $utc));

        $csv = (new TripCsvExporter(new IdentityTranslator()))->export([$trip]);

        self::assertStringContainsString('2026-07-01;08:15;09:00;', $csv);
    }
}
