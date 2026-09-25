<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Entity\TripSuggestion;
use KimaiPlugin\MileageBundle\Enum\SuggestionStatus;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review trips detected in the Dawarich history: accept (creates a trip) or dismiss.
 */
#[Route(path: '/mileage/suggestions')]
#[IsGranted('mileage')]
class SuggestionController extends AbstractController
{
    use TargetUserTrait;

    public function __construct(
        private readonly TripSuggestionRepository $suggestionRepository,
        private readonly UserRepository $userRepository,
        private readonly SuggestionService $suggestionService,
        private readonly MileageConfiguration $configuration,
        private readonly TranslatorInterface $translator,
        private readonly MonthLockService $lockService,
    ) {
    }

    #[Route(path: '', name: 'mileage_suggestions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);

        return $this->render('@Mileage/suggestion/index.html.twig', [
            'page_setup' => new PageSetup('mileage.suggestion.list'),
            'target_user' => $user,
            'suggestions' => $this->suggestionRepository->findOpen($user),
            'can_edit' => $this->canEditTripsOf($user),
            'dawarich_configured' => $this->configuration->isDawarichConfigured($user),
            'default_vehicle' => $this->configuration->getDefaultVehicle($user),
            'purposes' => TripPurpose::cases(),
            'vehicles' => VehicleType::cases(),
            'default_from' => new \DateTimeImmutable('-7 days'),
            'default_to' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route(path: '/detect', name: 'mileage_suggestions_detect', methods: ['POST'])]
    public function detect(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $this->assertEditable($user, $request, 'mileage_suggestions_detect');

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('from'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('to'));

        if ($from === false || $to === false || $to < $from || $from->diff($to)->days > 92) {
            $this->flashError($this->translator->trans('mileage.suggestion.error.range'));

            return $this->redirectToRoute('mileage_suggestions', ['user' => $user->getId()]);
        }

        try {
            $count = $this->suggestionService->detect($user, $from, $to);
            $this->flashSuccess($this->translator->trans('mileage.suggestion.detected', ['%count%' => $count]));
        } catch (DawarichException $e) {
            $this->flashError($this->translator->trans($e->getMessage(), $e->getParameters()));
        }

        return $this->redirectToRoute('mileage_suggestions', ['user' => $user->getId()]);
    }

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

        return $this->redirectToRoute('mileage_suggestions', ['user' => $user->getId()]);
    }

    #[Route(path: '/{id}/dismiss', name: 'mileage_suggestion_dismiss', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dismiss(Request $request, TripSuggestion $suggestion): Response
    {
        /** @var User $user */
        $user = $suggestion->getUser();
        $this->assertEditable($user, $request, 'mileage_suggestion' . $suggestion->getId());

        if ($suggestion->getStatus() === SuggestionStatus::OPEN) {
            $this->suggestionService->dismiss($suggestion);
        }

        return $this->redirectToRoute('mileage_suggestions', ['user' => $user->getId()]);
    }

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

        $this->flashSuccess($this->translator->trans('mileage.suggestion.accepted_all', ['%count%' => $count]));
        if ($locked > 0) {
            $this->flashWarning($this->translator->trans('mileage.logbook.error.locked'));
        }

        return $this->redirectToRoute('mileage_suggestions', ['user' => $user->getId()]);
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
        if (!$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }
}
