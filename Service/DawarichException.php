<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * Message is a translation key; parameters are passed to the translator.
 */
class DawarichException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(string $message, private readonly array $parameters = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, string|int>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
