<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\UserRepository;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\TripAuditRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\CsvSafe;
use KimaiPlugin\MileageBundle\Service\LogbookService;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\TeamService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fahrtenbuch per vehicle, month closing and change history.
 */
#[Route(path: '/mileage')]
#[IsGranted('mileage')]
class LogbookController extends AbstractController
{
    use TargetUserTrait;
    use MileageUiTrait;

    public function __construct(
        private readonly TripRepository $tripRepository,
        private readonly UserRepository $userRepository,
        private readonly MonthLockRepository $lockRepository,
        private readonly TripAuditRepository $auditRepository,
        private readonly LogbookService $logbookService,
        private readonly MonthLockService $lockService,
        private readonly TranslatorInterface $translator,
        private readonly MileageConfiguration $configuration,
        private readonly MileagePages $pages,
        private readonly TeamService $teamService,
    ) {
    }

    #[Route(path: '/logbook/{id}/{year}', name: 'mileage_logbook', defaults: ['year' => null], requirements: ['id' => '\d+', 'year' => '\d{4}'], methods: ['GET'])]
    public function logbook(Request $request, Vehicle $vehicle, ?int $year = null): Response
    {
        $year ??= (int) date('Y');
        /** @var User $user */
        $user = $vehicle->getUser();
        $this->assertCanView($user);

        $all = $this->tripRepository->findByVehicle($vehicle);
        $inYear = array_values(array_filter($all, static fn ($t) => (int) $t->getDate()?->format('Y') === $year));
        $startOdometer = $this->logbookService->suggestOdometerStart($vehicle, $all, new \DateTimeImmutable(\sprintf('%d-12-31', $year - 1)));
        $analysis = $this->logbookService->analyse($vehicle, $inYear, $startOdometer);

        if ($request->query->get('format') === 'csv') {
            return $this->csv($vehicle, $year, $analysis['rows']);
        }

        $userParam = $user === $this->getUser() ? null : $user->getId();
        $currentYear = (int) date('Y');

        return $this->render('@Mileage/logbook/index.html.twig', [
            'page_setup' => $this->pages->create('mileage_logbook', 'mileage.logbook.title', (string) $year, [
                'vehicle' => $vehicle,
                'year' => $year,
                'back' => $this->generateUrl('mileage_vehicles', ['user' => $userParam]),
            ]),
            'vehicle' => $vehicle,
            'target_user' => $user,
            'year' => $year,
            'analysis' => $analysis,
            'locks' => $this->lockRepository->findByUserAndYear($user, $year),
            'print' => $request->query->getBoolean('print'),
            'can_edit' => $this->canEditTripsOf($user),
            'edit_locked' => $this->isGranted('edit_locked_mileage'),
            'period' => [
                'prev' => $this->generateUrl('mileage_logbook', ['id' => $vehicle->getId(), 'year' => $year - 1]),
                'next' => $this->generateUrl('mileage_logbook', ['id' => $vehicle->getId(), 'year' => $year + 1]),
                'today' => $year === $currentYear ? null : $this->generateUrl('mileage_logbook', ['id' => $vehicle->getId(), 'year' => $currentYear]),
            ],
        ]);
    }

    #[Route(path: '/months/{year}', name: 'mileage_months', defaults: ['year' => null], requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function months(Request $request, ?int $year = null): Response
    {
        $year ??= (int) date('Y');
        $user = $this->getTargetUser($request, $this->userRepository);
        $userParam = $user === $this->getUser() ? null : $user->getId();

        $counts = array_fill(1, 12, 0);
        foreach ($this->tripRepository->findByUserAndYear($user, $year) as $trip) {
            $counts[(int) $trip->getDate()?->format('n')]++;
        }
        $currentYear = (int) date('Y');

        return $this->render('@Mileage/logbook/months.html.twig', [
            'page_setup' => $this->pages->create('mileage_months', 'mileage.logbook.months', (string) $year, [
                'user' => $userParam,
                'team' => $this->teamService->canSeeTeam(),
            ]),
            'target_user' => $user,
            'user_param' => $userParam,
            'year' => $year,
            'locks' => $this->lockRepository->findByUserAndYear($user, $year),
            'counts' => $counts,
            'can_lock' => $this->canLock($user),
            'can_unlock' => $this->isGranted('unlock_mileage'),
            'approval_enabled' => $this->configuration->isApprovalEnabled(),
            'period' => [
                'prev' => $this->generateUrl('mileage_months', ['year' => $year - 1, 'user' => $userParam]),
                'next' => $this->generateUrl('mileage_months', ['year' => $year + 1, 'user' => $userParam]),
                'today' => $year === $currentYear ? null : $this->generateUrl('mileage_months', ['year' => $currentYear, 'user' => $userParam]),
            ],
        ]);
    }

