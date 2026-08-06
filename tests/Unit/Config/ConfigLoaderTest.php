<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\MissingRequiredConfigException;

beforeEach(function (): void {
    CurrentConfig::current()->reset();
});

afterEach(function (): void {
    CurrentConfig::current()->reset();
});

test('applyDefaults is a genuine no-op -- every property already carries its own default', function (): void {
    CurrentConfig::current()->setGalleryTitle('Already set');

    ConfigLoader::applyDefaults();

    expect(CurrentConfig::current()->galleryTitle())->toBe('Already set');
});

test('applyEnvOverrides is a genuine no-op', function (): void {
    CurrentConfig::current()->setGalleryTitle('Already set');

    ConfigLoader::applyEnvOverrides();

    expect(CurrentConfig::current()->galleryTitle())->toBe('Already set');
});

test('validateRequired throws when a required key is missing', function (): void {
    // secretKey (the one #[Required] property) defaults to '', still empty
    // -- no applyDefaults() call needed, the property already carries its
    // own default from the moment the class loads.
    expect(static fn () => ConfigLoader::validateRequired(CurrentConfig::current()))
        ->toThrow(MissingRequiredConfigException::class);
});

test('validateRequired passes when every required key is set', function (): void {
    CurrentConfig::current()->setSecretKey('a-real-secret');

    ConfigLoader::validateRequired(CurrentConfig::current()); // should not throw
    expect(true)->toBeTrue();
});
