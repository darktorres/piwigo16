<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\MissingRequiredConfigException;

// putenv($var)-to-unset must save+restore the original value, never just
// clear it -- see memory feedback_putenv_unset_must_restore.md. PIWIGO_DB_*
// are real infra env vars set once by tests/bootstrap.php for the whole
// suite; clearing them without restoring would corrupt every later
// Integration test's real DB connection.
$dbEnvVars = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX'];
$originalDbEnvVars = [];

beforeEach(function () use ($dbEnvVars, &$originalDbEnvVars): void {
    Config::reset();
    foreach ($dbEnvVars as $var) {
        $value = getenv($var);
        $originalDbEnvVars[$var] = $value === false ? null : $value;
        putenv($var);
    }
});

afterEach(function () use ($dbEnvVars, &$originalDbEnvVars): void {
    Config::reset();
    foreach ($dbEnvVars as $var) {
        putenv($originalDbEnvVars[$var] === null ? $var : $var . '=' . $originalDbEnvVars[$var]);
    }
});

test('applyDefaults seeds a plain-default key', function (): void {
    ConfigLoader::applyDefaults();

    expect(Config::has('gallery_title'))->toBeTrue()
        ->and(Config::galleryTitle())->toBe('Piwigo');
});

test('applyDefaults seeds a custom-accessor key via its own hardcoded default', function (): void {
    ConfigLoader::applyDefaults();

    expect(Config::has('picture_ext'))->toBeTrue()
        ->and(Config::pictureExtensions())->toBe(['jpg', 'jpeg', 'png', 'gif', 'webp']);
});

test('applyDefaults does not seed a null-defaulted (nullable) key', function (): void {
    ConfigLoader::applyDefaults();

    expect(Config::has('gallery_url'))->toBeFalse();
});

test('applyDefaults does not overwrite an already-set key', function (): void {
    Config::override('gallery_title', 'Already set');

    ConfigLoader::applyDefaults();

    expect(Config::galleryTitle())->toBe('Already set');
});

test('applyDefaults is idempotent', function (): void {
    ConfigLoader::applyDefaults();
    $first = Config::all();

    ConfigLoader::applyDefaults();

    expect(Config::all())->toBe($first);
});

test('applyEnvOverrides honors PIWIGO_DB_* when set', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');
    putenv('PIWIGO_DB_USER=piwigo_app');

    ConfigLoader::applyEnvOverrides();

    expect(Config::all()['db_host'])->toBe('db.example.test')
        ->and(Config::all()['db_user'])->toBe('piwigo_app');
});

test('applyEnvOverrides ignores unset or empty env vars', function (): void {
    putenv('PIWIGO_DB_HOST=');

    ConfigLoader::applyEnvOverrides();

    expect(Config::has('db_host'))->toBeFalse();
});

test('applyEnvOverrides runs after applyDefaults so env wins', function (): void {
    putenv('PIWIGO_DB_HOST=db.example.test');

    ConfigLoader::applyDefaults();
    ConfigLoader::applyEnvOverrides();

    expect(Config::all()['db_host'])->toBe('db.example.test');
});

test('validateRequired throws when a required key is missing', function (): void {
    ConfigLoader::applyDefaults(); // seeds everything except db_* (no default) and secret_key

    expect(static fn () => ConfigLoader::validateRequired())
        ->toThrow(MissingRequiredConfigException::class);
});

test('validateRequired passes when every required key is set', function (): void {
    Config::override('db_host', 'localhost');
    Config::override('db_base', 'piwigo');
    Config::override('db_user', 'root');
    Config::override('secret_key', 'a-real-secret');

    ConfigLoader::validateRequired(); // should not throw
    expect(true)->toBeTrue();
});
