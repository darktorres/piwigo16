<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Users\CurrentUser;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * The bin/piwigo counterpart to RequestBootstrap::bootEntryPoint() for the
 * HTTP path. Kept in Bootstrap/ (not bin/piwigo itself) so
 * Kernel::container() access stays inside the existing arch-test-allowed
 * boundary rather than widening it; bin/piwigo stays a thin delegator,
 * mirroring index.php's own minimalism.
 *
 * Commands are resolved via the DI container (autowired -- every command
 * class needs only class-typed constructor params, so config/container.php
 * needs no new entries for it) rather than constructed directly, so they
 * receive real service dependencies the same way every other service does.
 *
 * ConfigLoader::applyDefaults()/applyEnvOverrides() run before
 * Kernel::boot(), seeding CurrentConfig::$data on the CLI path, mirroring
 * RequestBootstrap::configure()'s equivalent step on the HTTP path. DB
 * credentials come from Piwigo\Db\DbCredentials (a pure env read, with no
 * Config dependency), so this ordering only matters for whatever other
 * Config-backed values a CLI command's own constructor-injected
 * dependencies still read.
 *
 * CurrentConfigService::set($configService) is resolve-and-set only,
 * deliberately not followed by ConfigService::loadConfFromDb() the way
 * RequestBootstrap::connect() also calls it: that call is HTTP-only, since
 * CLI commands can run before the `config` table exists (e.g. a command run
 * before install.php has ever been executed), where an unconditional
 * loadConfFromDb() would throw. Merely resolving/injecting ConfigService
 * doesn't touch the DB (Doctrine repositories are lazy), so that part is
 * safe even pre-migration.
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
     * `$paths` defaults to null (unlike `RequestBootstrap::bootEntryPoint()`,
     * which requires it) so existing tests calling `buildApplication()` with no
     * arguments keep working -- passing a null Paths to the container
     * builder simply skips registering `Paths::class`.
     *
     * `$commandsFile` likewise defaults to null and falls back to the real
     * `config/commands.php`, mirroring `$paths`'s own shape -- the only seam
     * for tests/Unit/Bootstrap/CliBootstrapTest.php to exercise this
     * method's three defensive guards (must-return-an-array,
     * entries-must-be-class-strings, entry-must-resolve-to-a-Command)
     * against a disposable fixture file instead of ever having to
     * overwrite the real, shared production file mid-suite.
     */
    public static function buildApplication(?Paths $paths = null, ?string $commandsFile = null): Application
    {
        // Both calls are genuine no-ops today (see ConfigLoader's own
        // docblocks on each) -- kept as real, callable steps in the
        // standard boot sequence for when either gains a real body, but
        // there is nothing for a test to observe either removed today.
        // Confirmed while investigating a mutation-testing gap.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot($paths);
        $container = Kernel::container();
        $currentUser = $container->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();
        $configService = $container->get(ConfigService::class);
        if (! $configService instanceof ConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . ConfigService::class);
        }
        $currentConfigService = $container->get(CurrentConfigService::class);
        if (! $currentConfigService instanceof CurrentConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
        }
        $currentConfigService->set($configService);

        $commandClasses = require $commandsFile ?? dirname(__DIR__, 3) . '/config/commands.php';
        if (! is_array($commandClasses)) {
            throw new RuntimeException('config/commands.php must return an array of Command class-strings.');
        }

        $application = new Application('piwigo');
        $application->setAutoExit(false);

        foreach ($commandClasses as $commandClass) {
            if (! is_string($commandClass)) {
                throw new RuntimeException('config/commands.php entries must be class-strings.');
            }

            $command = $container->get($commandClass);
            if (! $command instanceof Command) {
                throw new LogicException(
                    "config/commands.php entry {$commandClass} did not resolve to a Symfony Console Command."
                );
            }

            $application->addCommand($command);
        }

        return $application;
    }
}
