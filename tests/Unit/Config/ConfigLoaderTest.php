<?php

declare(strict_types=1);

use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\MissingRequiredConfigException;

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

test('applyDefaults is a genuine no-op -- every property already carries its own default', function (): void {
    CurrentConfigTestFactory::get()->setGalleryTitle('Already set');

    ConfigLoader::applyDefaults();

    expect(CurrentConfigTestFactory::get()->galleryTitle())->toBe('Already set');
});

test('applyEnvOverrides is a genuine no-op', function (): void {
    CurrentConfigTestFactory::get()->setGalleryTitle('Already set');

    ConfigLoader::applyEnvOverrides();

    expect(CurrentConfigTestFactory::get()->galleryTitle())->toBe('Already set');
});

test('validateRequired throws when a required key is missing', function (): void {
    // secretKey (the one #[Required] property) defaults to '', still empty
    // -- no applyDefaults() call needed, the property already carries its
    // own default from the moment the class loads.
    expect(static fn () => ConfigLoader::validateRequired(CurrentConfigTestFactory::get()))
        ->toThrow(MissingRequiredConfigException::class);
});

test('validateRequired passes when every required key is set', function (): void {
    CurrentConfigTestFactory::get()->setSecretKey('a-real-secret');

    ConfigLoader::validateRequired(CurrentConfigTestFactory::get()); // should not throw
    expect(true)->toBeTrue();
});
