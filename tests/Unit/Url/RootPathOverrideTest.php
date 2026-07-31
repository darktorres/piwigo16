<?php

declare(strict_types=1);

use Piwigo\Url\RootPathOverride;

beforeEach(function (): void {
    RootPathOverride::reset();
});

afterEach(function (): void {
    RootPathOverride::reset();
});

test('current returns null before any push', function (): void {
    expect(RootPathOverride::current())->toBeNull();
});

test('push then current returns the pushed path', function (): void {
    RootPathOverride::push('/gallery/');

    expect(RootPathOverride::current())->toBe('/gallery/');
});

test('a nested push keeps the outermost path, and current stays set until the matching pop', function (): void {
    RootPathOverride::push('/outer/');
    RootPathOverride::push('/inner/');

    // The 2nd (nested) push does NOT overwrite $path -- only a push from
    // count===0 does.
    expect(RootPathOverride::current())->toBe('/outer/');

    RootPathOverride::pop();
    expect(RootPathOverride::current())->toBe('/outer/');

    RootPathOverride::pop();
    expect(RootPathOverride::current())->toBeNull();
});

test('pop() on an already-empty stack is a no-op, not a negative count', function (): void {
    // No matching push() -- pop()'s own `if (self::$count === 0) { return; }`
    // guard, defending against an unbalanced pop()/push() pair (a bug
    // elsewhere) turning $count negative, which would otherwise make a
    // single later push() + pop() incorrectly leave current() still set
    // (since decrementing from -1 never lands back on the 0 that clears
    // $path).
    RootPathOverride::pop();

    expect(RootPathOverride::current())->toBeNull();

    RootPathOverride::push('/after-stray-pop/');
    expect(RootPathOverride::current())->toBe('/after-stray-pop/');

    RootPathOverride::pop();
    expect(RootPathOverride::current())->toBeNull();
});

test('reset clears an active override', function (): void {
    RootPathOverride::push('/gallery/');

    RootPathOverride::reset();

    expect(RootPathOverride::current())->toBeNull();
});
