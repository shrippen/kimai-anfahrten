<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Field errors of an API request (answered with 400 {"errors": {field: message}}).
 */
class InvalidInputException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_map(static fn (string $field, string $error) => $field . ': ' . $error, array_keys($errors), $errors)));
    }
}
