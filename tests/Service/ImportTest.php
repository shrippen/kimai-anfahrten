<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\TripCsvExporter;
use KimaiPlugin\MileageBundle\Service\TripCsvImporter;
use KimaiPlugin\MileageBundle\Service\TripMapper;
use KimaiPlugin\MileageBundle\Service\TripService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

class ImportTest extends TestCase
{
    private function importer(array $existing = [], ?MonthLockService $lockService = null): TripCsvImporter
    {
        $tripService = $this->createMock(TripService::class);
        $tripService->method('createTrip')->willReturnCallback(static fn (User $u, \DateTimeImmutable $d) => (new Trip())->setUser($u)->setDate($d));
        $repository = $this->createMock(TripRepository::class);
        $repository->method('findByUserBetween')->willReturn($existing);

        return new TripCsvImporter(new TripMapper(), $tripService, $repository, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $lockService);
    }

    public function testColumnDetection(): void
    {
        self::assertSame('date', TripCsvImporter::column('Datum'));
        self::assertSame('distanceKm', TripCsvImporter::column('Entfernung (km)'));
        self::assertSame('roundTrip', TripCsvImporter::column('Hin- und Rückfahrt (Strecke verdoppeln)'));
        self::assertSame('comment', TripCsvImporter::column('Anlass / Bemerkung'));
        self::assertSame('odometerStart', TripCsvImporter::column('km-Stand Beginn'));
        self::assertSame('purpose', TripCsvImporter::column("\u{FEFF}Art der Fahrt"));
        self::assertNull(TripCsvImporter::column('Gesamt-km'));
        self::assertSame(';', TripCsvImporter::detectSeparator('Datum;Ziel;km'));
        self::assertSame(',', TripCsvImporter::detectSeparator('date,to,km'));
        self::assertSame("\t", TripCsvImporter::detectSeparator("date\tto\tkm"));
    }

    public function testParsesGermanWindowsCsv(): void
    {
        $csv = mb_convert_encoding("Datum;Art;Fahrzeug;Ziel;km;Hin- und Rückfahrt;Bemerkung\r\n03.02.2026;Dienstreise;PKW;Köln;1.234,5;ja;Messe\r\n;Privat;;Bäcker;2;;\r\n04.02.2026;;;x;;;\r\n", 'Windows-1252', 'UTF-8');

        $parsed = $this->importer()->parse($csv);
        $built = $this->importer()->build(new User(1), $parsed['rows']);

        self::assertCount(3, $built);
        $trip = $built[0]['trip'];
        self::assertInstanceOf(Trip::class, $trip);
        self::assertSame('2026-02-03', $trip->getDate()?->format('Y-m-d'));
        self::assertSame(TripPurpose::BUSINESS, $trip->getPurpose());
        self::assertSame(VehicleType::OWN_CAR, $trip->getVehicle());
        self::assertSame('Köln', $trip->getDestination());
        self::assertSame(1234.5, $trip->getDistanceKm());
        self::assertTrue($trip->isRoundTrip());
        self::assertSame('Messe', $trip->getComment());
        self::assertArrayHasKey('date', $built[1]['errors']);
        self::assertArrayHasKey('distanceKm', $built[2]['errors']);
    }

