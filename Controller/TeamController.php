<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\UserRepository;
use KimaiPlugin\MileageBundle\Entity\MonthLock;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
use KimaiPlugin\MileageBundle\Form\RejectMonthForm;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use KimaiPlugin\MileageBundle\Service\TeamService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Team report per month and the approval of handed-in months by team leads: approve runs immediately with an undo
 * toast (GUIDELINES 3.5), reject needs a reason and opens in Kimai's modal.
 */
#[Route(path: '/mileage/team')]
#[IsGranted('mileage')]
class TeamController extends AbstractController
{
    use MileageUiTrait;

    private const CSRF = 'mileage_team';

    public function __construct(
        private readonly TeamService $teamService,
        private readonly TripRepository $tripRepository,
        private readonly MonthLockRepository $lockRepository,
        private readonly MonthLockService $lockService,
        private readonly TaxCalculator $taxCalculator,
        private readonly MileageConfiguration $configuration,
        private readonly TranslatorInterface $translator,
        private readonly MileagePages $pages,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route(path: '/{year}/{month}', name: 'mileage_team', defaults: ['year' => null, 'month' => null], requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET'])]
    public function index(?int $year = null, ?int $month = null): Response
    {
        if (!$this->teamService->canSeeTeam()) {
            throw $this->createAccessDeniedException();
        }
        $year ??= (int) date('Y');
        $month ??= (int) date('n');
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException();
        }

        /** @var User $current */
        $current = $this->getUser();
        $users = $this->teamService->visibleUsers($current);
        $from = new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month));
        $to = $from->modify('last day of this month');

        $rows = [];
        $totals = ['submitted' => 0, 'approved' => 0, 'business' => 0.0, 'amount' => 0.0];
        foreach ($users as $user) {
            $trips = $this->tripRepository->findByUserBetween($user, $from, $to);
            $km = ['business' => 0.0, 'commute' => 0.0, 'private' => 0.0];
            foreach ($trips as $trip) {
                $km[$trip->getPurpose()->value] += $trip->getTotalDistanceKm();
            }
            $summary = $trips !== [] ? $this->taxCalculator->summarize($trips, $year, $this->configuration->getTaxProfile($user), $user->getDateTimezone()) : null;
            $lock = $this->lockRepository->findLock($user, $year, $month);
            $canApprove = $lock !== null && $lock->getStatus() === MonthStatus::SUBMITTED && $this->teamService->canApprove($current, $user);

            $rows[] = [
                'user' => $user,
                'trips' => \count($trips),
                'km' => $km,
                'total' => $summary['total'] ?? 0.0,
                'lock' => $lock,
                'can_approve' => $canApprove,
            ];
            $totals['submitted'] += $lock?->getStatus() === MonthStatus::SUBMITTED ? 1 : 0;
            $totals['approved'] += $lock?->getStatus() === MonthStatus::APPROVED ? 1 : 0;
            $totals['business'] += $km['business'];
            $totals['amount'] += (float) ($summary['total'] ?? 0.0);
        }

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $prev = $month === 1 ? ['year' => $year - 1, 'month' => 12] : ['year' => $year, 'month' => $month - 1];
        $next = $month === 12 ? ['year' => $year + 1, 'month' => 1] : ['year' => $year, 'month' => $month + 1];

