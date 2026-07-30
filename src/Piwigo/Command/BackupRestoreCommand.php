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
 * mistyped `bin/piwigo backup:restore` with no other args). Deliberately
 * does not "stop the app" or "run pending migrations": real traffic does
 * route through the full request pipeline now (there'd be something to
 * stop), but this command still doesn't attempt it -- an in-place
 * stop/restore/restart sequence is a separate, not-yet-made design
 * decision, not a missing-infrastructure gap. Doctrine Migrations
 * themselves were removed from the project entirely (the schema comes
 * from a static SQL file now, see docs/PLAN.md's Migration path section)
 * -- "runs pending migrations" is moot regardless.
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

        $targetDatabase = self::resolveTargetDatabase($input->getOption('database'));

        try {
            $this->backupService->restore($file, $targetDatabase);
        } catch (\Throwable $e) {
            $output->writeln("<error>Restore failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $output->writeln("Restored {$file} into database '{$targetDatabase}'.");

        return Command::SUCCESS;
    }

    private static function resolveTargetDatabase(mixed $database): string
    {
        return is_string($database) && $database !== '' ? $database : DbCredentials::fromEnv()->database;
    }
}
