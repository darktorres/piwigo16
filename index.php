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
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

require_once __DIR__ . '/vendor/autoload.php';

$paths = Paths::fromIndex(__FILE__);

$_qs = ltrim(is_string($_SERVER['QUERY_STRING'] ?? null) ? $_SERVER['QUERY_STRING'] : '', '/');

if (str_starts_with($_qs, 'i/')) {
    // Image derivative fast-path — same minimal bootstrap as the former i.php.
    // Skips CommonBootstrap (no session, no user, no plugins) for performance.
    ConfigLoader::applyDefaults();
    ConfigLoader::loadEnv($paths->root);
    ConfigLoader::applyEnvOverrides();
    $logger = new Logger([
        'directory' => $paths->root . Config::dataLocation() . Config::logDir(),
        'severity'  => Config::logLevel(),
        'filename'  => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
    ]);
    LoggerRegistry::set($logger);
    Kernel::bootMinimal($paths);
    Kernel::service(ImageDerivativeController::class)(RequestFactory::fromGlobals());
    exit;
}

if (str_starts_with($_qs, 'install')) {
    // Install wizard — no DB yet; bypass the full boot pipeline.
    ConfigLoader::applyDefaults();
    (new InstallController($paths))(RequestFactory::fromGlobals());
    exit;
}

if (str_starts_with($_qs, 'upgrade_feed')) {
    // Upgrade feed — DB schema may be mid-migration; bypass the full boot pipeline.
    ConfigLoader::applyDefaults();
    ConfigLoader::loadEnv($paths->root);
    ConfigLoader::applyEnvOverrides();
    Kernel::boot($paths);
    Kernel::service(UpgradeFeedController::class)(RequestFactory::fromGlobals());
    exit;
}

if (str_starts_with($_qs, 'upgrade')) {
    // Upgrade wizard — DB exists but schema is stale; bypass the full boot pipeline.
    if (function_exists('ini_set')) {
        ini_set('opcache.enable', '0');
    }
    ConfigLoader::applyDefaults();
    ConfigLoader::loadEnv($paths->root);
    ConfigLoader::applyEnvOverrides();
    Kernel::boot($paths);
    Kernel::service(UpgradeController::class)(RequestFactory::fromGlobals());
    exit;
}

CommonBootstrap::run($paths);
Kernel::boot($paths);

new ResponseEmitter()->emit(
    Kernel::handle(RequestFactory::fromGlobals())
);
