<?php

declare(strict_types=1);

use Piwigo\Config\ConfigLoader;
use Piwigo\Controller\InstallController;
use Piwigo\Controller\UpgradeController;
use Piwigo\Core\Kernel;
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

if (str_starts_with($_qs, 'install')) {
    // Install wizard — no DB yet; bypass the full boot pipeline.
    defined('DEFAULT_PREFIX_TABLE') or define('DEFAULT_PREFIX_TABLE', 'piwigo_');
    defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
    require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
    require PHPWG_ROOT_PATH . 'include/functions.inc.php';
    ConfigLoader::applyDefaults();
    (new InstallController())(RequestFactory::fromGlobals());
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
    (new UpgradeController())(RequestFactory::fromGlobals());
    exit;
}

require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
Kernel::boot();

new ResponseEmitter()->emit(
    Kernel::handle(RequestFactory::fromGlobals())
);
