<?php

declare(strict_types=1);

use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;

/**
 * No prior CurrentLoggerTest.php existed. A Logger constructed with
 * severity: OFF returns before touching the filesystem at all (see
 * Logger::__construct()'s own early-return), so this file never needs a
 * real log directory.
 */
beforeEach(function (): void {
    CurrentLogger::reset();
});

afterEach(function (): void {
    CurrentLogger::reset();
});

test('get throws when no Logger has been set yet', function (): void {
    expect(CurrentLogger::isInitialized())->toBeFalse();

    expect(fn () => CurrentLogger::get())->toThrow(
        \LogicException::class,
        'CurrentLogger not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect() or Piwigo\Controller\ImageDerivativeController::__invoke() first.',
    );
});

test('set installs the instance that get returns, and isInitialized reflects it', function (): void {
    $logger = new Logger(['severity' => Logger::OFF]);

    CurrentLogger::set($logger);

    expect(CurrentLogger::isInitialized())->toBeTrue()
        ->and(CurrentLogger::get())->toBe($logger);
});

test('reset clears the instance back to uninitialized', function (): void {
    CurrentLogger::set(new Logger(['severity' => Logger::OFF]));
    expect(CurrentLogger::isInitialized())->toBeTrue();

    CurrentLogger::reset();

    expect(CurrentLogger::isInitialized())->toBeFalse();
    expect(fn () => CurrentLogger::get())->toThrow(\LogicException::class);
});
