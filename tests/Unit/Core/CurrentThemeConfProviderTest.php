<?php

declare(strict_types=1);

use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\Kernel;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Core\CurrentThemeConfProvider's own current() is the exact same
 * "container-shared when booted, memoized static fallback otherwise"
 * shape as Piwigo\Template\CurrentTemplate::current() (see
 * CurrentTemplateTest's own equivalent pair of tests below) -- this class
 * had zero dedicated coverage of current() itself, only indirect exercise
 * via SrcImageTest's helper, which always runs with Kernel already booted
 * and never asserts current()'s own return-value identity.
 */
afterEach(function (): void {
    Kernel::reset();
});

test('current() falls back to a memoized instance when Kernel is not booted', function (): void {
    // Kills line 46's CoalesceEqualToEqual (`self::$fallback = new self()`
    // instead of `??=`) -- a bare `=` would build a brand-new instance on
    // every not-booted call, losing whatever set() published on an
    // earlier call.
    expect(Kernel::isBooted())->toBeFalse();

    $provider = new class() implements ThemeConfProviderInterface {
        public function themeConf(string $key): string
        {
            return '';
        }
    };

    $first = CurrentThemeConfProvider::current();
    $first->set($provider);

    $second = CurrentThemeConfProvider::current();

    expect($second)
        ->toBe($first)
        ->and($second->get())
        ->toBe($provider);
});

test('current() resolves the container-shared instance once Kernel is booted', function (): void {
    // Kills line 43's RemoveEarlyReturn (dropping `return $instance;`) --
    // without it, execution falls through to the not-booted fallback
    // branch even while Kernel IS booted, silently returning a
    // disconnected instance instead of the real container singleton.
    Kernel::boot();
    $instance = Kernel::container()->get(CurrentThemeConfProvider::class);

    expect(CurrentThemeConfProvider::current())->toBe($instance);
});

test('current() throws when the container returns an unexpected type', function (): void {
    // Kills line 39's InstanceOfToTrue (`if (!true)`, never taking the
    // throw branch).
    KernelContainerOverride::withWrongTypeFor(
        CurrentThemeConfProvider::class,
        static fn () => CurrentThemeConfProvider::current(),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentThemeConfProvider::class);
