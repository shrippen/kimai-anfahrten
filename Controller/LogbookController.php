<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\TripAuditRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\CsvSafe;
use KimaiPlugin\MileageBundle\Service\LogbookService;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
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

    public function __construct(
        private readonly TripRepository $tripRepository,
        private readonly UserRepository $userRepository,
        private readonly MonthLockRepository $lockRepository,
        private readonly TripAuditRepository $auditRepository,
        private readonly LogbookService $logbookService,
        private readonly MonthLockService $lockService,
        private readonly TranslatorInterface $translator,
        private readonly MileageConfiguration $configuration,
    ) {
    }

    #[Route(path: '/logbook/{id}/{year}', name: 'mileage_logbook', defaults: ['year' => null], requirements: ['id' => '\d+', 'year' => '\d{4}'], methods: ['GET'])]
    public function logbook(Request $request, Vehicle $vehicle, ?int $year = null): Response
    {
        $year ??= (int) date('Y');
        /** @var User $user */
        $user = $vehicle->getUser();
        $this->assertCanView($user);

        $analysis = $this->logbookService->analyse($vehicle, $this->tripRepository->findByVehicle($vehicle, $year));

        if ($request->query->get('format') === 'csv') {
            return $this->csv($vehicle, $year, $analysis['rows']);
        }

        return $this->render('@Mileage/logbook/index.html.twig', [
            'page_setup' => new PageSetup('logbook.title'),
            'vehicle' => $vehicle,
            'target_user' => $user,
            'year' => $year,
            'analysis' => $analysis,
            'locks' => $this->lockRepository->findByUserAndYear($user, $year),
            'print' => $request->query->getBoolean('print'),
        ]);
    }

    #[Route(path: '/months/{year}', name: 'mileage_months', defaults: ['year' => null], requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function months(Request $request, ?int $year = null): Response
    {
        $year ??= (int) date('Y');
        $user = $this->getTargetUser($request, $this->userRepository);

        $counts = array_fill(1, 12, 0);
        foreach ($this->tripRepository->findByUserAndYear($user, $year) as $trip) {
            $counts[(int) $trip->getDate()?->format('n')]++;
        }

        return $this->render('@Mileage/logbook/months.html.twig', [
            'page_setup' => new PageSetup('logbook.months'),
            'target_user' => $user,
            'year' => $year,
            'locks' => $this->lockRepository->findByUserAndYear($user, $year),
            'counts' => $counts,
            'can_lock' => $this->canLock($user),
            'can_unlock' => $this->isGranted('unlock_mileage'),
            'approval_enabled' => $this->configuration->isApprovalEnabled(),
        ]);
    }

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
        $this->flashSuccess($this->translator->trans($approval ? 'approval.submitted' : 'logbook.locked', ['%month%' => \sprintf('%02d/%d', $month, $year)]));

        return $this->redirectToRoute('mileage_months', ['year' => $year, 'user' => $user->getId()]);
    }

    #[Route(path: '/months/{year}/{month}/unlock', name: 'mileage_month_unlock', requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['POST'])]
    #[IsGranted('unlock_mileage')]
    public function unlock(Request $request, int $year, int $month): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $this->assertCsrf($request, 'mileage_month_lock');

        $this->lockService->unlock($user, $year, $month);
        $this->flashSuccess($this->translator->trans('logbook.unlocked', ['%month%' => \sprintf('%02d/%d', $month, $year)]));

        return $this->redirectToRoute('mileage_months', ['year' => $year, 'user' => $user->getId()]);
    }

    #[Route(path: '/history', name: 'mileage_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $tripId = $request->query->getInt('trip');

        $entries = $tripId > 0 ? $this->auditRepository->findByTrip($tripId) : $this->auditRepository->findLatestByOwner($user, 200);
        // A trip id from the URL must belong to the user we may see.
        $entries = array_values(array_filter($entries, static fn ($e) => $e->getOwner() === $user));

        return $this->render('@Mileage/logbook/history.html.twig', [
            'page_setup' => new PageSetup('logbook.history'),
            'target_user' => $user,
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

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
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
        fputcsv($handle, [$t('trip.date'), $t('trip.departure'), $t('trip.arrival'), $t('odometer.start'), $t('odometer.end'), 'km', $t('trip.purpose'), $t('trip.start_location'), $t('trip.destination'), $t('logbook.reason'), $t('logbook.partner')], ';', '"', '');

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
