<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 10: page-shell orchestration moved to Piwigo\Admin\AdminShell;
// this file is now pure bootstrap + dispatch, matching index.php's own
// final form.

// vendor/autoload.php must be required directly here (not just reached
// transitively via common.inc.php's own include/env.inc.php) -- Paths::
// fromIndex() below needs the autoloader before common.inc.php has even
// started running. Requiring it twice is safe (PHP's own realpath-keyed
// include cache no-ops the second require). Mirrors index.php's own
// ordering exactly.
require __DIR__ . '/vendor/autoload.php';

use Piwigo\Admin\AdminShell;
use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Core\Paths;

$paths = Paths::fromIndex(__FILE__);
define('PHPWG_ROOT_PATH', './');
define('IN_ADMIN', true);

include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// P21 boots the Kernel/DI container on the admin.php path too -- needed by
// AdminDispatcher (inside AdminShell) to resolve AdminSubControllerInterface
// services. Safe to run after common.inc.php's own legacy
// config/db/session/user bootstrap: every attachGlobals() this calls is
// additive/idempotent (CurrentUser/PageState/Lang all bridge onto or read
// from the *existing* $GLOBALS state rather than overwriting it -- see
// their own docblocks), same ordering index.php already uses.
CommonBootstrap::run($paths);

new AdminShell()
    ->run();
