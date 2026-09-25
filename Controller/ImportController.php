<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Repository\UserRepository;
use KimaiPlugin\MileageBundle\Service\MileagePages;
use KimaiPlugin\MileageBundle\Service\TripCsvImporter;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
        private readonly MileagePages $pages,
    ) {
    }

    #[Route(path: '', name: 'mileage_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getTargetUser($request, $this->userRepository);
        if (!$this->canEditTripsOf($user)) {
            throw $this->createAccessDeniedException();
        }

        $userParam = $user === $this->getUser() ? null : $user->getId();
        $preview = null;
        $content = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mileage_import', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $file = $request->files->get('file');
            if ($file instanceof UploadedFile) {
                if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
                    $this->flashError($this->translator->trans('mileage.import.error.file'));

                    return $this->redirectToRoute('mileage_import', ['user' => $userParam]);
                }
                $content = (string) file_get_contents($file->getPathname());
            } else {
                $content = base64_decode((string) $request->request->get('content'), true) ?: null;
            }

            if ($content === null || \strlen($content) > self::MAX_BYTES) {
                $this->flashError($this->translator->trans('mileage.import.error.file'));

                return $this->redirectToRoute('mileage_import', ['user' => $userParam]);
            }

            $parsed = $this->importer->parse($content);
            $built = $this->importer->build($user, $parsed['rows'], $this->isGranted('edit_locked_mileage'));

            if ($request->request->get('action') === 'import') {
                // unchecked checkbox = not sent = import duplicates too
                $result = $this->importer->import($built, $request->request->getBoolean('skip_duplicates'));
                $this->addFlash('kpu_result', $this->translator->trans('mileage.import.done', ['%imported%' => $result['imported'], '%skipped%' => $result['skipped']]));

                return $this->redirectToRoute('mileage_trips', ['user' => $userParam]);
            }

            $preview = ['columns' => $parsed['columns'], 'rows' => $built];
        }

        $action = $this->generateUrl('mileage_import', ['user' => $userParam]);
        $back = $this->generateUrl('mileage_trips', ['user' => $userParam]);

        // Symfony forms for Kimai's form rendering; the field names stay those the code above reads
        $factory = $this->container->get('form.factory');
        $options = ['action' => $action, 'method' => 'POST', 'csrf_field_name' => '_token', 'csrf_token_id' => 'mileage_import'];
        $upload = $factory->createNamed('', FormType::class, ['action' => 'preview'], $options)
            ->add('file', FileType::class, [
                'label' => 'mileage.import.file',
                'help' => 'mileage.import.columns',
                'attr' => ['accept' => '.csv,text/csv,text/plain'],
            ])
            ->add('action', HiddenType::class);
        $run = null;
        if ($preview !== null && $content !== null) {
            $run = $factory->createNamed('', FormType::class, ['content' => base64_encode($content), 'action' => 'import', 'skip_duplicates' => true], $options)
                ->add('content', HiddenType::class)
                ->add('action', HiddenType::class)
                ->add('skip_duplicates', CheckboxType::class, ['label' => 'mileage.import.skip_duplicates', 'required' => false]);
        }

        return $this->render('@Mileage/import/index.html.twig', [
            'page_setup' => $this->pages->create('mileage_form', 'mileage.menu', $this->translator->trans('mileage.import.title'), ['back' => $back]),
            'target_user' => $user,
            'preview' => $preview,
            'upload_form' => $upload->createView(),
            'run_form' => $run?->createView(),
            'back' => $back,
        ]);
    }
}
