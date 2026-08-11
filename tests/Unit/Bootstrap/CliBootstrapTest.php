<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CliBootstrap;
use Piwigo\Bootstrap\CommandDefinitions;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * CommandDefinitions::all()'s own two remaining defensive guards --
 * `! is_string($commandClass)` per entry, and the "did not resolve to a
 * Symfony Console Command" \LogicException -- are exercised below via
 * `buildApplication()`'s `$overrideCommands` parameter (mirrors its
 * existing `$paths` parameter's own shape: defaults to null, falls back
 * to the real `CommandDefinitions::all()`), passed a disposable fixture
 * array instead of ever touching the real, shared production command
 * list. The "must return an array" guard no longer applies --
 * `CommandDefinitions::all(): array` is return-type-hinted, so PHP itself
 * makes a non-array return impossible.
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
    ShutdownHandler::reset();
    pcntl_signal(SIGTERM, SIG_DFL);
});

test('CommandDefinitions entries resolve to registered command names', function (): void {
    $application = CliBootstrap::buildApplication();

    $commandClasses = CommandDefinitions::all();
    expect($commandClasses)
        ->not->toBe([]);

    foreach ($commandClasses as $commandClass) {
        expect(is_subclass_of($commandClass, Command::class))->toBeTrue();

        $attribute = new ReflectionClass($commandClass)
            ->getAttributes(AsCommand::class)[0]->newInstance();
        expect($application->has($attribute->name))
            ->toBeTrue();
    }
});

test('the built Application also exposes the Console built-in commands', function (): void {
    $application = CliBootstrap::buildApplication();

    expect($application->has('list'))
        ->toBeTrue()
        ->and($application->has('help'))
        ->toBeTrue();
});

test('buildApplication attaches a real CurrentUser (guest) globally', function (): void {
    CurrentUserTestFactory::get()->reset();

    CliBootstrap::buildApplication();

    expect(CurrentUserTestFactory::get()->get()->status)->toBe(UserStatus::Guest);

    CurrentUserTestFactory::get()->reset();
});

test('buildApplication initializes CurrentConfigService with a real, resolved ConfigService', function (): void {
    CliBootstrap::buildApplication();

    expect(fn () => CurrentConfigServiceTestFactory::get()->get())->not->toThrow(LogicException::class);
});

test('run() installs the shutdown handler, builds the Application and executes the given argv', function (): void {
    // --quiet (not ob_start(): Symfony's ConsoleOutput writes straight to
    // the STDOUT stream resource, bypassing PHP's output buffer entirely
    // -- confirmed live, ob_start()/ob_get_clean() around this call does
    // not capture a single byte) suppresses the real 'list' output so
    // this test doesn't spam the suite's own console every run.
    // Real SIG_DFL beforehand -- proves the handler this test checks for
    // afterward genuinely came from run()'s own ShutdownHandler::install()
    // call, not some earlier test in the same worker process.
    pcntl_signal(SIGTERM, SIG_DFL);

    $exitCode = CliBootstrap::run(['piwigo', 'list', '--quiet']);

    expect(pcntl_signal_get_handler(SIGTERM))
        ->not->toBe(SIG_DFL);

    // Symfony Console's own Command::SUCCESS -- 'list' just enumerates
    // the registered commands, so a real, side-effect-free way to prove
    // run() actually dispatched into a working Application rather than
    // merely constructing one (buildApplication() alone, as covered by
    // every other test in this file, never calls ->run() at all).
    expect($exitCode)
        ->toBe(0);
});

test('buildApplication throws when the container returns an unexpected type for ConfigService', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ConfigService::class,
        static fn () => CliBootstrap::buildApplication()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ConfigService::class);

test('buildApplication throws when the container returns an unexpected type for CurrentUser', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CurrentUser::class,
        static fn () => CliBootstrap::buildApplication()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentUser::class);

test('buildApplication throws when the container returns an unexpected type for CurrentConfigService', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CurrentConfigService::class,
        static fn () => CliBootstrap::buildApplication()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentConfigService::class);

test('buildApplication throws when an override commands entry is not a class-string', function (): void {
    CliBootstrap::buildApplication(overrideCommands: [123]);
})->throws(RuntimeException::class, 'CommandDefinitions entries must be class-strings.');

test('buildApplication throws when an override commands entry does not resolve to a Command', function (): void {
    // stdClass is a real, container-autowirable class (no constructor
    // args) that is deliberately not a Symfony Console Command -- proves
    // the guard checks the *resolved instance*, not just that the
    // class-string exists/autowires.
    CliBootstrap::buildApplication(overrideCommands: [stdClass::class]);
})->throws(LogicException::class, 'CommandDefinitions entry stdClass did not resolve to a Symfony Console Command.');
