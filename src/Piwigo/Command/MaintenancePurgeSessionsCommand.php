<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for the admin/maintenance_actions.php "Purge sessions"
 * action -- deletes sessions belonging to a since-deleted user id, via
 * DbMaintenanceRepository::purgeSessionsForDeletedUsers() (already existed,
 * zero real callers until this command).
 */
#[AsCommand(name: 'maintenance:purge-sessions', description: 'Purge sessions belonging to since-deleted users')]
final class MaintenancePurgeSessionsCommand extends Command
{
    public function __construct(
        private readonly DbMaintenanceRepository $repo,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->repo->purgeSessionsForDeletedUsers();

        $output->writeln('Purged sessions belonging to since-deleted users.');

        return Command::SUCCESS;
    }
}
