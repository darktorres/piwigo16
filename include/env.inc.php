<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 sub-batch 8f-5: this file is the autoload boundary and nothing else.
// Its 7 pwg_*() free functions moved verbatim to Piwigo\Core\Env (see that
// class's docblock for the function -> method mapping). The file itself
// must stay: thin entry points (include/common.inc.php's seam, i.php,
// ready.php, tests/bootstrap.php) include this exact path as their only
// vendor/autoload.php hookup, and their own next statement is already a
// Piwigo\ class call -- so the require below has to run before any of
// them can resolve Piwigo\Core\Env at all. install.php/upgrade.php/
// upgrade_feed.php/random.php also include this path (Legacy Coupling
// Retirement Phase 8, 8a/8b), but each now requires vendor/autoload.php
// explicitly first too -- Paths::fromIndex() (needed before their own
// Kernel::boot()) is itself a Piwigo\ class, so they can't wait for this
// include the way the others do. Requiring it twice is safe (PHP's own
// realpath-keyed include cache no-ops the second require).

require_once __DIR__ . '/../vendor/autoload.php';
