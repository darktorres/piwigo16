<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');
// PHPWG_VERSION migrated to AppInfo::VERSION — no PHP define needed.
define('PWG_LOCAL_DIR', 'local/');

// PREFIX_TABLE is defined at runtime by UpgradeController / UpgradeFeedController (upgrade path only).
// Declared here as a PHPStan placeholder so migration-step analysis resolves it.
define('PREFIX_TABLE', 'piwigo_');

// Runtime constants from CommonBootstrap::run() — placeholder values for static analysis.
if (!defined('PHPWG_DOMAIN')) {
    define('PHPWG_DOMAIN', 'piwigo.org');
    define('PHPWG_URL', 'https://piwigo.org');
    define('PEM_URL', 'https://piwigo.org/ext');
}

// Admin URL constants — defined at runtime by PhotoController; declared here for static analysis.
if (!defined('PHOTOS_ADD_BASE_URL')) {
    define('PHOTOS_ADD_BASE_URL', 'index.php?/admin&page=photos_add');
}

// Stubs for procedural plugin/theme callbacks (defined at runtime by plugin files).
// These allow PHPStan to know the functions exist without is_callable() checks.
if (!function_exists('plugin_install')) {
    function plugin_install(string $plugin_id, string $version, array &$errors = []): mixed
    {
        return null;
    }
    function plugin_activate(string $plugin_id, string $version, array &$errors = []): mixed
    {
        return null;
    }
    function plugin_deactivate(string $plugin_id): mixed
    {
        return null;
    }
    function plugin_uninstall(string $plugin_id): mixed
    {
        return null;
    }
}
if (!function_exists('theme_activate')) {
    function theme_activate(string $theme_id, string $version, array &$errors = []): mixed
    {
        return null;
    }
    function theme_deactivate(string $theme_id): mixed
    {
        return null;
    }
    function theme_delete(string $theme_id): mixed
    {
        return null;
    }
}
