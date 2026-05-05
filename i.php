<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Logger;
use Piwigo\Http\RequestFactory;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Image derivative server shim — minimal bootstrap for performance.
// Deliberately avoids loading common.inc.php and Kernel::boot() since
// this fast-path must not pay the cost of the full Piwigo stack.

define('PHPWG_ROOT_PATH', './');
require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';

ConfigLoader::applyDefaults();
defined('PWG_LOCAL_DIR')      or define('PWG_LOCAL_DIR', 'local/');
defined('PWG_DERIVATIVE_DIR') or define('PWG_DERIVATIVE_DIR', Config::dataLocation() . 'i/');
ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
ConfigLoader::applyEnvOverrides();

$GLOBALS['prefixeTable'] = Config::dbPrefix();

$logger = new Logger([
    'directory' => PHPWG_ROOT_PATH . Config::dataLocation() . Config::logDir(),
    'severity'  => Config::logLevel(),
    'filename'  => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
]);
\Piwigo\Core\LoggerRegistry::set($logger);

// Constants normally from functions.inc.php; redefined here since that file is not loaded.
defined('MKGETDIR_NONE')             or define('MKGETDIR_NONE', 0);
defined('MKGETDIR_RECURSIVE')        or define('MKGETDIR_RECURSIVE', 1);
defined('MKGETDIR_DIE_ON_ERROR')     or define('MKGETDIR_DIE_ON_ERROR', 2);
defined('MKGETDIR_PROTECT_INDEX')    or define('MKGETDIR_PROTECT_INDEX', 4);
defined('MKGETDIR_PROTECT_HTACCESS') or define('MKGETDIR_PROTECT_HTACCESS', 8);
defined('MKGETDIR_DEFAULT')          or define('MKGETDIR_DEFAULT', MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX);

(new \Piwigo\Controller\ImageDerivativeController)(RequestFactory::fromGlobals());
