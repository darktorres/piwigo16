<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CliBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\KernelContainerOverride;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * config/commands.php's own two defensive guards --
 * `! is_array($commandClasses)` and `! is_string($commandClass)` per
 * entry -- plus the "did not resolve to a Symfony Console Command"
 * \LogicException read from a hardcoded `dirname(__DIR__, 3) .
 * '/config/commands.php'` path with no injection seam (unlike
 * Kernel::container(), which CAN be swapped via
 * KernelContainerOverride). Exercising them would mean temporarily
 * overwriting that real, shared production file mid-suite -- risky if
 * anything else reads it concurrently -- so they stay undocumented-red,
 * same "no fake-able seam" shape as the HttpClientService skip list.
 */
beforeEach(function (): void {
    Kernel::reset();
});

afterEach(function (): void {
    Kernel::reset();
    // CliBootstrap::run() calls the real ShutdownHandler::install(),
    // wiring a genuine SIGTERM handler -- reset it back to SIG_DFL so it
    // doesn't bleed into unrelated tests in the same worker process,
    // matching ShutdownHandlerTest.php's own established convention.
    \Piwigo\Core\ShutdownHandler::reset();
    pcntl_signal(SIGTERM, SIG_DFL);
});

test('config/commands.php entries resolve to registered command names', function (): void {
    $application = CliBootstrap::buildApplication();

    /** @var list<class-string<Command>> $commandClasses */
    $commandClasses = require dirname(__DIR__, 3) . '/config/commands.php';
    expect($commandClasses)->toBeArray()->not->toBe([]);

    foreach ($commandClasses as $commandClass) {
        expect(is_subclass_of($commandClass, Command::class))->toBeTrue();

        $attribute = new ReflectionClass($commandClass)->getAttributes(AsCommand::class)[0]->newInstance();
        expect($application->has($attribute->name))->toBeTrue();
    }
});

test('the built Application also exposes the Console built-in commands', function (): void {
    $application = CliBootstrap::buildApplication();

    expect($application->has('list'))->toBeTrue()
        ->and($application->has('help'))->toBeTrue();
});

test('run() installs the shutdown handler, builds the Application and executes the given argv', function (): void {
    // --quiet (not ob_start(): Symfony's ConsoleOutput writes straight to
    // the STDOUT stream resource, bypassing PHP's output buffer entirely
    // -- confirmed live, ob_start()/ob_get_clean() around this call does
    // not capture a single byte) suppresses the real 'list' output so
    // this test doesn't spam the suite's own console every run.
    $exitCode = CliBootstrap::run(['piwigo', 'list', '--quiet']);

    // Symfony Console's own Command::SUCCESS -- 'list' just enumerates
    // the registered commands, so a real, side-effect-free way to prove
    // run() actually dispatched into a working Application rather than
    // merely constructing one (buildApplication() alone, as covered by
    // every other test in this file, never calls ->run() at all).
    expect($exitCode)->toBe(0);
});

test('buildApplication throws when the container returns an unexpected type for ConfigService', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ConfigService::class,
        static fn () => CliBootstrap::buildApplication()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ConfigService::class);
