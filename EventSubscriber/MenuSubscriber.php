<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

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

        $timesheets = $event->getTimesheetMenu();
        if ($timesheets !== null && $timesheets->getChild('mileage_trips') === null) {
            $item = new MenuItemModel('mileage_trips', 'menu.mileage', 'mileage_trips', [], 'fas fa-car');
            $item->setTranslationDomain('messages');
            $timesheets->addChild($item);
        }

        $reporting = $event->getReportingMenu();
        if ($reporting !== null && $reporting->getChild('mileage_tax_report') === null) {
            $item = new MenuItemModel('mileage_tax_report', 'menu.mileage_tax', 'mileage_tax_report', [], 'fas fa-file-invoice');
            $item->setTranslationDomain('messages');
            $reporting->addChild($item);
        }

        $teamAccess = $this->security->isGranted('approve_mileage') || $this->security->isGranted('approve_other_mileage')
            || $this->security->isGranted('view_other_mileage') || $this->security->isGranted('view_team_mileage');
        if ($reporting !== null && $teamAccess && $reporting->getChild('mileage_team') === null) {
            $item = new MenuItemModel('mileage_team', 'approval.team', 'mileage_team', [], 'fas fa-people-group');
            $item->setTranslationDomain('messages');
            $reporting->addChild($item);
        }
    }
}
