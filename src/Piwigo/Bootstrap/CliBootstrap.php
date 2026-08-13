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
use Psr\Container\ContainerInterface;
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
     * The boot-and-configure half of the CLI bootstrap sequence, split out
     * so `tools/phpstan-latte-engine.php` (PHPStan's own `engineBootstrap`
     * contract requires a bare file returning an `Engine`, not a
     * dispatchable Command -- the one CLI-adjacent tool that structurally
     * can't go through a real Command) can still reach a fully-configured
     * container through `src/Piwigo/Bootstrap/` instead of hand-rolling a
     * second, parallel `Kernel::boot()`/`Kernel::container()` sequence
     * outside it. Every other CLI-facing need should go through a real
     * `Command` (resolved via `buildApplication()` below), not this method
     * directly.
     *
     * Both `ConfigLoader` calls are genuine no-ops today (see its own
     * docblocks) -- kept as real, callable steps in the standard boot
     * sequence for when either gains a real body, but there is nothing for
     * a test to observe either removed today.
     */
    public static function bootContainer(?Paths $paths = null): ContainerInterface
    {
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

        return $container;
    }

    /**
     * Split out from run() so tests can inspect the registered command set
     * (tests/Unit/Bootstrap/CliBootstrapTest.php) without actually running
     * one -- ContainerDefinitionsTest.php's "every entry resolves" shape,
     * applied to `CommandDefinitions::all()` instead of
     * `config/container.php`'s own definitions array.
     *
     * `$paths` defaults to null (unlike `RequestBootstrap::bootEntryPoint()`,
     * which requires it) so existing tests calling `buildApplication()` with no
     * arguments keep working -- passing a null Paths to the container
     * builder simply skips registering `Paths::class`.
     *
     * `$overrideCommands` likewise defaults to null and falls back to the
     * real `CommandDefinitions::all()`, mirroring `$paths`'s own shape --
     * the only seam for tests/Unit/Bootstrap/CliBootstrapTest.php to
     * exercise this method's two remaining defensive guards
     * (entries-must-be-class-strings, entry-must-resolve-to-a-Command)
     * against a disposable fixture array instead of ever having to
     * overwrite the real, shared production command list. Typed
     * `list<mixed>`, not `list<string>` -- the two guards below exist
     * specifically because PHP doesn't enforce a generic array shape at
     * runtime, so a test deliberately passes non-conforming elements
     * (e.g. an int) to prove the guards catch what the type system alone
     * cannot.
     *
     * @param list<mixed>|null $overrideCommands
     */
    public static function buildApplication(?Paths $paths = null, ?array $overrideCommands = null): Application
    {
        $container = self::bootContainer($paths);

        $commandClasses = $overrideCommands ?? CommandDefinitions::all();

        $application = new Application('piwigo');
        $application->setAutoExit(false);

        foreach ($commandClasses as $commandClass) {
            if (! is_string($commandClass)) {
                throw new RuntimeException('CommandDefinitions entries must be class-strings.');
            }

            $command = $container->get($commandClass);
            if (! $command instanceof Command) {
                throw new LogicException(
                    "CommandDefinitions entry {$commandClass} did not resolve to a Symfony Console Command."
                );
            }

            $application->addCommand($command);
        }

        return $application;
    }
}
