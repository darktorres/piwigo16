<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Piwigo\Config\Config;

final class MigrationRunner
{
    public static function migrate(): void
    {
        if (!defined('PHPWG_ROOT_PATH')) {
            return;
        }

        if (Config::dbName() === '') {
            return;
        }

        $connection = DriverManager::getConnection([
            'driver'   => 'mysqli',
            'host'     => Config::dbHost(),
            'user'     => Config::dbUser(),
            'password' => Config::dbPassword(),
            'dbname'   => Config::dbName(),
            'charset'  => 'utf8mb4',
        ]);

        $config  = new PhpFile(PHPWG_ROOT_PATH . 'migrations.php');
        $factory = DependencyFactory::fromConnection($config, new ExistingConnection($connection));

        $factory->getMetadataStorage()->ensureInitialized();

        $repository = $factory->getMigrationRepository();
        if (count($repository->getMigrations()) === 0) {
            return;
        }

        /** @psalm-suppress InternalMethod */
        $version = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan    = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);
        if (count($plan) === 0) {
            return;
        }

        /** @psalm-suppress InternalClass, InternalMethod */
        $migratorConfig = new MigratorConfiguration()->setAllOrNothing(true);
        /** @psalm-suppress InternalMethod */
        $factory->getMigrator()->migrate($plan, $migratorConfig);
    }
}
