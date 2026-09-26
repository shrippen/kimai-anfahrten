<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\Query\BaseQuery;
use App\Repository\UserRepository;
use App\Utils\DataTable;
use App\Utils\Pagination;
use KimaiPlugin\MileageBundle\Entity\TripSuggestion;
use KimaiPlugin\MileageBundle\Enum\SuggestionStatus;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Form\SuggestionDetectForm;
use KimaiPlugin\MileageBundle\Repository\AttachmentRepository;
use KimaiPlugin\MileageBundle\Repository\TripAuditRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use Pagerfanta\Adapter\ArrayAdapter;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review trips detected in the Dawarich history: accept (creates a trip) or dismiss. Both are reversible and run
 * immediately with an undo toast (kimai-plugin-ui GUIDELINES 3.5).
 */
#[Route(path: '/mileage/suggestions')]
#[IsGranted('mileage')]
class SuggestionController extends AbstractController
{
    use TargetUserTrait;
    use MileageUiTrait;

    private const CSRF = 'mileage_suggestions';

    public function __construct(
        private readonly TripSuggestionRepository $suggestionRepository,
        private readonly UserRepository $userRepository,
        private readonly SuggestionService $suggestionService,
        private readonly MileageConfiguration $configuration,
        private readonly TranslatorInterface $translator,
        private readonly MonthLockService $lockService,
        private readonly TripRepository $tripRepository,
        private readonly TripAuditRepository $auditRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly MileagePages $pages,
    ) {
    }

    #[Route(path: '', name: 'mileage_suggestions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $userParam = $user === $this->getUser() ? null : $user->getId();
        $suggestions = $this->suggestionRepository->findOpen($user);
        $canEdit = $this->canEditTripsOf($user);
        $dawarich = $this->configuration->isDawarichConfigured($user);

        $locked = [];
        foreach ($suggestions as $suggestion) {
            $locked[(int) $suggestion->getId()] = $this->isLockedFor($user, $suggestion);
        }
        $bulk = $canEdit && \in_array(false, $locked, true);

        $table = new DataTable('mileage_suggestions', new BaseQuery());
        $table->setPagination(new Pagination(new ArrayAdapter($suggestions)));
        $table->setSticky(false);
        if ($bulk) {
            $table->addColumn('select', [
                'class' => 'alwaysVisible multiCheckbox w-min',
                'orderBy' => false,
                'title' => false,
                'html_after' => \sprintf(
                    '<input type="checkbox" class="form-check-input m-0 align-middle kpu-select-all" data-kpu-form="mileage-suggestions-bulk" aria-label="%1$s" title="%1$s">',
                    htmlspecialchars($this->translator->trans('kpu.bulk.select_all', [], 'kpu'))
                ),
            ]);
        }
        $table->addColumn('date', ['class' => 'alwaysVisible w-min', 'orderBy' => false, 'title' => 'mileage.trip.date']);
        $table->addColumn('route', ['class' => 'alwaysVisible', 'orderBy' => false, 'title' => 'mileage.trip.route']);
        $table->addColumn('distance', ['class' => 'text-end w-min text-nowrap', 'orderBy' => false, 'title' => 'mileage.trip.distance_short']);
        $table->addColumn('purpose', ['class' => 'd-none d-md-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.purpose']);
        $table->addColumn('project', ['class' => 'd-none d-lg-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.project']);
        $table->addColumn('status', ['class' => 'd-none d-sm-table-cell w-min', 'orderBy' => false, 'title' => 'status']);
        $table->addColumn('actions', ['class' => 'actions alwaysVisible']);

        $page = $this->pages->create('mileage_suggestions', 'mileage.suggestion.list', null, [
            'user' => $userParam,
            'own' => $user === $this->getUser(),
            'can_edit' => $canEdit,
            'dawarich' => $dawarich,
        ]);
        $page->setDataTable($table);

