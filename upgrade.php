<?php

declare(strict_types=1);

use Piwigo\Config\ConfigLoader;
use Piwigo\Http\RequestFactory;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Upgrade wizard shim — custom bootstrap (no common.inc.php; reads DB config
// from env file before the full stack is ready).

if (function_exists('ini_set')) {
    ini_set('opcache.enable', '0');
}

define('PHPWG_ROOT_PATH', './');
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
ConfigLoader::applyDefaults();
ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
ConfigLoader::applyEnvOverrides();

(new \Piwigo\Controller\UpgradeController)(RequestFactory::fromGlobals());
