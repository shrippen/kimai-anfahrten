<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\Customer;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Enum\PlaceType;

/**
 * Applies the JSON body of POST/PATCH /api/mileage/places to a place. Fields left out keep their value, null clears
 * an optional one. A place written through the API is a regular place, no longer a temporary one.
 */
final class PlaceInput
{
    private const MAX_NAME = 100;
    private const MAX_ADDRESS = 255;

    /**
     * @param array<string, mixed> $data
     * @param callable(int): ?Customer $customer finds a customer by id
     * @return array<string, string> field => error
     */
    public static function apply(Place $place, array $data, callable $customer): array
    {
        $errors = [];

        if (\array_key_exists('name', $data)) {
            $place->setName(\is_string($data['name']) ? mb_substr(trim($data['name']), 0, self::MAX_NAME) : null);
        }
        if (\array_key_exists('address', $data)) {
            $place->setAddress(\is_string($data['address']) && trim($data['address']) !== '' ? mb_substr(trim($data['address']), 0, self::MAX_ADDRESS) : null);
        }

        if (\array_key_exists('type', $data)) {
            $type = PlaceType::tryFrom((string) $data['type']);
            if ($type === null) {
                $errors['type'] = 'unknown type';
            } else {
                $place->setType($type);
            }
        }

        // customerId: null clears, an unknown id is an error
        if (\array_key_exists('customerId', $data)) {
            $found = is_numeric($data['customerId']) ? $customer((int) $data['customerId']) : null;
            if ($data['customerId'] !== null && $found === null) {
                $errors['customerId'] = 'unknown customer';
            } else {
                $place->setCustomer($found);
            }
        }

        foreach (['latitude' => 'setLatitude', 'longitude' => 'setLongitude'] as $field => $setter) {
            if (!\array_key_exists($field, $data)) {
                continue;
            }
            if (!is_numeric($data[$field])) {
                $errors[$field] = 'not a number';
                continue;
            }
            $place->$setter((float) $data[$field]);
        }

        foreach (['radius' => 'setRadius', 'dawarichAreaId' => 'setDawarichAreaId', 'dawarichPlaceId' => 'setDawarichPlaceId'] as $field => $setter) {
            if (!\array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] !== null && !is_numeric($data[$field])) {
                $errors[$field] = 'not a number';
                continue;
            }
            $place->$setter($data[$field] === null ? null : (int) $data[$field]);
        }

        $place->setTemporary(false);

        return $errors;
    }
}
