<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\Query\BaseQuery;
use App\Utils\DataTable;
use App\Utils\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\TripSource;
use KimaiPlugin\MileageBundle\Form\CommuteForm;
use KimaiPlugin\MileageBundle\Form\TripForm;
use KimaiPlugin\MileageBundle\Repository\AttachmentRepository;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\RentalRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;
use KimaiPlugin\MileageBundle\Repository\VehicleRepository;
use KimaiPlugin\MileageBundle\Service\AttachmentStorage;
use KimaiPlugin\MileageBundle\Service\CommuteGenerator;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\GpsPoint;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use KimaiPlugin\MileageBundle\Service\TripCsvExporter;
use KimaiPlugin\MileageBundle\Service\TripService;
use Symfony\Component\Form\ClickableInterface;
use Pagerfanta\Adapter\ArrayAdapter;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/mileage')]
#[IsGranted('mileage')]
class TripController extends AbstractController
{
    use TargetUserTrait;
    use MileageUiTrait;

    public function __construct(
        private readonly TripRepository $tripRepository,
        private readonly UserRepository $userRepository,
        private readonly MileageConfiguration $configuration,
        private readonly DawarichClient $dawarichClient,
        private readonly TaxCalculator $taxCalculator,
        private readonly TripCsvExporter $csvExporter,
        private readonly CommuteGenerator $commuteGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TripSuggestionRepository $suggestionRepository,
        private readonly TripService $tripService,
        private readonly VehicleRepository $vehicleRepository,
        private readonly RentalRepository $rentalRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly MonthLockService $lockService,
        private readonly MonthLockRepository $lockRepository,
        private readonly MileagePages $pages,
    ) {
    }

