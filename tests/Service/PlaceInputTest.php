<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Entity\Customer;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Service\PlaceInput;
use PHPUnit\Framework\TestCase;

class PlaceInputTest extends TestCase
{
    public function testAppliesAllFields(): void
    {
        $customer = $this->createMock(Customer::class);
        $place = (new Place())->setTemporary(true);

        $errors = PlaceInput::apply($place, [
            'name' => 'Muster GmbH', 'type' => 'customer', 'customerId' => 12, 'latitude' => 53.55, 'longitude' => 9.93,
            'radius' => 120, 'address' => 'Kranichweg 12', 'dawarichAreaId' => 7, 'dawarichPlaceId' => null,
        ], fn (int $id) => $id === 12 ? $customer : null);

        self::assertSame([], $errors);
        self::assertSame('Muster GmbH', $place->getName());
        self::assertSame(PlaceType::CUSTOMER, $place->getType());
        self::assertSame($customer, $place->getCustomer());
        self::assertSame(53.55, $place->getLatitude());
        self::assertSame(9.93, $place->getLongitude());
        self::assertSame(120, $place->getRadius());
        self::assertSame('Kranichweg 12', $place->getAddress());
        self::assertSame(7, $place->getDawarichAreaId());
        self::assertNull($place->getDawarichPlaceId());
        self::assertFalse($place->isTemporary());
    }

    public function testKeepsFieldsLeftOut(): void
    {
        $place = (new Place())->setName('Zuhause')->setType(PlaceType::HOME)->setDawarichAreaId(3);

        $errors = PlaceInput::apply($place, ['radius' => 200], fn (int $id) => null);

        self::assertSame([], $errors);
        self::assertSame('Zuhause', $place->getName());
        self::assertSame(PlaceType::HOME, $place->getType());
        self::assertSame(3, $place->getDawarichAreaId());
        self::assertSame(200, $place->getRadius());
    }

    public function testRejectsUnknownTypeAndCustomer(): void
    {
        $place = new Place();

        $errors = PlaceInput::apply($place, ['type' => 'castle', 'customerId' => 99, 'latitude' => 'x'], fn (int $id) => null);

        self::assertArrayHasKey('type', $errors);
        self::assertArrayHasKey('customerId', $errors);
        self::assertArrayHasKey('latitude', $errors);
    }

    public function testClearsCustomer(): void
    {
        $place = (new Place())->setCustomer($this->createMock(Customer::class));

        PlaceInput::apply($place, ['customerId' => null], fn (int $id) => null);

        self::assertNull($place->getCustomer());
    }
}
