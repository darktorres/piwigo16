<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Piwigo\Backup\BackupService;
use Piwigo\Db\DbCredentials;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Destructive by design: drops/recreates the target database and overwrites
 * galleries/. --force is required so this can never run by accident (e.g. a
 * mistyped `bin/piwigo backup:restore` with no other args). The doc's
 * "stops the app"/"runs pending migrations" restore steps are not
 * implemented -- no live traffic is routed through the new pipeline yet
 * (nothing to stop) and Doctrine Migrations don't exist until P14 (nothing
 * to run). See docs/PLAN-REPLAY.md P12's scope-decision section.
 */
#[AsCommand(name: 'backup:restore', description: 'Restore the database and galleries/ from a backup archive (destructive)')]
final class BackupRestoreCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to a backup archive created by backup:create')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required to actually run this destructive operation')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Target database name (defaults to PIWIGO_DB_BASE)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        if (! is_string($file) || $file === '') {
            $output->writeln('<error>Missing required argument: file</error>');

            return Command::INVALID;
        }

        if (! is_file($file)) {
            $output->writeln("<error>Backup archive not found: {$file}</error>");

            return Command::INVALID;
        }

        if ($input->getOption('force') !== true) {
            $output->writeln('<error>Refusing to restore without --force (this drops/recreates the target database and overwrites galleries/).</error>');

            return Command::INVALID;
        }

        $database = $input->getOption('database');
        $targetDatabase = is_string($database) && $database !== '' ? $database : DbCredentials::fromEnv()->database;

        try {
            $this->backupService->restore($file, $targetDatabase);
        } catch (\Throwable $e) {
            $output->writeln("<error>Restore failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $output->writeln("Restored {$file} into database '{$targetDatabase}'.");

        return Command::SUCCESS;
    }
}
