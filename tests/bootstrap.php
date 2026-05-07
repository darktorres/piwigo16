<?php

declare(strict_types=1);


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
\Piwigo\Config\ConfigLoader::loadEnv(dirname(__DIR__), ['.env.test']);

// PHPWG_VERSION migrated to AppInfo::VERSION — no PHP define needed.

// Root path constant required by classes that build file paths.
// Points to the repository root; safe for unit tests that use tmp dirs.
if (!defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', dirname(__DIR__) . '/');
}

// Web-service constants from include/ws_core.inc.php.
if (!defined('WS_PARAM_OPTIONAL')) {
    define('WS_PARAM_ACCEPT_ARRAY', 0x010000);
    define('WS_PARAM_FORCE_ARRAY', 0x030000);
    define('WS_PARAM_OPTIONAL', 0x040000);
    define('WS_TYPE_BOOL', 0x01);
    define('WS_TYPE_INT', 0x02);
    define('WS_TYPE_FLOAT', 0x04);
    define('WS_TYPE_POSITIVE', 0x10);
    define('WS_TYPE_NOTNULL', 0x20);
    define('WS_TYPE_ID', WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL);
}

// Stub Piwigo global functions needed by unit-tested classes that normally
// depend on the full HTTP/DB bootstrap not present in isolated unit tests.
if (!function_exists('set_status_header')) {
    function set_status_header(int $code, string $text = ''): void
    {
        // No-op in unit test environment — headers cannot be sent from CLI.
    }
}

// Derivative image type constants from include/derivative_std_params.inc.php.
if (!defined('IMG_CUSTOM')) {
    define('IMG_SQUARE', 'square');
    define('IMG_THUMB', 'thumb');
    define('IMG_XXSMALL', '2small');
    define('IMG_XSMALL', 'xsmall');
    define('IMG_SMALL', 'small');
    define('IMG_MEDIUM', 'medium');
    define('IMG_LARGE', 'large');
    define('IMG_XLARGE', 'xlarge');
    define('IMG_XXLARGE', 'xxlarge');
    define('IMG_3XLARGE', '3xlarge');
    define('IMG_4XLARGE', '4xlarge');
    define('IMG_CUSTOM', 'custom');
}

// Search-module constants from include/functions_search.inc.php.
if (!defined('QST_QUOTED')) {
    define('QST_QUOTED', 0x01);
    define('QST_NOT', 0x02);
    define('QST_OR', 0x04);
    define('QST_WILDCARD_BEGIN', 0x08);
    define('QST_WILDCARD_END', 0x10);
    define('QST_WILDCARD', QST_WILDCARD_BEGIN | QST_WILDCARD_END);
    define('QST_BREAK', 0x20);
}

// Web-service XML constant from include/ws_core.inc.php.
if (!defined('WS_XML_ATTRIBUTES')) {
    define('WS_XML_ATTRIBUTES', 'attributes_xml_');
}

// File extension helper stub used by SrcImage and other image classes.
if (!function_exists('get_extension')) {
    function get_extension(string $filename): string
    {
        $ext = strrchr($filename, '.');
        return $ext !== false ? strtolower(substr($ext, 1)) : '';
    }
}

// Charset helper stub used by PwgRestEncoder.
if (!function_exists('get_pwg_charset')) {
    function get_pwg_charset(): string
    {
        return 'utf-8';
    }
}
