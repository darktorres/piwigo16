<?php

declare(strict_types=1);

// PHPWG_ROOT_PATH eliminated in Phase 6; production callers receive Paths
// via DI. PHPWG_VERSION migrated to AppInfo::VERSION.
define('PWG_LOCAL_DIR', 'local/');

// PREFIX_TABLE is defined at runtime by UpgradeController / UpgradeFeedController (upgrade path only).
// Declared here as a PHPStan placeholder so migration-step analysis resolves it.
define('PREFIX_TABLE', 'piwigo_');

// Runtime constants from CommonBootstrap::run() — placeholder values for static analysis.
if (!defined('PHPWG_URL')) {
    define('PHPWG_URL', 'https://piwigo.org');
    define('PEM_URL', 'https://piwigo.org/ext');
}

// Admin URL constants — defined at runtime by PhotoController; declared here for static analysis.
if (!defined('PHOTOS_ADD_BASE_URL')) {
    define('PHOTOS_ADD_BASE_URL', 'index.php?/admin&page=photos_add');
}
