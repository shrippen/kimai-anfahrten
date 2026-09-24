<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Form\VehicleForm;
use KimaiPlugin\MileageBundle\Repository\VehicleRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/mileage/vehicles')]
#[IsGranted('mileage')]
class VehicleController extends AbstractController
{
    use TargetUserTrait;

    public function __construct(
        private readonly VehicleRepository $vehicleRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route(path: '', name: 'mileage_vehicles', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);

        return $this->render('@Mileage/vehicle/index.html.twig', [
            'page_setup' => new PageSetup('vehicle.list'),
            'target_user' => $user,
            'vehicles' => $this->vehicleRepository->findByUser($user),
            'can_edit' => $this->canEditTripsOf($user),
            'year' => (int) date('Y'),
        ]);
    }

    #[Route(path: '/create', name: 'mileage_vehicle_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, (new Vehicle())->setUser($user));
    }

    #[Route(path: '/{id}/edit', name: 'mileage_vehicle_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Vehicle $vehicle): Response
    {
        if (!$this->canEditTripsOf($vehicle->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $vehicle);
    }

    #[Route(path: '/{id}/delete', name: 'mileage_vehicle_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Vehicle $vehicle): Response
    {
        if (!$this->canDeleteTripsOf($vehicle->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_vehicle_delete' . $vehicle->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $userId = $vehicle->getUser()?->getId();
        $this->vehicleRepository->remove($vehicle);
        $this->flashSuccess('action.delete.success');

        return $this->redirectToRoute('mileage_vehicles', ['user' => $userId]);
    }

    private function handleForm(Request $request, Vehicle $vehicle): Response
    {
        $form = $this->createForm(VehicleForm::class, $vehicle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->vehicleRepository->save($vehicle);
            $this->flashSuccess('action.update.success');

            return $this->redirectToRoute('mileage_vehicles', ['user' => $vehicle->getUser()?->getId()]);
        }

        return $this->render('@Mileage/vehicle/edit.html.twig', [
            'page_setup' => new PageSetup($vehicle->getId() === null ? 'vehicle.create' : 'vehicle.edit'),
            'vehicle' => $vehicle,
            'form' => $form->createView(),
            'target_user' => $vehicle->getUser(),
        ]);
    }
}
