<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CliBootstrap;
use Piwigo\Bootstrap\CommandDefinitions;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserStatus;
use Symfony\Component\Console\Application;
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
 *
 * A real `Paths` is required below (unlike this file's own history before
 * `LintLatteCommand` landed, when every real command's dependency graph
 * happened to bottom out without ever needing one) -- `buildApplication()`
 * resolves every `CommandDefinitions::all()` entry to add it to the
 * `Application`, and `LintLatteCommand` transitively needs a real
 * `Piwigo\Core\Paths` (via `PiwigoExtension` -> `Template` -> `Lang` ->
 * ... ), which has no container binding at all when `$paths` is null
 * (confirmed live: PHP-DI's own "has no value defined or guessable"
 * failure on `Paths::$root`). Real production always passes a real
 * `Paths` here (`bin/piwigo` itself does) -- this was only ever a
 * no-args convenience for tests that didn't need one, not a genuine
 * "commands must never need Paths" constraint.
 */
beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-cli-bootstrap-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root, 0o777, true);
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
    if (is_dir($this->root)) {
        exec('rm -rf ' . escapeshellarg($this->root));
    }
});

test('CommandDefinitions entries resolve to registered command names', function (): void {
    $application = CliBootstrap::buildApplication(Paths::fromRoot($this->root));

    $commandClasses = CommandDefinitions::all();
    expect($commandClasses)
        ->not->toBe([]);

    // Matched by class, not by re-deriving the name from AsCommand's own
    // attribute instance -- `AsCommand`'s constructor rewrites `$name` for
    // a `hidden: true` command into a `|`-prefixed internal encoding
    // (confirmed live: "lint:latte:inner" becomes "|lint:latte:inner"),
    // so `$application->has($attribute->name)` would wrongly fail for any
    // hidden command even though it's genuinely registered and runnable
    // (`Application::all()` includes hidden commands -- only `list`'s own
    // output filters them, confirmed via `Command::isHidden()`).
    $registeredClasses = array_map(get_class(...), $application->all());

    foreach ($commandClasses as $commandClass) {
        expect(is_subclass_of($commandClass, Command::class))->toBeTrue();
        expect($registeredClasses)
            ->toContain($commandClass);
    }
});

test('the built Application also exposes the Console built-in commands', function (): void {
    $application = CliBootstrap::buildApplication(Paths::fromRoot($this->root));

    expect($application->has('list'))
        ->toBeTrue()
        ->and($application->has('help'))
        ->toBeTrue();
});

test('buildApplication attaches a real CurrentUser (guest) globally', function (): void {
    CurrentUserTestFactory::get()->reset();

    CliBootstrap::buildApplication(Paths::fromRoot($this->root));

    expect(CurrentUserTestFactory::get()->get()->status)->toBe(UserStatus::Guest);

    CurrentUserTestFactory::get()->reset();
});

test('buildApplication initializes CurrentConfigService with a real, resolved ConfigService', function (): void {
    CliBootstrap::buildApplication(Paths::fromRoot($this->root));

    expect(fn (): ConfigService => CurrentConfigServiceTestFactory::get()->get())->not->toThrow(LogicException::class);
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

    $exitCode = CliBootstrap::run(['piwigo', 'list', '--quiet'], Paths::fromRoot($this->root));

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
        static fn (): Application => CliBootstrap::buildApplication()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ConfigService::class);

test('buildApplication throws when the container returns an unexpected type for CurrentUser', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CurrentUser::class,
        static fn (): Application => CliBootstrap::buildApplication()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentUser::class);

test('buildApplication throws when the container returns an unexpected type for CurrentConfigService', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CurrentConfigService::class,
        static fn (): Application => CliBootstrap::buildApplication()
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
