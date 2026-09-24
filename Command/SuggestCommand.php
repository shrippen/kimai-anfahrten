<?php

namespace KimaiPlugin\MileageBundle\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Detects trips in the Dawarich history for all users (e.g. nightly via cron).
 */
#[AsCommand(name: 'kimai:bundle:mileage:suggest', description: 'Detect trips from Dawarich and create suggestions')]
class SuggestCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MileageConfiguration $configuration,
        private readonly SuggestionService $suggestionService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Month to scan (YYYY-MM)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Scan the last N days (default: 2)', '2')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only this username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $month = $input->getOption('month');
        if (\is_string($month)) {
            $from = \DateTimeImmutable::createFromFormat('!Y-m', $month);
            if ($from === false) {
                $io->error('Invalid --month, expected YYYY-MM');

                return Command::INVALID;
            }
            $to = $from->modify('last day of this month');
        } else {
            $days = max(1, (int) $input->getOption('days'));
            $to = new \DateTimeImmutable('today');
            $from = $to->modify(\sprintf('-%d days', $days - 1));
        }

        $username = $input->getOption('user');
        if (\is_string($username)) {
            $user = $this->userRepository->findByUsername($username);
            if (!$user instanceof User) {
                $io->error('Unknown user: ' . $username);

                return Command::FAILURE;
            }
            $users = [$user];
        } else {
            $users = $this->userRepository->findBy(['enabled' => true]);
        }

        $failed = false;
        $rows = [];
        foreach ($users as $user) {
            if (!$this->configuration->isDawarichConfigured($user)) {
                continue;
            }
            try {
                $rows[] = [$user->getUserIdentifier(), $this->suggestionService->detect($user, $from, $to)];
            } catch (DawarichException $e) {
                $failed = true;
                $rows[] = [$user->getUserIdentifier(), $this->translator->trans($e->getMessage(), $e->getParameters())];
            }
        }

        $io->text(\sprintf('%s – %s', $from->format('Y-m-d'), $to->format('Y-m-d')));
        $io->table(['User', 'New suggestions'], $rows);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
