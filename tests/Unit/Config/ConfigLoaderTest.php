<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\MissingRequiredConfigException;
use Piwigo\Core\Paths;

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

// Legacy Coupling Retirement gap-closure: applyLocalFileOverrides() bridges
// a site's own local/config/config.inc.php into Config:: -- previously
// included into a bare $conf array (common.inc.php) that nothing in the
// Config::-based request path ever read.

function config_loader_test_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $entries = scandir($dir);
    foreach ($entries !== false ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? config_loader_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('applyLocalFileOverrides syncs a key the site file sets into Config::', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-config-loader-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    file_put_contents(
        $root . '/local/config/config.inc.php',
        "<?php\n\$conf['order_by_custom'] = 'ORDER BY test_column ASC';\n"
    );

    ConfigLoader::applyLocalFileOverrides(Paths::fromRoot($root));

    expect(Config::all()['order_by_custom'])->toBe('ORDER BY test_column ASC');

    config_loader_test_rrmdir($root);
});

test('applyLocalFileOverrides leaves a non-overridden key at its normal default', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-config-loader-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    file_put_contents(
        $root . '/local/config/config.inc.php',
        "<?php\n\$conf['order_by_custom'] = 'ORDER BY test_column ASC';\n"
    );

    ConfigLoader::applyDefaults();
    ConfigLoader::applyLocalFileOverrides(Paths::fromRoot($root));

    expect(Config::galleryTitle())->toBe('Piwigo');

    config_loader_test_rrmdir($root);
});

test('applyLocalFileOverrides silently drops a key not in Config::SCHEMA', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-config-loader-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    file_put_contents(
        $root . '/local/config/config.inc.php',
        "<?php\n\$conf['some_totally_unknown_legacy_key'] = 'should not appear';\n"
    );

    ConfigLoader::applyLocalFileOverrides(Paths::fromRoot($root));

    // Non-literal key argument on purpose -- Config::has() with a literal
    // unknown key trips the ConfigKeyExistsRule PHPStan safety net (by
    // design, it can't tell "deliberate test of unknown-key handling" from
    // a real typo); its own documented escape hatch is a dynamic key.
    $unknownKey = 'some_totally_unknown_legacy_key';
    expect(Config::has($unknownKey))->toBeFalse();

    config_loader_test_rrmdir($root);
});

test('applyLocalFileOverrides also reads the local_dir_site dir-site file when set', function (): void {
    $originalLocalDir = getenv('PIWIGO_LOCAL_DIR');
    $originalLocalDir = $originalLocalDir === false ? null : $originalLocalDir;
    putenv('PIWIGO_LOCAL_DIR=test-dir-site');

    $root = sys_get_temp_dir() . '/piwigo-config-loader-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    file_put_contents(
        $root . '/local/config/config.inc.php',
        "<?php\n\$conf['local_dir_site'] = true;\n"
    );
    mkdir($root . '/test-dir-site/config', 0o777, true);
    file_put_contents(
        $root . '/test-dir-site/config/config.inc.php',
        "<?php\n\$conf['gallery_title'] = 'Dir-Site Gallery';\n"
    );

    try {
        ConfigLoader::applyLocalFileOverrides(Paths::fromRoot($root));

        expect(Config::all()['gallery_title'])->toBe('Dir-Site Gallery');
    } finally {
        putenv($originalLocalDir === null ? 'PIWIGO_LOCAL_DIR' : 'PIWIGO_LOCAL_DIR=' . $originalLocalDir);
        config_loader_test_rrmdir($root);
    }
});

test('applyLocalFileOverrides is idempotent', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-config-loader-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    file_put_contents(
        $root . '/local/config/config.inc.php',
        "<?php\n\$conf['order_by_custom'] = 'ORDER BY test_column ASC';\n"
    );

    ConfigLoader::applyLocalFileOverrides(Paths::fromRoot($root));
    $first = Config::all();
    ConfigLoader::applyLocalFileOverrides(Paths::fromRoot($root));

    expect(Config::all())->toBe($first);

    config_loader_test_rrmdir($root);
});