        return $this->render('@Mileage/suggestion/index.html.twig', [
            'page_setup' => $page,
            'dataTable' => $table,
            'target_user' => $user,
            'user_param' => $userParam,
            'can_edit' => $canEdit,
            'bulk' => $bulk,
            'locked' => $locked,
            'dawarich_configured' => $dawarich,
            'default_vehicle' => $this->configuration->getDefaultVehicle($user),
        ]);
    }

    /**
     * "Detect trips" in Kimai's modal: period with Kimai date pickers, result count as callout.
     */
    #[Route(path: '/detect', name: 'mileage_suggestions_detect', methods: ['GET', 'POST'])]
    public function detect(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
        $userParam = $user === $this->getUser() ? null : $user->getId();

        $form = $this->createForm(SuggestionDetectForm::class, ['from' => new \DateTime('-7 days'), 'to' => new \DateTime('today')], [
            'action' => $this->generateUrl('mileage_suggestions_detect', ['user' => $userParam]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{from: \DateTimeInterface, to: \DateTimeInterface} $data */
            $data = $form->getData();
            $from = \DateTimeImmutable::createFromInterface($data['from'])->setTime(0, 0);
            $to = \DateTimeImmutable::createFromInterface($data['to'])->setTime(0, 0);

            if ($to < $from || $from->diff($to)->days > 92) {
                $form->get('to')->addError(new FormError($this->translator->trans('mileage.suggestion.error.range')));
            } else {
                try {
                    $count = $this->suggestionService->detect($user, $from, $to);
                    $this->addFlash('kpu_result', $this->translator->trans('mileage.suggestion.detected', ['%count%' => $count]));

                    return $this->kpuFormSuccess($request, 'mileage_suggestions', ['user' => $userParam]);
                } catch (DawarichException $e) {
                    $form->addError(new FormError($this->translator->trans($e->getMessage(), $e->getParameters())));
                }
            }
        }

        $back = $this->generateUrl('mileage_suggestions', ['user' => $userParam]);

        return $this->render('@Mileage/_form.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.suggestion.list', $this->translator->trans('mileage.suggestion.detect'), ['back' => $back]),
            'form' => $form->createView(),
            'title' => $this->translator->trans('mileage.suggestion.detect'),
            'intro' => $this->translator->trans('mileage.suggestion.detect_help'),
            'submit' => 'mileage.suggestion.detect',
            'back' => $back,
            'target_user' => $user,
        ]);
    }

    /**
     * Accepts the selected suggestions (kit bulk bar or row "…"): optional "purpose" for all of them, "edit" opens
     * the created trip (single suggestion). Reversible via mileage_suggestions_undo.
     */
    #[Route(path: '/accept', name: 'mileage_suggestions_accept', methods: ['POST'])]
    public function bulkAccept(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF);
        $suggestions = $this->loadSelected($request);
        $purpose = TripPurpose::tryFrom((string) $request->request->get('purpose'));

        $done = [];
        $skipped = 0;
        $locked = 0;
        $lastTrip = null;
        foreach ($suggestions as $suggestion) {
            /** @var User $user */
            $user = $suggestion->getUser();
            if ($suggestion->getStatus() !== SuggestionStatus::OPEN) {
                ++$skipped;
                continue;
            }
            if ($this->isLockedFor($user, $suggestion)) {
                ++$locked;
                continue;
            }
            $vehicle = $suggestion->getVehicle() ?? $this->configuration->getDefaultVehicle($user);
            $result = $this->suggestionService->acceptTracked($suggestion, $purpose ?? $suggestion->getPurpose(), $vehicle);
            $lastTrip = $result['trip'];
            $done[] = ['suggestion' => (int) $suggestion->getId(), 'trip' => (int) $result['trip']->getId(), 'created' => $result['created']];
        }

        $list = $this->listParameters($suggestions[0] ?? null);
        if ($done === []) {
            return $this->actionResult($request, $this->translator->trans($locked > 0 ? 'mileage.logbook.error.locked' : 'mileage.suggestion.none'), null, 'mileage_suggestions', $list, 422);
        }

        if ($request->request->getBoolean('edit') && \count($done) === 1 && $lastTrip?->getId() !== null) {
            // the trip form is a page: kit.js follows Kimai's 201 + x-modal-redirect
            return $this->redirectToRouteAfterCreate('mileage_trip_edit', ['id' => $lastTrip->getId()]);
        }

        $message = $this->translator->trans('mileage.suggestion.accepted_all', ['%count%' => \count($done)]);
        $message .= $this->skippedMessage($skipped, $locked);
        $undo = $this->undo($request, 'accept', $done);

        return $this->actionResult($request, $message, $undo, 'mileage_suggestions', $list);
    }

    #[Route(path: '/dismiss', name: 'mileage_suggestions_dismiss', methods: ['POST'])]
    public function bulkDismiss(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF);
        $suggestions = $this->loadSelected($request);

        $done = [];
        foreach ($suggestions as $suggestion) {
            if ($suggestion->getStatus() === SuggestionStatus::OPEN) {
                $this->suggestionService->dismiss($suggestion);
                $done[] = ['suggestion' => (int) $suggestion->getId()];
            }
        }

        $list = $this->listParameters($suggestions[0] ?? null);
        if ($done === []) {
            return $this->actionResult($request, $this->translator->trans('mileage.suggestion.none'), null, 'mileage_suggestions', $list, 422);
        }

        $message = $this->translator->trans('mileage.suggestion.dismissed', ['%count%' => \count($done)]);

        return $this->actionResult($request, $message, $this->undo($request, 'dismiss', $done), 'mileage_suggestions', $list);
    }

    /**
     * Undo of the current user's own accept/dismiss (GUIDELINES 3.5 "Rückgängig-Fenster"): only the action stored in
     * this session, same user, within 15 minutes, only its suggestions and only if nothing changed since (the created
     * trip was not edited, got no receipt and its month was not closed). Edit rights are checked again.
     */
    #[Route(path: '/undo/{type}/{action}', name: 'mileage_suggestions_undo', requirements: ['type' => 'accept|dismiss', 'action' => '[a-f0-9]{16}'], methods: ['POST'])]
    public function undoAction(Request $request, string $type, string $action): Response
    {
        $this->assertCsrf($request, self::CSRF);
        $entries = $this->takeUndo($request, 'suggestions.' . $type, $action);
        if ($entries === null) {
            return $this->actionResult($request, $this->translator->trans('mileage.undo.expired'), null, 'mileage_suggestions', [], 409);
        }

        /** @var list<array{suggestion: int, trip?: int, created?: bool}> $entries */
        $requested = array_map('intval', $request->request->all('ids'));
        $known = array_column($entries, 'suggestion');
        if (array_diff($requested, $known) !== []) {
            throw $this->createAccessDeniedException('Undo is limited to the suggestions of the action');
        }

        $done = 0;
        $changed = 0;
        $first = null;
        foreach ($entries as $entry) {
            $suggestion = $this->suggestionRepository->find($entry['suggestion']);
            if (!$suggestion instanceof TripSuggestion || !$this->canEditTripsOf($suggestion->getUser())) {
                ++$changed;
                continue;
            }
            $first ??= $suggestion;
            if ($type === 'dismiss') {
                if ($suggestion->getStatus() !== SuggestionStatus::DISMISSED) {
                    ++$changed;
                    continue;
                }
                $this->suggestionService->reopen($suggestion);
                ++$done;
                continue;
            }

            $trip = $suggestion->getTrip();
            if ($suggestion->getStatus() !== SuggestionStatus::ACCEPTED || $trip?->getId() !== ($entry['trip'] ?? null)) {
                ++$changed;
                continue;
            }
            if ($entry['created'] ?? false) {
                // created by the action: remove it again, but only untouched (one audit entry = creation)
                if (\count($this->auditRepository->findByTrip((int) $trip->getId())) > 1
                    || $this->attachmentRepository->findByTrip($trip) !== []
                    || ($this->lockService->isTripLocked($trip) && !$this->isGranted('edit_locked_mileage'))) {
                    ++$changed;
                    continue;
                }
                $this->suggestionService->reopen($suggestion);
                $this->tripRepository->remove($trip);
            } else {
                $this->suggestionService->reopen($suggestion);
            }
            ++$done;
        }

        $list = $this->listParameters($first);
        if ($done === 0) {
            return $this->actionResult($request, $this->translator->trans('mileage.undo.changed'), null, 'mileage_suggestions', $list, 409);
        }

        $message = $this->translator->trans('mileage.suggestion.reopened', ['%count%' => $done]);
        if ($changed > 0) {
            $message .= ' ' . $this->translator->trans('mileage.undo.skipped', ['%count%' => $changed]);
        }

        return $this->actionResult($request, $message, null, 'mileage_suggestions', $list);
    }

    /** @deprecated single form of mileage_suggestions_accept (former per-row form), kept for existing links */
    #[Route(path: '/{id}/accept', name: 'mileage_suggestion_accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(Request $request, TripSuggestion $suggestion): Response
    {
        /** @var User $user */
        $user = $suggestion->getUser();
        $this->assertEditable($user, $request, 'mileage_suggestion' . $suggestion->getId());

        if ($suggestion->getStatus() === SuggestionStatus::OPEN && $this->isLockedFor($user, $suggestion)) {
            $this->flashError($this->translator->trans('mileage.logbook.error.locked'));
        } elseif ($suggestion->getStatus() === SuggestionStatus::OPEN) {
            $purpose = TripPurpose::tryFrom((string) $request->request->get('purpose')) ?? $suggestion->getPurpose();
            $vehicle = VehicleType::tryFrom((string) $request->request->get('vehicle')) ?? $suggestion->getVehicle() ?? $this->configuration->getDefaultVehicle($user);
            $trip = $this->suggestionService->accept($suggestion, $purpose, $vehicle);

            if ($request->request->getBoolean('edit') && $trip->getId() !== null) {
                return $this->redirectToRoute('mileage_trip_edit', ['id' => $trip->getId()]);
            }
            $this->flashSuccess('action.update.success');
        }

        return $this->redirectToRoute('mileage_suggestions', $this->listParameters($suggestion));
    }

    /** @deprecated single form of mileage_suggestions_dismiss, kept for existing links */
    #[Route(path: '/{id}/dismiss', name: 'mileage_suggestion_dismiss', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dismiss(Request $request, TripSuggestion $suggestion): Response
    {
        /** @var User $user */
        $user = $suggestion->getUser();
        $this->assertEditable($user, $request, 'mileage_suggestion' . $suggestion->getId());

        if ($suggestion->getStatus() === SuggestionStatus::OPEN) {
            $this->suggestionService->dismiss($suggestion);
        }

        return $this->redirectToRoute('mileage_suggestions', $this->listParameters($suggestion));
    }

    /** @deprecated replaced by the selection + "Accept" of the list, kept for existing links */
    #[Route(path: '/accept-all', name: 'mileage_suggestions_accept_all', methods: ['POST'])]
    public function acceptAll(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $this->assertEditable($user, $request, 'mileage_suggestions_accept_all');

        $vehicle = $this->configuration->getDefaultVehicle($user);
        $count = 0;
        $locked = 0;
        foreach ($this->suggestionRepository->findOpen($user) as $suggestion) {
            // Private trips are not needed for the tax return — only take the relevant ones.
            if ($suggestion->getPurpose() === TripPurpose::PRIVATE) {
                continue;
            }
            if ($this->isLockedFor($user, $suggestion)) {
                $locked++;
                continue;
            }
            $this->suggestionService->accept($suggestion, $suggestion->getPurpose(), $suggestion->getVehicle() ?? $vehicle);
            $count++;
        }

        $this->addFlash('kpu_result', $this->translator->trans('mileage.suggestion.accepted_all', ['%count%' => $count]));
        if ($locked > 0) {
            $this->flashWarning($this->translator->trans('mileage.logbook.error.locked'));
        }

        return $this->redirectToRoute('mileage_suggestions', ['user' => $user === $this->getUser() ? null : $user->getId()]);
    }

    /**
     * @param list<array<string, mixed>> $done
     * @return array{url: string, token: string, ids: list<int>}
     */
    private function undo(Request $request, string $type, array $done): array
    {
        return [
            'url' => $this->generateUrl('mileage_suggestions_undo', ['type' => $type, 'action' => $this->rememberUndo($request, 'suggestions.' . $type, $done)]),
            'token' => $this->csrfToken(self::CSRF),
            'ids' => array_column($done, 'suggestion'),
        ];
    }

    private function skippedMessage(int $skipped, int $locked): string
    {
        $message = '';
        if ($locked > 0) {
            $message .= ' ' . $this->translator->trans('mileage.suggestion.skipped_locked', ['%count%' => $locked]);
        }
        if ($skipped > 0) {
            $message .= ' ' . $this->translator->trans('mileage.suggestion.skipped', ['%count%' => $skipped]);
        }

        return $message;
    }

    /**
     * Selected suggestions; every one must belong to a user whose trips the current user may edit
     * (checked for all before the first one is changed).
     *
     * @return list<TripSuggestion>
     */
    private function loadSelected(Request $request): array
    {
        $ids = $this->selectedIds($request);
        $suggestions = $this->suggestionRepository->findBy(['id' => $ids], ['startAt' => 'ASC']);
        if (\count($suggestions) !== \count($ids)) {
            throw $this->createNotFoundException('Suggestion not found');
        }
        foreach ($suggestions as $suggestion) {
            if (!$this->canEditTripsOf($suggestion->getUser())) {
                throw $this->createAccessDeniedException();
            }
        }

        return array_values($suggestions);
    }

    /**
     * @return array{user: int|null}
     */
    private function listParameters(?TripSuggestion $suggestion): array
    {
        $user = $suggestion?->getUser();

        return ['user' => $user === null || $user === $this->getUser() ? null : $user->getId()];
    }

    private function isLockedFor(User $user, TripSuggestion $suggestion): bool
    {
        return !$this->isGranted('edit_locked_mileage') && $this->lockService->isLocked($user, $suggestion->getDate());
    }

    private function assertEditable(User $user, Request $request, string $csrfId): void
    {
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
        $this->assertCsrf($request, $csrfId);
    }
}
