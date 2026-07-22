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
// rule SEC-60. Legacy Coupling Retirement gap-closure (install/upgrade-flow
// constants round): PREFIX_TABLE/CURRENT_DATE/PHPWG_IN_UPGRADE are gone --
// UpgradeRunner/UpgradeService/AbstractRangeVersionUpgrade are real,
// already-DI-shaped classes that read Config::/Tables::/UpgradeRunDate::
// directly now instead of a global those classes' own methods used to
// read. Only PWG_CHARSET stays: Patch65/85/90 (pre-2.0 database charset
// migration) still consult it via UpgradeCharset:: on pre-2.0 databases
// where this shell hasn't defined it yet.

use Piwigo\Admin\Install\UpgradeRunDate;
use Piwigo\Admin\Install\UpgradeRunner;
use Piwigo\Admin\Install\UpgradeService;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Config\Config;
use Piwigo\Core\Paths;

// right after the overwrite of previous version files by the unzip in the administration,
// PHP engine might still have old files in cache. We do not want to use the cache and
// force reload of all application files. Thus we disable opcache.
if (function_exists('ini_set')) {
    @ini_set('opcache.enable', 0);
}

// Legacy Coupling Retirement Phase 8, 8b: Paths::fromIndex() below is a
// Piwigo\ class, so the autoloader must be required explicitly first now
// (this file's former "resolve Piwigo\ classes with zero autoloader
// hookup" bug, fixed in 8f-6 by the include/env.inc.php include below,
// still needs that include to run too -- requiring twice is safe, PHP's
// own realpath-keyed include cache no-ops the second require).
require __DIR__ . '/vendor/autoload.php';

$paths = Paths::fromIndex(__FILE__);

// Autoload boundary (see include/env.inc.php: it only requires
// vendor/autoload.php). Added in the 8f-6 port -- the former file resolved
// Piwigo\ classes (Config::override etc.) without ANY autoloader hookup on
// its include chain, a latent "Class not found" fatal on direct
// upgrade.php requests that nothing smoke-tested; every entry shell now
// requires the autoloader first, matching admin.php/install.php.
include $paths->root . 'include/env.inc.php';

// Legacy Coupling Retirement Phase 8, 8b (the "boot-first" fix, extended
// from 8a's HTTP-request-path version to install/upgrade): must run
// before the database.inc.php-sourced db_* overrides below, so real,
// site-specific credentials always win over a coincidental PIWIGO_DB_*
// env var.
InstallBootstrap::boot($paths);

/**
 * @var array<string, mixed> $conf
 * @var string $prefixeTable
 */

// load config file
include $paths->root . 'include/config_default.inc.php';
@include $paths->local . 'config/config.inc.php';

$config_file = $paths->siteLocal . 'config/database.inc.php';
$config_file_contents = @file_get_contents($config_file);
if ($config_file_contents === false) {
    die('Cannot load ' . $config_file);
}
$php_end_tag = strrpos($config_file_contents, '?>');
if ($php_end_tag === false) {
    die('Cannot find php end tag in ' . $config_file);
}

include $config_file;

// Legacy Coupling Retirement Phase 8, 8b -- real, already-diagnosed bug
// fix: database.inc.php (just included) sets $conf['db_host']/['db_user']/
// ['db_password']/['db_base'] into the legacy $conf array, but nothing
// previously bridged them into Config::'s static state.
// UpgradeService::upgradeDbConnect() below calls DbConnection::build(),
// which reads Config::dbHost()/dbUser()/dbPassword()/dbBase() exclusively
// -- so every real invocation of this script silently attempted to
// connect as an empty-string user with an empty-string password against
// an empty-string database at 'localhost', regardless of the site's real
// configuration, which fails for any real MySQL install.
foreach (['db_host', 'db_user', 'db_password', 'db_base'] as $dbConfKey) {
    $dbConfValue = $conf[$dbConfKey] ?? null;
    if (is_string($dbConfValue)) {
        Config::override($dbConfKey, $dbConfValue);
    }
}

