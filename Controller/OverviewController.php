<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Entity\Customer;
use App\Form\Model\DateRange;
use App\Repository\CustomerRepository;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\CsvSafe;
use KimaiPlugin\MileageBundle\Service\RentalCostAllocator;
use KimaiPlugin\MileageBundle\Form\OverviewFilterForm;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Business trips per customer/project — for copying into an invoice by hand
 * (e.g. Invoice Ninja). Nothing is billed from here.
 */
#[Route(path: '/mileage/overview')]
#[IsGranted('mileage')]
class OverviewController extends AbstractController
{
    use TargetUserTrait;

    public function __construct(
        private readonly TripRepository $tripRepository,
        private readonly UserRepository $userRepository,
        private readonly RentalCostAllocator $allocator,
        private readonly TranslatorInterface $translator,
        private readonly MileagePages $pages,
        private readonly CustomerRepository $customerRepository,
    ) {
    }

    #[Route(path: '', name: 'mileage_overview', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        $userParam = $user === $this->getUser() ? null : $user->getId();

        // former plain parameters (?from=Y-m-d&to=Y-m-d&customer=id) still work
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from')) ?: new \DateTimeImmutable('first day of this month 00:00');
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to')) ?: new \DateTimeImmutable('last day of this month 00:00');
        $range = new DateRange();
        $range->setBegin($from);
        $range->setEnd($to);
        $customer = $request->query->getInt('customer') > 0 ? $this->customerRepository->find($request->query->getInt('customer')) : null;

        $filter = $this->container->get('form.factory')->createNamed('', OverviewFilterForm::class, ['daterange' => $range, 'customer' => $customer], [
            'action' => $this->generateUrl('mileage_overview', ['user' => $userParam]),
        ]);
        if ($request->query->has('daterange')) {
            $filter->submit(['daterange' => $request->query->get('daterange'), 'customer' => $request->query->get('customer')], false);
        }
        if ($filter->isSubmitted() && $filter->isValid()) {
            /** @var array{daterange: DateRange, customer: ?Customer} $data */
            $data = $filter->getData();
            $from = \DateTimeImmutable::createFromInterface($data['daterange']->getBegin() ?? $from)->setTime(0, 0);
            $to = \DateTimeImmutable::createFromInterface($data['daterange']->getEnd() ?? $to)->setTime(0, 0);
            $customer = $data['customer'];
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $customerId = $customer?->getId() ?? 0;

        $all = array_values(array_filter(
            $this->tripRepository->findByUserBetween($user, $from, $to),
            static fn (Trip $t) => $t->getPurpose() === TripPurpose::BUSINESS
        ));
        $allocation = $this->allocator->allocateAll($this->tripRepository->findByUserBetween($user, $from, $to));

        $groups = [];
        foreach ($all as $trip) {
            $tripCustomer = $trip->getProject()?->getCustomer();
            $id = $tripCustomer?->getId() ?? 0;
            if ($customerId > 0 && $id !== $customerId) {
                continue;
            }
            $groups[$id] ??= ['name' => $tripCustomer?->getName() ?? $this->translator->trans('mileage.overview.no_customer'), 'customer' => $tripCustomer, 'trips' => [], 'km' => 0.0, 'costs' => 0.0];
            $costs = (float) $trip->getCosts() + ($allocation[spl_object_id($trip)] ?? 0.0);
            $groups[$id]['trips'][] = ['trip' => $trip, 'costs' => $costs];
            $groups[$id]['km'] += $trip->getTotalDistanceKm();
            $groups[$id]['costs'] += $costs;
        }
        uasort($groups, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        if ($request->query->get('format') === 'csv') {
            return $this->csv($groups, $from, $to);
        }

        $exportParameters = ['user' => $userParam, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'customer' => $customerId > 0 ? $customerId : null, 'format' => 'csv'];

        return $this->render('@Mileage/overview/index.html.twig', [
            'page_setup' => $this->pages->create('mileage_overview', 'mileage.overview.title', $this->pages->dateLabel($from) . ' – ' . $this->pages->dateLabel($to), [
                'export' => $this->generateUrl('mileage_overview', $exportParameters),
            ]),
            'target_user' => $user,
            'from' => $from,
            'to' => $to,
            'customer' => $customer,
            'filter' => $filter->createView(),
            'groups' => $groups,
            'totals' => [
                'trips' => array_sum(array_map(static fn (array $g) => \count($g['trips']), $groups)),
                'km' => array_sum(array_column($groups, 'km')),
                'costs' => array_sum(array_column($groups, 'costs')),
            ],
        ]);
    }

    /**
     * @param array<int, array{name: string, customer: ?Customer, trips: list<array{trip: Trip, costs: float}>, km: float, costs: float}> $groups
     */
    private function csv(array $groups, \DateTimeImmutable $from, \DateTimeImmutable $to): Response
    {
        $t = fn (string $key) => $this->translator->trans($key);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [$t('mileage.place.customer'), $t('mileage.trip.project'), $t('mileage.trip.date'), $t('mileage.trip.start_location'), $t('mileage.trip.destination'), 'km', $t('mileage.trip.costs'), $t('mileage.trip.comment')], ';', '"', '');
        foreach ($groups as $group) {
            foreach ($group['trips'] as $row) {
                $trip = $row['trip'];
                fputcsv($handle, [
                    CsvSafe::cell($group['name']),
                    CsvSafe::cell($trip->getProject()?->getName()),
                    $trip->getDate()?->format('Y-m-d'),
                    CsvSafe::cell($trip->getStartLocation()),
                    CsvSafe::cell($trip->getDestination()),
                    number_format($trip->getTotalDistanceKm(), 1, ',', ''),
                    number_format($row['costs'], 2, ',', ''),
                    CsvSafe::cell($trip->getComment()),
                ], ';', '"', '');
            }
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="fahrten-kunden-%s-%s.csv"', $from->format('Ymd'), $to->format('Ymd')),
        ]);
    }
}
