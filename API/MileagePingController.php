<?php

namespace KimaiPlugin\MileageBundle\API;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Repository\MonthLockRepository;
use KimaiPlugin\MileageBundle\Repository\VehicleRepository;
use KimaiPlugin\MileageBundle\Service\ApiInfo;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/mileage/ping: whether the plugin is installed, which API it serves and what the token owner may do.
 * Needs only API access (not the "mileage" permission), so clients can tell "not installed" (404) from
 * "not allowed" (permissions.view = false). Never contains the Dawarich URL or key.
 */
#[Route(path: '/mileage')]
#[IsGranted('API')]
class MileagePingController extends AbstractController
{
    public function __construct(
        private readonly MileageConfiguration $configuration,
        private readonly VehicleRepository $vehicleRepository,
        private readonly MonthLockRepository $lockRepository,
    ) {
    }

    #[Route(path: '/ping', name: 'api_mileage_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $view = $this->isGranted('mileage');
        $permissions = [
            'view' => $view,
            'editOwn' => $view && $this->isGranted('edit_own_mileage'),
            'deleteOwn' => $view && $this->isGranted('delete_own_mileage'),
            'editLocked' => $view && $this->isGranted('edit_locked_mileage'),
            'viewTeam' => $view && $this->isGranted('view_team_mileage'),
            'viewOther' => $view && $this->isGranted('view_other_mileage'),
            'editOther' => $view && $this->isGranted('edit_other_mileage'),
        ];
        if (!$view) {
            return $this->json(ApiInfo::ping($permissions, null, []));
        }

        $today = new \DateTimeImmutable('today', $user->getDateTimezone());
        $year = (int) $today->format('Y');
        $profile = [
            'commuteKm' => $this->configuration->getCommuteKm($user),
            'defaultVehicle' => $this->configuration->getDefaultVehicle($user)->value,
            'defaultVehicleId' => $this->vehicleRepository->findDefaultFor($user, $today)?->getId(),
            'dawarichConfigured' => $this->configuration->isDawarichConfigured($user),
        ];
        $locks = array_merge($this->lockRepository->findByUserAndYear($user, $year - 1), $this->lockRepository->findByUserAndYear($user, $year));

        return $this->json(ApiInfo::ping($permissions, $profile, ApiInfo::lockedMonths($locks)));
    }
}
