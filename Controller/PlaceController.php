<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Repository\Query\BaseQuery;
use App\Utils\DataTable;
use App\Utils\Pagination;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Form\PlaceForm;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use Pagerfanta\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Named places (home, place of work, customers) used to label detected trips.
 */
#[Route(path: '/mileage/places')]
#[IsGranted('mileage')]
class PlaceController extends AbstractController
{
    use TargetUserTrait;
    use MileageUiTrait;

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly UserRepository $userRepository,
        private readonly SuggestionService $suggestionService,
        private readonly TranslatorInterface $translator,
        private readonly MileagePages $pages,
        private readonly MileageConfiguration $configuration,
    ) {
    }

    #[Route(path: '', name: 'mileage_places', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $userParam = $user === $this->getUser() ? null : $user->getId();
        $places = $this->placeRepository->findByUser($user);
        $canEdit = $this->canEditTripsOf($user);

        $table = new DataTable('mileage_places', new BaseQuery());
        $table->setPagination(new Pagination(new ArrayAdapter($places)));
        $table->setSticky(false);
        $table->addColumn('name', ['class' => 'alwaysVisible', 'orderBy' => false, 'title' => 'mileage.place.name']);
        $table->addColumn('type', ['class' => 'd-none d-sm-table-cell', 'orderBy' => false, 'title' => 'mileage.place.type']);
        $table->addColumn('address', ['class' => 'd-none d-lg-table-cell', 'orderBy' => false, 'title' => 'mileage.place.address']);
        $table->addColumn('radius', ['class' => 'd-none d-md-table-cell text-end w-min text-nowrap', 'orderBy' => false, 'title' => 'mileage.place.radius']);
        $table->addColumn('customer', ['class' => 'd-none d-md-table-cell', 'orderBy' => false, 'title' => 'mileage.place.customer']);
        $table->addColumn('actions', ['class' => 'actions alwaysVisible']);

        $page = $this->pages->create('mileage_places', 'mileage.place.list', null, [
            'user' => $userParam,
            'can_edit' => $canEdit,
            'dawarich' => $this->configuration->isDawarichConfigured($user),
        ]);
        $page->setDataTable($table);

        return $this->render('@Mileage/place/index.html.twig', [
            'page_setup' => $page,
            'dataTable' => $table,
            'target_user' => $user,
            'user_param' => $userParam,
            'places' => $places,
            'can_edit' => $canEdit,
        ]);
    }

    #[Route(path: '/create', name: 'mileage_place_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $place = (new Place())->setUser($user);
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');
        if (is_numeric($lat) && is_numeric($lon)) {
            $place->setLatitude((float) $lat)->setLongitude((float) $lon);
        }
        if (\is_string($name = $request->query->get('name'))) {
            $place->setName(mb_substr($name, 0, 100));
        }

        $userParam = $user === $this->getUser() ? null : $user->getId();

        return $this->handleForm($request, $place, $this->generateUrl('mileage_place_create', ['user' => $userParam, 'lat' => $lat, 'lon' => $lon]));
    }

    #[Route(path: '/{id}/edit', name: 'mileage_place_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Place $place): Response
    {
        if (!$this->canEditTripsOf($place->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $place, $this->generateUrl('mileage_place_edit', ['id' => $place->getId()]));
    }

    #[Route(path: '/{id}/delete', name: 'mileage_place_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, Place $place): Response
    {
        if (!$this->canEditTripsOf($place->getUser())) {
            throw $this->createAccessDeniedException();
        }
        $tokenId = 'mileage_place_delete' . $place->getId();
        $list = $this->listParameters($place);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, $tokenId);
            $this->placeRepository->remove($place);
            $this->flashSuccess('action.delete.success');

            return $this->kpuFormSuccess($request, 'mileage_places', $list, true);
        }

        return $this->render('@Mileage/_delete.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.place.list', $this->translator->trans('mileage.place.delete'), ['back' => $this->generateUrl('mileage_places', $list)]),
            'form' => $this->createPlainForm($tokenId, $this->generateUrl('mileage_place_delete', ['id' => $place->getId()]), true)->createView(),
            'item_name' => $place->getName(),
            'message' => $this->translator->trans('mileage.place.delete_message'),
            'back' => $this->generateUrl('mileage_places', $list),
        ]);
    }

    #[Route(path: '/import', name: 'mileage_place_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
        $this->assertCsrf($request, 'mileage_place_import');
        $list = ['user' => $user === $this->getUser() ? null : $user->getId()];

        try {
            $count = $this->suggestionService->importAreas($user);

            return $this->actionResult($request, $this->translator->trans('mileage.place.imported', ['%count%' => $count]), null, 'mileage_places', $list);
        } catch (DawarichException $e) {
            return $this->actionResult($request, $this->translator->trans($e->getMessage(), $e->getParameters()), null, 'mileage_places', $list, 502);
        }
    }

    private function handleForm(Request $request, Place $place, string $action): Response
    {
        $form = $this->createForm(PlaceForm::class, $place, [
            'action' => $action,
            'attr' => ['data-form-event' => 'kpu.reload'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->placeRepository->save($place);
            $this->flashSuccess('action.update.success');

            // keep the URL: the form opens from the places list and from the detected trips
            return $this->kpuFormSuccess($request, 'mileage_places', $this->listParameters($place), true);
        }

        $title = $place->getId() === null ? 'mileage.place.create' : 'mileage.place.edit';
        $back = $this->generateUrl('mileage_places', $this->listParameters($place));

        return $this->render('@Mileage/_form.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.place.list', $this->translator->trans($title), ['back' => $back]),
            'form' => $form->createView(),
            'title' => $this->translator->trans($title),
            'intro' => $this->translator->trans('mileage.place.map_help'),
            'back' => $back,
            'target_user' => $place->getUser(),
        ]);
    }

    /**
     * @return array{user: int|null}
     */
    private function listParameters(Place $place): array
    {
        $user = $place->getUser();

        return ['user' => $user === null || $user === $this->getUser() ? null : $user->getId()];
    }
}
