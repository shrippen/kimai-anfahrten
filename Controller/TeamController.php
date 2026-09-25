<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Entity\MonthLock;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use KimaiPlugin\MileageBundle\Service\TeamService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Team report and month approval for team leads.
 */
#[Route(path: '/mileage/team')]
#[IsGranted('mileage')]
class TeamController extends AbstractController
{
    public function __construct(
        private readonly TeamService $teamService,
        private readonly TripRepository $tripRepository,
        private readonly MonthLockRepository $lockRepository,
        private readonly MonthLockService $lockService,
        private readonly TaxCalculator $taxCalculator,
        private readonly MileageConfiguration $configuration,
        private readonly TranslatorInterface $translator,
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
        foreach ($users as $user) {
            $trips = $this->tripRepository->findByUserBetween($user, $from, $to);
            $km = ['business' => 0.0, 'commute' => 0.0, 'private' => 0.0];
            foreach ($trips as $trip) {
                $km[$trip->getPurpose()->value] += $trip->getTotalDistanceKm();
            }
            $summary = $trips !== [] ? $this->taxCalculator->summarize($trips, $year, $this->configuration->getTaxProfile($user), $user->getDateTimezone()) : null;

            $rows[] = [
                'user' => $user,
                'trips' => \count($trips),
                'km' => $km,
                'total' => $summary['total'] ?? 0.0,
                'lock' => $this->lockRepository->findLock($user, $year, $month),
                'can_approve' => $this->teamService->canApprove($current, $user),
            ];
        }

        $pending = array_filter(
            $this->lockRepository->findSubmitted($users),
            fn (MonthLock $lock) => $this->teamService->canApprove($current, $lock->getUser())
        );

        return $this->render('@Mileage/team/index.html.twig', [
            'page_setup' => new PageSetup('mileage.approval.team'),
            'target_user' => $current,
            'year' => $year,
            'month' => $month,
            'rows' => $rows,
            'pending' => $pending,
            'approval_enabled' => $this->configuration->isApprovalEnabled(),
        ]);
    }

    #[Route(path: '/review/{id}', name: 'mileage_team_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(Request $request, MonthLock $lock): Response
    {
        /** @var User $current */
        $current = $this->getUser();
        if (!$this->teamService->canApprove($current, $lock->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_team_review' . $lock->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        if ($lock->getStatus() !== MonthStatus::SUBMITTED) {
            return $this->redirectToRoute('mileage_team', ['year' => $lock->getYear(), 'month' => $lock->getMonth()]);
        }

        $approve = $request->request->get('decision') === 'approve';
        $comment = trim((string) $request->request->get('comment'));
        if (!$approve && $comment === '') {
            $this->flashError($this->translator->trans('mileage.approval.error.reason'));

            return $this->redirectToRoute('mileage_team', ['year' => $lock->getYear(), 'month' => $lock->getMonth()]);
        }

        $this->lockService->review($lock, $approve, $current, $comment !== '' ? mb_substr($comment, 0, 2000) : null);
        $this->flashSuccess($this->translator->trans($approve ? 'mileage.approval.approved' : 'mileage.approval.rejected', [
            '%user%' => $lock->getUser()->getDisplayName(),
            '%month%' => \sprintf('%02d/%d', $lock->getMonth(), $lock->getYear()),
        ]));

        return $this->redirectToRoute('mileage_team', ['year' => $lock->getYear(), 'month' => $lock->getMonth()]);
    }
}
