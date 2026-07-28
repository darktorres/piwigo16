<?php

declare(strict_types=1);

use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;

beforeEach(function (): void {
    CurrentPaths::reset();
});

afterEach(function (): void {
    CurrentPaths::reset();
});

test('get throws when no Paths has been set yet', function (): void {
    expect(CurrentPaths::isSet())->toBeFalse();

    expect(fn () => CurrentPaths::get())->toThrow(
        \LogicException::class,
        'CurrentPaths not initialised -- call Piwigo\Core\Kernel::boot() first.',
    );
});

test('set installs the Paths instance that get returns, and isSet reflects it', function (): void {
    $paths = Paths::fromRoot('/tmp/piwigo-current-paths-test');

    CurrentPaths::set($paths);

    expect(CurrentPaths::isSet())->toBeTrue()
        ->and(CurrentPaths::get())->toBe($paths);
});

test('reset clears the instance back to unset', function (): void {
    CurrentPaths::set(Paths::fromRoot('/tmp/piwigo-current-paths-test'));
    expect(CurrentPaths::isSet())->toBeTrue();

    CurrentPaths::reset();

    expect(CurrentPaths::isSet())->toBeFalse();
    expect(fn () => CurrentPaths::get())->toThrow(\LogicException::class);
});
