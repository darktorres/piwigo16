<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 8f: page logic moved to
// Piwigo\Controller\ImageDerivativeController; this file is now the thin
// FAST-BOOTSTRAP entry shell -- it deliberately does NOT include
// include/common.inc.php (no plugin loading, no user/session bootstrap,
// minimal DB work), a hard performance requirement for serving derivative
// images. Only what genuinely must live at real top-level global scope
// stays here: the define()s (SEC-60 forbids define() in src/) and the
// config includes (config_default.inc.php and the user's local config
// write bare globals, which only exist as globals at top-level scope).

define('PHPWG_ROOT_PATH', './');

// fast bootstrap - no db connection
include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

// autoload boundary -- nothing above this line may reference a Piwigo\ class
include PHPWG_ROOT_PATH . 'include/env.inc.php';

new \Piwigo\Controller\ImageDerivativeController()
    ->serve();
