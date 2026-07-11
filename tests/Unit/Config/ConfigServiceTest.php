<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;

beforeEach(function (): void {
    Config::reset();
});

afterEach(function (): void {
    Config::reset();
});

test('confGetParam reads a dynamic key not in SCHEMA', function (): void {
    Config::override('blk_menubar', 'some-layout-blob');

    $service = new ConfigService();

    expect($service->confGetParam('blk_menubar'))->toBe('some-layout-blob');
});

test('confGetParam falls back to the given default when the key is unset', function (): void {
    $service = new ConfigService();

    expect($service->confGetParam('never_set_key', 'fallback'))->toBe('fallback')
        ->and($service->confGetParam('never_set_key'))->toBeNull();
});