        return $this->render('@Mileage/team/index.html.twig', [
            'page_setup' => $this->pages->create('mileage_team', 'mileage.approval.team', $this->pages->monthLabel($year, $month)),
            'target_user' => $current,
            'year' => $year,
            'month' => $month,
            'rows' => $rows,
            'totals' => $totals,
            'bulk' => \in_array(true, array_column($rows, 'can_approve'), true),
            'approval_enabled' => $this->configuration->isApprovalEnabled(),
            'period' => [
                'label' => $this->pages->monthLabel($year, $month),
                'prev' => $this->generateUrl('mileage_team', $prev),
                'next' => $this->generateUrl('mileage_team', $next),
                'today' => $year === $currentYear && $month === $currentMonth ? null : $this->generateUrl('mileage_team', ['year' => $currentYear, 'month' => $currentMonth]),
            ],
        ]);
    }

    /**
     * Approves the selected handed-in months (ids = month lock ids). Every one must be approvable by the current
     * user, checked for all before the first one is changed.
     */
    #[Route(path: '/approve', name: 'mileage_team_approve', methods: ['POST'])]
    public function approve(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF);
        /** @var User $current */
        $current = $this->getUser();
        $locks = $this->lockRepository->findBy(['id' => $this->selectedIds($request)]);
        foreach ($locks as $lock) {
            if (!$this->teamService->canApprove($current, $lock->getUser())) {
                throw $this->createAccessDeniedException();
            }
        }

        $done = [];
        foreach ($locks as $lock) {
            if ($lock->getStatus() !== MonthStatus::SUBMITTED) {
                continue;
            }
            $before = MonthLockService::snapshot($lock);
            $this->lockService->review($lock, true, $current);
            $done[(int) $lock->getId()] = ['before' => $before, 'reviewed_at' => $lock->getReviewedAt()?->format(\DateTimeInterface::ATOM)];
        }

        $list = $this->listParameters($locks[0] ?? null);
        if ($done === []) {
            return $this->actionResult($request, $this->translator->trans('mileage.approval.none'), null, 'mileage_team', $list, 422);
        }

        $message = \count($done) === 1 && ($lock = $this->lockRepository->find(array_key_first($done))) !== null
            ? $this->translator->trans('mileage.approval.approved', ['%user%' => $lock->getUser()->getDisplayName(), '%month%' => $this->pages->monthLabel($lock->getYear(), $lock->getMonth())])
            : $this->translator->trans('mileage.approval.approved_count', ['%count%' => \count($done)]);
        $undo = [
            'url' => $this->generateUrl('mileage_team_undo', ['action' => $this->rememberUndo($request, 'team.approve', $done)]),
            'token' => $this->csrfToken(self::CSRF),
            'ids' => array_keys($done),
        ];

        return $this->actionResult($request, $message, $undo, 'mileage_team', $list);
    }

    /**
     * Undo of the own approval (GUIDELINES 3.5): same user and session, 15 minutes, only the months of the action and
     * only if they were not reviewed again since. The approval permission is checked again.
     */
    #[Route(path: '/approve/undo/{action}', name: 'mileage_team_undo', requirements: ['action' => '[a-f0-9]{16}'], methods: ['POST'])]
    public function undoApprove(Request $request, string $action): Response
    {
        $this->assertCsrf($request, self::CSRF);
        $entries = $this->takeUndo($request, 'team.approve', $action);
        if ($entries === null) {
            return $this->actionResult($request, $this->translator->trans('mileage.undo.expired'), null, 'mileage_team', [], 409);
        }
        if (array_diff(array_map('intval', $request->request->all('ids')), array_keys($entries)) !== []) {
            throw $this->createAccessDeniedException('Undo is limited to the months of the action');
        }

        /** @var User $current */
        $current = $this->getUser();
        $done = 0;
        $changed = 0;
        $first = null;
        foreach ($entries as $id => $entry) {
            $lock = $this->lockRepository->find((int) $id);
            if ($lock === null) {
                ++$changed;
                continue;
            }
            if (!$this->teamService->canApprove($current, $lock->getUser())) {
                throw $this->createAccessDeniedException();
            }
            $first ??= $lock;
            if ($lock->getStatus() !== MonthStatus::APPROVED
                || $lock->getReviewedBy() !== $current
                || $lock->getReviewedAt()?->format(\DateTimeInterface::ATOM) !== $entry['reviewed_at']) {
                ++$changed;
                continue;
            }
            $this->lockService->restore($lock->getUser(), $lock->getYear(), $lock->getMonth(), $lock, $entry['before'], fn (int $userId): ?User => $this->userRepository->find($userId));
            ++$done;
        }

        $list = $this->listParameters($first);
        if ($done === 0) {
            return $this->actionResult($request, $this->translator->trans('mileage.undo.changed'), null, 'mileage_team', $list, 409);
        }
        $message = $this->translator->trans('mileage.approval.reopened', ['%count%' => $done]);
        if ($changed > 0) {
            $message .= ' ' . $this->translator->trans('mileage.undo.skipped', ['%count%' => $changed]);
        }

        return $this->actionResult($request, $message, null, 'mileage_team', $list);
    }

    /**
     * Rejects a handed-in month with a reason (Kimai modal).
     */
    #[Route(path: '/reject/{id}', name: 'mileage_team_reject', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function reject(Request $request, MonthLock $lock): Response
    {
        /** @var User $current */
        $current = $this->getUser();
        if (!$this->teamService->canApprove($current, $lock->getUser())) {
            throw $this->createAccessDeniedException();
        }
        $list = $this->listParameters($lock);
        if ($lock->getStatus() !== MonthStatus::SUBMITTED) {
            return $this->kpuFormSuccess($request, 'mileage_team', $list);
        }

        $form = $this->createForm(RejectMonthForm::class, null, [
            'action' => $this->generateUrl('mileage_team_reject', ['id' => $lock->getId()]),
            'csrf_token_id' => 'mileage_team_review' . $lock->getId(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment = trim((string) $form->get('comment')->getData());
            $this->lockService->review($lock, false, $current, mb_substr($comment, 0, 2000));
            $this->addFlash('kpu_result', $this->translator->trans('mileage.approval.rejected', [
                '%user%' => $lock->getUser()->getDisplayName(),
                '%month%' => $this->pages->monthLabel($lock->getYear(), $lock->getMonth()),
            ]));

            return $this->kpuFormSuccess($request, 'mileage_team', $list);
        }

        $title = $this->translator->trans('mileage.approval.reject_title', [
            '%user%' => $lock->getUser()->getDisplayName(),
            '%month%' => $this->pages->monthLabel($lock->getYear(), $lock->getMonth()),
        ]);
        $back = $this->generateUrl('mileage_team', $list);

        return $this->render('@Mileage/_form.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.approval.team', $title, ['back' => $back]),
            'form' => $form->createView(),
            'title' => $title,
            'submit' => 'mileage.approval.reject',
            'back' => $back,
        ]);
    }

    /**
     * Former form of the pending list (approve with optional comment / reject with reason), kept for existing links.
     */
    #[Route(path: '/review/{id}', name: 'mileage_team_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(Request $request, MonthLock $lock): Response
    {
        /** @var User $current */
        $current = $this->getUser();
        if (!$this->teamService->canApprove($current, $lock->getUser())) {
            throw $this->createAccessDeniedException();
        }
        $this->assertCsrf($request, 'mileage_team_review' . $lock->getId());
        $list = $this->listParameters($lock);
        if ($lock->getStatus() !== MonthStatus::SUBMITTED) {
            return $this->redirectToRoute('mileage_team', $list);
        }

        $approve = $request->request->get('decision') === 'approve';
        $comment = trim((string) $request->request->get('comment'));
        if (!$approve && $comment === '') {
            $this->flashError($this->translator->trans('mileage.approval.error.reason'));

            return $this->redirectToRoute('mileage_team', $list);
        }

        $this->lockService->review($lock, $approve, $current, $comment !== '' ? mb_substr($comment, 0, 2000) : null);
        $this->addFlash('kpu_result', $this->translator->trans($approve ? 'mileage.approval.approved' : 'mileage.approval.rejected', [
            '%user%' => $lock->getUser()->getDisplayName(),
            '%month%' => $this->pages->monthLabel($lock->getYear(), $lock->getMonth()),
        ]));

        return $this->redirectToRoute('mileage_team', $list);
    }

    /**
     * @return array{year?: int, month?: int}
     */
    private function listParameters(?MonthLock $lock): array
    {
        return $lock === null ? [] : ['year' => $lock->getYear(), 'month' => $lock->getMonth()];
    }
}
