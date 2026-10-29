<?php

namespace KimaiPlugin\MileageBundle\EventSubscriber;

use App\Event\ThemeEvent;
use KimaiPlugin\MileageBundle\Service\AutoDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Starts the automatic trip detection ({@see AutoDetector}) from any Kimai page: when it is due for the logged-in
 * user, the page gets a small script that posts to `mileage_suggestions_auto` after loading. The detection then runs
 * in that request, so the page itself never waits for Dawarich (Kimai's Docker image runs Apache with mod_php, where
 * work after the response would still hold the page open). No cron job is needed.
 */
class AutoDetectionSubscriber implements EventSubscriberInterface
{
    public const CSRF = 'mileage_auto_detect';

    public function __construct(
        private readonly AuthorizationCheckerInterface $security,
        private readonly AutoDetector $detector,
        private readonly UrlGeneratorInterface $urls,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::JAVASCRIPT => 'onJavascript',
        ];
    }

    public function onJavascript(ThemeEvent $event): void
    {
        $user = $event->getUser();
        if ($user === null) {
            return;
        }

        try {
            if (!$this->security->isGranted('edit_own_mileage') || !$this->detector->isDue($user)) {
                return;
            }
            $url = htmlspecialchars($this->urls->generate('mileage_suggestions_auto'), \ENT_QUOTES);
            $token = htmlspecialchars($this->csrf->getToken(self::CSRF)->getValue(), \ENT_QUOTES);
        } catch (\Throwable $e) {
            // a page must never fail because of the detection
            $this->logger?->error('Mileage: automatic trip detection not started: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return;
        }

        $event->addContent(<<<HTML
            <script data-url="{$url}" data-token="{$token}">
            (function (c) {
                var body = new URLSearchParams();
                body.append('_token', c.token);
                fetch(c.url, {method: 'POST', body: body, credentials: 'same-origin', keepalive: true,
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}}).catch(function () {});
            })(document.currentScript.dataset);
            </script>
            HTML);
    }
}
