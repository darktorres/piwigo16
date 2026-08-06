<?php

declare(strict_types=1);

use Piwigo\Core\ShutdownHandler;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserStatus;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Bootstrap\CliBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\KernelContainerOverride;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * config/commands.php's own three defensive guards --
 * `! is_array($commandClasses)`, `! is_string($commandClass)` per entry,
 * and the "did not resolve to a Symfony Console Command" \LogicException
 * -- are exercised below via `buildApplication()`'s `$commandsFile`
 * parameter (mirrors its existing `$paths` parameter's own shape:
 * defaults to null, falls back to the real, hardcoded
 * `dirname(__DIR__, 3) . '/config/commands.php'`), pointed at a disposable
 * fixture file under sys_get_temp_dir() instead of ever touching the
 * real, shared production file.
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

test('buildApplication attaches a real CurrentUser (guest) globally', function (): void {
    CurrentUser::current()->reset();

    CliBootstrap::buildApplication();

    expect(CurrentUser::current()->get()->status)->toBe(UserStatus::Guest);

    CurrentUser::current()->reset();
});

test('buildApplication initializes CurrentConfigService with a real, resolved ConfigService', function (): void {
    CliBootstrap::buildApplication();

    expect(fn () => CurrentConfigServiceTestFactory::get()->get())->not->toThrow(LogicException::class);
    expect(CurrentConfigServiceTestFactory::get()->get())->toBeInstanceOf(ConfigService::class);
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

    expect(pcntl_signal_get_handler(SIGTERM))->not->toBe(SIG_DFL);

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

test('buildApplication throws when the commands file does not return an array', function (): void {
    $commandsFile = sys_get_temp_dir() . '/piwigo-commands-not-array-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($commandsFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn 'not-an-array';\n");

    try {
        CliBootstrap::buildApplication(commandsFile: $commandsFile);
    } finally {
        unlink($commandsFile);
    }
})->throws(RuntimeException::class, 'config/commands.php must return an array of Command class-strings.');

test('buildApplication throws when a commands file entry is not a class-string', function (): void {
    $commandsFile = sys_get_temp_dir() . '/piwigo-commands-bad-entry-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($commandsFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn [123];\n");

    try {
        CliBootstrap::buildApplication(commandsFile: $commandsFile);
    } finally {
        unlink($commandsFile);
    }
})->throws(RuntimeException::class, 'config/commands.php entries must be class-strings.');

test('buildApplication throws when a commands file entry does not resolve to a Command', function (): void {
    // stdClass is a real, container-autowirable class (no constructor
    // args) that is deliberately not a Symfony Console Command -- proves
    // the guard checks the *resolved instance*, not just that the
    // class-string exists/autowires.
    $commandsFile = sys_get_temp_dir() . '/piwigo-commands-not-command-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($commandsFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn [stdClass::class];\n");

    try {
        CliBootstrap::buildApplication(commandsFile: $commandsFile);
    } finally {
        unlink($commandsFile);
    }
})->throws(LogicException::class, 'config/commands.php entry stdClass did not resolve to a Symfony Console Command.');
