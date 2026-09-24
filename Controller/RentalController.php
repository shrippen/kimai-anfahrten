<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Form\RentalForm;
use KimaiPlugin\MileageBundle\Repository\AttachmentRepository;
use KimaiPlugin\MileageBundle\Repository\RentalRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\RentalCostAllocator;
use KimaiPlugin\MileageBundle\Service\TripService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Car rentals: period, costs, receipts; rental-car trips in the period share the costs.
 */
#[Route(path: '/mileage/rentals')]
#[IsGranted('mileage')]
class RentalController extends AbstractController
{
    use TargetUserTrait;

    public function __construct(
        private readonly RentalRepository $rentalRepository,
        private readonly TripRepository $tripRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly UserRepository $userRepository,
        private readonly RentalCostAllocator $allocator,
        private readonly TripService $tripService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/{year}', name: 'mileage_rentals', defaults: ['year' => null], requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function index(Request $request, ?int $year = null): Response
    {
        $year ??= (int) date('Y');
        $user = $this->getTargetUser($request, $this->userRepository);

        return $this->render('@Mileage/rental/index.html.twig', [
            'page_setup' => new PageSetup('rental.list'),
            'target_user' => $user,
            'year' => $year,
            'rentals' => $this->rentalRepository->findByUserAndYear($user, $year),
            'can_edit' => $this->canEditTripsOf($user),
        ]);
    }

    #[Route(path: '/create', name: 'mileage_rental_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $rental = (new Rental())->setUser($user)
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today'));

        return $this->handleForm($request, $rental);
    }

    #[Route(path: '/{id}', name: 'mileage_rental_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Rental $rental): Response
    {
        $user = $rental->getUser();
        if (!$this->canViewTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $trips = $this->tripRepository->findByRental($rental);

        return $this->render('@Mileage/rental/show.html.twig', [
            'page_setup' => new PageSetup('rental.label'),
            'rental' => $rental,
            'target_user' => $user,
            'trips' => $trips,
            'allocation' => $this->allocator->allocate($rental, $trips),
            'attachments' => $this->attachmentRepository->findByRental($rental),
            'can_edit' => $this->canEditTripsOf($user),
        ]);
    }

    #[Route(path: '/{id}/edit', name: 'mileage_rental_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Rental $rental): Response
    {
        if (!$this->canEditTripsOf($rental->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $rental);
    }

    #[Route(path: '/{id}/delete', name: 'mileage_rental_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Rental $rental): Response
    {
        if (!$this->canDeleteTripsOf($rental->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_rental_delete' . $rental->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $userId = $rental->getUser()?->getId();
        $this->rentalRepository->remove($rental);
        $this->flashSuccess('action.delete.success');

        return $this->redirectToRoute('mileage_rentals', ['user' => $userId]);
    }

    private function handleForm(Request $request, Rental $rental): Response
    {
        $form = $this->createForm(RentalForm::class, $rental);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->rentalRepository->save($rental);
            $linked = $this->tripService->linkRentalTrips($rental);
            $this->entityManager->flush();
            $this->flashSuccess($linked > 0 ? $this->translator->trans('rental.linked', ['%count%' => $linked]) : 'action.update.success');

            return $this->redirectToRoute('mileage_rental_show', ['id' => $rental->getId()]);
        }

        return $this->render('@Mileage/rental/edit.html.twig', [
            'page_setup' => new PageSetup($rental->getId() === null ? 'rental.create' : 'rental.edit'),
            'rental' => $rental,
            'form' => $form->createView(),
            'target_user' => $rental->getUser(),
        ]);
    }
}
