<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for the admin/maintenance_actions.php "Repair and optimize
 * database" action, via DbMaintenanceRepository::repairOptimizeAllTables()
 * (already reachable from the admin web UI's MaintenanceActionDispatcher,
 * had no CLI wrapper until now).
 */
#[AsCommand(name: 'maintenance:repair-db', description: 'Repair, re-order, and optimize every table with this install\'s DB prefix')]
final class MaintenanceRepairDbCommand extends Command
{
    public function __construct(
        private readonly DbMaintenanceRepository $repo,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->repo->repairOptimizeAllTables();

        $output->writeln('Repaired, re-ordered, and optimized all tables.');

        return Command::SUCCESS;
    }
}
