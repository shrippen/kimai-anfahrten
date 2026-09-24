<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Yearly overview for the tax return (Anlage N: Entfernungspauschale + Reisekosten).
 */
#[Route(path: '/mileage/tax')]
#[IsGranted('mileage')]
class TaxReportController extends AbstractController
{
    use TargetUserTrait;

    public function __construct(
        private readonly TripRepository $tripRepository,
        private readonly UserRepository $userRepository,
        private readonly TaxCalculator $taxCalculator,
    ) {
    }

    #[Route(path: '/{year}', name: 'mileage_tax_report', defaults: ['year' => null], requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function index(Request $request, ?int $year = null): Response
    {
        // Default to the previous year — that is the one usually being filed.
        $year ??= (int) date('Y') - 1;
        $user = $this->getTargetUser($request, $this->userRepository);
        $trips = $this->tripRepository->findByUserAndYear($user, $year);

        return $this->render('@Mileage/report/tax.html.twig', [
            'page_setup' => new PageSetup('menu.mileage_tax'),
            'year' => $year,
            'target_user' => $user,
            'trip_count' => \count($trips),
            'summary' => $this->taxCalculator->summarize($trips),
        ]);
    }
}
