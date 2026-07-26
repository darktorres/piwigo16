<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for the admin/maintenance_actions.php "Purge sessions"
 * action -- deletes sessions belonging to a since-deleted user id, via
 * DbMaintenanceRepository::purgeSessionsForDeletedUsers() (built P21, zero
 * real callers until this command).
 *
 * $idColumn (normally \Piwigo\Config\CurrentConfig::userFields()['id'], the
 * external-auth column-mapping convention) is hardcoded to the literal
 * 'id' default here rather than calling CurrentConfig::userFields()
 * itself -- purely to avoid this command depending on Config bootstrap
 * state at all, not because the accessor is unavailable to CLI code (it's
 * a plain static getter with a compile-time default, already called
 * directly from CLI-reachable code elsewhere). Only affects installs using
 * Apache-auth external user tables with a non-default id column name --
 * confirm/extend if that ever becomes a real deployment target for the CLI
 * tool.
 */
#[AsCommand(name: 'maintenance:purge-sessions', description: 'Purge sessions belonging to since-deleted users')]
final class MaintenancePurgeSessionsCommand extends Command
{
    public function __construct(
        private readonly DbMaintenanceRepository $repo,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->repo->purgeSessionsForDeletedUsers('id');

        $output->writeln('Purged sessions belonging to since-deleted users.');

        return Command::SUCCESS;
    }
}
