<?php

declare(strict_types=1);

use Piwigo\Command\BackupCreateCommand;
use Piwigo\Command\BackupRestoreCommand;
use Piwigo\Command\CacheClearCommand;
use Piwigo\Command\MaintenanceOrphanTagsCommand;
use Piwigo\Command\MaintenancePurgeHistoryCommand;
use Piwigo\Command\MaintenancePurgeSessionsCommand;
use Piwigo\Command\UserListCommand;

// bin/piwigo's registered command list, resolved via the DI container
// (Piwigo\Bootstrap\CliBootstrap). Grows one entry at a time as commands
// gain real backing services -- same discipline as config/container.php
// and config/routes.php.
//
// Maintenance*Command (found missing by a 2026-07-13 audit -- P12 planned
// all 4 maintenance:* commands but none were ever built) autowire
// DbMaintenanceRepository (built P21, had zero real callers until now)
// with zero new container.php entries.
// maintenance:repair-db still isn't here -- not because of a blocker
// anymore (the legacy include/dblayer/functions_mysqli.inc.php file this
// comment used to cite is long gone, and the backing logic now lives in
// a real typed method, DbMaintenanceRepository::repairOptimizeAllTables(),
// reachable from the admin web UI's MaintenanceActionDispatcher). Nobody
// has circled back to add the CLI wrapper. See docs/PLAN.md's Epoch C
// section (P12) and Security master checklist for the full history --
// this is a real, still-open gap, not a deliberate deferral anymore.

/**
 * @return list<class-string<\Symfony\Component\Console\Command\Command>>
 */
return [
    CacheClearCommand::class,
    BackupCreateCommand::class,
    BackupRestoreCommand::class,
    UserListCommand::class,
    MaintenancePurgeHistoryCommand::class,
    MaintenancePurgeSessionsCommand::class,
    MaintenanceOrphanTagsCommand::class,
];
