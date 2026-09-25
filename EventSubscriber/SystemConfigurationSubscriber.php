<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration as SystemConfigurationModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
                    // no default: empty means "use the legal rate of the trip's year"
                    (new Configuration('mileage.rate_commute'))
                        ->setLabel('mileage.rate_commute')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(NumberType::class)
                        ->setOptions(['help' => 'mileage.rate_commute_help', 'scale' => 2, 'html5' => true, 'attr' => ['min' => 0, 'step' => 0.01]]),
                    $rate('mileage.rate_business_car', 0.30),
                    $rate('mileage.rate_business_motorcycle', 0.20),
                    $rate('mileage.commute_cap', 4500.0),
                    $rate('mileage.meal_partial', 14.0),
                    $rate('mileage.meal_full', 28.0),
                    (new Configuration('mileage.journey_max_gap_days'))
                        ->setLabel('mileage.journey_max_gap_days')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(IntegerType::class)
                        ->setValue(14)
                        ->setOptions(['help' => 'mileage.journey_max_gap_days_help']),
                    (new Configuration('mileage.dawarich_url'))
                        ->setLabel('mileage.dawarich_url')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(UrlType::class)
                        ->setOptions(['help' => 'mileage.dawarich_url_help']),
                    (new Configuration('mileage.dawarich_user_url'))
                        ->setLabel('mileage.dawarich_user_url')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(CheckboxType::class)
                        ->setValue(false)
                        ->setOptions(['help' => 'mileage.dawarich_user_url_help']),
                    (new Configuration('mileage.approval_enabled'))
                        ->setLabel('mileage.approval_enabled')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(CheckboxType::class)
                        ->setOptions(['help' => 'mileage.approval_enabled_help']),
                    (new Configuration('mileage.geocoder_url'))
                        ->setLabel('mileage.geocoder_url')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(UrlType::class)
                        ->setOptions(['help' => 'mileage.geocoder_url_help']),
                    (new Configuration('mileage.map_tiles_url'))
                        ->setLabel('mileage.map_tiles_url')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(TextType::class)
                        ->setValue('https://tile.openstreetmap.org/{z}/{x}/{y}.png')
                        ->setOptions(['help' => 'mileage.map_tiles_url_help']),
                    (new Configuration('mileage.map_attribution'))
                        ->setLabel('mileage.map_attribution')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(TextType::class)
                        ->setValue('© OpenStreetMap contributors'),
                ])
        );

        $event->addConfiguration(
            (new SystemConfigurationModel('mileage_detection'))
                ->setTranslation('mileage.detection_section')
                ->setTranslationDomain('messages')
                ->setConfiguration([
                    (new Configuration('mileage.dawarich_exclude_non_motorized'))
                        ->setLabel('mileage.dawarich_exclude_non_motorized')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(CheckboxType::class)
                        ->setValue(true)
                        ->setOptions(['help' => 'mileage.dawarich_exclude_non_motorized_help']),
                    (new Configuration('mileage.detect_stop_minutes'))
                        ->setLabel('mileage.detect_stop_minutes')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(IntegerType::class)
                        ->setValue(5)
                        ->setOptions(['help' => 'mileage.detect_stop_minutes_help']),
                    $rate('mileage.detect_min_km', 1.0),
                    (new Configuration('mileage.place_radius'))
                        ->setLabel('mileage.place_radius')
                        ->setTranslationDomain('messages')
                        ->setRequired(false)
                        ->setType(IntegerType::class)
                        ->setValue(200)
                        ->setOptions(['help' => 'mileage.place_radius_help']),
                ])
        );
    }
}
