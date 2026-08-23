<?php

declare(strict_types=1);

use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeConfProviderInterface;

/**
 * Piwigo\Core\CurrentThemeConfProvider has no `current()` service-locator
 * method anymore (Finding 0, post-DI-campaign shim/facade audit) --
 * SrcImage::themeConf() resolves Kernel::container()->get(self::class)
 * directly, matching its sibling collaborator methods. This file now
 * covers get()/set()/reset() directly rather than through current(); the
 * indirect exercise via SrcImageTest.php's own helper still covers
 * SrcImage's own integration with this class.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-current-theme-conf-provider-test'));
});

afterEach(function (): void {
    Kernel::reset();
});

test('the container-shared instance resolves to the same object on every call within one boot', function (): void {
    $first = Kernel::container()->get(CurrentThemeConfProvider::class);
    $second = Kernel::container()->get(CurrentThemeConfProvider::class);

    expect($first)
        ->toBe($second);
});

test('a fresh instance starts with no provider set', function (): void {
    $instance = Kernel::container()->get(CurrentThemeConfProvider::class);
    expect($instance)
        ->toBeInstanceOf(CurrentThemeConfProvider::class);
    if (! $instance instanceof CurrentThemeConfProvider) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }

    expect(fn (): ThemeConfProviderInterface => $instance->get())
        ->toThrow(RuntimeException::class, 'SrcImage: no theme-conf provider set (Template not constructed yet?)');
});

test('set() then get() returns the same provider instance', function (): void {
    $instance = Kernel::container()->get(CurrentThemeConfProvider::class);
    expect($instance)
        ->toBeInstanceOf(CurrentThemeConfProvider::class);
    if (! $instance instanceof CurrentThemeConfProvider) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }

    $provider = new class() implements ThemeConfProviderInterface {
        #[\Override]
        public function themeConf(string $key): string
        {
            return '';
        }
    };

    $instance->set($provider);

    expect($instance->get())
        ->toBe($provider);
});

test('reset() clears a previously set provider, so get() throws again afterward', function (): void {
    $instance = Kernel::container()->get(CurrentThemeConfProvider::class);
    expect($instance)
        ->toBeInstanceOf(CurrentThemeConfProvider::class);
    if (! $instance instanceof CurrentThemeConfProvider) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }

    $instance->set(new class() implements ThemeConfProviderInterface {
        #[\Override]
        public function themeConf(string $key): string
        {
            return '';
        }
    });

    $instance->reset();

    expect(fn (): ThemeConfProviderInterface => $instance->get())
        ->toThrow(RuntimeException::class, 'SrcImage: no theme-conf provider set (Template not constructed yet?)');
});
