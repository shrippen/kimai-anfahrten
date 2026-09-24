<?php

namespace KimaiPlugin\MileageBundle\Service;

use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * Converts trips from/to plain arrays (REST API, CSV import). Unknown keys are ignored.
 */
class TripMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Trip $trip): array
    {
        $tz = $trip->getUser()?->getDateTimezone();
        $time = static fn (?\DateTimeImmutable $d) => $d === null ? null : ($tz !== null ? $d->setTimezone($tz) : $d)->format(\DateTimeInterface::ATOM);

        return [
            'id' => $trip->getId(),
            'user' => $trip->getUser()?->getId(),
            'date' => $trip->getDate()?->format('Y-m-d'),
            'departure' => $time($trip->getDepartureAt()),
            'arrival' => $time($trip->getArrivalAt()),
            'purpose' => $trip->getPurpose()->value,
            'vehicle' => $trip->getVehicle()->value,
            'vehicleId' => $trip->getAssignedVehicle()?->getId(),
            'licensePlate' => $trip->getLicensePlate(),
            'start' => $trip->getStartLocation(),
            'destination' => $trip->getDestination(),
            'distanceKm' => $trip->getDistanceKm(),
            'roundTrip' => $trip->isRoundTrip(),
            'totalKm' => $trip->getTotalDistanceKm(),
            'overnight' => $trip->isOvernight(),
            'odometerStart' => $trip->getOdometerStart(),
            'odometerEnd' => $trip->getOdometerEnd(),
            'costs' => $trip->getCosts(),
            'rentalId' => $trip->getRental()?->getId(),
            'project' => $trip->getProject()?->getId(),
            'timesheet' => $trip->getTimesheet()?->getId(),
            'comment' => $trip->getComment(),
            'source' => $trip->getSource()->value,
        ];
    }

    /**
     * Applies the given fields; returns error messages (translation keys => field).
     *
     * @param array<string, mixed> $data
     * @return array<string, string> field => error
     */
    public function apply(Trip $trip, array $data, ?\DateTimeZone $timezone = null): array
    {
        $errors = [];
        $timezone ??= $trip->getUser()?->getDateTimezone() ?? new \DateTimeZone(date_default_timezone_get());

        if (\array_key_exists('date', $data)) {
            $date = self::parseDate($data['date']);
            $date !== null ? $trip->setDate($date) : $errors['date'] = 'invalid date, expected YYYY-MM-DD or DD.MM.YYYY';
        }
        foreach (['departure' => 'setDepartureAt', 'arrival' => 'setArrivalAt'] as $field => $setter) {
            if (\array_key_exists($field, $data)) {
                if ($data[$field] === null || $data[$field] === '') {
                    $trip->$setter(null);
                    continue;
                }
                $value = self::parseDateTime($data[$field], $timezone, $trip->getDate());
                $value !== null ? $trip->$setter($value) : $errors[$field] = 'invalid date/time';
            }
        }
        if (\array_key_exists('purpose', $data)) {
            $purpose = self::parsePurpose($data['purpose']);
            $purpose !== null ? $trip->setPurpose($purpose) : $errors['purpose'] = 'expected one of: ' . implode(', ', array_column(TripPurpose::cases(), 'value'));
        }
        if (\array_key_exists('vehicle', $data)) {
            $vehicle = self::parseVehicle($data['vehicle']);
            $vehicle !== null ? $trip->setVehicle($vehicle) : $errors['vehicle'] = 'expected one of: ' . implode(', ', array_column(VehicleType::cases(), 'value'));
        }
        foreach (['licensePlate' => ['setLicensePlate', 20], 'start' => ['setStartLocation', 255], 'destination' => ['setDestination', 255], 'comment' => ['setComment', 65535]] as $field => [$setter, $max]) {
            if (\array_key_exists($field, $data)) {
                $value = $data[$field] === null ? null : trim((string) $data[$field]);
                $trip->$setter($value === '' ? null : mb_substr((string) $value, 0, $max));
            }
        }
        foreach (['distanceKm' => 'setDistanceKm', 'costs' => 'setCosts'] as $field => $setter) {
            if (\array_key_exists($field, $data)) {
                $value = self::parseNumber($data[$field]);
                if ($data[$field] !== null && $data[$field] !== '' && ($value === null || $value < 0)) {
                    $errors[$field] = 'expected a positive number';
                    continue;
                }
                $trip->$setter($value);
            }
        }
        foreach (['odometerStart' => 'setOdometerStart', 'odometerEnd' => 'setOdometerEnd'] as $field => $setter) {
            if (\array_key_exists($field, $data)) {
                $value = self::parseNumber($data[$field]);
                $trip->$setter($value === null ? null : (int) round($value));
            }
        }
        foreach (['roundTrip' => 'setRoundTrip', 'overnight' => 'setOvernight'] as $field => $setter) {
            if (\array_key_exists($field, $data)) {
                $trip->$setter(self::parseBool($data[$field]));
            }
        }

        return $errors;
    }

    public static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);
        foreach (['Y-m-d', 'd.m.Y', 'd.m.y', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            // must match exactly: rejects 31.02. (overflow) and "26" read as the year 0026
            if ($date !== false && ($date->format($format) === $value || $date->format(str_replace(['d', 'm'], ['j', 'n'], $format)) === $value)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Full timestamps (ISO 8601) or a plain time ("08:15") on the trip's date.
     */
    public static function parseDateTime(mixed $value, \DateTimeZone $timezone, ?\DateTimeImmutable $date = null): ?\DateTimeImmutable
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            if ($date === null || (int) $m[1] > 23 || (int) $m[2] > 59) {
                return null;
            }

            return new \DateTimeImmutable($date->format('Y-m-d') . \sprintf(' %02d:%02d', (int) $m[1], (int) $m[2]), $timezone);
        }
        try {
            return new \DateTimeImmutable($value, $timezone);
        } catch (\Exception) {
            return null;
        }
    }

    public static function parsePurpose(mixed $value): ?TripPurpose
    {
        $value = mb_strtolower(trim((string) $value));

        return TripPurpose::tryFrom($value) ?? match ($value) {
            'arbeitsweg', 'pendeln', 'wohnung-arbeit' => TripPurpose::COMMUTE,
            'dienstreise', 'dienstlich', 'geschäftlich', 'geschaeftlich', 'betrieblich' => TripPurpose::BUSINESS,
            'privat' => TripPurpose::PRIVATE,
            default => null,
        };
    }

    public static function parseVehicle(mixed $value): ?VehicleType
    {
        $value = mb_strtolower(trim((string) $value));

        return VehicleType::tryFrom($value) ?? match ($value) {
            'pkw', 'auto', 'eigener pkw', 'car' => VehicleType::OWN_CAR,
            'mietwagen', 'rental' => VehicleType::RENTAL_CAR,
            'firmenwagen', 'dienstwagen' => VehicleType::COMPANY_CAR,
            'motorrad', 'roller' => VehicleType::MOTORCYCLE,
            'fahrrad', 'rad', 'bike' => VehicleType::BICYCLE,
            'bahn', 'öpnv', 'oepnv', 'zug', 'bus', 'train' => VehicleType::PUBLIC_TRANSPORT,
            'sonstiges' => VehicleType::OTHER,
            default => null,
        };
    }

    public static function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        $value = str_replace([' ', "\u{00A0}", '€', 'km'], '', (string) $value);
        // "1.234,5" (German) vs "1,234.5" (English)
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $value) || (str_contains($value, ',') && !str_contains($value, '.'))) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    public static function parseBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'ja', 'x', 'y', 'j'], true);
    }
}
