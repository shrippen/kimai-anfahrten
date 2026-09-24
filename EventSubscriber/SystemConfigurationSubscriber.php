<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration as SystemConfigurationModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigurationEvent::class => ['onSystemConfiguration', 100],
        ];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $rate = static fn (string $name, float $default) => (new Configuration($name))
            ->setLabel($name)
            ->setTranslationDomain('messages')
            ->setRequired(false)
            ->setType(NumberType::class)
            ->setValue($default)
            ->setOptions(['scale' => 2, 'html5' => true, 'attr' => ['min' => 0, 'step' => 0.01]]);

        $event->addConfiguration(
            (new SystemConfigurationModel('mileage'))
                ->setTranslation('mileage.settings_section')
                ->setTranslationDomain('messages')
                ->setConfiguration([
                    $rate('mileage.rate_commute', 0.38)->setOptions(['help' => 'mileage.rate_commute_help', 'scale' => 2, 'html5' => true]),
                    $rate('mileage.rate_business_car', 0.30),
                    $rate('mileage.rate_business_motorcycle', 0.20),
                    $rate('mileage.commute_cap', 4500.0),
                    (new Configuration('mileage.dawarich_url'))
                        ->setLabel('mileage.dawarich_url')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(UrlType::class)
                        ->setOptions(['help' => 'mileage.dawarich_url_help']),
                    (new Configuration('mileage.dawarich_max_accuracy'))
                        ->setLabel('mileage.dawarich_max_accuracy')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(IntegerType::class)
                        ->setValue(100)
                        ->setOptions(['help' => 'mileage.dawarich_max_accuracy_help']),
                ])
        );
    }
}