    /**
     * Closes a month or hands it in for approval. Final for the user (unlocking needs a special permission), so
     * the row menu asks with Kimai's confirmation first (data-kpu-question).
     */
    #[Route(path: '/months/{year}/{month}/lock', name: 'mileage_month_lock', requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['POST'])]
    public function lock(Request $request, int $year, int $month): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canLock($user) || $month < 1 || $month > 12) {
            throw $this->createAccessDeniedException();
        }
        $this->assertCsrf($request, 'mileage_month_lock');

        /** @var User $current */
        $current = $this->getUser();
        $approval = $this->configuration->isApprovalEnabled();
        $this->lockService->lock($user, $year, $month, $current, $approval ? MonthStatus::SUBMITTED : MonthStatus::CLOSED);
        $message = $this->translator->trans($approval ? 'mileage.approval.submitted' : 'mileage.logbook.locked', ['%month%' => $this->pages->monthLabel($year, $month)]);

        return $this->actionResult($request, $message, null, 'mileage_months', ['year' => $year, 'user' => $user === $current ? null : $user->getId()]);
    }

    /**
     * Opens a month again (reversible: the undo puts back the lock with its approval state).
     */
    #[Route(path: '/months/{year}/{month}/unlock', name: 'mileage_month_unlock', requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['POST'])]
    #[IsGranted('unlock_mileage')]
    public function unlock(Request $request, int $year, int $month): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $this->assertCsrf($request, 'mileage_month_lock');
        $list = ['year' => $year, 'user' => $user === $this->getUser() ? null : $user->getId()];

        $lock = $this->lockRepository->findLock($user, $year, $month);
        $undo = null;
        if ($lock !== null) {
            $action = $this->rememberUndo($request, 'month.unlock', [
                'user' => $user->getId(), 'year' => $year, 'month' => $month, 'lock' => MonthLockService::snapshot($lock),
            ]);
            $undo = [
                'url' => $this->generateUrl('mileage_month_unlock_undo', ['action' => $action]),
                'token' => $this->csrfToken('mileage_month_lock'),
                'ids' => [],
            ];
        }
        $this->lockService->unlock($user, $year, $month);

        return $this->actionResult($request, $this->translator->trans('mileage.logbook.unlocked', ['%month%' => $this->pages->monthLabel($year, $month)]), $undo, 'mileage_months', $list);
    }

    /**
     * Undo of the own "unlock" (GUIDELINES 3.5): same user and session, 15 minutes, only if the month has not been
     * closed or handed in again since. The unlock permission is checked again.
     */
    #[Route(path: '/months/unlock/undo/{action}', name: 'mileage_month_unlock_undo', requirements: ['action' => '[a-f0-9]{16}'], methods: ['POST'])]
    #[IsGranted('unlock_mileage')]
    public function undoUnlock(Request $request, string $action): Response
    {
        $this->assertCsrf($request, 'mileage_month_lock');
        $data = $this->takeUndo($request, 'month.unlock', $action);
        if ($data === null) {
            return $this->actionResult($request, $this->translator->trans('mileage.undo.expired'), null, 'mileage_months', [], 409);
        }

        $user = $this->userRepository->find((int) $data['user']);
        if (!$user instanceof User || !$this->canViewTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $list = ['year' => $year, 'user' => $user === $this->getUser() ? null : $user->getId()];
        if ($this->lockRepository->findLock($user, $year, $month) !== null) {
            return $this->actionResult($request, $this->translator->trans('mileage.undo.changed'), null, 'mileage_months', $list, 409);
        }

        $this->lockService->restore($user, $year, $month, null, $data['lock'], fn (int $id): ?User => $this->userRepository->find($id));

        return $this->actionResult($request, $this->translator->trans('mileage.logbook.relocked', ['%month%' => $this->pages->monthLabel($year, $month)]), null, 'mileage_months', $list);
    }

    #[Route(path: '/history', name: 'mileage_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $tripId = $request->query->getInt('trip');
        $userParam = $user === $this->getUser() ? null : $user->getId();

        $entries = $tripId > 0 ? $this->auditRepository->findByTrip($tripId) : $this->auditRepository->findLatestByOwner($user, 200);
        // A trip id from the URL must belong to the user we may see.
        $entries = array_values(array_filter($entries, static fn ($e) => $e->getOwner() === $user));

        $part = $tripId > 0 ? $this->translator->trans('mileage.logbook.history_trip', ['%id%' => $tripId]) : null;

        return $this->render('@Mileage/logbook/history.html.twig', [
            'page_setup' => $this->pages->create('mileage_history', 'mileage.logbook.history', $part, [
                'user' => $userParam,
                'trip' => $tripId > 0 ? $tripId : null,
                'back' => $this->generateUrl('mileage_trips', ['user' => $userParam]),
            ]),
            'target_user' => $user,
            'user_param' => $userParam,
            'entries' => $entries,
            'trip_id' => $tripId,
        ]);
    }

    private function canLock(User $user): bool
    {
        return $this->isGranted('lock_mileage') && ($user === $this->getUser() || $this->isGranted('edit_other_mileage'));
    }

    private function assertCanView(User $user): void
    {
        if (!$this->canViewTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
    }


    /**
     * @param list<array{trip: \KimaiPlugin\MileageBundle\Entity\Trip, warnings: list<string>}> $rows
     */
    private function csv(Vehicle $vehicle, int $year, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        $t = fn (string $key) => $this->translator->trans($key);
        fputcsv($handle, [$t('mileage.trip.date'), $t('mileage.trip.departure'), $t('mileage.trip.arrival'), $t('mileage.odometer.start'), $t('mileage.odometer.end'), 'km', $t('mileage.trip.purpose'), $t('mileage.trip.start_location'), $t('mileage.trip.destination'), $t('mileage.logbook.reason'), $t('mileage.logbook.partner')], ';', '"', '');

        foreach ($rows as $row) {
            $trip = $row['trip'];
            $tz = $trip->getUser()?->getDateTimezone() ?? new \DateTimeZone(date_default_timezone_get());
            fputcsv($handle, [
                $trip->getDate()?->format('Y-m-d'),
                $trip->getDepartureAt()?->setTimezone($tz)->format('H:i'),
                $trip->getArrivalAt()?->setTimezone($tz)->format('H:i'),
                $trip->getOdometerStart(),
                $trip->getOdometerEnd(),
                number_format($trip->getOdometerStart() !== null && $trip->getOdometerEnd() !== null ? $trip->getOdometerEnd() - $trip->getOdometerStart() : $trip->getTotalDistanceKm(), 1, ',', ''),
                $t($trip->getPurpose()->label()),
                CsvSafe::cell($trip->getStartLocation()),
                CsvSafe::cell($trip->getDestination()),
                CsvSafe::cell($trip->getComment() ?? $trip->getProject()?->getName()),
                CsvSafe::cell($trip->getProject()?->getCustomer()?->getName()),
            ], ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $name = preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($vehicle->getLicensePlate() ?? $vehicle->getName()));

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="fahrtenbuch-%s-%d.csv"', trim((string) $name, '-'), $year),
        ]);
    }
}
