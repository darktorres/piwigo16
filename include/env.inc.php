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
// ready.php, install.php, tests/bootstrap.php) include this exact path as
// their only vendor/autoload.php hookup, and their own next statement is
// already a Piwigo\ class call -- so the require below has to run before
// any of them can resolve Piwigo\Core\Env at all.

require_once __DIR__ . '/../vendor/autoload.php';
