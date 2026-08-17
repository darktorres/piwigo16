<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Command\BackupCreateCommand;
use Piwigo\Command\BackupRestoreCommand;
use Piwigo\Command\CacheClearCommand;
use Piwigo\Command\LintLatteCommand;
use Piwigo\Command\MaintenanceCacheSizeCommand;
use Piwigo\Command\MaintenanceCalculateOrphansCommand;
use Piwigo\Command\MaintenanceDeleteOrphansCommand;
use Piwigo\Command\MaintenanceOrphanTagsCommand;
use Piwigo\Command\MaintenancePurgeFailedLoginsCommand;
use Piwigo\Command\MaintenancePurgeHistoryCommand;
use Piwigo\Command\MaintenancePurgeSessionsCommand;
use Piwigo\Command\MaintenanceRepairDbCommand;
use Piwigo\Command\MaintenanceSyncMetadataCommand;
use Piwigo\Command\PhpStanLatteCompileCommand;
use Piwigo\Command\PhpStanLatteShimsCommand;
use Piwigo\Command\PhpStanLatteSyncVarTypeCommand;
use Piwigo\Command\PrecompileTemplatesCommand;
use Piwigo\Command\SchemaDumpCommand;
use Piwigo\Command\UserListCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

/**
 * bin/piwigo's registered command list, resolved via the DI container
 * (Piwigo\Bootstrap\CliBootstrap). Grows one entry at a time as commands
 * gain real backing services -- same discipline as config/container.php
 * and Piwigo\Bootstrap\RouteDefinitions.
 *
 * The 4 original Maintenance*Command classes autowire DbMaintenanceRepository
 * with zero new container entries; the 4 newer ones (CalculateOrphans/
 * DeleteOrphans/SyncMetadata/CacheSize) autowire CategoryService/
 * ImageService/MetadataService/CacheSizeCalculator instead, same
 * zero-new-entries autowiring.
 *
 * [Finding 6] ConsumeMessagesCommand needs its own explicit
 * config/container.php factory entry (RoutableMessageBus/receiver
 * locator aren't autowireable) -- makes the async Job/Messenger
 * pipeline reachable via a real worker, purely additive since nothing
 * dispatches a job yet.
 */
final class CommandDefinitions
{
    /**
     * @return list<class-string<Command>>
     */
    public static function all(): array
    {
        return [
            CacheClearCommand::class,
            BackupCreateCommand::class,
            BackupRestoreCommand::class,
            UserListCommand::class,
            MaintenancePurgeHistoryCommand::class,
            MaintenancePurgeSessionsCommand::class,
            MaintenanceOrphanTagsCommand::class,
            MaintenanceRepairDbCommand::class,
            MaintenancePurgeFailedLoginsCommand::class,
            MaintenanceCalculateOrphansCommand::class,
            MaintenanceDeleteOrphansCommand::class,
            MaintenanceSyncMetadataCommand::class,
            MaintenanceCacheSizeCommand::class,
            MigrateCommand::class,
            SchemaDumpCommand::class,
            LintLatteCommand::class,
            PrecompileTemplatesCommand::class,
            PhpStanLatteShimsCommand::class,
            PhpStanLatteCompileCommand::class,
            PhpStanLatteSyncVarTypeCommand::class,
            ConsumeMessagesCommand::class,
        ];
    }
}
