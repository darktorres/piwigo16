<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\MissingRequiredConfigException;

beforeEach(function (): void {
    CurrentConfig::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
});

test('applyDefaults is a genuine no-op -- every property already carries its own default', function (): void {
    CurrentConfig::setGalleryTitle('Already set');

    ConfigLoader::applyDefaults();

    expect(CurrentConfig::galleryTitle())->toBe('Already set');
});

test('applyEnvOverrides is a genuine no-op', function (): void {
    CurrentConfig::setGalleryTitle('Already set');

    ConfigLoader::applyEnvOverrides();

    expect(CurrentConfig::galleryTitle())->toBe('Already set');
});

test('validateRequired throws when a required key is missing', function (): void {
    // secretKey (the one #[Required] property) defaults to '', still empty
    // -- no applyDefaults() call needed, the property already carries its
    // own default from the moment the class loads.
    expect(static fn () => ConfigLoader::validateRequired())
        ->toThrow(MissingRequiredConfigException::class);
});

test('validateRequired passes when every required key is set', function (): void {
    CurrentConfig::setSecretKey('a-real-secret');

    ConfigLoader::validateRequired(); // should not throw
    expect(true)->toBeTrue();
});
