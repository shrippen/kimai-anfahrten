<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber\Actions;

use App\Entity\Timesheet;
use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;

/**
 * "Fahrt erfassen" in the action dropdown of a timesheet row.
 */
final class TimesheetTripSubscriber extends AbstractActionsSubscriber
{
    public static function getActionName(): string
    {
        return 'timesheet';
    }

    public function onActions(PageActionsEvent $event): void
    {
        $timesheet = $event->getPayload()['timesheet'] ?? null;
        if (!$timesheet instanceof Timesheet || $timesheet->getId() === null) {
            return;
        }

        // Own vs. other user is enforced by the controller.
        if (!$this->isGranted('mileage') || (!$this->isGranted('edit_own_mileage') && !$this->isGranted('edit_other_mileage'))) {
            return;
        }

        $event->addAction('mileage_trip', [
            'url' => $this->path('mileage_trip_create', [
                'timesheet' => $timesheet->getId(),
                'purpose' => 'business',
                'user' => $timesheet->getUser()?->getId(),
            ]),
            'title' => 'trip.create_from_timesheet',
            'translation_domain' => 'messages',
            'icon' => 'fas fa-car',
        ]);
    }
}
