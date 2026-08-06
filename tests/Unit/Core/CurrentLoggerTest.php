<?php

declare(strict_types=1);

use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Tests\Support\CurrentLoggerTestFactory;

/**
 * A Logger constructed with severity: OFF returns before touching the
 * filesystem at all (see Logger::__construct()'s own early-return), so
 * this file never needs a real log directory.
 *
 * Container-shared instance (singleton/service-locator elimination
 * campaign, Phase 2) -- each test constructs its own fresh instance
 * directly; no reset() needed for the instance API. getStatic()'s own
 * Kernel::isBooted() branches are covered separately below.
 */
test('get throws when no Logger has been set yet', function (): void {
    $currentLogger = new CurrentLogger();

    expect($currentLogger->isInitialized())->toBeFalse();

    expect(fn () => $currentLogger->get())->toThrow(
        LogicException::class,
        'CurrentLogger not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect() or Piwigo\Controller\ImageDerivativeController::__invoke() first.',
    );
});

test('set installs the instance that get returns, and isInitialized reflects it', function (): void {
    $currentLogger = new CurrentLogger();
    $logger = new Logger(['severity' => Logger::OFF]);

    $currentLogger->set($logger);

    expect($currentLogger->isInitialized())->toBeTrue()
        ->and($currentLogger->get())->toBe($logger);
});

test('reset clears the instance back to uninitialized', function (): void {
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger(['severity' => Logger::OFF]));
    expect($currentLogger->isInitialized())->toBeTrue();

    $currentLogger->reset();

    expect($currentLogger->isInitialized())->toBeFalse();
    expect(fn () => $currentLogger->get())->toThrow(LogicException::class);
});

test('CurrentLoggerTestFactory::getStatic falls back to a no-op OFF Logger when Kernel has not booted', function (): void {
    expect(Kernel::isBooted())->toBeFalse();

    $logger = CurrentLoggerTestFactory::getStatic();

    expect($logger->severity())->toBe(Logger::OFF);
});

test('CurrentLoggerTestFactory::getStatic delegates to the container-shared instance once Kernel has booted', function (): void {
    Kernel::boot();
    $currentLogger = Kernel::container()->get(CurrentLogger::class);
    if (! $currentLogger instanceof CurrentLogger) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
    }
    $logger = new Logger(['severity' => Logger::OFF]);
    $currentLogger->set($logger);

    expect(CurrentLoggerTestFactory::getStatic())->toBe($logger);

    Kernel::reset();
});
