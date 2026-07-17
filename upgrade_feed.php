<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 sub-batch 8f-6: thin bootstrap shell (matching admin.php's
// established shape). The former top-level upgrade loop lives in
// Piwigo\Admin\Install\UpgradeFeedRunner; this file keeps only what MUST
// run at real top-level scope (config/database.inc.php includes write bare
// globals) or is forbidden inside src/Piwigo by Arch rule SEC-60 (every
// define() -- PREFIX_TABLE/UPGRADES_PATH and the former
// prepare_conf_upgrade() block are read at include time by the FROZEN
// install/db/*.php scripts this page runs).

// check php version
if (version_compare(PHP_VERSION, '8.5.0', '<')) {
    die('Piwigo requires PHP 8.5 or above.');
}

define('PHPWG_ROOT_PATH', './');

// Autoload boundary (see include/env.inc.php: it only requires
// vendor/autoload.php). Added in the 8f-6 port -- the former file resolved
// Piwigo\ classes (SessionBootstrap, MysqliDb, ...) without ANY autoloader
// hookup on its include chain, a latent "Class not found" fatal on direct
// upgrade_feed.php requests that nothing smoke-tested; every entry shell
// now requires the autoloader first, matching admin.php/install.php.
include PHPWG_ROOT_PATH . 'include/env.inc.php';

// Bootstrap globals, set by include/config_default.inc.php and database.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var string $prefixeTable
 */
global $conf, $prefixeTable;

include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

include PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php';

// $conf['dblayer'] is set by config_default.inc.php/database.inc.php as a
// string, but the generic array<string, mixed> type of $conf erases that
// specific type; re-narrow at the point of use (same pattern as
// include/common.inc.php and upgrade.php).
$dblayer = $conf['dblayer'];
if (! is_string($dblayer)) {
    die("Invalid \$conf['dblayer'] configuration: expected a string.");
}
// P23 sub-batch 8g-6: the dblayer facade include is gone -- the frozen
// scripts that called the bare pwg_query() family are now DbPatch classes
// calling MysqliDb:: directly, and the facade file's define()s became
// MysqliDb class constants.

// P23 sub-batch 8f-5: the former include/functions_session.inc.php include
// became Piwigo\Bootstrap\SessionBootstrap::register() (same body, same
// guards). Piwigo\Config\ConfigDb (the former conf_* free functions) keeps
// its fatal-error renderer wired for this non-common.inc.php entry path.
\Piwigo\Bootstrap\SessionBootstrap::register();
\Piwigo\Config\ConfigDb::setHtmlRenderer(new \Piwigo\Html\HtmlService());

// +-----------------------------------------------------------------------+
// | Check Access and exit when it is not ok                               |
// +-----------------------------------------------------------------------+

if (! (bool) $conf['check_upgrade_feed']) {
    die('upgrade feed is not active');
}

// P23 sub-batch 8g-6: the frozen install/db scripts (and their
// prepare_conf_upgrade() codemod-artifact constant block, whose names were
// the literal strings 'Tables::categories()' -- a documented pre-existing
// breakage) are gone; the DbPatch classes read Piwigo\Db\Tables::*(),
// which resolves its prefix from Config::dbPrefix(). This script never
// goes through Kernel::boot()/ConfigLoader, so seed the prefix directly
// (same as upgrade.php does), or every Tables::*() call would silently
// fall back to the 'piwigo_' schema default.
\Piwigo\Config\Config::override('db_prefix', $prefixeTable);

// Read by UpgradeFeedRunner's ledger SQL (SEC-60 keeps the define() here).
define('PREFIX_TABLE', $prefixeTable);
// P23 sub-batch 8g-6: replaces the former define('UPGRADES_PATH', ...) as
// the "this request is the upgrade flow" marker Lang::loadLanguage() reads.
\Piwigo\Core\UpgradeFlow::mark();

// +-----------------------------------------------------------------------+
// |                              Upgrades                                 |
// +-----------------------------------------------------------------------+

new \Piwigo\Admin\Install\UpgradeFeedRunner()
    ->run();