// Piwigo\Db\Tables::*() reads Config::dbPrefix() -- InstallBootstrap::boot()
// above only seeds SCHEMA defaults + env overrides, so $prefixeTable
// (resolved above from database.inc.php, independent of $conf) must be
// seeded into Config's static state directly here too, or every
// Tables::*() call downstream would fall back to whatever
// InstallBootstrap::boot() left in place instead of the real prefix.
Config::override('db_prefix', $prefixeTable);
// Legacy Coupling Retirement Phase 8, 8d: must run after the real db_*
// seeding above, not before -- see InstallBootstrap::activateConfigService()'s
// own docblock.
InstallBootstrap::activateConfigService();
// P23 sub-batch 8g-6: replaces the former define('UPGRADES_PATH', ...) as
// the "this request is the upgrade flow" marker Lang::loadLanguage() reads
// (skip DB default-language lookups against a mid-migration database).
\Piwigo\Core\UpgradeFlow::mark();

// P23 sub-batch 8f-5: the former include/functions_session.inc.php include
// became Piwigo\Bootstrap\SessionBootstrap::register() (same body, same
// guards).
\Piwigo\Bootstrap\SessionBootstrap::register();
// Pre-existing gap, found live while verifying Legacy Coupling Retirement
// Phase 8, 8b (renderIntro() below reaches upgrade.tpl's unconditional
// {get_combined_scripts load='footer'} -- unrelated to 8a/8b themselves,
// just never caught because tests/Browser/RegenerateFixtureTest.php is
// excluded from routine CI runs, per its own docblock): every other entry
// path wires this via RequestBootstrap::configure(), which upgrade.php
// never runs.
\Piwigo\Template\ScriptLoader::setUrlService(new \Piwigo\Url\UrlService(new \Piwigo\Html\HtmlService()));

// See include/common.inc.php for why this fork never points PHPWG_DOMAIN at
// the real piwigo.org.
define('PHPWG_DOMAIN', 'upstream.example.invalid');
define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

$runner = new UpgradeRunner($config_file, $config_file_contents, $php_end_tag, $paths);

// Language pick + Lang loads, before the DB connection on purpose: a
// failed connection must die with an already localized message.
$runner->loadLanguage();

// +-----------------------------------------------------------------------+
// |                          database connection                          |
// +-----------------------------------------------------------------------+

// P23 sub-batch 8g-6: the frozen-script compatibility include
// (admin/include/functions_upgrade.php) and the dblayer facade include
// are gone -- the frozen install/db and install/upgrade_X.Y.Z.php scripts
// are now real DbPatch/VersionUpgrade classes calling MysqliDb::/
// ConfigDb:: directly, and the facade file's define()s became MysqliDb
// class constants.

$conn = UpgradeService::upgradeDbConnect();

$row = $conn->fetchNumeric('SELECT NOW();');
assert($row !== false);
[$dbnow] = $row;
// Every VersionUpgrade ledger row inserted during this run shares this
// exact moment (see UpgradeRunDate's own docblock).
UpgradeRunDate::set(is_scalar($dbnow) ? (string) $dbnow : '');

// +-----------------------------------------------------------------------+
// |             template init / remote-site refusal / release             |
// +-----------------------------------------------------------------------+

// May exit(): remote sites are refused, an up-to-date DB short-circuits.
$current_release = $runner->prepare($conn);

// Check access rights (webmaster session, or POSTed admin/webmaster
// credentials -- the auth gate, ported verbatim).
$isAuthorized = UpgradeService::checkUpgradeAccessRights($conn, $current_release);

// +-----------------------------------------------------------------------+
// |                            upgrade launch                             |
// +-----------------------------------------------------------------------+

if ((isset($_POST['submit']) or isset($_GET['now']))
  and $isAuthorized) {
    $runner->performUpgrade($conn);
} else {
    // Deliberately only defined on this branch, exactly like the former
    // top-level code: during an actual upgrade launch, Patch65 (the
    // pre-2.0 charset migration) reads defined('PWG_CHARSET') via
    // UpgradeCharset:: to decide whether its one-shot charset migration
    // still has to run.
    if (! defined('PWG_CHARSET')) {
        define('PWG_CHARSET', 'utf-8');
    }
    $runner->renderIntro($isAuthorized);
}

$runner->finish();
