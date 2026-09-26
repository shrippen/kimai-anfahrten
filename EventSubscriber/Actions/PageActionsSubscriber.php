<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber\Actions;

use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Page header actions of all plugin pages (kimai-plugin-ui GUIDELINES 2.3). The controllers pass what the
 * current user may do in the payload (MileagePages::create()); the routes check the permissions again.
 *
 * Sub navigation: the plugin pages are the children of Kimai's "Fahrten" menu; links between related pages
 * (logbook, history, places, trips of a month) are page actions here or entries in the row "…" menus.
 */
final class PageActionsSubscriber extends AbstractActionsSubscriber
{
    private const ACTIONS = [
        'mileage_trips', 'mileage_trip_form', 'mileage_form', 'mileage_suggestions', 'mileage_places', 'mileage_vehicles',
        'mileage_logbook', 'mileage_rentals', 'mileage_rental_page', 'mileage_months', 'mileage_history', 'mileage_overview',
        'mileage_team', 'mileage_tax',
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
            'mileage_trips' => $this->trips($event, $payload),
            'mileage_trip_form' => $this->tripForm($event, $payload),
            'mileage_suggestions' => $this->suggestions($event, $payload),
            'mileage_places' => $this->places($event, $payload),
            'mileage_vehicles' => $this->vehicles($event, $payload),
            'mileage_logbook' => $this->logbook($event, $payload),
            'mileage_rentals' => $this->rentals($event, $payload),
            'mileage_rental_page' => $this->rental($event, $payload),
            'mileage_months' => $this->months($event, $payload),
            'mileage_history' => $this->history($event, $payload),
            'mileage_overview' => $this->overview($event, $payload),
            'mileage_team' => $this->team($event, $payload),
            'mileage_tax' => $this->tax($event, $payload),
            default => $this->form($event, $payload),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function trips(PageActionsEvent $event, array $payload): void
    {
        $user = $payload['user'] ?? null;
        $year = (int) ($payload['year'] ?? date('Y'));
        $month = $payload['month'] ?? null;

        $more = [];
        if ($payload['can_edit'] ?? false) {
            $event->addAction('create', ['url' => $this->path('mileage_trip_create', ['user' => $user]), 'title' => 'mileage.trip.create']);
            // Kimai takes a page action's icon from its key and ignores "icon" (kit GUIDELINES 2.3)
            $event->addAction('home', [
                'url' => $this->path('mileage_trip_create', ['user' => $user, 'purpose' => 'commute']),
                'title' => 'mileage.trip.create_commute',
            ]);
            if ($month !== null) {
                $more['mileage_commutes'] = [
                    'url' => $this->path('mileage_commutes', ['year' => $year, 'month' => $month, 'user' => $user]),
                    'title' => 'mileage.commute.generate',
                ];
            }
            $more['import'] = ['url' => $this->path('mileage_import', ['user' => $user]), 'title' => 'mileage.import.title'];
            if ($payload['dawarich'] ?? false) {
                $more['mileage_dawarich_test'] = [
                    'url' => '#',
                    'title' => 'mileage.dawarich.test',
                    'attr' => [
                        'data-kpu-post' => $this->path('mileage_dawarich_test', ['user' => $user]),
                        'data-kpu-token' => $this->token('mileage_dawarich_test'),
                    ],
                ];
            }
        }

        $event->addQuickExport($this->path('mileage_export', ['year' => $year, 'user' => $user]));
        if ($more !== []) {
            // rarer actions in one menu, so the header keeps room for the period navigation
            // Kimai renders the icon of a dropdown from its key, there is no alias for "more"
            $event->addAction('fas fa-ellipsis-h', ['title' => 'mileage.action.more', 'children' => $more]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tripForm(PageActionsEvent $event, array $payload): void
    {
        $trip = $payload['trip'] ?? null;
        $this->back($event, $payload);
        if ($trip === null || $trip->getId() === null) {
            return;
        }

        $user = $trip->getUser();
        $userParam = $user !== null && $user->getId() !== $event->getUser()->getId() ? $user->getId() : null;
        $event->addAction('audit', [
            'url' => $this->path('mileage_history', ['user' => $userParam, 'trip' => $trip->getId()]),
            'title' => 'mileage.logbook.history',
        ]);
        if ($payload['can_edit'] ?? false) {
            $event->addAction('copy', ['url' => $this->path('mileage_trip_duplicate', ['id' => $trip->getId()]), 'title' => 'mileage.trip.duplicate']);
        }
        if ($payload['can_delete'] ?? false) {
            $event->addDelete($this->path('mileage_trip_delete', ['id' => $trip->getId()]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function suggestions(PageActionsEvent $event, array $payload): void
    {
        $user = $payload['user'] ?? null;
        if (($payload['can_edit'] ?? false) && ($payload['dawarich'] ?? false)) {
            $event->addAction('search', [
                'url' => $this->path('mileage_suggestions_detect', ['user' => $user]),
                'class' => 'modal-ajax-form',
                'title' => 'mileage.suggestion.detect',
            ]);
        }
        $event->addAction('mileage_places', ['url' => $this->path('mileage_places', ['user' => $user]), 'title' => 'mileage.place.list']);
        if (!($payload['dawarich'] ?? false) && ($payload['own'] ?? false)) {
            $event->addAction('settings', ['url' => $this->path('user_profile_preferences', ['username' => $event->getUser()->getUserIdentifier()]), 'title' => 'mileage.dawarich.setup']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function places(PageActionsEvent $event, array $payload): void
    {
        $user = $payload['user'] ?? null;
        if ($payload['can_edit'] ?? false) {
            $event->addCreate($this->path('mileage_place_create', ['user' => $user]));
            if ($payload['dawarich'] ?? false) {
                $event->addAction('download', [
                    'url' => '#',
                    'title' => 'mileage.place.import',
                    'attr' => [
                        'data-kpu-post' => $this->path('mileage_place_import', ['user' => $user]),
                        'data-kpu-token' => $this->token('mileage_place_import'),
                    ],
                ]);
            }
        }
        $event->addAction('mileage_suggestions', ['url' => $this->path('mileage_suggestions', ['user' => $user]), 'title' => 'mileage.suggestion.list']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function vehicles(PageActionsEvent $event, array $payload): void
    {
        if ($payload['can_edit'] ?? false) {
            $event->addCreate($this->path('mileage_vehicle_create', ['user' => $payload['user'] ?? null]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logbook(PageActionsEvent $event, array $payload): void
    {
        $vehicle = $payload['vehicle'] ?? null;
        $year = (int) ($payload['year'] ?? date('Y'));
        $this->back($event, $payload);
        if ($vehicle === null) {
            return;
        }
        $event->addQuickExport($this->path('mileage_logbook', ['id' => $vehicle->getId(), 'year' => $year, 'format' => 'csv']));
        $event->addAction('print', [
            'url' => $this->path('mileage_logbook', ['id' => $vehicle->getId(), 'year' => $year, 'print' => 1]),
            'title' => 'mileage.logbook.print',
            'target' => '_blank',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rentals(PageActionsEvent $event, array $payload): void
    {
        if ($payload['can_edit'] ?? false) {
            $event->addCreate($this->path('mileage_rental_create', ['user' => $payload['user'] ?? null]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rental(PageActionsEvent $event, array $payload): void
    {
        $rental = $payload['rental'] ?? null;
        $this->back($event, $payload);
        if ($rental === null) {
            return;
        }
        if ($payload['can_edit'] ?? false) {
            $event->addEdit($this->path('mileage_rental_edit', ['id' => $rental->getId()]));
        }
        if ($payload['can_delete'] ?? false) {
            $event->addDelete($this->path('mileage_rental_delete', ['id' => $rental->getId()]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function months(PageActionsEvent $event, array $payload): void
    {
        $user = $payload['user'] ?? null;
        $event->addAction('audit', ['url' => $this->path('mileage_history', ['user' => $user]), 'title' => 'mileage.logbook.history']);
        if ($payload['team'] ?? false) {
            $event->addAction('team', ['url' => $this->path('mileage_team'), 'title' => 'mileage.approval.team']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function history(PageActionsEvent $event, array $payload): void
    {
        $this->back($event, $payload);
        if ($payload['trip'] ?? null) {
            $event->addAction('list', ['url' => $this->path('mileage_history', ['user' => $payload['user'] ?? null]), 'title' => 'mileage.logbook.history_all']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function overview(PageActionsEvent $event, array $payload): void
    {
        if (isset($payload['export'])) {
            $event->addQuickExport($payload['export']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function team(PageActionsEvent $event, array $payload): void
    {
        $event->addAction('locked', ['url' => $this->path('mileage_months'), 'title' => 'mileage.logbook.months']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tax(PageActionsEvent $event, array $payload): void
    {
        $user = $payload['user'] ?? null;
        $year = (int) ($payload['year'] ?? date('Y'));
        $profile = (string) ($payload['profile'] ?? '');

        $children = [];
        foreach ($payload['profiles'] ?? [] as $value => $label) {
            $children['mileage_profile_' . $value] = [
                'url' => $this->path('mileage_tax_report', ['year' => $year, 'user' => $user, 'profile' => $value]),
                'title' => $label,
                'class' => $value === $profile ? 'active' : '',
                'attr' => $value === $profile ? ['aria-current' => 'true'] : [],
            ];
        }
        if ($children !== []) {
            $event->addAction('user', ['title' => 'mileage.tax.profile', 'children' => $children]);
        }
        $event->addAction('list', [
            'url' => $this->path('mileage_trips', ['year' => $year, 'user' => $user]),
            'title' => 'mileage.menu',
        ]);
        $event->addQuickExport($this->path('mileage_export', ['year' => $year, 'user' => $user]));
        $event->addAction('pdf', [
            'url' => $this->path('mileage_tax_report', ['year' => $year, 'user' => $user, 'profile' => $profile, 'format' => 'pdf']),
            'title' => 'mileage.tax.pdf',
        ]);
        $event->addAction('print', [
            'url' => $this->path('mileage_tax_report', ['year' => $year, 'user' => $user, 'profile' => $profile, 'format' => 'print']),
            'title' => 'mileage.logbook.print',
            'target' => '_blank',
        ]);
    }

    /**
     * Pages of a single form or confirmation: back and (optional) delete.
     *
     * @param array<string, mixed> $payload
     */
    private function form(PageActionsEvent $event, array $payload): void
    {
        $this->back($event, $payload);
        if (isset($payload['delete'])) {
            $event->addDelete($payload['delete']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function back(PageActionsEvent $event, array $payload): void
    {
        if (isset($payload['back'])) {
            $event->addAction('back', ['url' => $payload['back'], 'title' => 'back']);
        }
    }

    private function token(string $id): string
    {
        return $this->csrfTokenManager->getToken($id)->getValue();
    }
}
