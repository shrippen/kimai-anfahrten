<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Attachment;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Repository\AttachmentRepository;
use KimaiPlugin\MileageBundle\Service\AttachmentStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Receipts for trips and rentals.
 */
#[Route(path: '/mileage/attachments')]
#[IsGranted('mileage')]
class AttachmentController extends AbstractController
{
    use TargetUserTrait;

    public function __construct(
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentStorage $storage,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/trip/{id}', name: 'mileage_attachment_trip', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadForTrip(Request $request, Trip $trip): Response
    {
        $this->upload($request, $trip->getUser(), static fn (Attachment $a) => $a->setTrip($trip));

        return $this->redirectToRoute('mileage_trip_edit', ['id' => $trip->getId()]);
    }

    #[Route(path: '/rental/{id}', name: 'mileage_attachment_rental', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadForRental(Request $request, Rental $rental): Response
    {
        $this->upload($request, $rental->getUser(), static fn (Attachment $a) => $a->setRental($rental));

        return $this->redirectToRoute('mileage_rental_show', ['id' => $rental->getId()]);
    }

    #[Route(path: '/{id}', name: 'mileage_attachment_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(Attachment $attachment): Response
    {
        $owner = $attachment->getUser();
        if (!$this->canViewTripsOf($owner)) {
            throw $this->createAccessDeniedException();
        }

        $path = $this->storage->path($attachment);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            $attachment->getMimeType() === 'application/pdf' || str_starts_with($attachment->getMimeType(), 'image/') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getOriginalName(),
            'attachment-' . $attachment->getId()
        );

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'mileage_attachment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Attachment $attachment): Response
    {
        if (!$this->canDeleteTripsOf($attachment->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_attachment_delete' . $attachment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $trip = $attachment->getTrip();
        $rental = $attachment->getRental();
        $this->storage->delete($attachment);
        $this->attachmentRepository->remove($attachment);
        $this->flashSuccess('action.delete.success');

        if ($rental !== null) {
            return $this->redirectToRoute('mileage_rental_show', ['id' => $rental->getId()]);
        }

        return $this->redirectToRoute('mileage_trip_edit', ['id' => $trip?->getId()]);
    }

    /**
     * @param callable(Attachment): mixed $link
     */
    private function upload(Request $request, ?User $owner, callable $link): void
    {
        if ($owner === null || !$this->canEditTripsOf($owner)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mileage_attachment_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            $this->flashError($this->translator->trans('mileage.attachment.error.upload'));

            return;
        }

        try {
            $attachment = $this->storage->store($owner, $file);
            $link($attachment);
            $this->attachmentRepository->save($attachment);
            $this->flashSuccess('action.update.success');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($this->translator->trans($e->getMessage(), ['%max%' => AttachmentStorage::MAX_SIZE / 1024 / 1024]));
        }
    }
}
