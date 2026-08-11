<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Factory for a Doctrine Migrations DependencyFactory -- extracted from
 * config/container.php's own DependencyFactory::class factory (which now
 * delegates here) so that a caller structurally unable to resolve it via
 * the container (Piwigo\Admin\Install\InstallWizard -- see its own
 * constructor docblock: PHP-DI would cache a Connection/
 * EntityManagerInterface built from stale, pre-seed() credentials) has a
 * direct path to a working DependencyFactory built from its own
 * already-seeded, locally-built EntityManager, same as
 * EntityManagerFactory::build() already gives every layer a direct path
 * to an EntityManager.
 *
 * Deliberately takes no Piwigo\Core\Paths param, unlike most of this
 * class's own siblings -- MigrationDefinitions is first-party codebase
 * config, always co-located with this class's own source file, never a
 * deployment-specific override the way Paths's other directories are (see
 * that class's own docblock on _data/local/multi-instance overrides). A
 * Paths param here would also break CliBootstrap::buildApplication(null)'s
 * documented "skip registering Paths::class" test seam
 * (tests/Unit/Bootstrap/CliBootstrapTest.php) -- once
 * CommandDefinitions's own MigrateCommand::class entry is eagerly
 * resolved to build the Application, PHP-DI would have nothing to
 * autowire a real Paths from.
 */
final class MigrationDependencyFactory
{
    public static function build(EntityManagerInterface $em): DependencyFactory
    {
        $migrationsConfig = MigrationDefinitions::config();
        $migrationsConfig['table_storage'] = [
            'table_name' => 'migration_versions',
        ];

        return DependencyFactory::fromEntityManager(
            new ConfigurationArray($migrationsConfig),
            new ExistingEntityManager($em),
        );
    }
}