    public function testDistanceFromOdometerAndTimes(): void
    {
        $csv = "date,departure,arrival,purpose,odometer start,odometer end\n2026-05-06,07:15,08:05,commute,12000,12023\n";
        $user = new User(1);
        $user->setPreferenceValue('timezone', 'Europe/Berlin');

        $trip = $this->importer()->build($user, $this->importer()->parse($csv)['rows'])[0]['trip'];

        self::assertSame(23.0, $trip?->getDistanceKm());
        self::assertSame('2026-05-06 07:15', $trip?->getDepartureAt()?->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'));
        self::assertSame(TripPurpose::COMMUTE, $trip?->getPurpose());
    }

    public function testDuplicatesAreMarked(): void
    {
        $existing = (new Trip())->setDate(new \DateTimeImmutable('2026-03-03'))->setPurpose(TripPurpose::BUSINESS)->setDistanceKm(10)->setDestination('Kunde A');
        $csv = "Datum;Ziel;km\n03.03.2026;kunde a;10\n03.03.2026;Kunde B;10\n03.03.2026;Kunde B;10\n";

        $built = $this->importer([$existing])->build(new User(1), $this->importer()->parse($csv)['rows']);

        self::assertSame([true, false, true], array_column($built, 'duplicate'));
    }

    public function testRoundTripOfOwnExport(): void
    {
        $translator = new Translator('de');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', __DIR__ . '/../../Resources/translations/messages.de.xlf', 'de');

        $original = (new Trip())
            ->setDate(new \DateTimeImmutable('2026-06-01'))
            ->setPurpose(TripPurpose::BUSINESS)
            ->setVehicle(VehicleType::RENTAL_CAR)
            ->setLicensePlate('M-AB 1')
            ->setStartLocation('Büro')
            ->setDestination('Kunde; "Nord"')
            ->setDistanceKm(42.5)
            ->setRoundTrip(true)
            ->setCosts(12.3)
            // multi-line and formula-like text survives export (escaped) and import (unescaped)
            ->setComment("=Wartung\nzweite Zeile");

        $csv = (new TripCsvExporter($translator))->export([$original]);
        $trip = $this->importer()->build(new User(1), $this->importer()->parse($csv)['rows'])[0]['trip'];

        self::assertNotNull($trip);
        foreach (['getPurpose', 'getVehicle', 'getLicensePlate', 'getStartLocation', 'getDestination', 'getDistanceKm', 'isRoundTrip', 'getCosts', 'getComment'] as $getter) {
            self::assertEquals($original->$getter(), $trip->$getter(), $getter);
        }
    }

    public function testRowsInClosedMonthsAreReported(): void
    {
        $locks = $this->createMock(MonthLockService::class);
        $locks->method('isTripLocked')->willReturnCallback(static fn (Trip $t) => $t->getDate()?->format('Y-m') === '2026-07');
        $importer = $this->importer([], $locks);
        $rows = $importer->parse("Datum;km\n2026-07-15;4\n2026-08-15;5\n")['rows'];

        $built = $importer->build(new User(1), $rows);
        self::assertSame(['date' => 'mileage.logbook.error.locked'], $built[0]['errors']);
        self::assertNull($built[0]['trip']);
        self::assertNotNull($built[1]['trip']);

        // with "edit_locked_mileage" the row is fine
        self::assertSame([], $importer->build(new User(1), $rows, true)[0]['errors']);
    }

    public function testQuotedLineBreaks(): void
    {
        $csv = "Datum;km;Ziel;Bemerkung\n2026-08-21;4;Kunde A;\"Zeile1\nZeile2\"\n\n2026-08-22;5;Kunde B;ok\n";
        $rows = $this->importer()->parse($csv)['rows'];

        self::assertCount(2, $rows);
        self::assertSame([], $rows[0]['errors']);
        self::assertSame("Zeile1\nZeile2", $rows[0]['data']['comment']);
        self::assertSame('Kunde B', $rows[1]['data']['destination']);
    }

    public function testMapperParsers(): void
    {
        self::assertSame(1234.5, TripMapper::parseNumber('1.234,5'));
        self::assertSame(1234.5, TripMapper::parseNumber('1,234.5'));
        self::assertSame(12.5, TripMapper::parseNumber('12,5 km'));
        self::assertNull(TripMapper::parseNumber('abc'));
        self::assertNull(TripMapper::parseDate('31.02.2026'));
        self::assertSame('2026-02-01', TripMapper::parseDate('01.02.26')?->format('Y-m-d'));
        self::assertSame(VehicleType::PUBLIC_TRANSPORT, TripMapper::parseVehicle('Bahn'));
        self::assertSame(TripPurpose::COMMUTE, TripMapper::parsePurpose('Arbeitsweg'));
        self::assertTrue(TripMapper::parseBool('x'));
        self::assertFalse(TripMapper::parseBool('nein'));
    }

    public function testMapperReportsErrors(): void
    {
        $trip = (new Trip())->setDate(new \DateTimeImmutable('2026-01-01'));
        $errors = (new TripMapper())->apply($trip, ['date' => 'gestern', 'purpose' => 'urlaub', 'distanceKm' => '-5', 'departure' => '25:99'], new \DateTimeZone('UTC'));

        self::assertSame(['date', 'departure', 'purpose', 'distanceKm'], array_keys($errors));
    }
}