    #[Route(path: '/{year}/{month}', name: 'mileage_trips', defaults: ['year' => null, 'month' => null], requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET'])]
    public function index(Request $request, ?int $year = null, ?int $month = null): Response
    {
        $year ??= (int) date('Y');
        if ($month !== null && ($month < 1 || $month > 12)) {
            throw $this->createNotFoundException();
        }
        $user = $this->getTargetUser($request, $this->userRepository);
        $userParam = $user === $this->getUser() ? null : $user->getId();

        if ($month === null) {
            $trips = $this->tripRepository->findByUserAndYear($user, $year);
        } else {
            $from = new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month));
            $trips = $this->tripRepository->findByUserBetween($user, $from, $from->modify('last day of this month'));
        }

        $km = ['business' => 0.0, 'commute' => 0.0, 'private' => 0.0];
        foreach ($trips as $trip) {
            $km[$trip->getPurpose()->value] += $trip->getTotalDistanceKm();
        }

        $canEdit = $this->canEditTripsOf($user);
        $label = $month !== null ? $this->pages->monthLabel($year, $month) : (string) $year;
        $page = $this->pages->create('mileage_trips', 'mileage.menu', $label, [
            'user' => $userParam,
            'year' => $year,
            'month' => $month,
            'can_edit' => $canEdit,
            'dawarich' => $this->configuration->isDawarichConfigured($user),
        ]);

        $table = new DataTable('mileage_trips', new BaseQuery());
        $table->setPagination(new Pagination(new ArrayAdapter($trips)));
        $table->setSticky(false);
        $table->addColumn('date', ['class' => 'alwaysVisible w-min', 'orderBy' => false, 'title' => 'mileage.trip.date']);
        $table->addColumn('purpose', ['class' => 'd-none d-md-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.purpose']);
        $table->addColumn('vehicle', ['class' => 'd-none d-xl-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.vehicle']);
        $table->addColumn('route', ['class' => 'alwaysVisible', 'orderBy' => false, 'title' => 'mileage.trip.route']);
        $table->addColumn('distance', ['class' => 'text-end w-min text-nowrap', 'orderBy' => false, 'title' => 'mileage.trip.total_distance']);
        $table->addColumn('costs', ['class' => 'd-none d-md-table-cell text-end w-min text-nowrap', 'orderBy' => false, 'title' => 'mileage.trip.costs']);
        $table->addColumn('project', ['class' => 'd-none d-lg-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.project']);
        $table->addColumn('status', ['class' => 'd-none d-sm-table-cell w-min', 'orderBy' => false, 'title' => 'status']);
        $table->addColumn('actions', ['class' => 'actions alwaysVisible']);
        $page->setDataTable($table);

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $route = fn (array $parameters): string => $this->generateUrl('mileage_trips', $parameters + ['user' => $userParam]);
        if ($month !== null) {
            $prev = $month === 1 ? ['year' => $year - 1, 'month' => 12] : ['year' => $year, 'month' => $month - 1];
            $next = $month === 12 ? ['year' => $year + 1, 'month' => 1] : ['year' => $year, 'month' => $month + 1];
            $today = $year === $currentYear && $month === $currentMonth ? null : $route(['year' => $currentYear, 'month' => $currentMonth]);
        } else {
            $prev = ['year' => $year - 1];
            $next = ['year' => $year + 1];
            $today = $year === $currentYear ? null : $route(['year' => $currentYear]);
        }

        return $this->render('@Mileage/trip/index.html.twig', [
            'page_setup' => $page,
            'dataTable' => $table,
            'year' => $year,
            'month' => $month,
            'target_user' => $user,
            'user_param' => $userParam,
            'km' => $km,
            'summary' => $this->taxCalculator->summarize($trips, $year, $this->configuration->getTaxProfile($user), $user->getDateTimezone()),
            'can_edit' => $canEdit,
            'can_delete' => $this->canDeleteTripsOf($user),
            'dawarich_configured' => $this->configuration->isDawarichConfigured($user),
            'open_suggestions' => $this->suggestionRepository->countOpen($user),
            'locks' => $this->lockRepository->findByUserAndYear($user, $year),
            'edit_locked' => $this->isGranted('edit_locked_mileage'),
            'period' => [
                'unit' => $month !== null ? 'month' : 'year',
                'label' => $label,
                'prev' => $route($prev),
                'next' => $route($next),
                'today' => $today,
                'units' => [
                    'month' => $route(['year' => $year, 'month' => $month ?? ($year === $currentYear ? $currentMonth : 1)]),
                    'year' => $route(['year' => $year]),
                ],
            ],
        ]);
    }

    #[Route(path: '/trip/create', name: 'mileage_trip_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $trip = $this->prefill($request, $user);

        return $this->handleForm($request, $trip, 'mileage.trip.create');
    }

    #[Route(path: '/trip/{id}/edit', name: 'mileage_trip_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Trip $trip): Response
    {
        if (!$this->canEditTripsOf($trip->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $trip, 'mileage.trip.edit');
    }

    #[Route(path: '/trip/{id}/duplicate', name: 'mileage_trip_duplicate', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function duplicate(Request $request, Trip $original): Response
    {
        if (!$this->canEditTripsOf($original->getUser())) {
            throw $this->createAccessDeniedException();
        }

        $trip = (new Trip())
            ->setUser($original->getUser())
            ->setDate(new \DateTimeImmutable('today'))
            ->setPurpose($original->getPurpose())
            ->setVehicle($original->getVehicle())
            ->setLicensePlate($original->getLicensePlate())
            ->setStartLocation($original->getStartLocation())
            ->setDestination($original->getDestination())
            ->setDistanceKm($original->getDistanceKm())
            ->setRoundTrip($original->isRoundTrip())
            ->setProject($original->getProject())
            ->setAssignedVehicle($original->getAssignedVehicle());

        return $this->handleForm($request, $trip, 'mileage.trip.create');
    }

    #[Route(path: '/trip/{id}/delete', name: 'mileage_trip_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, Trip $trip): Response
    {
        if (!$this->canDeleteTripsOf($trip->getUser())) {
            throw $this->createAccessDeniedException();
        }

        $redirect = $this->listRoute($trip);
        $form = $this->createPlainForm('mileage_trip_delete' . $trip->getId(), $this->generateUrl('mileage_trip_delete', ['id' => $trip->getId()]));

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'mileage_trip_delete' . $trip->getId());
            if (!$this->mayChangeLocked($trip)) {
                $this->flashError($this->translator->trans('mileage.logbook.error.locked'));

                return $this->kpuFormSuccess($request, 'mileage_trips', $redirect);
            }
            foreach ($this->attachmentRepository->findByTrip($trip) as $attachment) {
                $this->attachmentStorage->delete($attachment);
            }
            $this->tripRepository->remove($trip);
            $this->flashSuccess('action.delete.success');

            return $this->kpuFormSuccess($request, 'mileage_trips', $redirect);
        }

        $name = $this->pages->dateLabel($trip->getDate() ?? new \DateTimeImmutable()) . ' · ' . $this->translator->trans($trip->getPurpose()->label());
        if ($trip->getDestination() !== null) {
            $name .= ' · ' . $trip->getDestination();
        }

        return $this->render('@Mileage/_delete.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.menu', $this->translator->trans('mileage.trip.delete'), ['back' => $this->generateUrl('mileage_trips', $redirect)]),
            'form' => $form->createView(),
            'item_name' => $name,
            'message' => $this->translator->trans('mileage.trip.delete_message'),
            'back' => $this->generateUrl('mileage_trips', $redirect),
        ]);
    }

    #[Route(path: '/commutes/{year}/{month}', name: 'mileage_commutes', requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET', 'POST'])]
    public function commutes(Request $request, int $year, int $month): Response
    {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException();
        }
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
        $userParam = $user === $this->getUser() ? null : $user->getId();
        $list = ['year' => $year, 'month' => $month, 'user' => $userParam];

        $days = $this->commuteGenerator->suggestDays($user, $year, $month);
        $form = $this->createForm(CommuteForm::class, ['distance' => $this->configuration->getCommuteKm($user), 'dates' => array_keys(array_filter($days, static fn (array $day) => !$day['has_commute']))], [
            'days' => $days,
            'action' => $this->generateUrl('mileage_commutes', ['year' => $year, 'month' => $month, 'user' => $userParam]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{distance: float, dates: list<string>} $data */
            $data = $form->getData();
            $result = $this->commuteGenerator->create($user, $data['dates'], (float) $data['distance'], $year, $month, $this->isGranted('edit_locked_mileage'));
            $this->addFlash('kpu_result', $this->translator->trans('mileage.commute.created', ['%count%' => $result['created']]));
            if ($result['locked'] > 0) {
                $this->flashWarning($this->translator->trans('mileage.logbook.error.locked'));
            }

            return $this->redirectToRoute('mileage_trips', $list);
        }

        $label = $this->pages->monthLabel($year, $month);

        return $this->render('@Mileage/trip/commutes.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.commute.generate', $label, ['back' => $this->generateUrl('mileage_trips', $list)]),
            'form' => $form->createView(),
            'days' => $days,
            'target_user' => $user,
            'period_label' => $label,
            'back' => $this->generateUrl('mileage_trips', $list),
        ]);
    }

    #[Route(path: '/dawarich/test', name: 'mileage_dawarich_test', methods: ['POST'])]
    public function testDawarich(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        // Uses the user's Dawarich credentials: seeing someone's trips is not enough.
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
        $this->assertCsrf($request, 'mileage_dawarich_test');
        $list = ['user' => $user === $this->getUser() ? null : $user->getId()];

        try {
            $count = $this->dawarichClient->testConnection($user);

            return $this->actionResult($request, $this->translator->trans('mileage.dawarich.test_ok', ['%count%' => $count]), null, 'mileage_trips', $list);
        } catch (DawarichException $e) {
            return $this->actionResult($request, $this->translator->trans($e->getMessage(), $e->getParameters()), null, 'mileage_trips', $list, 502);
        }
    }

    /**
     * Driven parts of the Dawarich tracks in a trip's time window for the map preview (not stored anywhere).
     */
    #[Route(path: '/trip/{id}/track', name: 'mileage_trip_track', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function track(Trip $trip): Response
    {
        $user = $trip->getUser();
        if (!$this->canViewTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $from = $trip->getDepartureAt();
        $to = $trip->getArrivalAt();
        if ($user === null || $from === null || $to === null || !$this->configuration->isDawarichConfigured($user)) {
            return $this->json(['lines' => []]);
        }

        try {
            $lines = $this->dawarichClient->fetchLines($user, $from, $to);
        } catch (DawarichException $e) {
            return $this->json(['error' => $this->translator->trans($e->getMessage(), $e->getParameters())], 502);
        }

        // Thin out long tracks; the preview does not need every point.
        $step = max(1, (int) ceil(array_sum(array_map('count', $lines)) / 2000));
        $coords = [];
        foreach ($lines as $line) {
            $last = \count($line) - 1;
            $coords[] = array_values(array_map(
                static fn (GpsPoint $point) => [round($point->latitude, 6), round($point->longitude, 6)],
                array_filter($line, static fn (int $i) => $i % $step === 0 || $i === $last, \ARRAY_FILTER_USE_KEY),
            ));
        }

        return $this->json(['lines' => $coords]);
    }

    #[Route(path: '/export/{year}', name: 'mileage_export', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function export(Request $request, int $year): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $csv = $this->csvExporter->export($this->tripRepository->findByUserAndYear($user, $year));

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="fahrten-%s-%d.csv"', $user->getUserIdentifier(), $year),
        ]);
    }

    private function handleForm(Request $request, Trip $trip, string $title): Response
    {
        /** @var User $owner */
        $owner = $trip->getUser();
        $dawarich = $this->configuration->isDawarichConfigured($owner);

        // Closed month without "edit_locked_mileage": the trip is shown read-only, receipts can still be added.
        $readOnly = $trip->getId() !== null && !$this->mayChangeLocked($trip);
        if ($readOnly && $request->isMethod('POST')) {
            $this->flashError($this->translator->trans('mileage.logbook.error.locked'));

            return $this->redirectToRoute('mileage_trip_edit', ['id' => $trip->getId()]);
        }

        $year = (int) ($trip->getDate() ?? new \DateTimeImmutable())->format('Y');
        $options = [
            'dawarich' => $dawarich,
            'vehicles' => $this->vehicleRepository->findByUser($owner),
            'rentals' => array_merge($this->rentalRepository->findByUserAndYear($owner, $year - 1), $this->rentalRepository->findByUserAndYear($owner, $year)),
            'disabled' => $readOnly,
        ];

        $form = $this->createForm(TripForm::class, $trip, $options);
        if ($dawarich) {
            [$from, $to] = $this->measureWindow($trip, $owner);
            $form->get('measureFrom')->setData($from);
            $form->get('measureTo')->setData($to);
        }
        $form->handleRequest($request);

        $lookup = $dawarich ? $form->get('dawarich') : null;
        // The lookup button skips validation, so check the CSRF token (a form-level error) explicitly.
        if ($form->isSubmitted() && $lookup instanceof ClickableInterface && $lookup->isClicked() && \count($form->getErrors()) === 0) {
            $from = $form->get('measureFrom')->getData();
            $to = $form->get('measureTo')->getData();
            $this->lookupDistance($trip, $from instanceof \DateTimeImmutable ? $from : null, $to instanceof \DateTimeImmutable ? $to : null);

            // Re-create the form so the measured distance replaces the submitted value.
            $form = $this->createForm(TripForm::class, $trip, $options);
            $form->get('measureFrom')->setData($from);
            $form->get('measureTo')->setData($to);
        } elseif ($form->isSubmitted() && $form->isValid()) {
            if (!$this->mayChangeLocked($trip)) {
                $form->get('date')->addError(new FormError($this->translator->trans('mileage.logbook.error.locked')));
            } else {
                // Take over tax category and plate when a concrete vehicle was picked.
                $trip->setAssignedVehicle($trip->getAssignedVehicle());
                $this->tripService->prepare($trip);
                $this->tripRepository->save($trip);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('mileage_trips', $this->listRoute($trip));
            }
        }

        $list = $this->generateUrl('mileage_trips', $this->listRoute($trip));
        $page = $this->pages->create('mileage_trip_form', 'mileage.menu', $this->translator->trans($title), [
            'trip' => $trip,
            'back' => $list,
            'can_edit' => $this->canEditTripsOf($owner) && !$readOnly,
            'can_delete' => $this->canDeleteTripsOf($owner) && !$readOnly,
        ]);

        return $this->render('@Mileage/trip/edit.html.twig', [
            'page_setup' => $page,
            'title' => $title,
            'trip' => $trip,
            'form' => $form->createView(),
            'target_user' => $owner,
            'dawarich_configured' => $dawarich,
            'attachments' => $trip->getId() !== null ? $this->attachmentRepository->findByTrip($trip) : [],
            'attachment_form' => $trip->getId() !== null && $this->canEditTripsOf($owner)
                ? $this->createAttachmentForm($this->generateUrl('mileage_attachment_trip', ['id' => $trip->getId()]))->createView()
                : null,
            'can_edit' => $this->canEditTripsOf($owner) && !$readOnly,
            'can_delete' => $this->canDeleteTripsOf($owner) && !$readOnly,
            'read_only' => $readOnly,
            'back' => $list,
        ]);
    }

    private function mayChangeLocked(Trip $trip): bool
    {
        return !$this->lockService->isTripLocked($trip) || $this->isGranted('edit_locked_mileage');
    }

    /**
     * Default Dawarich window: departure to arrival when both are known, otherwise the whole day of the trip
     * (so the way there and back is covered). Only the window, never stored as departure/arrival: those are
     * the real times of the trip and the base of the meal allowance.
     *
     * @return array{?\DateTimeImmutable, ?\DateTimeImmutable}
     */
    private function measureWindow(Trip $trip, User $owner): array
    {
        $date = $trip->getDate();
        if ($date === null) {
            return [$trip->getDepartureAt(), $trip->getArrivalAt()];
        }
        $day = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone($owner->getTimezone()));

        return [$trip->getDepartureAt() ?? $day->setTime(0, 0), $trip->getArrivalAt() ?? $day->setTime(23, 59)];
    }

    private function lookupDistance(Trip $trip, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        if ($from === null || $to === null || $to <= $from) {
            $this->flashError($this->translator->trans('mileage.dawarich.error.time_window'));

            return;
        }

        try {
            $result = $this->dawarichClient->measureDistance($trip->getUser(), $from, $to);
        } catch (DawarichException $e) {
            $this->flashError($this->translator->trans($e->getMessage(), $e->getParameters()));

            return;
        }

        if ($result->segmentCount === 0) {
            $this->flashWarning($this->translator->trans('mileage.dawarich.no_segments'));

            return;
        }

        $trip->setDistanceKm($result->distanceKm);
        $trip->setSource(TripSource::DAWARICH);
        $trip->setPointCount($result->pointCount);

        // A time window measures everything that was driven, so it is not a single direction.
        if ($trip->getPurpose() !== TripPurpose::COMMUTE) {
            $trip->setRoundTrip(false);
        }

        // Kimai hides success flashes: the measured distance is a result callout (kimai-plugin-ui GUIDELINES 3.6)
        $this->addFlash('kpu_result', $this->translator->trans('mileage.dawarich.measured', [
            '%km%' => $this->pages->number($result->distanceKm),
            '%segments%' => $result->segmentCount,
        ]));
    }

    private function prefill(Request $request, User $user): Trip
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date'));
        $trip = $this->tripService->createTrip($user, $date !== false ? $date : new \DateTimeImmutable('today'))
            ->setStartLocation($this->configuration->getHomeAddress($user));

        $purpose = TripPurpose::tryFrom((string) $request->query->get('purpose'));
        if ($purpose !== null) {
            $trip->setPurpose($purpose);
        }

        if ($trip->getPurpose() === TripPurpose::COMMUTE) {
            $trip->setDestination($this->configuration->getWorkAddress($user));
            $commuteKm = $this->configuration->getCommuteKm($user);
            $trip->setDistanceKm($commuteKm);
            if ($commuteKm === null && $request->isMethod('GET')) {
                $this->flashWarning($this->translator->trans('mileage.commute.error.no_distance'));
            }
        }

        $timesheetId = $request->query->getInt('timesheet');
        if ($timesheetId > 0) {
            $timesheet = $this->entityManager->find(Timesheet::class, $timesheetId);
            if ($timesheet instanceof Timesheet && $timesheet->getUser() === $user) {
                $trip->setTimesheet($timesheet);
                $trip->setProject($timesheet->getProject());
                if ($timesheet->getBegin() !== null) {
                    $begin = \DateTimeImmutable::createFromInterface($timesheet->getBegin());
                    // No departure/arrival: the working time is not the travel time. The Dawarich window
                    // defaults to the whole day (see measureWindow()).
                    $trip->setDate($begin);
                }
                if ($trip->getDestination() === null) {
                    $trip->setDestination($timesheet->getProject()?->getCustomer()?->getName());
                }
            }
        }

        return $trip;
    }

    /**
     * @return array{year: int, month: int, user: ?int}
     */
    private function listRoute(Trip $trip): array
    {
        $date = $trip->getDate() ?? new \DateTimeImmutable();
        $user = $trip->getUser();

        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'user' => $user === null || $user === $this->getUser() ? null : $user->getId(),
        ];
    }
}
