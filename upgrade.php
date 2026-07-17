<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 sub-batch 8f-6: thin bootstrap shell (matching admin.php's
// established shape). All former top-level orchestration lives in
// Piwigo\Admin\Install\UpgradeRunner + UpgradeService; this file keeps
// only what MUST run at real top-level scope (config/database.inc.php
// includes write bare globals) or is forbidden inside src/Piwigo by Arch
// rule SEC-60 (every define() -- PREFIX_TABLE/UPGRADES_PATH/CURRENT_DATE/
// PHPWG_IN_UPGRADE/PWG_CHARSET are all read at include time by the FROZEN
// install/upgrade_X.Y.Z.php + install/db/*.php scripts this page runs).

use Piwigo\Admin\Install\UpgradeRunner;
use Piwigo\Admin\Install\UpgradeService;
use Piwigo\Config\Config;

// right after the overwrite of previous version files by the unzip in the administration,
// PHP engine might still have old files in cache. We do not want to use the cache and
// force reload of all application files. Thus we disable opcache.
if (function_exists('ini_set')) {
    @ini_set('opcache.enable', 0);
}

define('PHPWG_ROOT_PATH', './');

// Autoload boundary (see include/env.inc.php: it only requires
// vendor/autoload.php). Added in the 8f-6 port -- the former file resolved
// Piwigo\ classes (Config::override etc.) without ANY autoloader hookup on
// its include chain, a latent "Class not found" fatal on direct
// upgrade.php requests that nothing smoke-tested; every entry shell now
// requires the autoloader first, matching admin.php/install.php.
include PHPWG_ROOT_PATH . 'include/env.inc.php';

// Bootstrap globals, set by include/config_default.inc.php and $config_file.
/**
 * @var array<string, mixed> $conf
 * @var string $prefixeTable
 */
global $conf, $prefixeTable;

// load config file
include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

$config_file = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php';
$config_file_contents = @file_get_contents($config_file);
if ($config_file_contents === false) {
    die('Cannot load ' . $config_file);
}
$php_end_tag = strrpos($config_file_contents, '?>');
if ($php_end_tag === false) {
    die('Cannot find php end tag in ' . $config_file);
}

include $config_file;

// Piwigo\Db\Tables::*() reads Config::dbPrefix() -- this script never goes
// through Kernel::boot()/ConfigLoader (the DB isn't necessarily configured
// yet), so $prefixeTable (resolved above from database.inc.php, independent
// of $conf) must be seeded into Config's static state directly, or every
// Tables::*() call downstream silently falls back to the 'piwigo_' SCHEMA
// default instead of the real prefix.
Config::override('db_prefix', $prefixeTable);
define('PREFIX_TABLE', $prefixeTable);
define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

// P23 sub-batch 8f-5: the former include/functions_session.inc.php include
// became Piwigo\Bootstrap\SessionBootstrap::register() (same body, same
// guards). Piwigo\Config\ConfigDb (the former conf_* free functions) keeps
// its fatal-error renderer wired for this non-common.inc.php entry path.
\Piwigo\Bootstrap\SessionBootstrap::register();
\Piwigo\Config\ConfigDb::setHtmlRenderer(new \Piwigo\Html\HtmlService());

// See include/common.inc.php for why this fork never points PHPWG_DOMAIN at
// the real piwigo.org.
define('PHPWG_DOMAIN', 'upstream.example.invalid');
define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

$runner = new UpgradeRunner($config_file, $config_file_contents, $php_end_tag);

// Language pick + Lang loads, before the DB connection on purpose: a
// failed connection must die with an already localized message.
$runner->loadLanguage();

// +-----------------------------------------------------------------------+
// |                          database connection                          |
// +-----------------------------------------------------------------------+

// Frozen-script compatibility surface (bare load_conf_from_db()/
// conf_update_param()/get_available_upgrade_ids() delegates + IMG_*
// define()s) consumed at runtime by the FROZEN install/upgrade_X.Y.Z.php
// and install/db/*.php scripts included from UpgradeRunner::performUpgrade().
include_once PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php';

// config_default.inc.php/database.inc.php always set $conf['dblayer'] to a
// string ('mysqli'), but the value crosses an include() boundary invisible
// to static analysis, so we re-narrow at the point of use (same pattern as
// include/common.inc.php).
$dblayer = $conf['dblayer'];
if (! is_string($dblayer)) {
    die("Invalid \$conf['dblayer'] configuration: expected a string.");
}
// The frozen scripts run by performUpgrade() call the bare pwg_query()
// family, deliberately kept as thin facades in this file (P23 batch 8f-2).
include PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $dblayer . '.inc.php';

UpgradeService::upgradeDbConnect();
\Piwigo\Db\MysqliDb::checkCharset();

$row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query('SELECT NOW();'));
assert($row !== null);
[$dbnow] = $row;
// Read at include time by the frozen install/upgrade_X.Y.Z.php scripts --
// must stay a real global constant (SEC-60 keeps the define() here).
define('CURRENT_DATE', $dbnow);

// +-----------------------------------------------------------------------+
// |             template init / remote-site refusal / release             |
// +-----------------------------------------------------------------------+

// May exit(): remote sites are refused, an up-to-date DB short-circuits.
$current_release = $runner->prepare();

// Check access rights (webmaster session, or POSTed admin/webmaster
// credentials -- the auth gate, ported verbatim). The define() itself must
// live here: SEC-60 forbids define() in src/Piwigo, and the frozen
// install/upgrade_X.Y.Z.php scripts die('Hacking attempt!') unless
// PHPWG_IN_UPGRADE is a real defined constant when they are included.
if (UpgradeService::checkUpgradeAccessRights($current_release)) {
    define('PHPWG_IN_UPGRADE', true);
}

// +-----------------------------------------------------------------------+
// |                            upgrade launch                             |
// +-----------------------------------------------------------------------+

if ((isset($_POST['submit']) or isset($_GET['now']))
  and UpgradeService::checkUpgrade()) {
    $runner->performUpgrade();
} else {
    // Deliberately only defined on this branch, exactly like the former
    // top-level code: during an actual upgrade launch the frozen
    // install/db/65-database.php reads defined('PWG_CHARSET') to decide
    // whether its one-shot charset migration still has to run.
    if (! defined('PWG_CHARSET')) {
        define('PWG_CHARSET', 'utf-8');
    }
    $runner->renderIntro();
}

$runner->finish();
