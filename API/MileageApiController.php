<?php

namespace KimaiPlugin\MileageBundle\API;

use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Controller\TargetUserTrait;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\TripSuggestion;
use KimaiPlugin\MileageBundle\Enum\SuggestionStatus;
use KimaiPlugin\MileageBundle\Enum\TaxProfile;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\TripSource;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;
use KimaiPlugin\MileageBundle\Repository\VehicleRepository;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use KimaiPlugin\MileageBundle\Service\TripMapper;
use KimaiPlugin\MileageBundle\Service\TripService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * REST API under /api/mileage (authenticate with a Kimai API token: "Authorization: Bearer …").
 */
#[Route(path: '/mileage')]
#[IsGranted('mileage')]
class MileageApiController extends AbstractController
{
    use TargetUserTrait;

    public function __construct(
        private readonly TripRepository $tripRepository,
        private readonly VehicleRepository $vehicleRepository,
        private readonly TripSuggestionRepository $suggestionRepository,
        private readonly UserRepository $userRepository,
        private readonly TripMapper $mapper,
        private readonly TripService $tripService,
        private readonly SuggestionService $suggestionService,
        private readonly TaxCalculator $taxCalculator,
        private readonly MileageConfiguration $configuration,
        private readonly MonthLockService $lockService,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/meta', name: 'api_mileage_meta', methods: ['GET'])]
    public function meta(): JsonResponse
    {
        $list = fn (array $cases) => array_map(fn ($c) => ['value' => $c->value, 'label' => $this->translator->trans($c->label())], $cases);

        return $this->json([
            'purposes' => $list(TripPurpose::cases()),
            'vehicles' => $list(VehicleType::cases()),
            'taxProfiles' => $list(TaxProfile::cases()),
        ]);
    }

    #[Route(path: '/trips', name: 'api_mileage_trips', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->targetUser($request);
        $year = $request->query->getInt('year', (int) date('Y'));
        $month = $request->query->getInt('month');

        if ($month >= 1 && $month <= 12) {
            $from = new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month));
            $trips = $this->tripRepository->findByUserBetween($user, $from, $from->modify('last day of this month'));
        } else {
            $trips = $this->tripRepository->findByUserAndYear($user, $year);
        }

        return $this->json(array_map([$this->mapper, 'toArray'], $trips));
    }

    #[Route(path: '/trips/{id}', name: 'api_mileage_trip', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(Trip $trip): JsonResponse
    {
        $this->assertCanView($trip->getUser());

        return $this->json($this->mapper->toArray($trip));
    }

    /**
     * Minimal body: {"distanceKm": 12.5}. Defaults: today, business trip, default vehicle.
     */
    #[Route(path: '/trips', name: 'api_mileage_trip_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->targetUser($request);
        $this->assertCanEdit($user);
        $data = $this->payload($request);

        $date = isset($data['date']) ? TripMapper::parseDate($data['date']) : new \DateTimeImmutable('today');
        $trip = $this->tripService->createTrip($user, $date ?? new \DateTimeImmutable('today'));
        if (($data['purpose'] ?? null) === TripPurpose::COMMUTE->value) {
            $trip->setDistanceKm($this->configuration->getCommuteKm($user))
                ->setStartLocation($this->configuration->getHomeAddress($user))
                ->setDestination($this->configuration->getWorkAddress($user));
        }

        return $this->save($trip, $data, Response::HTTP_CREATED);
    }

    #[Route(path: '/trips/{id}', name: 'api_mileage_trip_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(Request $request, Trip $trip): JsonResponse
    {
        $this->assertCanEdit($trip->getUser());

        return $this->save($trip, $this->payload($request), Response::HTTP_OK);
    }

    #[Route(path: '/trips/{id}', name: 'api_mileage_trip_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(Trip $trip): Response
    {
        $user = $trip->getUser();
        if ($user !== $this->getUser() ? !$this->isGranted('delete_other_mileage') : !$this->isGranted('delete_own_mileage')) {
            throw $this->createAccessDeniedException();
        }
        $this->assertNotLocked($trip);
        $this->tripRepository->remove($trip);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/vehicles', name: 'api_mileage_vehicles', methods: ['GET'])]
    public function vehicles(Request $request): JsonResponse
    {
        $user = $this->targetUser($request);

        return $this->json(array_map(static fn ($v) => [
            'id' => $v->getId(),
            'name' => $v->getName(),
            'type' => $v->getType()->value,
            'licensePlate' => $v->getLicensePlate(),
            'active' => $v->isActive(),
        ], $this->vehicleRepository->findByUser($user)));
    }

    #[Route(path: '/suggestions', name: 'api_mileage_suggestions', methods: ['GET'])]
    public function suggestions(Request $request): JsonResponse
    {
        $user = $this->targetUser($request);

        return $this->json(array_map(static fn (TripSuggestion $s) => [
            'id' => $s->getId(),
            'start' => $s->getStartAt()->format(\DateTimeInterface::ATOM),
            'end' => $s->getEndAt()->format(\DateTimeInterface::ATOM),
            'from' => $s->getStartLabel(),
            'to' => $s->getEndLabel(),
            'distanceKm' => $s->getDistanceKm(),
            'purpose' => $s->getPurpose()->value,
            'mode' => $s->getMode(),
            'vehicle' => $s->getVehicle()?->value,
            'project' => $s->getProject()?->getId(),
        ], $this->suggestionRepository->findOpen($user)));
    }

    #[Route(path: '/suggestions/{id}/accept', name: 'api_mileage_suggestion_accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(Request $request, TripSuggestion $suggestion): JsonResponse
    {
        /** @var User $user */
        $user = $suggestion->getUser();
        $this->assertCanEdit($user);
        if ($suggestion->getStatus() !== SuggestionStatus::OPEN) {
            return $this->json(['error' => 'suggestion is not open'], Response::HTTP_CONFLICT);
        }
        $data = $this->payload($request);
        $purpose = TripMapper::parsePurpose($data['purpose'] ?? '') ?? $suggestion->getPurpose();
        $vehicle = TripMapper::parseVehicle($data['vehicle'] ?? '') ?? $suggestion->getVehicle() ?? $this->configuration->getDefaultVehicle($user);

        $trip = $this->suggestionService->accept($suggestion, $purpose, $vehicle);

        return $this->json($this->mapper->toArray($trip), Response::HTTP_CREATED);
    }

    #[Route(path: '/suggestions/{id}/dismiss', name: 'api_mileage_suggestion_dismiss', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dismiss(TripSuggestion $suggestion): Response
    {
        $this->assertCanEdit($suggestion->getUser());
        if ($suggestion->getStatus() === SuggestionStatus::OPEN) {
            $this->suggestionService->dismiss($suggestion);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/tax/{year}', name: 'api_mileage_tax', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function tax(Request $request, int $year): JsonResponse
    {
        $user = $this->targetUser($request);
        $profile = TaxProfile::tryFrom((string) $request->query->get('profile')) ?? $this->configuration->getTaxProfile($user);
        $summary = $this->taxCalculator->summarize($this->tripRepository->findByUserAndYear($user, $year), $year, $profile, $user->getDateTimezone());

        return $this->json([
            'year' => $year,
            'profile' => $profile->value,
            'commute' => $summary['commute'],
            'business' => array_values(array_map(static fn (array $row) => ['vehicle' => $row['vehicle']->value] + array_diff_key($row, ['vehicle' => true]), $summary['business'])),
            'businessTotal' => $summary['business_total'],
            'meals' => array_diff_key($summary['meals'], ['days' => true]),
            'privateKm' => $summary['private']['km'],
            'totalKm' => $summary['total_km'],
            'total' => $summary['total'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(Trip $trip, array $data, int $status): JsonResponse
    {
        $wasLocked = $trip->getId() !== null && $this->lockService->isTripLocked($trip);

        $errors = $this->mapper->apply($trip, $data);
        if (\array_key_exists('vehicleId', $data)) {
            $id = self::id($data['vehicleId']);
            $vehicle = $id === null ? null : $this->vehicleRepository->find($id);
            if ($data['vehicleId'] !== null && ($vehicle === null || $vehicle->getUser() !== $trip->getUser())) {
                $errors['vehicleId'] = 'unknown vehicle';
            } else {
                $trip->setAssignedVehicle($vehicle);
            }
        }
        if (\array_key_exists('project', $data)) {
            $id = self::id($data['project']);
            $project = $id === null ? null : $this->entityManager->find(Project::class, $id);
            if ($data['project'] !== null && $project === null) {
                $errors['project'] = 'unknown project';
            } else {
                $trip->setProject($project);
            }
        }
        if (\array_key_exists('timesheet', $data)) {
            // Only the trip owner's own timesheet entries can be linked.
            $id = self::id($data['timesheet']);
            $timesheet = $id === null ? null : $this->entityManager->find(Timesheet::class, $id);
            if ($data['timesheet'] !== null && ($timesheet === null || $timesheet->getUser() !== $trip->getUser())) {
                $errors['timesheet'] = 'unknown timesheet';
            } else {
                $trip->setTimesheet($timesheet);
                if ($timesheet !== null && !\array_key_exists('project', $data)) {
                    $trip->setProject($timesheet->getProject());
                }
            }
        }
        if ($trip->getId() === null) {
            $trip->setSource(TripSource::MANUAL);
        }

        foreach ($this->validator->validate($trip) as $violation) {
            $errors[$violation->getPropertyPath() ?: 'trip'] = $this->translator->trans((string) $violation->getMessage());
        }
        if ($errors !== []) {
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }
        if ($wasLocked || $this->lockService->isTripLocked($trip)) {
            $this->assertNotLocked($trip);
        }

        $this->tripService->prepare($trip);
        $this->tripRepository->save($trip);

        return $this->json($this->mapper->toArray($trip), $status);
    }

    /**
     * Positive integer id from JSON (number or numeric string), else null.
     */
    private static function id(mixed $value): ?int
    {
        if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON body');
        }

        return $data;
    }

    private function targetUser(Request $request): User
    {
        if (!$this->getUser() instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->getTargetUser($request, $this->userRepository);
    }

    private function assertCanView(?User $user): void
    {
        if (!$this->canViewTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertCanEdit(?User $user): void
    {
        if ($user === $this->getUser() ? !$this->isGranted('edit_own_mileage') : !$this->isGranted('edit_other_mileage')) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertNotLocked(Trip $trip): void
    {
        if ($this->lockService->isTripLocked($trip) && !$this->isGranted('edit_locked_mileage')) {
            throw $this->createAccessDeniedException('This month of the logbook is closed.');
        }
    }
}
