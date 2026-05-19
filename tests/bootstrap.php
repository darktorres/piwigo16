<?php

declare(strict_types=1);

use Piwigo\Config\ConfigLoader;

require __DIR__ . '/../vendor/autoload.php';

// Paratest worker isolation: when the suite runs under paratest, each
// worker process gets a TEST_TOKEN (1, 2, …). We materialise a
// per-worker .env.test.w<TOKEN> (derived from .env.test with
// PIWIGO_DB_BASE suffixed by _w<TOKEN>) and route the runtime to it via
// the X-Piwigo-Env: test-w<TOKEN> header. TestMode validates the header
// against ^test(-w\d+)?$ and resolves the env file + sentinel path.
$testToken = getenv('TEST_TOKEN');
$envHeader = 'test';
if (is_string($testToken) && $testToken !== '' && preg_match('/^\d+$/', $testToken) === 1) {
    $envHeader = 'test-w' . $testToken;
    $srcEnv  = dirname(__DIR__) . '/.env.test';
    $destEnv = dirname(__DIR__) . '/.env.' . $envHeader;
    if (is_file($srcEnv) && !is_file($destEnv)) {
        $contents = (string) file_get_contents($srcEnv);
        $contents = preg_replace(
            '/^(PIWIGO_DB_BASE=)(.*)$/m',
            '$1$2_w' . $testToken,
            $contents,
        );
        file_put_contents($destEnv, (string) $contents);
    }

    // Paratest workers inherit the parent paratest process's environment,
    // which already has PIWIGO_* set from the parent's .env.test load.
    // ConfigLoader uses dotenv in immutable mode and will not overwrite
    // those values. Clear PIWIGO_* so the worker's .env.test-w<TOKEN>
    // populates them fresh below. ($_ENV is empty when variables_order
    // omits 'E' — getenv() is the authoritative source.)
    foreach (array_keys(getenv()) as $key) {
        if (str_starts_with($key, 'PIWIGO_')) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }
}

// Mark this PHP process as a test-mode runtime BEFORE the loader reads
// any env file. ConfigLoader / InstallSentinel / install.php all check
// Piwigo\Config\TestMode, which inspects $_SERVER['HTTP_X_PIWIGO_ENV']
// and is satisfied here in CLI mode. Result: every in-process runtime
// invocation reads .env.<header> and uses local/.installed.<header>.
$_SERVER['HTTP_X_PIWIGO_ENV'] = $envHeader;

// Required: a self-contained .env.test at the repo root. We do NOT fall
// back to .env — silently inheriting prod credentials is exactly the
// poisoning bug the two-file split is designed to prevent.
$envTestPath = dirname(__DIR__) . '/.env.test';
if (!is_file($envTestPath)) {
    fwrite(STDERR, "tests/bootstrap.php: missing $envTestPath\n");
    fwrite(STDERR, "Create it from .env.example and fill in test DB credentials.\n");
    exit(1);
}
ConfigLoader::loadEnv(dirname(__DIR__), ['.env.' . $envHeader]);

// PHPWG_VERSION migrated to AppInfo::VERSION — no PHP define needed.
// PHPWG_ROOT_PATH eliminated in Phase 6: tests construct
// Paths::fromRoot(dirname(__DIR__, N)) directly and boot Kernel with it
// when exercising production code that resolves Paths via DI.
