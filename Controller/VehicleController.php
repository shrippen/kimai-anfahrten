<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Repository\Query\BaseQuery;
use App\Utils\DataTable;
use App\Utils\Pagination;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Form\VehicleForm;
use KimaiPlugin\MileageBundle\Repository\VehicleRepository;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use Pagerfanta\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/mileage/vehicles')]
#[IsGranted('mileage')]
class VehicleController extends AbstractController
{
    use TargetUserTrait;
    use MileageUiTrait;

    public function __construct(
        private readonly VehicleRepository $vehicleRepository,
        private readonly UserRepository $userRepository,
        private readonly MileagePages $pages,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'mileage_vehicles', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $userParam = $user === $this->getUser() ? null : $user->getId();
        $canEdit = $this->canEditTripsOf($user);

        $table = new DataTable('mileage_vehicles', new BaseQuery());
        $table->setPagination(new Pagination(new ArrayAdapter($this->vehicleRepository->findByUser($user))));
        $table->setSticky(false);
        $table->addColumn('name', ['class' => 'alwaysVisible', 'orderBy' => false, 'title' => 'mileage.vehicle.name']);
        $table->addColumn('type', ['class' => 'd-none d-md-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.vehicle']);
        $table->addColumn('plate', ['class' => 'd-none d-sm-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.license_plate']);
        $table->addColumn('period', ['class' => 'd-none d-lg-table-cell', 'orderBy' => false, 'title' => 'mileage.vehicle.period']);
        $table->addColumn('private_use', ['class' => 'd-none d-xl-table-cell', 'orderBy' => false, 'title' => 'mileage.vehicle.private_use']);
        $table->addColumn('active', ['class' => 'text-center w-min', 'orderBy' => false, 'title' => 'mileage.vehicle.active']);
        $table->addColumn('actions', ['class' => 'actions alwaysVisible']);

        $page = $this->pages->create('mileage_vehicles', 'mileage.vehicle.list', null, ['user' => $userParam, 'can_edit' => $canEdit]);
        $page->setDataTable($table);

        return $this->render('@Mileage/vehicle/index.html.twig', [
            'page_setup' => $page,
            'dataTable' => $table,
            'target_user' => $user,
            'user_param' => $userParam,
            'can_edit' => $canEdit,
            'can_delete' => $this->canDeleteTripsOf($user),
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

        return $this->handleForm($request, (new Vehicle())->setUser($user), $this->generateUrl('mileage_vehicle_create', ['user' => $user === $this->getUser() ? null : $user->getId()]));
    }

    #[Route(path: '/{id}/edit', name: 'mileage_vehicle_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Vehicle $vehicle): Response
    {
        if (!$this->canEditTripsOf($vehicle->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $vehicle, $this->generateUrl('mileage_vehicle_edit', ['id' => $vehicle->getId()]));
    }

    #[Route(path: '/{id}/delete', name: 'mileage_vehicle_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, Vehicle $vehicle): Response
    {
        if (!$this->canDeleteTripsOf($vehicle->getUser())) {
            throw $this->createAccessDeniedException();
        }
        $tokenId = 'mileage_vehicle_delete' . $vehicle->getId();
        $list = $this->listParameters($vehicle);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, $tokenId);
            $this->vehicleRepository->remove($vehicle);
            $this->flashSuccess('action.delete.success');

            return $this->kpuFormSuccess($request, 'mileage_vehicles', $list, true);
        }

        return $this->render('@Mileage/_delete.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.vehicle.list', $this->translator->trans('mileage.vehicle.delete'), ['back' => $this->generateUrl('mileage_vehicles', $list)]),
            'form' => $this->createPlainForm($tokenId, $this->generateUrl('mileage_vehicle_delete', ['id' => $vehicle->getId()]), true)->createView(),
            'item_name' => $vehicle->getLabel(),
            'message' => $this->translator->trans('mileage.vehicle.delete_message'),
            'back' => $this->generateUrl('mileage_vehicles', $list),
        ]);
    }

    private function handleForm(Request $request, Vehicle $vehicle, string $action): Response
    {
        $form = $this->createForm(VehicleForm::class, $vehicle, [
            'action' => $action,
            'attr' => ['data-form-event' => 'kpu.reload'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->vehicleRepository->save($vehicle);
            $this->flashSuccess('action.update.success');

            return $this->kpuFormSuccess($request, 'mileage_vehicles', $this->listParameters($vehicle), true);
        }

        $title = $vehicle->getId() === null ? 'mileage.vehicle.create' : 'mileage.vehicle.edit';
        $back = $this->generateUrl('mileage_vehicles', $this->listParameters($vehicle));

        return $this->render('@Mileage/_form.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.vehicle.list', $this->translator->trans($title), ['back' => $back]),
            'form' => $form->createView(),
            'title' => $this->translator->trans($title),
            'intro' => $vehicle->getId() === null ? $this->translator->trans('mileage.vehicle.help') : null,
            'back' => $back,
            'target_user' => $vehicle->getUser(),
        ]);
    }

    /**
     * @return array{user: int|null}
     */
    private function listParameters(Vehicle $vehicle): array
    {
        $user = $vehicle->getUser();

        return ['user' => $user === null || $user === $this->getUser() ? null : $user->getId()];
    }
}
