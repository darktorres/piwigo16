<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Backup\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'backup:create', description: 'Dump the database and galleries/ to a timestamped archive in _data/backups/')]
final class BackupCreateCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $archivePath = $this->backupService->create();
        } catch (Throwable $e) {
            $output->writeln("<error>Backup failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $output->writeln("Backup created: {$archivePath}");

        return Command::SUCCESS;
    }
}
