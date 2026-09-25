<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Own section "Fahrten" in the sidebar, placed right after Kimai's time tracking.
 */
class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigureMainMenuEvent::class => ['onMainMenu', -20],
        ];
    }

    public function onMainMenu(ConfigureMainMenuEvent $event): void
    {
        if (!$this->security->isGranted('IS_AUTHENTICATED_REMEMBERED') || !$this->security->isGranted('mileage')) {
            return;
        }

        $root = $event->getMenu();
        if ($root->getChild('mileage') !== null) {
            return;
        }

        $section = new MenuItemModel('mileage', 'mileage.menu', null, [], 'fas fa-car');
        $section->setTranslationDomain('messages');

        // Kimai icon aliases where one fits (kimai-plugin-ui GUIDELINES 9), FontAwesome otherwise
        $items = [
            ['mileage_trips', 'mileage.menu.trips', 'list', ['mileage_trip_create', 'mileage_trip_edit', 'mileage_trip_duplicate', 'mileage_trip_delete', 'mileage_commutes', 'mileage_import', 'mileage_history']],
            ['mileage_suggestions', 'mileage.suggestion.list', 'fas fa-satellite-dish', ['mileage_suggestions_detect']],
            ['mileage_vehicles', 'mileage.vehicle.list', 'fas fa-car-side', ['mileage_vehicle_create', 'mileage_vehicle_edit', 'mileage_vehicle_delete', 'mileage_logbook']],
            ['mileage_rentals', 'mileage.rental.list', 'fas fa-key', ['mileage_rental_create', 'mileage_rental_edit', 'mileage_rental_delete', 'mileage_rental_show']],
            ['mileage_places', 'mileage.place.list', 'fas fa-location-dot', ['mileage_place_create', 'mileage_place_edit', 'mileage_place_delete']],
            ['mileage_months', 'mileage.logbook.months', 'locked', []],
            ['mileage_overview', 'mileage.overview.title', 'customer', []],
            ['mileage_tax_report', 'mileage.menu.tax', 'money', []],
        ];

        $teamAccess = $this->security->isGranted('approve_mileage') || $this->security->isGranted('approve_other_mileage')
            || $this->security->isGranted('view_other_mileage') || $this->security->isGranted('view_team_mileage');
        if ($teamAccess) {
            $items[] = ['mileage_team', 'mileage.approval.team', 'team', ['mileage_team_reject']];
        }

        foreach ($items as [$route, $label, $icon, $childRoutes]) {
            $item = new MenuItemModel($route, $label, $route, [], $icon);
            $item->setTranslationDomain('messages');
            $item->setChildRoutes($childRoutes);
            $section->addChild($item);
        }

        $root->addChild($section);

        // Move the section directly behind "Zeiterfassung" (or keep it at the end).
        $children = array_values(array_filter($root->getChildren(), static fn (MenuItemModel $c) => $c !== $section));
        $position = null;
        foreach ($children as $index => $child) {
            if ($child->getIdentifier() === 'times') {
                $position = $index + 1;
            }
        }
        if ($position !== null) {
            array_splice($children, $position, 0, [$section]);
            $root->setChildren($children);
        }
    }
}
