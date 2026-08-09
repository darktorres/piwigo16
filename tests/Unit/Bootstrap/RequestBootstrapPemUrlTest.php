<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;

/**
 * pemUrl() reads through self::currentConfig(), which resolves
 * CurrentConfig directly from Kernel::container() with no not-booted
 * fallback of its own -- unlike the CurrentConfigTestFactory::get() shim, so
 * each test below boots the Kernel first and writes onto that same
 * container-shared instance.
 */
afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('pemUrl returns the alternative PEM URL when one is configured', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->alternativePemUrl = 'https://pem.example.test/mirror';

    expect(RequestBootstrap::pemUrl())->toBe('https://pem.example.test/mirror');
});

test('pemUrl falls back to AppInfo::URL . "/ext" when no alternative is configured', function (): void {
    Kernel::boot();
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->alternativePemUrl = '';

    expect(RequestBootstrap::pemUrl())->toBe(AppInfo::URL . '/ext');
});
