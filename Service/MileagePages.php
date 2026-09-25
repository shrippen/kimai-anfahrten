<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Configuration\LocaleService;
use App\Utils\LocaleFormatter;
use App\Utils\PageSetup;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Kimai page header of every plugin page (kimai-plugin-ui GUIDELINES 2.3/2.4): title "<page> · <period>",
 * the action name the PageActionsEvent subscribers listen to, and the help link.
 *
 *   create('mileage_trips', 'mileage.menu', 'September 2026') -> "Fahrten · September 2026", actions.mileage_trips
 */
class MileagePages
{
    public const HELP_URL = 'https://github.com/shrippen/kimai-anfahrten#readme';

    private const SEPARATOR = ' · ';

    private ?LocaleFormatter $formatter = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LocaleService $localeService,
    ) {
    }

    /**
     * @param string $action PageActionsEvent name, also set for pages without actions
     *                       (Kimai renders the page header only with an action name)
     * @param string $titleKey translation key of the page name
     * @param string|null $part already formatted title suffix (period, object), null for the bare page name
     * @param array<string, mixed> $payload passed to the actions subscriber
     */
    public function create(string $action, string $titleKey, ?string $part = null, array $payload = []): PageSetup
    {
        $title = $this->translator->trans($titleKey);
        if ($part !== null && $part !== '') {
            $title .= self::SEPARATOR . $part;
        }

        $page = new PageSetup($title);
        $page->setActionName($action);
        $page->setActionPayload($payload);
        $page->setHelp(self::HELP_URL);

        return $page;
    }

    /**
     * Month with year ("September 2026"), same as Kimai's month_name(true) in Twig (user's locale setting).
     */
    public function monthLabel(int $year, int $month): string
    {
        return $this->formatter()->monthName(new \DateTimeImmutable(\sprintf('%d-%02d-10', $year, $month)), true);
    }

    /**
     * Short date, same as Kimai's date_short.
     */
    public function dateLabel(\DateTimeInterface $date): string
    {
        return (string) $this->formatter()->dateShort($date);
    }

    /**
     * Number for messages built in PHP, same as Kimai's amount (at most $decimals fraction digits).
     */
    public function number(float $value, int $decimals = 1): string
    {
        return $this->formatter()->amount(round($value, $decimals));
    }

    private function formatter(): LocaleFormatter
    {
        // Kimai sets the default locale to the user's locale setting (UserEnvironmentSubscriber)
        return $this->formatter ??= new LocaleFormatter($this->localeService, \Locale::getDefault());
    }
}
