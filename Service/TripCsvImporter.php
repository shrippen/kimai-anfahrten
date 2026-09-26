<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripSource;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Imports trips from CSV (old logbooks, exports of car apps, this plugin's own export).
 * Separator, encoding and column names (German/English) are detected.
 */
class TripCsvImporter
{
    public const MAX_ROWS = 5000;

    /**
     * Normalized header => field. Prefix matches are allowed for long headers.
     */
    private const COLUMNS = [
        'datum' => 'date', 'date' => 'date', 'tag' => 'date', 'fahrtdatum' => 'date',
        'abfahrt' => 'departure', 'departure' => 'departure', 'beginn' => 'departure', 'startzeit' => 'departure', 'abfahrtszeit' => 'departure',
        'ankunft' => 'arrival', 'arrival' => 'arrival', 'ende' => 'arrival', 'endzeit' => 'arrival', 'ankunftszeit' => 'arrival',
        'artderfahrt' => 'purpose', 'fahrtart' => 'purpose', 'art' => 'purpose', 'kategorie' => 'purpose', 'typ' => 'purpose', 'purpose' => 'purpose', 'type' => 'purpose', 'triptype' => 'purpose',
        'fahrzeug' => 'vehicle', 'vehicle' => 'vehicle', 'verkehrsmittel' => 'vehicle',
        'kennzeichen' => 'licensePlate', 'licenseplate' => 'licensePlate', 'plate' => 'licensePlate',
        'start' => 'start', 'von' => 'start', 'startort' => 'start', 'from' => 'start', 'abfahrtsort' => 'start',
        'ziel' => 'destination', 'nach' => 'destination', 'reiseziel' => 'destination', 'zielort' => 'destination', 'destination' => 'destination', 'to' => 'destination',
        'entfernungkm' => 'distanceKm', 'entfernung' => 'distanceKm', 'km' => 'distanceKm', 'strecke' => 'distanceKm', 'distance' => 'distanceKm', 'distancekm' => 'distanceKm', 'kilometer' => 'distanceKm', 'gefahrenekm' => 'distanceKm',
        'hinundrück' => 'roundTrip', 'hinundrueck' => 'roundTrip', 'roundtrip' => 'roundTrip',
        'kosten' => 'costs', 'costs' => 'costs', 'betrag' => 'costs',
        'anlassbemerkung' => 'comment', 'bemerkung' => 'comment', 'kommentar' => 'comment', 'reisezweck' => 'comment', 'anlass' => 'comment', 'comment' => 'comment', 'notiz' => 'comment', 'zweck' => 'comment',
        'kmstandbeginn' => 'odometerStart', 'kmstandstart' => 'odometerStart', 'odometerstart' => 'odometerStart', 'anfangskm' => 'odometerStart',
        'kmstandende' => 'odometerEnd', 'odometerend' => 'odometerEnd', 'endkm' => 'odometerEnd',
        'übernachtung' => 'overnight', 'auswärtsübernachtet' => 'overnight', 'overnight' => 'overnight',
    ];

    public function __construct(
        private readonly TripMapper $mapper,
        private readonly TripService $tripService,
        private readonly TripRepository $tripRepository,
        private readonly ValidatorInterface $validator,
        private readonly ?MonthLockService $lockService = null,
    ) {
    }

    /**
     * @return array{columns: array<int, ?string>, rows: list<array{line: int, data: array<string, string>, errors: array<string, string>}>}
     */
    public function parse(string $content): array
    {
        $content = self::toUtf8($content);
        $firstLine = strtok(ltrim($content), "\r\n");
        if ($firstLine === false || trim($firstLine) === '') {
            return ['columns' => [], 'rows' => []];
        }
        $separator = self::detectSeparator($firstLine);

        // Read with fgetcsv: quoted fields may contain line breaks (e.g. multi-line comments of our own export).
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $columns = null;
        $rows = [];
        $line = 0;
        while (($values = fgetcsv($handle, null, $separator, '"', '')) !== false) {
            $line++;
            if ($values === [null] || implode('', array_map('trim', array_map('strval', $values))) === '') {
                continue;
            }
            if ($columns === null) {
                $columns = array_map([self::class, 'column'], array_map('strval', $values));
                continue;
            }
            if (\count($rows) >= self::MAX_ROWS) {
                break;
            }
            $data = [];
            foreach ($columns as $index => $field) {
                $value = trim((string) ($values[$index] ?? ''));
                if ($field !== null && $value !== '' && !isset($data[$field])) {
                    $data[$field] = CsvSafe::unescape($value);
                }
            }
            $errors = [];
            if (!isset($data['date'])) {
                $errors['date'] = 'mileage.import.error.date_missing';
            }
            if (!isset($data['distanceKm']) && !(isset($data['odometerStart'], $data['odometerEnd']))) {
                $errors['distanceKm'] = 'mileage.import.error.distance_missing';
            }
            $rows[] = ['line' => $line, 'data' => $data, 'errors' => $errors];
        }
        fclose($handle);

        return ['columns' => $columns ?? [], 'rows' => $rows];
    }

    /**
     * Builds (but does not save) the trips; rows with errors get them attached.
     *
     * @param list<array{line: int, data: array<string, string>, errors: array<string, string>}> $rows
     * @return list<array{line: int, data: array<string, string>, errors: array<string, string>, trip: ?Trip, duplicate: bool}>
     */
    public function build(User $user, array $rows, bool $mayEditLocked = false): array
    {
        $existing = [];
        $result = [];
        foreach ($rows as $row) {
            $trip = null;
            $duplicate = false;
            $errors = $row['errors'];

            if ($errors === []) {
                $date = TripMapper::parseDate($row['data']['date']) ?? new \DateTimeImmutable('today');
                $trip = $this->tripService->createTrip($user, $date);
                $data = $row['data'];
                // Only the distance of one direction is stored; derive it from the odometer if needed.
                if (!isset($data['distanceKm']) && isset($data['odometerStart'], $data['odometerEnd'])) {
                    $data['distanceKm'] = (string) max(0, (float) TripMapper::parseNumber($data['odometerEnd']) - (float) TripMapper::parseNumber($data['odometerStart']));
                }
                $errors = $this->mapper->apply($trip, $data, $user->getDateTimezone());
                $trip->setSource(TripSource::MANUAL);

                foreach ($this->validator->validate($trip) as $violation) {
                    $errors[$violation->getPropertyPath() ?: 'trip'] = (string) $violation->getMessage();
                }
                // Closed months are refused when saving anyway; report it per row instead of failing the whole import.
                if ($errors === [] && !$mayEditLocked && $this->lockService?->isTripLocked($trip) === true) {
                    $errors['date'] = 'mileage.logbook.error.locked';
                }

                if ($errors === [] && ($day = $trip->getDate()) !== null) {
                    $key = $day->format('Y-m');
                    $existing[$key] ??= array_map([self::class, 'fingerprint'], $this->tripRepository->findByUserBetween($user, $day->modify('first day of this month'), $day->modify('last day of this month')));
                    $fingerprint = self::fingerprint($trip);
                    $duplicate = \in_array($fingerprint, $existing[$key], true);
                    $existing[$key][] = $fingerprint;
                }
            }

            $result[] = [
                'line' => $row['line'],
                'data' => $row['data'],
                'errors' => $errors,
                'trip' => $errors === [] ? $trip : null,
                'duplicate' => $duplicate,
            ];
        }

        return $result;
    }

    /**
     * @param list<array{trip: ?Trip, duplicate: bool}> $built
     * @return array{imported: int, skipped: int}
     */
    public function import(array $built, bool $skipDuplicates = true): array
    {
        $imported = 0;
        $skipped = 0;
        foreach ($built as $row) {
            $trip = $row['trip'];
            if ($trip === null || ($skipDuplicates && $row['duplicate'])) {
                $skipped++;
                continue;
            }
            $this->tripService->prepare($trip);
            $this->tripRepository->save($trip, false);
            $imported++;
        }
        $this->tripRepository->flush();

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public static function column(string $header): ?string
    {
        $key = preg_replace('/[^a-z0-9äöüß]/u', '', mb_strtolower(trim($header, " \t\n\r\0\x0B\u{FEFF}"))) ?? '';
        if ($key === '') {
            return null;
        }
        if (isset(self::COLUMNS[$key])) {
            return self::COLUMNS[$key];
        }
        // Long headers like "Hin- und Rückfahrt (Strecke verdoppeln)" or "Entfernung (km)"
        foreach (self::COLUMNS as $alias => $field) {
            if (mb_strlen($alias) >= 6 && str_starts_with($key, $alias)) {
                return $field;
            }
        }

        return null;
    }

    public static function detectSeparator(string $header): string
    {
        $counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    public static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }

    private static function fingerprint(Trip $trip): string
    {
        return implode('|', [
            $trip->getDate()?->format('Y-m-d'),
            $trip->getPurpose()->value,
            number_format($trip->getDistanceKm(), 1, '.', ''),
            mb_strtolower((string) $trip->getDestination()),
        ]);
    }
}
