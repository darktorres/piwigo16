<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Users\CurrentUser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * P12 CLI entry orchestrator -- the bin/piwigo counterpart to
 * CommonBootstrap::run() for the HTTP path. Kept in Bootstrap/ (not
 * bin/piwigo itself) so Kernel::container() access stays inside the
 * existing arch-test-allowed boundary rather than widening it; bin/piwigo
 * stays a thin delegator, mirroring index.php's own minimalism.
 *
 * Commands are resolved via the DI container (autowired -- every command
 * class this phase adds has only class-typed constructor params, so
 * config/container.php needs zero new entries) rather than constructed
 * directly, so they can receive real service dependencies the same way
 * every other P7-P11 service does.
 *
 * P14 adds ConfigLoader::applyDefaults()/applyEnvOverrides() before
 * Kernel::boot() -- a real gap found while testing migrations:migrate:
 * config/container.php's Connection/EntityManagerInterface factories read
 * Config::dbHost()/etc (static, P13), and until now nothing seeded
 * Config::$data on the CLI path (P12's own commands read DB creds
 * directly via DbCredentials::fromEnv(), never through Config, so this
 * never surfaced before). Mirrors CommonBootstrap::run()'s equivalent
 * P13 addition on the HTTP path exactly.
 *
 * Phase 5 adds CurrentConfigService::set($configService) -- resolve-and-
 * set only, deliberately not followed by ConfigService::loadConfFromDb()
 * the way CommonBootstrap::run() also calls it: that call is HTTP-only,
 * since CLI commands can run before the `config` table exists (e.g.
 * migrations:migrate on a fresh DB), where an unconditional
 * loadConfFromDb() would throw. Merely resolving/injecting ConfigService
 * doesn't touch the DB (Doctrine repositories are lazy), so that part is
 * safe here even pre-migration.
 */
final class CliBootstrap
{
    /**
     * @param list<string> $argv
     */
    public static function run(array $argv, ?Paths $paths = null): int
    {
        ShutdownHandler::install();

        return self::buildApplication($paths)->run(new ArgvInput($argv));
    }

    /**
     * Split out from run() so tests can inspect the registered command set
     * (tests/Unit/Bootstrap/CliBootstrapTest.php) without actually running
     * one -- ContainerDefinitionsTest.php's "every entry resolves" shape,
     * applied to config/commands.php instead of config/container.php.
     *
     * `$paths` defaults to null (unlike `CommonBootstrap::run()`, which
     * requires it) so existing tests calling `buildApplication()` with no
     * arguments keep working -- passing a null Paths to the container
     * builder simply skips registering `Paths::class`.
     */
    public static function buildApplication(?Paths $paths = null): Application
    {
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot($paths);
        CurrentUser::attachGlobals();
        $container = Kernel::container();
        $configService = $container->get(ConfigService::class);
        if (! $configService instanceof ConfigService) {
            throw new \LogicException('Container returned an unexpected type for ' . ConfigService::class);
        }
        CurrentConfigService::set($configService);

        $commandClasses = require dirname(__DIR__, 3) . '/config/commands.php';
        if (! is_array($commandClasses)) {
            throw new \RuntimeException('config/commands.php must return an array of Command class-strings.');
        }

        $application = new Application('piwigo');
        $application->setAutoExit(false);

        foreach ($commandClasses as $commandClass) {
            if (! is_string($commandClass)) {
                throw new \RuntimeException('config/commands.php entries must be class-strings.');
            }

            $command = $container->get($commandClass);
            if (! $command instanceof Command) {
                throw new \LogicException(
                    "config/commands.php entry {$commandClass} did not resolve to a Symfony Console Command."
                );
            }

            $application->addCommand($command);
        }

        return $application;
    }
}
