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

// Installation wizard shim — custom bootstrap (no common.inc.php because
// the DB may not exist yet).  Calls InstallController directly without
// running the full PSR-15 pipeline.

define('PHPWG_ROOT_PATH', './');
define('DEFAULT_PREFIX_TABLE', 'piwigo_');
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
require PHPWG_ROOT_PATH . 'include/functions.inc.php';

ConfigLoader::applyDefaults();

(new \Piwigo\Controller\InstallController)(RequestFactory::fromGlobals());
