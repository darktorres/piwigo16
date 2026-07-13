<?php

declare(strict_types=1);

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Command\BackupCreateCommand;
use Piwigo\Command\BackupRestoreCommand;
use Piwigo\Command\CacheClearCommand;
use Piwigo\Command\MaintenanceOrphanTagsCommand;
use Piwigo\Command\MaintenancePurgeHistoryCommand;
use Piwigo\Command\MaintenancePurgeSessionsCommand;
use Piwigo\Command\SchemaDumpCommand;
use Piwigo\Command\UserListCommand;

// bin/piwigo's registered command list, resolved via the DI container
// (Piwigo\Bootstrap\CliBootstrap). Grows one entry at a time as commands
// gain real backing services -- same discipline as config/container.php
// and config/routes.php.
//
// MigrateCommand (registered as `migrations:migrate`) is Doctrine's own
// command, not a Piwigo\Command wrapper (P14) -- its constructor takes a
// nullable DependencyFactory and is otherwise autowireable, and reusing
// Doctrine's real, fully-featured command (dry-run, rollback, interactive
// confirmation) beats re-implementing a thinner version.
//
// SchemaDumpCommand (P15) autowires SchemaDumpService with zero new
// container.php entries -- its only dependency, Connection::class,
// already has a factory entry from P14.
//
// Maintenance*Command (found missing by a 2026-07-13 audit -- P12 planned
// all 4 maintenance:* commands but none were ever built) autowire
// DbMaintenanceRepository (built P21, had zero real callers until now)
// with zero new container.php entries, same reason as SchemaDumpCommand.
// maintenance:repair-db is deliberately still not here -- its backing
// logic lives in include/dblayer/functions_mysqli.inc.php, which P23's own
// legacy-absorption work already has to touch; building an interim wrapper
// now would mean touching that file twice.

/**
 * @return list<class-string<\Symfony\Component\Console\Command\Command>>
 */
return [
    CacheClearCommand::class,
    BackupCreateCommand::class,
    BackupRestoreCommand::class,
    UserListCommand::class,
    MigrateCommand::class,
    SchemaDumpCommand::class,
    MaintenancePurgeHistoryCommand::class,
    MaintenancePurgeSessionsCommand::class,
    MaintenanceOrphanTagsCommand::class,
];
