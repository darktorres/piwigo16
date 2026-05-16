<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Controller\ImageDerivativeController;
use Piwigo\Controller\InstallController;
use Piwigo\Controller\UpgradeController;
use Piwigo\Controller\UpgradeFeedController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');

$_qs = ltrim(is_string($_SERVER['QUERY_STRING'] ?? null) ? $_SERVER['QUERY_STRING'] : '', '/');

if (str_starts_with($_qs, 'i/')) {
    // Image derivative fast-path — same minimal bootstrap as the former i.php.
    // Skips CommonBootstrap (no session, no user, no plugins) for performance.
    defined('PWG_LOCAL_DIR')      or define('PWG_LOCAL_DIR', 'local/');
    require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
    ConfigLoader::applyDefaults();
    ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
    ConfigLoader::applyEnvOverrides();
    $logger = new Logger([
        'directory' => PHPWG_ROOT_PATH . Config::dataLocation() . Config::logDir(),
        'severity'  => Config::logLevel(),
        'filename'  => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
    ]);
    LoggerRegistry::set($logger);
    Kernel::bootMinimal();
    Kernel::service(ImageDerivativeController::class)(RequestFactory::fromGlobals());
    exit;
}

if (str_starts_with($_qs, 'install')) {
    // Install wizard — no DB yet; bypass the full boot pipeline.
    defined('DEFAULT_PREFIX_TABLE') or define('DEFAULT_PREFIX_TABLE', 'piwigo_');
    defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
    require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
    ConfigLoader::applyDefaults();
    (new InstallController())(RequestFactory::fromGlobals());
    exit;
}

if (str_starts_with($_qs, 'upgrade_feed')) {
    // Upgrade feed — DB schema may be mid-migration; bypass the full boot pipeline.
    defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
    require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
    ConfigLoader::applyDefaults();
    ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
    ConfigLoader::applyEnvOverrides();
    Kernel::boot();
    Kernel::service(UpgradeFeedController::class)(RequestFactory::fromGlobals());
    exit;
}

if (str_starts_with($_qs, 'upgrade')) {
    // Upgrade wizard — DB exists but schema is stale; bypass the full boot pipeline.
    if (function_exists('ini_set')) {
        ini_set('opcache.enable', '0');
    }
    defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
    require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
    ConfigLoader::applyDefaults();
    ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
    ConfigLoader::applyEnvOverrides();
    Kernel::boot();
    Kernel::service(UpgradeController::class)(RequestFactory::fromGlobals());
    exit;
}

require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
CommonBootstrap::run();
Kernel::boot();

new ResponseEmitter()->emit(
    Kernel::handle(RequestFactory::fromGlobals())
);
