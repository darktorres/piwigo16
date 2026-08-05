<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;

/**
 * Piwigo\Bootstrap\RequestBootstrap::pemUrl() -- pure, side-effect-free,
 * had zero dedicated coverage. Only the alternativePemUrl() override
 * branch was red; the fallback (AppInfo::URL . '/ext') is already
 * exercised indirectly wherever this is called with no override
 * configured.
 *
 * pemUrl() reads through self::currentConfig(), which resolves
 * CurrentConfig straight from Kernel::container() (singleton/
 * service-locator elimination campaign, Phase 9) with no not-booted
 * fallback of its own -- unlike the CurrentConfig::current() shim, so
 * each test below boots the Kernel first and writes onto that same
 * container-shared instance.
 */
afterEach(function (): void {
    CurrentConfig::current()->reset();
    Kernel::reset();
});

test('pemUrl returns the alternative PEM URL when one is configured', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->setAlternativePemUrl('https://pem.example.test/mirror');

    expect(RequestBootstrap::pemUrl())->toBe('https://pem.example.test/mirror');
});

test('pemUrl falls back to AppInfo::URL . "/ext" when no alternative is configured', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->setAlternativePemUrl('');

    expect(RequestBootstrap::pemUrl())->toBe(AppInfo::URL . '/ext');
});
