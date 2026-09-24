<?php

namespace KimaiPlugin\MileageBundle\Twig;

use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class MileageExtension extends AbstractExtension
{
    public function __construct(private readonly MileageConfiguration $configuration)
    {
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
