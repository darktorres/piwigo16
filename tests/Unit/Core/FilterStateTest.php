<?php

declare(strict_types=1);

use Piwigo\Core\FilterState;

/**
 * set()/the getters' happy path already have full coverage through real
 * callers (FilterService, PermissionService, CategoryService, etc.) --
 * this file closes the one remaining gap: assertInitialized()'s own
 * LogicException, never triggered by any real caller since
 * RequestBootstrap::finalize() always calls set() (directly or via its
 * disabled-filter fallback) before anything else can read this class.
 */
beforeEach(function (): void {
    FilterState::reset();
});

afterEach(function (): void {
    FilterState::reset();
});

test('every getter throws before set() has ever been called', function (): void {
    expect(FilterState::isInitialized())->toBeFalse();

    $message = 'FilterState not initialised -- call Piwigo\Filter\FilterService::initializeFromRequest() (or RequestBootstrap::finalize()\'s own disabled-filter fallback) first.';

    expect(fn () => FilterState::isEnabled())->toThrow(\LogicException::class, $message);
    expect(fn () => FilterState::visibleCategories())->toThrow(\LogicException::class, $message);
    expect(fn () => FilterState::visibleImages())->toThrow(\LogicException::class, $message);
    expect(fn () => FilterState::categories())->toThrow(\LogicException::class, $message);
});

test('set() initializes every field and isInitialized flips to true', function (): void {
    FilterState::set(true, 'a,b,c', 'x,y', ['1' => ['name' => 'Holidays']]);

    expect(FilterState::isInitialized())->toBeTrue()
        ->and(FilterState::isEnabled())->toBeTrue()
        ->and(FilterState::visibleCategories())->toBe('a,b,c')
        ->and(FilterState::visibleImages())->toBe('x,y')
        ->and(FilterState::categories())->toBe(['1' => ['name' => 'Holidays']]);
});

test('reset() clears every field back to its uninitialized default', function (): void {
    FilterState::set(true, 'a,b,c', 'x,y', ['1' => ['name' => 'Holidays']]);

    FilterState::reset();

    expect(FilterState::isInitialized())->toBeFalse();
});
