<?php

namespace KimaiPlugin\MileageBundle\Controller;

use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response helpers for Kimai modals, kit result callouts, immediate actions and undo
 * (kimai-plugin-ui GUIDELINES 3.5 and 3.6, README "kpuFormSuccess").
 */
trait MileageUiTrait
{
    /** data-form-event of modal forms that keep the page URL: kit.js reloads the page (result callouts, KPIs). */
    private static string $reloadEvent = 'kpu.reload';

    /** Undo window of reversible actions: 15 minutes, same user, same session (GUIDELINES 3.5). */
    private static int $undoWindow = 900;

    /** Request sent by Kimai's modal form plugin (modal-ajax-form). */
    private function isModalRequest(Request $request): bool
    {
        return str_contains(strtolower((string) $request->headers->get('X-Requested-With')), 'kimai-modal');
    }

    /** Request sent by kit.js (data-kpu-post, bulk bar, undo) that expects JSON. */
    private function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    /**
     * Answer after a successful form that may run in Kimai's modal. A plain 302 would be followed inside the modal's
     * fetch() and consume the kpu_result flash.
     *  - keepUrl = false: 201 + x-modal-redirect, Kimai loads $route completely;
     *  - keepUrl = true : empty 200, Kimai closes the modal and fires data-form-event "kpu.reload" (form option
     *                     attr), kit.js reloads the current page (period and filters stay).
     * Without the modal (plain page) always a redirect.
     *
     * @param array<string, mixed> $parameters
     */
    private function kpuFormSuccess(Request $request, string $route, array $parameters = [], bool $keepUrl = false): Response
    {
        if (!$this->isModalRequest($request)) {
            return $this->redirectToRoute($route, $parameters);
        }

        return $keepUrl ? new Response('') : $this->redirectToRouteAfterCreate($route, $parameters);
    }

    /**
     * Result of an immediate action: JSON for kit.js ({message, undo?}), otherwise a kpu_result callout (errors as
     * Kimai error flash) and a redirect.
     *
     * @param array{url: string, token: string, ids: list<int>}|null $undo
     * @param array<string, mixed> $parameters
     */
    private function actionResult(Request $request, string $message, ?array $undo, string $route, array $parameters, int $status = 200): Response
    {
        if ($this->wantsJson($request)) {
            $data = ['message' => $message];
            if ($undo !== null) {
                $data['undo'] = $undo;
            }

            return new JsonResponse($data, $status);
        }

        $this->addFlash($status >= 400 ? 'error' : 'kpu_result', $message);

        return $this->redirectToRoute($route, $parameters);
    }

    /**
     * Stores what an undo needs in the session and returns the action id for the undo URL. Expired entries of
     * earlier actions are dropped on the way.
     *
     * @param array<mixed> $data
     */
    private function rememberUndo(Request $request, string $type, array $data): string
    {
        $session = $request->getSession();
        $prefix = 'mileage.undo.';
        foreach ($session->all() as $name => $entry) {
            if (str_starts_with($name, $prefix) && (int) (\is_array($entry) ? ($entry['at'] ?? 0) : 0) < time() - self::$undoWindow) {
                $session->remove($name);
            }
        }

        $action = bin2hex(random_bytes(8));
        $session->set($prefix . $action, ['type' => $type, 'user' => $this->getUser()->getId(), 'at' => time(), 'data' => $data]);

        return $action;
    }

    /**
     * Takes (and removes) the undo entry of an own action: same type, same user, within the undo window.
     *
     * @return array<mixed>|null the stored data, null when there is none (expired, other user, used)
     */
    private function takeUndo(Request $request, string $type, string $action): ?array
    {
        $session = $request->getSession();
        $key = 'mileage.undo.' . $action;
        $entry = $session->get($key);
        $session->remove($key);

        if (!\is_array($entry)
            || ($entry['type'] ?? null) !== $type
            || ($entry['user'] ?? null) !== $this->getUser()->getId()
            || (int) ($entry['at'] ?? 0) < time() - self::$undoWindow
            || !\is_array($entry['data'] ?? null)) {
            return null;
        }

        return $entry['data'];
    }

    /**
     * Form without fields whose CSRF field is the plain "_token" (same token ids as the former inline forms), used
     * for the delete confirmation of Kimai's modal.
     */
    private function createPlainForm(string $tokenId, string $action, bool $reload = false): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('', FormType::class, null, [
            'action' => $action,
            'method' => 'POST',
            'csrf_field_name' => '_token',
            'csrf_token_id' => $tokenId,
            'attr' => $reload ? ['data-form-event' => self::$reloadEvent] : [],
        ]);
    }

    /**
     * Upload form of the receipts card (_attachments.html.twig). Field names stay "file" and "_token", as the
     * upload routes of AttachmentController expect them.
     */
    private function createAttachmentForm(string $action): FormInterface
    {
        $form = $this->container->get('form.factory')->createNamed('', FormType::class, null, [
            'action' => $action,
            'method' => 'POST',
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'mileage_attachment_upload',
        ]);
        $form->add('file', FileType::class, [
            'label' => 'mileage.attachment.upload',
            'help' => 'mileage.attachment.help',
            'attr' => ['accept' => 'application/pdf,image/*'],
        ]);

        return $form;
    }

    /**
     * @return list<int> positive, unique ids of the kit selection (ids[])
     */
    private function selectedIds(Request $request, int $max = 500): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $request->request->all('ids')),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === [] || \count($ids) > $max) {
            throw $this->createNotFoundException('Nothing selected');
        }

        return $ids;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }

    private function csrfToken(string $id): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken($id)->getValue();
    }
}
