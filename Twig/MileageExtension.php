<?php

namespace KimaiPlugin\MileageBundle\Twig;

use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class MileageExtension extends AbstractExtension
{
    public function __construct(private readonly MileageConfiguration $configuration)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('mileage_object_id', static fn (object $object): int => spl_object_id($object)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mileage_map_enabled', fn (): bool => $this->configuration->getMapTilesUrl() !== null),
            new TwigFunction('mileage_map_tiles', fn (): string => (string) $this->configuration->getMapTilesUrl()),
            new TwigFunction('mileage_map_attribution', fn (): string => $this->configuration->getMapAttribution()),
        ];
    }
}
