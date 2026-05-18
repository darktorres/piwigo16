<?php

declare(strict_types=1);

use Piwigo\Config\ConfigLoader;

require __DIR__ . '/../vendor/autoload.php';

// Mark this PHP process as a test-mode runtime BEFORE the loader reads
// any env file. ConfigLoader / InstallSentinel / install.php all check
// Piwigo\Config\TestMode, which inspects $_SERVER['HTTP_X_PIWIGO_ENV']
// and is satisfied here in CLI mode. Result: every in-process runtime
// invocation reads .env.test (not .env) and uses local/.installed.test
// (not local/.installed).
$_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';

// Required: a self-contained .env.test at the repo root. We do NOT fall
// back to .env — silently inheriting prod credentials is exactly the
// poisoning bug the two-file split is designed to prevent.
$envTestPath = dirname(__DIR__) . '/.env.test';
if (!is_file($envTestPath)) {
    fwrite(STDERR, "tests/bootstrap.php: missing $envTestPath\n");
    fwrite(STDERR, "Create it from .env.example and fill in test DB credentials.\n");
    exit(1);
}
ConfigLoader::loadEnv(dirname(__DIR__), ['.env.test']);

// PHPWG_VERSION migrated to AppInfo::VERSION — no PHP define needed.
// PHPWG_ROOT_PATH eliminated in Phase 6: tests construct
// Paths::fromRoot(dirname(__DIR__, N)) directly and boot Kernel with it
// when exercising production code that resolves Paths via DI.
