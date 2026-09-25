<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Repository\Query\BaseQuery;
use App\Utils\DataTable;
use App\Utils\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Form\RentalForm;
use KimaiPlugin\MileageBundle\Repository\AttachmentRepository;
use KimaiPlugin\MileageBundle\Repository\RentalRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\AttachmentStorage;
use KimaiPlugin\MileageBundle\Service\RentalCostAllocator;
use KimaiPlugin\MileageBundle\Service\TripService;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use Pagerfanta\Adapter\ArrayAdapter;
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
    use MileageUiTrait;

    public function __construct(
        private readonly RentalRepository $rentalRepository,
        private readonly TripRepository $tripRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly UserRepository $userRepository,
        private readonly RentalCostAllocator $allocator,
        private readonly TripService $tripService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly MileagePages $pages,
    ) {
    }

    #[Route(path: '/{year}', name: 'mileage_rentals', defaults: ['year' => null], requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function index(Request $request, ?int $year = null): Response
    {
        $year ??= (int) date('Y');
        $user = $this->getTargetUser($request, $this->userRepository);
        $userParam = $user === $this->getUser() ? null : $user->getId();
        $rentals = $this->rentalRepository->findByUserAndYear($user, $year);
        $canEdit = $this->canEditTripsOf($user);

        $table = new DataTable('mileage_rentals', new BaseQuery());
        $table->setPagination(new Pagination(new ArrayAdapter($rentals)));
        $table->setSticky(false);
        $table->addColumn('provider', ['class' => 'alwaysVisible', 'orderBy' => false, 'title' => 'mileage.rental.provider']);
        $table->addColumn('period', ['class' => 'd-none d-sm-table-cell', 'orderBy' => false, 'title' => 'mileage.rental.period']);
        $table->addColumn('plate', ['class' => 'd-none d-md-table-cell', 'orderBy' => false, 'title' => 'mileage.trip.license_plate']);
        $table->addColumn('total', ['class' => 'text-end w-min text-nowrap', 'orderBy' => false, 'title' => 'mileage.rental.total']);
        $table->addColumn('actions', ['class' => 'actions alwaysVisible']);

        $page = $this->pages->create('mileage_rentals', 'mileage.rental.list', (string) $year, ['user' => $userParam, 'can_edit' => $canEdit]);
        $page->setDataTable($table);

        $currentYear = (int) date('Y');

        return $this->render('@Mileage/rental/index.html.twig', [
            'page_setup' => $page,
            'dataTable' => $table,
            'target_user' => $user,
            'user_param' => $userParam,
            'year' => $year,
            'total' => array_sum(array_map(static fn (Rental $r) => $r->getTotalCosts(), $rentals)),
            'can_edit' => $canEdit,
            'can_delete' => $this->canDeleteTripsOf($user),
            'period' => [
                'prev' => $this->generateUrl('mileage_rentals', ['year' => $year - 1, 'user' => $userParam]),
                'next' => $this->generateUrl('mileage_rentals', ['year' => $year + 1, 'user' => $userParam]),
                'today' => $year === $currentYear ? null : $this->generateUrl('mileage_rentals', ['year' => $currentYear, 'user' => $userParam]),
            ],
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

        return $this->handleForm($request, $rental, $this->generateUrl('mileage_rental_create', ['user' => $user === $this->getUser() ? null : $user->getId()]));
    }

    #[Route(path: '/{id}', name: 'mileage_rental_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Rental $rental): Response
    {
        $user = $rental->getUser();
        if (!$this->canViewTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $trips = $this->tripRepository->findByRental($rental);
        $canEdit = $this->canEditTripsOf($user);
        $back = $this->generateUrl('mileage_rentals', ['year' => $rental->getStartDate()?->format('Y')] + $this->listParameters($rental));

        return $this->render('@Mileage/rental/show.html.twig', [
            'page_setup' => $this->pages->create('mileage_rental_page', 'mileage.rental.list', $rental->getProvider(), [
                'rental' => $rental,
                'back' => $back,
                'can_edit' => $canEdit,
                'can_delete' => $this->canDeleteTripsOf($user),
            ]),
            'rental' => $rental,
            'target_user' => $user,
            'trips' => $trips,
            'allocation' => $this->allocator->allocate($rental, $trips),
            'attachments' => $this->attachmentRepository->findByRental($rental),
            'attachment_form' => $canEdit ? $this->createAttachmentForm($this->generateUrl('mileage_attachment_rental', ['id' => $rental->getId()]))->createView() : null,
            'can_edit' => $canEdit,
        ]);
    }

    #[Route(path: '/{id}/edit', name: 'mileage_rental_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Rental $rental): Response
    {
        if (!$this->canEditTripsOf($rental->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->handleForm($request, $rental, $this->generateUrl('mileage_rental_edit', ['id' => $rental->getId()]));
    }

    #[Route(path: '/{id}/delete', name: 'mileage_rental_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, Rental $rental): Response
    {
        if (!$this->canDeleteTripsOf($rental->getUser())) {
            throw $this->createAccessDeniedException();
        }
        $tokenId = 'mileage_rental_delete' . $rental->getId();
        $list = ['year' => $rental->getStartDate()?->format('Y')] + $this->listParameters($rental);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, $tokenId);
            // the rows go with the rental (FK cascade), the files have to be removed here
            foreach ($this->attachmentRepository->findByRental($rental) as $attachment) {
                $this->attachmentStorage->delete($attachment);
            }
            $this->rentalRepository->remove($rental);
            $this->flashSuccess('action.delete.success');

            return $this->kpuFormSuccess($request, 'mileage_rentals', $list);
        }

        return $this->render('@Mileage/_delete.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.rental.list', $this->translator->trans('mileage.rental.delete'), ['back' => $this->generateUrl('mileage_rentals', $list)]),
            'form' => $this->createPlainForm($tokenId, $this->generateUrl('mileage_rental_delete', ['id' => $rental->getId()]))->createView(),
            'item_name' => $rental->getLabel(),
            'message' => $this->translator->trans('mileage.rental.delete_message'),
            'back' => $this->generateUrl('mileage_rentals', $list),
        ]);
    }

    private function handleForm(Request $request, Rental $rental, string $action): Response
    {
        $form = $this->createForm(RentalForm::class, $rental, ['action' => $action]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->rentalRepository->save($rental);
            $linked = $this->tripService->linkRentalTrips($rental);
            $this->entityManager->flush();
            if ($linked > 0) {
                $this->addFlash('kpu_result', $this->translator->trans('mileage.rental.linked', ['%count%' => $linked]));
            } else {
                $this->flashSuccess('action.update.success');
            }

            // the rental page shows costs, shares and linked trips
            return $this->kpuFormSuccess($request, 'mileage_rental_show', ['id' => $rental->getId()]);
        }

        $title = $rental->getId() === null ? 'mileage.rental.create' : 'mileage.rental.edit';
        $back = $rental->getId() !== null
            ? $this->generateUrl('mileage_rental_show', ['id' => $rental->getId()])
            : $this->generateUrl('mileage_rentals', $this->listParameters($rental));

        return $this->render('@Mileage/_form.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.rental.list', $this->translator->trans($title), ['back' => $back]),
            'form' => $form->createView(),
            'title' => $this->translator->trans($title),
            'intro' => $rental->getId() === null ? $this->translator->trans('mileage.rental.help') : null,
            'back' => $back,
            'target_user' => $rental->getUser(),
        ]);
    }

    /**
     * @return array{user: int|null}
     */
    private function listParameters(Rental $rental): array
    {
        $user = $rental->getUser();

        return ['user' => $user === null || $user === $this->getUser() ? null : $user->getId()];
    }
}
