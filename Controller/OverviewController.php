<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\CsvSafe;
use KimaiPlugin\MileageBundle\Service\RentalCostAllocator;
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
    ) {
    }

    #[Route(path: '', name: 'mileage_overview', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from')) ?: new \DateTimeImmutable('first day of this month');
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to')) ?: new \DateTimeImmutable('last day of this month');
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $customerId = $request->query->getInt('customer');

        $all = array_values(array_filter(
            $this->tripRepository->findByUserBetween($user, $from, $to),
            static fn (Trip $t) => $t->getPurpose() === TripPurpose::BUSINESS
        ));
        $allocation = $this->allocator->allocateAll($this->tripRepository->findByUserBetween($user, $from, $to));

        $customers = [];
        $groups = [];
        foreach ($all as $trip) {
            $customer = $trip->getProject()?->getCustomer();
            $id = $customer?->getId() ?? 0;
            $customers[$id] = $customer?->getName() ?? $this->translator->trans('overview.no_customer');
            if ($customerId > 0 && $id !== $customerId) {
                continue;
            }
            $groups[$id] ??= ['name' => $customers[$id], 'trips' => [], 'km' => 0.0, 'costs' => 0.0];
            $costs = (float) $trip->getCosts() + ($allocation[spl_object_id($trip)] ?? 0.0);
            $groups[$id]['trips'][] = ['trip' => $trip, 'costs' => $costs];
            $groups[$id]['km'] += $trip->getTotalDistanceKm();
            $groups[$id]['costs'] += $costs;
        }
        asort($customers);
        uasort($groups, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        if ($request->query->get('format') === 'csv') {
            return $this->csv($groups, $from, $to);
        }

        return $this->render('@Mileage/overview/index.html.twig', [
            'page_setup' => new PageSetup('overview.title'),
            'target_user' => $user,
            'from' => $from,
            'to' => $to,
            'customer_id' => $customerId,
            'customers' => $customers,
            'groups' => $groups,
        ]);
    }

    /**
     * @param array<int, array{name: string, trips: list<array{trip: Trip, costs: float}>, km: float, costs: float}> $groups
     */
    private function csv(array $groups, \DateTimeImmutable $from, \DateTimeImmutable $to): Response
    {
        $t = fn (string $key) => $this->translator->trans($key);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [$t('place.customer'), $t('trip.project'), $t('trip.date'), $t('trip.start_location'), $t('trip.destination'), 'km', $t('trip.costs'), $t('trip.comment')], ';', '"', '');
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
