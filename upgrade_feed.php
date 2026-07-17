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
// The frozen install/db/*.php scripts run by UpgradeFeedRunner::run() call
// the bare pwg_query() family, deliberately kept as thin facades in this
// file (P23 batch 8f-2).
include PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $dblayer . '.inc.php';

// P23 sub-batch 8f-5: the former include/functions_session.inc.php include
// became Piwigo\Bootstrap\SessionBootstrap::register() (same body, same
// guards). Piwigo\Config\ConfigDb (the former conf_* free functions) keeps
// its fatal-error renderer wired for this non-common.inc.php entry path.
\Piwigo\Bootstrap\SessionBootstrap::register();
\Piwigo\Config\ConfigDb::setHtmlRenderer(new \Piwigo\Html\HtmlService());

// Frozen-script compatibility surface (bare load_conf_from_db()/
// conf_update_param()/get_available_upgrade_ids() delegates + IMG_*
// define()s) consumed at runtime by the FROZEN install/db/*.php scripts
// included from UpgradeFeedRunner::run().
include_once PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when it is not ok                               |
// +-----------------------------------------------------------------------+

if (! (bool) $conf['check_upgrade_feed']) {
    die('upgrade feed is not active');
}

// Former admin/include/functions_upgrade.php prepare_conf_upgrade() block,
// inlined verbatim (P23 sub-batch 8f-6): SEC-60 forbids define() in
// src/Piwigo, so this constant wiring stays in the entry shell. NOTE the
// constant NAMES are the literal strings 'Tables::categories()' etc. --
// a historical mechanical-rename artifact preserved as-is for strict
// behavior parity (the frozen scripts actually read the *_TABLE spellings,
// a pre-existing breakage documented in the 8f-6 report, not fixed here).
// concerning upgrade, we use the default tables
// $conf is not used for users tables
// define cannot be re-defined
define('Tables::categories()', $prefixeTable . 'categories');
define('Tables::comments()', $prefixeTable . 'comments');
define('Tables::config()', $prefixeTable . 'config');
define('Tables::favorites()', $prefixeTable . 'favorites');
define('Tables::groupAccess()', $prefixeTable . 'group_access');
define('Tables::groups()', $prefixeTable . 'groups');
define('Tables::history()', $prefixeTable . 'history');
define('Tables::historySummary()', $prefixeTable . 'history_summary');
define('Tables::imageCategory()', $prefixeTable . 'image_category');
define('Tables::images()', $prefixeTable . 'images');
define('Tables::sessions()', $prefixeTable . 'sessions');
define('Tables::sites()', $prefixeTable . 'sites');
define('Tables::userAccess()', $prefixeTable . 'user_access');
define('Tables::userGroup()', $prefixeTable . 'user_group');
define('Tables::users()', $prefixeTable . 'users');
define('Tables::userInfos()', $prefixeTable . 'user_infos');
define('Tables::userFeed()', $prefixeTable . 'user_feed');
define('Tables::rate()', $prefixeTable . 'rate');
define('Tables::userCache()', $prefixeTable . 'user_cache');
define('Tables::userCacheCategories()', $prefixeTable . 'user_cache_categories');
define('Tables::caddie()', $prefixeTable . 'caddie');
define('Tables::upgrade()', $prefixeTable . 'upgrade');
define('Tables::search()', $prefixeTable . 'search');
define('Tables::userMailNotification()', $prefixeTable . 'user_mail_notification');
define('Tables::tags()', $prefixeTable . 'tags');
define('Tables::imageTag()', $prefixeTable . 'image_tag');
define('Tables::plugins()', $prefixeTable . 'plugins');
define('Tables::oldPermalinks()', $prefixeTable . 'old_permalinks');
define('Tables::themes()', $prefixeTable . 'themes');
define('Tables::languages()', $prefixeTable . 'languages');

// Read at include time by the frozen install/db/*.php scripts -- must stay
// real global constants (SEC-60 keeps the define()s here).
define('PREFIX_TABLE', $prefixeTable);
define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

// +-----------------------------------------------------------------------+
// |                              Upgrades                                 |
// +-----------------------------------------------------------------------+

new \Piwigo\Admin\Install\UpgradeFeedRunner()
    ->run();
