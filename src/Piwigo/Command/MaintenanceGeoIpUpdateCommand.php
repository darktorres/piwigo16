<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\GeoIp\GeoIpDatabaseUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Downloads (or refreshes) the DB-IP City Lite database GeoIpLookupService
 * reads from. DB-IP republishes it monthly, so this is meant to be run
 * from cron on that cadence -- there is no in-app scheduler, the same
 * documented-cron-fallback convention every other `maintenance:*` command
 * here already uses.
 */
#[AsCommand(name: 'maintenance:geoip-update', description: 'Download the current DB-IP City Lite geolocation database')]
final class MaintenanceGeoIpUpdateCommand extends Command
{
    public function __construct(
        private readonly GeoIpDatabaseUpdater $geoIpDatabaseUpdater,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! $this->geoIpDatabaseUpdater->update()) {
            $output->writeln('<error>Failed to download the GeoIp database.</error>');

            return Command::FAILURE;
        }

        $output->writeln('GeoIp database updated.');

        return Command::SUCCESS;
    }
}
