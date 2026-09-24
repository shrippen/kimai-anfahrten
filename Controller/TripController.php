<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\TripSource;
use KimaiPlugin\MileageBundle\Form\TripForm;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\CommuteGenerator;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use KimaiPlugin\MileageBundle\Service\TripCsvExporter;
use Doctrine\ORM\EntityManagerInterface;
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

        if ($month === null) {
            $trips = $this->tripRepository->findByUserAndYear($user, $year);
        } else {
            $from = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
            $trips = $this->tripRepository->findByUserBetween($user, $from, $from->modify('last day of this month'));
        }

        return $this->render('@Mileage/trip/index.html.twig', [
            'page_setup' => new PageSetup('menu.mileage'),
            'year' => $year,
            'month' => $month,
            'target_user' => $user,
            'trips' => $trips,
            'summary' => $this->taxCalculator->summarize($trips),
            'can_edit' => $this->canEditTripsOf($user),
            'can_delete' => $this->canDeleteTripsOf($user),
            'dawarich_configured' => $this->configuration->isDawarichConfigured($user),
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

        return $this->handleForm($request, $trip, 'trip.create');
    }

    #[Route(path: '/trip/{id}/edit', name: 'mileage_trip_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Trip $trip): Response
    {
        if (!$this->canEditTripsOf($trip->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $trip, 'trip.edit');
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
            ->setProject($original->getProject());

        return $this->handleForm($request, $trip, 'trip.create');
    }

    #[Route(path: '/trip/{id}/delete', name: 'mileage_trip_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Trip $trip): Response
    {
        if (!$this->canDeleteTripsOf($trip->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_trip_delete' . $trip->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $redirect = $this->listRoute($trip);
        $this->tripRepository->remove($trip);
        $this->flashSuccess('action.delete.success');

        return $this->redirectToRoute('mileage_trips', $redirect);
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

        $defaultKm = $this->configuration->getCommuteKm($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mileage_commutes', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $km = (float) str_replace(',', '.', (string) $request->request->get('distance', $defaultKm ?? 0));
            $dates = array_filter((array) $request->request->all('dates'), 'is_string');

            if ($km <= 0) {
                $this->flashError($this->translator->trans('trip.error.commute_distance'));
            } else {
                $created = $this->commuteGenerator->create($user, $dates, $km);
                $this->flashSuccess($this->translator->trans('commute.created', ['%count%' => $created]));

                return $this->redirectToRoute('mileage_trips', ['year' => $year, 'month' => $month, 'user' => $user->getId()]);
            }
        }

        return $this->render('@Mileage/trip/commutes.html.twig', [
            'page_setup' => new PageSetup('commute.generate'),
            'year' => $year,
            'month' => $month,
            'target_user' => $user,
            'days' => $this->commuteGenerator->suggestDays($user, $year, $month),
            'distance' => $defaultKm,
        ]);
    }

    #[Route(path: '/export/{year}', name: 'mileage_export', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function export(Request $request, int $year): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $csv = $this->csvExporter->export($this->tripRepository->findByUserAndYear($user, $year));

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="fahrten-%s-%d.csv"', $user->getUserIdentifier(), $year),
        ]);
    }

    private function handleForm(Request $request, Trip $trip, string $title): Response
    {
        /** @var User $owner */
        $owner = $trip->getUser();
        $dawarich = $this->configuration->isDawarichConfigured($owner);

        $form = $this->createForm(TripForm::class, $trip, ['dawarich' => $dawarich]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $dawarich && $form->get('dawarich')->isClicked()) {
            $this->lookupDistance($trip);

            // Re-create the form so the measured distance replaces the submitted value.
            $form = $this->createForm(TripForm::class, $trip, ['dawarich' => $dawarich]);
        } elseif ($form->isSubmitted() && $form->isValid()) {
            $this->tripRepository->save($trip);
            $this->flashSuccess('action.update.success');

            return $this->redirectToRoute('mileage_trips', $this->listRoute($trip));
        }

        return $this->render('@Mileage/trip/edit.html.twig', [
            'page_setup' => new PageSetup($title),
            'title' => $title,
            'trip' => $trip,
            'form' => $form->createView(),
            'target_user' => $owner,
            'dawarich_configured' => $dawarich,
        ]);
    }

    private function lookupDistance(Trip $trip): void
    {
        $from = $trip->getDepartureAt();
        $to = $trip->getArrivalAt();

        if ($from === null || $to === null) {
            $this->flashError($this->translator->trans('dawarich.error.time_window'));

            return;
        }

        try {
            $result = $this->dawarichClient->measureDistance($trip->getUser(), $from, $to);
        } catch (DawarichException $e) {
            $this->flashError($this->translator->trans($e->getMessage(), $e->getParameters()));

            return;
        }

        if ($result->usedPointCount < 2) {
            $this->flashWarning($this->translator->trans('dawarich.no_points'));

            return;
        }

        $trip->setDistanceKm($result->distanceKm);
        $trip->setSource(TripSource::DAWARICH);
        $trip->setPointCount($result->pointCount);

        // A time window measures everything that was driven, so it is not a single direction.
        if ($trip->getPurpose() !== TripPurpose::COMMUTE) {
            $trip->setRoundTrip(false);
        }

        $this->flashSuccess($this->translator->trans('dawarich.measured', [
            '%km%' => number_format($result->distanceKm, 1, ',', '.'),
            '%points%' => $result->usedPointCount,
        ]));
    }

    private function prefill(Request $request, User $user): Trip
    {
        $trip = (new Trip())
            ->setUser($user)
            ->setDate(new \DateTimeImmutable('today'))
            ->setVehicle($this->configuration->getDefaultVehicle($user))
            ->setLicensePlate($this->configuration->getLicensePlate($user))
            ->setStartLocation($this->configuration->getHomeAddress($user));

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date'));
        if ($date !== false) {
            $trip->setDate($date);
        }

        $purpose = TripPurpose::tryFrom((string) $request->query->get('purpose'));
        if ($purpose !== null) {
            $trip->setPurpose($purpose);
        }

        if ($trip->getPurpose() === TripPurpose::COMMUTE) {
            $trip->setDestination($this->configuration->getWorkAddress($user));
            $trip->setDistanceKm($this->configuration->getCommuteKm($user));
        }

        $timesheetId = $request->query->getInt('timesheet');
        if ($timesheetId > 0) {
            $timesheet = $this->entityManager->find(Timesheet::class, $timesheetId);
            if ($timesheet instanceof Timesheet && $timesheet->getUser() === $user) {
                $trip->setTimesheet($timesheet);
                $trip->setProject($timesheet->getProject());
                if ($timesheet->getBegin() !== null) {
                    $begin = \DateTimeImmutable::createFromInterface($timesheet->getBegin());
                    $trip->setDate($begin);
                    // Default window: the whole working day, so the way there and back is covered.
                    $trip->setDepartureAt($begin->setTime(0, 0));
                    $trip->setArrivalAt($begin->setTime(23, 59));
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

        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'user' => $trip->getUser()?->getId(),
        ];
    }
}
