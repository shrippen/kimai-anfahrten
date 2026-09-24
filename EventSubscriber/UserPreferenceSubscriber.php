<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber;

use App\Entity\UserPreference;
use App\Event\UserPreferenceEvent;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Form\Type\SecretType;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Adds the mileage / Dawarich settings to Profil → Einstellungen.
 */
class UserPreferenceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserPreferenceEvent::class => ['loadUserPreferences', 200],
        ];
    }

    public function loadUserPreferences(UserPreferenceEvent $event): void
    {
        if (!$this->security->isGranted('mileage')) {
            return;
        }

        $vehicles = [];
        foreach (VehicleType::cases() as $vehicle) {
            $vehicles[$vehicle->label()] = $vehicle->value;
        }

        $order = 1000;
        $add = static function (string $name, string $type, array $options = [], mixed $default = null) use ($event, &$order): void {
            $event->addPreference(
                (new UserPreference($name, $default))
                    ->setType($type)
                    ->setSection('mileage')
                    ->setOrder($order++)
                    ->setEnabled(true)
                    ->setOptions(['label' => $name, 'translation_domain' => 'messages', 'required' => false] + $options)
            );
        };

        $add(MileageConfiguration::PREF_DAWARICH_URL, UrlType::class, ['help' => 'mileage_dawarich_url_help']);
        $add(MileageConfiguration::PREF_DAWARICH_API_KEY, SecretType::class, ['help' => 'mileage_dawarich_api_key_help']);
        $add(MileageConfiguration::PREF_HOME_ADDRESS, TextType::class);
        $add(MileageConfiguration::PREF_WORK_ADDRESS, TextType::class);
        $add(MileageConfiguration::PREF_COMMUTE_KM, NumberType::class, ['help' => 'mileage_commute_km_help', 'scale' => 1, 'html5' => true]);
        $add(MileageConfiguration::PREF_DEFAULT_VEHICLE, ChoiceType::class, ['choices' => $vehicles, 'choice_translation_domain' => 'messages'], VehicleType::OWN_CAR->value);
        $add(MileageConfiguration::PREF_LICENSE_PLATE, TextType::class);
    }
}
