<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;

/**
 * Piwigo\Bootstrap\RequestBootstrap::pemUrl() -- pure, side-effect-free,
 * had zero dedicated coverage. Only the alternativePemUrl() override
 * branch was red; the fallback (AppInfo::URL . '/ext') is already
 * exercised indirectly wherever this is called with no override
 * configured.
 */
afterEach(function (): void {
    CurrentConfig::reset();
});

test('pemUrl returns the alternative PEM URL when one is configured', function (): void {
    CurrentConfig::setAlternativePemUrl('https://pem.example.test/mirror');

    expect(RequestBootstrap::pemUrl())->toBe('https://pem.example.test/mirror');
});

test('pemUrl falls back to AppInfo::URL . "/ext" when no alternative is configured', function (): void {
    CurrentConfig::setAlternativePemUrl('');

    expect(RequestBootstrap::pemUrl())->toBe(AppInfo::URL . '/ext');
});
