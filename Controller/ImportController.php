<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use KimaiPlugin\MileageBundle\Service\TripCsvImporter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV import with preview: upload → check → import.
 */
#[Route(path: '/mileage/import')]
#[IsGranted('mileage')]
class ImportController extends AbstractController
{
    use TargetUserTrait;

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly TripCsvImporter $importer,
        private readonly UserRepository $userRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'mileage_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $preview = null;
        $content = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mileage_import', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $file = $request->files->get('file');
            if ($file instanceof UploadedFile) {
                if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
                    $this->flashError($this->translator->trans('import.error.file'));

                    return $this->redirectToRoute('mileage_import', ['user' => $user->getId()]);
                }
                $content = (string) file_get_contents($file->getPathname());
            } else {
                $content = base64_decode((string) $request->request->get('content'), true) ?: null;
            }

            if ($content === null || \strlen($content) > self::MAX_BYTES) {
                $this->flashError($this->translator->trans('import.error.file'));

                return $this->redirectToRoute('mileage_import', ['user' => $user->getId()]);
            }

            $parsed = $this->importer->parse($content);
            $built = $this->importer->build($user, $parsed['rows']);

            if ($request->request->get('action') === 'import') {
                $result = $this->importer->import($built, $request->request->getBoolean('skip_duplicates', true));
                $this->flashSuccess($this->translator->trans('import.done', ['%imported%' => $result['imported'], '%skipped%' => $result['skipped']]));

                return $this->redirectToRoute('mileage_trips', ['user' => $user->getId()]);
            }

            $preview = ['columns' => $parsed['columns'], 'rows' => $built];
        }

        return $this->render('@Mileage/import/index.html.twig', [
            'page_setup' => new PageSetup('import.title'),
            'target_user' => $user,
            'preview' => $preview,
            'content' => $content !== null ? base64_encode($content) : null,
        ]);
    }
}
