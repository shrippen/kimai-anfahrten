<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Pdf\HtmlToPdfConverter;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Enum\TaxProfile;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\PlausibilityChecker;
use KimaiPlugin\MileageBundle\Service\TaxCalculator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Yearly overview for the tax return: EÜR (self-employed) or Anlage N (employee).
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
        private readonly PlausibilityChecker $plausibilityChecker,
        private readonly MileageConfiguration $configuration,
        private readonly HtmlToPdfConverter $pdfConverter,
    ) {
    }

    #[Route(path: '/{year}', name: 'mileage_tax_report', defaults: ['year' => null], requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function index(Request $request, ?int $year = null): Response
    {
        // Default to the previous year — that is the one usually being filed.
        $year ??= (int) date('Y') - 1;
        $user = $this->getTargetUser($request, $this->userRepository);
        $trips = $this->tripRepository->findByUserAndYear($user, $year);

        $profile = TaxProfile::tryFrom((string) $request->query->get('profile')) ?? $this->configuration->getTaxProfile($user);
        $summary = $this->taxCalculator->summarize($trips, $year, $profile, $user->getDateTimezone());
        $format = (string) $request->query->get('format');

        $context = [
            'page_setup' => new PageSetup('mileage.menu.tax'),
            'year' => $year,
            'target_user' => $user,
            'trip_count' => \count($trips),
            'summary' => $summary,
            'profile' => $profile,
            'profiles' => TaxProfile::cases(),
            'findings' => array_merge(
                $this->plausibilityChecker->check($user, $year, $trips),
                $this->plausibilityChecker->checkBookkeeping($user, $year, $trips)
            ),
            'print' => $format === 'print' || $format === 'pdf',
        ];

        if ($format === 'pdf') {
            $html = $this->renderView('@Mileage/report/tax.html.twig', $context);
            $pdf = $this->pdfConverter->convertToPdf($html, ['format' => 'A4']);

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => \sprintf('attachment; filename="fahrtkosten-%d-%s.pdf"', $year, preg_replace('/[^A-Za-z0-9_-]/', '', $user->getUserIdentifier())),
            ]);
        }

        return $this->render('@Mileage/report/tax.html.twig', $context);
    }
}
