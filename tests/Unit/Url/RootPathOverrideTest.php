<?php

declare(strict_types=1);

use Piwigo\Url\RootPathOverride;

test('current returns null before any push', function (): void {
    $rootPathOverride = new RootPathOverride();

    expect($rootPathOverride->current())
        ->toBeNull();
});

test('push then current returns the pushed path', function (): void {
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/gallery/');

    expect($rootPathOverride->current())
        ->toBe('/gallery/');
});

test('a nested push keeps the outermost path, and current stays set until the matching pop', function (): void {
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/outer/');
    $rootPathOverride->push('/inner/');

    // The 2nd (nested) push does NOT overwrite $path -- only a push from
    // count===0 does.
    expect($rootPathOverride->current())
        ->toBe('/outer/');

    $rootPathOverride->pop();
    expect($rootPathOverride->current())
        ->toBe('/outer/');

    $rootPathOverride->pop();
    expect($rootPathOverride->current())
        ->toBeNull();
});

test('pop() on an already-empty stack is a no-op, not a negative count', function (): void {
    // No matching push() -- pop()'s own `if ($this->count === 0) { return; }`
    // guard, defending against an unbalanced pop()/push() pair (a bug
    // elsewhere) turning $count negative, which would otherwise make a
    // single later push() + pop() incorrectly leave current() still set
    // (since decrementing from -1 never lands back on the 0 that clears
    // $path).
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->pop();

    expect($rootPathOverride->current())
        ->toBeNull();

    $rootPathOverride->push('/after-stray-pop/');
    expect($rootPathOverride->current())
        ->toBe('/after-stray-pop/');

    $rootPathOverride->pop();
    expect($rootPathOverride->current())
        ->toBeNull();
});

test('reset clears an active override', function (): void {
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/gallery/');

    $rootPathOverride->reset();

    expect($rootPathOverride->current())
        ->toBeNull();
});

test('a fresh push after reset behaves like the very first push, not a nested one', function (): void {
    // Kills reset()'s own $this->count = 0 DecrementInteger/IncrementInteger
    // mutants (-1/1): push()'s `if ($this->count === 0)` guard only sets
    // $path from a genuinely zeroed count. A stray -1 or 1 left over from a
    // mutated reset() would make this push() skip setting $path (count
    // isn't exactly 0), so current() would wrongly stay null instead of
    // returning the freshly pushed path.
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/gallery/');
    $rootPathOverride->reset();

    $rootPathOverride->push('/after-reset/');

    expect($rootPathOverride->current())
        ->toBe('/after-reset/');
});

test('pop() back to an empty stack clears the stored path, not just the ref count', function (): void {
    // current() already hides $path once $count is back to 0, so this
    // reads the instance property directly via reflection -- otherwise the
    // `$this->count--; if ($this->count === 0)` cleanup on the class's
    // own pop() could regress to a no-op (e.g. an off-by-one on that
    // inner guard) without any test noticing, since current()'s own
    // `$count > 0` gate would still mask the stale value.
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/gallery/');
    $rootPathOverride->pop();

    $path = new ReflectionClass(RootPathOverride::class)->getProperty('path');

    expect($path->getValue($rootPathOverride))
        ->toBeNull();
});

test('current() ignores a stale path once the ref count is back to zero', function (): void {
    // Forces $path to remain non-null at $count === 0 (a state the
    // class's own push()/pop() pair never actually produces) to pin
    // down current()'s own guard as strictly "$count > 0", not merely
    // "$count >= 0" -- both would agree on every reachable state, since
    // push()/pop() always clear $path exactly when $count returns to 0.
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/gallery/');
    $rootPathOverride->pop();

    $path = new ReflectionClass(RootPathOverride::class)->getProperty('path');
    $path->setValue($rootPathOverride, '/leaked/');

    expect($rootPathOverride->current())
        ->toBeNull();
});
