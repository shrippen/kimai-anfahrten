<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Pdf\HtmlToPdfConverter;
use App\Repository\UserRepository;
use KimaiPlugin\MileageBundle\Enum\TaxProfile;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use KimaiPlugin\MileageBundle\Service\OvernightVisitChecker;
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
        private readonly MileagePages $pages,
        private readonly OvernightVisitChecker $overnightChecker,
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
        // The evaluation checks overnight stays against Dawarich visits (the other summaries do not ask Dawarich);
        // that uses the user's Dawarich credentials, so only with the right to edit the trips.
        $confirm = $this->canEditTripsOf($user) ? $this->overnightChecker->forUser($user) : null;
        $summary = $this->taxCalculator->summarize($trips, $year, $profile, $user->getDateTimezone(), $confirm);
        $format = (string) $request->query->get('format');

        $userParam = $user === $this->getUser() ? null : $user->getId();
        $profiles = [];
        foreach (TaxProfile::cases() as $case) {
            $profiles[$case->value] = $case->label();
        }
        $currentYear = (int) date('Y');

        $context = [
            'page_setup' => $this->pages->create('mileage_tax', 'mileage.menu.tax', (string) $year, [
                'user' => $userParam,
                'year' => $year,
                'profile' => $profile->value,
                'profiles' => $profiles,
            ]),
            'period' => [
                'prev' => $this->generateUrl('mileage_tax_report', ['year' => $year - 1, 'user' => $userParam, 'profile' => $profile->value]),
                'next' => $this->generateUrl('mileage_tax_report', ['year' => $year + 1, 'user' => $userParam, 'profile' => $profile->value]),
                'today' => $year === $currentYear ? null : $this->generateUrl('mileage_tax_report', ['year' => $currentYear, 'user' => $userParam, 'profile' => $profile->value]),
            ],
            'pdf' => $format === 'pdf',
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
