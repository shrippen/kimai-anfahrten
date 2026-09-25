<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Form\PlaceForm;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
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

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly UserRepository $userRepository,
        private readonly SuggestionService $suggestionService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'mileage_places', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);

        return $this->render('@Mileage/place/index.html.twig', [
            'page_setup' => new PageSetup('mileage.place.list'),
            'target_user' => $user,
            'places' => $this->placeRepository->findByUser($user),
            'can_edit' => $this->canEditTripsOf($user),
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

        return $this->handleForm($request, $place);
    }

    #[Route(path: '/{id}/edit', name: 'mileage_place_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Place $place): Response
    {
        if (!$this->canEditTripsOf($place->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $place);
    }

    #[Route(path: '/{id}/delete', name: 'mileage_place_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Place $place): Response
    {
        if (!$this->canEditTripsOf($place->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_place_delete' . $place->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $userId = $place->getUser()?->getId();
        $this->placeRepository->remove($place);
        $this->flashSuccess('action.delete.success');

        return $this->redirectToRoute('mileage_places', ['user' => $userId]);
    }

    #[Route(path: '/import', name: 'mileage_place_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_place_import', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        try {
            $count = $this->suggestionService->importAreas($user);
            $this->flashSuccess($this->translator->trans('mileage.place.imported', ['%count%' => $count]));
        } catch (DawarichException $e) {
            $this->flashError($this->translator->trans($e->getMessage(), $e->getParameters()));
        }

        return $this->redirectToRoute('mileage_places', ['user' => $user->getId()]);
    }

    private function handleForm(Request $request, Place $place): Response
    {
        $form = $this->createForm(PlaceForm::class, $place);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->placeRepository->save($place);
            $this->flashSuccess('action.update.success');

            return $this->redirectToRoute('mileage_places', ['user' => $place->getUser()?->getId()]);
        }

        return $this->render('@Mileage/place/edit.html.twig', [
            'page_setup' => new PageSetup($place->getId() === null ? 'mileage.place.create' : 'mileage.place.edit'),
            'place' => $place,
            'form' => $form->createView(),
            'target_user' => $place->getUser(),
        ]);
    }
}
