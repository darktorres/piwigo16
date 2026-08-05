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
 * CLI wrapper for the admin/maintenance_actions.php "Purge history"
 * actions -- purges both the detail and summary tables via
 * DbMaintenanceRepository, which already existed (built P21) but had zero
 * real callers until this command (found during a 2026-07-13 audit of P12's
 * never-built maintenance:* commands).
 */
#[AsCommand(name: 'maintenance:purge-history', description: 'Purge history detail and summary data')]
final class MaintenancePurgeHistoryCommand extends Command
{
    public function __construct(
        private readonly DbMaintenanceRepository $repo,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->repo->purgeHistoryDetail();
        $this->repo->purgeHistorySummary();

        $output->writeln('Purged history detail and summary data.');

        return Command::SUCCESS;
    }
}
