<?php

namespace KimaiPlugin\MileageBundle\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use KimaiPlugin\MileageBundle\Service\TripCsvImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:bundle:mileage:import', description: 'Import trips from a CSV file')]
class ImportCommand extends Command
{
    public function __construct(
        private readonly TripCsvImporter $importer,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'CSV file')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Username the trips belong to')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only check the file')
            ->addOption('keep-duplicates', null, InputOption::VALUE_NONE, 'Also import rows that look like existing trips');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $user = $this->userRepository->findByUsername((string) $input->getOption('user'));

        if (!$user instanceof User) {
            $io->error('Unknown or missing --user');

            return Command::INVALID;
        }
        if (!is_readable($file)) {
            $io->error('Cannot read ' . $file);

            return Command::INVALID;
        }

        $parsed = $this->importer->parse((string) file_get_contents($file));
        $built = $this->importer->build($user, $parsed['rows']);

        $errors = array_filter($built, static fn (array $r) => $r['trip'] === null);
        foreach ($errors as $row) {
            $io->warning(\sprintf('Line %d: %s', $row['line'], json_encode($row['errors'])));
        }
        $io->text(\sprintf('%d rows, %d valid, %d duplicates, %d with errors', \count($built), \count($built) - \count($errors), \count(array_filter($built, static fn (array $r) => $r['duplicate'])), \count($errors)));

        if ($input->getOption('dry-run')) {
            return $errors === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $result = $this->importer->import($built, !$input->getOption('keep-duplicates'));
        $io->success(\sprintf('%d trips imported, %d skipped', $result['imported'], $result['skipped']));

        return Command::SUCCESS;
    }
}
