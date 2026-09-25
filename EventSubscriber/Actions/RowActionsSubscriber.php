<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber\Actions;

use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\TripSuggestion;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Row "…" menus of the plugin tables (kimai-plugin-ui GUIDELINES 3.2). The templates pass the entity and what the
 * current user may do; the routes check the permissions again. Reversible actions run immediately through
 * kit.js (data-kpu-post) with an undo toast, deleting goes through Kimai's confirmation modal.
 */
final class RowActionsSubscriber extends AbstractActionsSubscriber
{
    private const ACTIONS = [
        'mileage_trip', 'mileage_suggestion', 'mileage_place', 'mileage_vehicle', 'mileage_rental', 'mileage_month',
        'mileage_team_member', 'mileage_logbook_row',
    ];

    public function __construct(
        AuthorizationCheckerInterface $auth,
        UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
        parent::__construct($auth, $urlGenerator);
    }

    public static function getSubscribedEvents(): array
    {
        $events = [];
        foreach (self::ACTIONS as $action) {
            $events['actions.' . $action] = ['handleEvent', 1000];
        }

        return $events;
    }

    public function onActions(PageActionsEvent $event): void
    {
        $payload = $event->getPayload();

        match ($event->getActionName()) {
            'mileage_trip', 'mileage_logbook_row' => $this->trip($event, $payload),
            'mileage_suggestion' => $this->suggestion($event, $payload),
            'mileage_place' => $this->place($event, $payload),
            'mileage_vehicle' => $this->vehicle($event, $payload),
            'mileage_rental' => $this->rental($event, $payload),
            'mileage_month' => $this->month($event, $payload),
            'mileage_team_member' => $this->teamMember($event, $payload),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function trip(PageActionsEvent $event, array $payload): void
    {
        $trip = $payload['trip'] ?? null;
        if (!$trip instanceof Trip || $trip->getId() === null) {
            return;
        }

        if ($payload['can_edit'] ?? false) {
            $event->addEdit($this->path('mileage_trip_edit', ['id' => $trip->getId()]), false);
            $event->addAction('copy', ['url' => $this->path('mileage_trip_duplicate', ['id' => $trip->getId()]), 'title' => 'mileage.trip.duplicate']);
        }
        $user = $trip->getUser();
        $event->addAction('audit', [
            'url' => $this->path('mileage_history', ['trip' => $trip->getId(), 'user' => $user !== null && $user->getId() !== $event->getUser()->getId() ? $user->getId() : null]),
            'title' => 'mileage.logbook.history',
        ]);
        if ($payload['can_delete'] ?? false) {
            $event->addDelete($this->path('mileage_trip_delete', ['id' => $trip->getId()]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function suggestion(PageActionsEvent $event, array $payload): void
    {
        $suggestion = $payload['suggestion'] ?? null;
        if (!$suggestion instanceof TripSuggestion || $suggestion->getId() === null || !($payload['can_edit'] ?? false)) {
            return;
        }

        $id = (string) $suggestion->getId();
        $token = $this->token('mileage_suggestions');
        $accept = $this->path('mileage_suggestions_accept');

        if (!($payload['locked'] ?? false)) {
            $event->addAction('success', ['url' => '#', 'title' => 'mileage.suggestion.accept', 'attr' => [
                'data-kpu-post' => $accept, 'data-kpu-token' => $token, 'data-kpu-ids' => $id,
            ]]);
            foreach (TripPurpose::cases() as $purpose) {
                if ($purpose === $suggestion->getPurpose()) {
                    continue;
                }
                $event->addAction('mileage_accept_' . $purpose->value, ['url' => '#', 'title' => 'mileage.suggestion.accept_as.' . $purpose->value, 'attr' => [
                    'data-kpu-post' => $accept, 'data-kpu-token' => $token, 'data-kpu-ids' => $id,
                    'data-kpu-params' => json_encode(['purpose' => $purpose->value]),
                ]]);
            }
            $event->addAction('edit', ['url' => '#', 'title' => 'mileage.suggestion.accept_edit', 'attr' => [
                'data-kpu-post' => $accept, 'data-kpu-token' => $token, 'data-kpu-ids' => $id,
                'data-kpu-params' => json_encode(['edit' => 1]),
            ]]);
        }

        $user = $suggestion->getUser();
        $userParam = $user !== null && $user->getId() !== $event->getUser()->getId() ? $user->getId() : null;
        if ($suggestion->getStartPlace() === null || $suggestion->getEndPlace() === null) {
            $event->addDivider();
        }
        if ($suggestion->getStartPlace() === null) {
            $event->addAction('mileage_place_start', [
                'url' => $this->path('mileage_place_create', ['user' => $userParam, 'lat' => $suggestion->getStartLatitude(), 'lon' => $suggestion->getStartLongitude(), 'name' => $suggestion->getStartLabel()]),
                'class' => 'modal-ajax-form',
                'title' => 'mileage.place.save_start',
            ]);
        }
        if ($suggestion->getEndPlace() === null) {
            $event->addAction('mileage_place_end', [
                'url' => $this->path('mileage_place_create', ['user' => $userParam, 'lat' => $suggestion->getEndLatitude(), 'lon' => $suggestion->getEndLongitude(), 'name' => $suggestion->getEndLabel()]),
                'class' => 'modal-ajax-form',
                'title' => 'mileage.place.save_end',
            ]);
        }

        $event->addDivider();
        $event->addAction('rejected', ['url' => '#', 'title' => 'mileage.suggestion.dismiss', 'attr' => [
            'data-kpu-post' => $this->path('mileage_suggestions_dismiss'), 'data-kpu-token' => $token, 'data-kpu-ids' => $id,
        ]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function place(PageActionsEvent $event, array $payload): void
    {
        $place = $payload['place'] ?? null;
        if (!$place instanceof Place || $place->getId() === null || !($payload['can_edit'] ?? false)) {
            return;
        }
        $event->addEdit($this->path('mileage_place_edit', ['id' => $place->getId()]));
        $event->addDelete($this->path('mileage_place_delete', ['id' => $place->getId()]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function vehicle(PageActionsEvent $event, array $payload): void
    {
        $vehicle = $payload['vehicle'] ?? null;
        if (!$vehicle instanceof Vehicle || $vehicle->getId() === null) {
            return;
        }
        $event->addAction('documentation', [
            'url' => $this->path('mileage_logbook', ['id' => $vehicle->getId(), 'year' => $payload['year'] ?? null]),
            'title' => 'mileage.logbook.title',
        ]);
        if ($payload['can_edit'] ?? false) {
            $event->addEdit($this->path('mileage_vehicle_edit', ['id' => $vehicle->getId()]));
        }
        if ($payload['can_delete'] ?? false) {
            $event->addDelete($this->path('mileage_vehicle_delete', ['id' => $vehicle->getId()]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rental(PageActionsEvent $event, array $payload): void
    {
        $rental = $payload['rental'] ?? null;
        if (!$rental instanceof Rental || $rental->getId() === null) {
            return;
        }
        $event->addAction('details', ['url' => $this->path('mileage_rental_show', ['id' => $rental->getId()]), 'title' => 'mileage.rental.show']);
        if ($payload['can_edit'] ?? false) {
            $event->addEdit($this->path('mileage_rental_edit', ['id' => $rental->getId()]));
        }
        if ($payload['can_delete'] ?? false) {
            $event->addDelete($this->path('mileage_rental_delete', ['id' => $rental->getId()]));
        }
    }

    /**
     * Month of the closing list: close/hand in (final for the user → Kimai confirmation), unlock (undo toast).
     *
     * @param array<string, mixed> $payload
     */
    private function month(PageActionsEvent $event, array $payload): void
    {
        $year = (int) ($payload['year'] ?? 0);
        $month = (int) ($payload['month'] ?? 0);
        $user = $payload['user'] ?? null;
        $token = $this->token('mileage_month_lock');

        if ($payload['can_lock'] ?? false) {
            $event->addAction('locked', ['url' => '#', 'title' => $payload['lock_title'] ?? 'mileage.logbook.lock', 'attr' => [
                'data-kpu-post' => $this->path('mileage_month_lock', ['year' => $year, 'month' => $month, 'user' => $user]),
                'data-kpu-token' => $token,
                'data-kpu-question' => (string) ($payload['lock_question'] ?? ''),
            ]]);
        }
        if ($payload['can_unlock'] ?? false) {
            $event->addAction('unlocked', ['url' => '#', 'title' => 'mileage.logbook.unlock', 'attr' => [
                'data-kpu-post' => $this->path('mileage_month_unlock', ['year' => $year, 'month' => $month, 'user' => $user]),
                'data-kpu-token' => $token,
            ]]);
        }
        $event->addAction('list', ['url' => $this->path('mileage_trips', ['year' => $year, 'month' => $month, 'user' => $user]), 'title' => 'mileage.menu']);
    }

    /**
     * Team member of one month: approve (undo toast) or reject (modal with reason).
     *
     * @param array<string, mixed> $payload
     */
    private function teamMember(PageActionsEvent $event, array $payload): void
    {
        $lockId = $payload['lock_id'] ?? null;
        if ($lockId !== null && ($payload['can_approve'] ?? false)) {
            $event->addAction('success', ['url' => '#', 'title' => 'mileage.approval.approve', 'attr' => [
                'data-kpu-post' => $this->path('mileage_team_approve'),
                'data-kpu-token' => $this->token('mileage_team'),
                'data-kpu-ids' => (string) $lockId,
            ]]);
            $event->addAction('rejected', [
                'url' => $this->path('mileage_team_reject', ['id' => $lockId]),
                'class' => 'modal-ajax-form',
                'title' => 'mileage.approval.reject',
            ]);
            $event->addDivider();
        }
        $event->addAction('list', ['url' => $this->path('mileage_trips', ['year' => $payload['year'] ?? null, 'month' => $payload['month'] ?? null, 'user' => $payload['user'] ?? null]), 'title' => 'mileage.menu']);
        $event->addAction('locked', ['url' => $this->path('mileage_months', ['year' => $payload['year'] ?? null, 'user' => $payload['user'] ?? null]), 'title' => 'mileage.logbook.months']);
    }

    private function token(string $id): string
    {
        return $this->csrfTokenManager->getToken($id)->getValue();
    }
}
