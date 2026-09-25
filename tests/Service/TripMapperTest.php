<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Service\TripMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Invalid input must end as a field error (HTTP 400), never as a database error (HTTP 500).
 */
class TripMapperTest extends TestCase
{
    private function trip(): Trip
    {
        return (new Trip())->setUser(new User())->setDate(new \DateTimeImmutable('2026-08-10'))->setDistanceKm(5);
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @return array<string, string>
     */
    private function violations(object $entity): array
    {
        $result = [];
        foreach ($this->validator()->validate($entity) as $violation) {
            $result[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $result;
    }

    public function testNonScalarValuesAreRejected(): void
    {
        $trip = $this->trip();
        $errors = (new TripMapper())->apply($trip, [
            'comment' => ['x'],
            'destination' => ['a' => 1],
            'purpose' => ['business'],
            'vehicle' => new \stdClass(),
            'roundTrip' => [true],
            'distanceKm' => [5],
        ]);

        $fields = array_keys($errors);
        sort($fields);
        self::assertSame(['comment', 'destination', 'distanceKm', 'purpose', 'roundTrip', 'vehicle'], $fields);
        self::assertNull($trip->getComment());
        self::assertNull($trip->getDestination());
        self::assertSame(5.0, $trip->getDistanceKm());
    }

    public function testNonFiniteAndOutOfRangeNumbersAreRejected(): void
    {
        $trip = $this->trip();
        $errors = (new TripMapper())->apply($trip, [
            'distanceKm' => INF,
            'costs' => '1e999',
            'odometerStart' => 3000000000,
            'odometerEnd' => 'abc',
        ]);

        self::assertSame(['distanceKm', 'costs', 'odometerStart', 'odometerEnd'], array_keys($errors));
        self::assertNull(TripMapper::parseNumber(NAN));
        self::assertNull(TripMapper::parseNumber(true));
        self::assertSame(12.5, TripMapper::parseNumber('12,5'));
    }

    public function testEntityLimitsMatchTheColumns(): void
    {
        $trip = $this->trip()
            ->setLicensePlate(str_repeat('A', 21))
            ->setStartLocation(str_repeat('B', 256))
            ->setDestination(str_repeat('C', 256))
            ->setDistanceKm(1e300)
            ->setCosts(1e12)
            ->setOdometerStart(2147483648)
            ->setOdometerEnd(2147483649)
            ->setComment(str_repeat('D', Trip::MAX_COMMENT + 1));

        self::assertSame(
            ['distanceKm', 'costs', 'licensePlate', 'comment', 'odometerStart', 'odometerEnd', 'startLocation', 'destination'],
            array_values(array_intersect(['distanceKm', 'costs', 'licensePlate', 'comment', 'odometerStart', 'odometerEnd', 'startLocation', 'destination'], array_keys($this->violations($trip))))
        );
        self::assertCount(8, $this->violations($trip));

        self::assertSame([], $this->violations($this->trip()->setLicensePlate(str_repeat('A', 20))->setOdometerStart(123456)->setOdometerEnd(123460)));

        $rental = (new Rental())->setProvider(str_repeat('P', 101))->setLicensePlate(str_repeat('A', 21))
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))->setEndDate(new \DateTimeImmutable('2026-01-02'));
        self::assertSame(['provider', 'licensePlate'], array_keys($this->violations($rental)));

        $place = (new Place())->setName('Büro')->setAddress(str_repeat('X', 256));
        self::assertSame(['address'], array_keys($this->violations($place)));
    }
}
