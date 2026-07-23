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
// globals) or is forbidden inside src/Piwigo by Arch rule SEC-60. Legacy
// Coupling Retirement gap-closure (install/upgrade-flow constants round):
// PREFIX_TABLE is gone -- UpgradeFeedRunner is real, already-DI-shaped
// class that reads Tables::upgrade() directly now instead of a global its
// own ledger SQL used to read.

// check php version
if (version_compare(PHP_VERSION, '8.5.0', '<')) {
    die('Piwigo requires PHP 8.5 or above.');
}

// vendor/autoload.php must be required directly here -- Paths::fromRoot()
// below is a Piwigo\ class, so the autoloader must already be active
// before it's referenced, matching every other real entry point.
require __DIR__ . '/../vendor/autoload.php';

$paths = \Piwigo\Core\Paths::fromRoot(dirname(__DIR__));

// Legacy Coupling Retirement Phase 8, 8b (the "boot-first" fix, extended
// from 8a's HTTP-request-path version to install/upgrade): must run
// before the database.inc.php-sourced db_* overrides below, so real,
// site-specific credentials always win over a coincidental PIWIGO_DB_*
// env var.
\Piwigo\Bootstrap\InstallBootstrap::boot($paths);

/**
 * @var array<string, mixed> $conf
 * @var string $prefixeTable
 */
$conf = \Piwigo\Config\Config::defaultsArray();
@include $paths->local . 'config/config.inc.php';

include $paths->siteLocal . 'config/database.inc.php';

// Legacy Coupling Retirement Phase 8, 8b -- real, already-diagnosed bug
// fix, identical to upgrade.php's own: database.inc.php (just included)
// sets $conf['db_host']/['db_user']/['db_password']/['db_base'] into the
// legacy $conf array, but nothing previously bridged them into Config::'s
// static state. DbPatchRegistry::make(...)->apply() below eventually
// reaches DbConnection::build() (via UpgradeFeedRunner::run()'s own $conn),
// which reads Config::dbHost()/dbUser()/dbPassword()/dbBase() exclusively
// -- so every real invocation of this script silently attempted to
// connect as an empty-string user with an empty-string password against
// an empty-string database at 'localhost', regardless of the site's real
// configuration, which fails for any real MySQL install.
foreach (['db_host', 'db_user', 'db_password', 'db_base'] as $dbConfKey) {
    $dbConfValue = $conf[$dbConfKey] ?? null;
    if (is_string($dbConfValue)) {
        \Piwigo\Config\Config::override($dbConfKey, $dbConfValue);
    }
}

// $conf['dblayer'] is set by database.inc.php (just included) as a string
// -- "nothing is frozen" gap-closure (2026-07-22) confirmed config_default.
// inc.php never set it, contrary to what this comment used to claim, dblayer
// has no Config::SCHEMA entry either (see LegacyDbLayer::value()'s own
// docblock for why: a different value space than Config::dbDriver()) -- but
// the generic array<string, mixed> type of $conf erases dblayer's specific
// type regardless of source; re-narrow at the point of use (same pattern
// upgrade.php uses).
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
// guards).
\Piwigo\Bootstrap\SessionBootstrap::register();

// +-----------------------------------------------------------------------+
// | Check Access and exit when it is not ok                               |
// +-----------------------------------------------------------------------+

if (! (bool) $conf['check_upgrade_feed']) {
    die('upgrade feed is not active');
}

// P23 sub-batch 8g-6: the DbPatch classes read Piwigo\Db\Tables::*(),
// which resolves its prefix from Config::dbPrefix(). InstallBootstrap::boot()
// above only seeds SCHEMA defaults + env overrides, so seed the real prefix
// directly here too (same as upgrade.php does), or every Tables::*() call
// would fall back to whatever InstallBootstrap::boot() left in place
// instead of the real prefix.
\Piwigo\Config\Config::override('db_prefix', $prefixeTable);
// Legacy Coupling Retirement Phase 8, 8d: must run after the real db_*
// seeding above, not before -- see InstallBootstrap::activateConfigService()'s
// own docblock.
\Piwigo\Bootstrap\InstallBootstrap::activateConfigService();

// P23 sub-batch 8g-6: replaces the former define('UPGRADES_PATH', ...) as
// the "this request is the upgrade flow" marker Lang::loadLanguage() reads.
\Piwigo\Core\UpgradeFlow::mark();

// +-----------------------------------------------------------------------+
// |                              Upgrades                                 |
// +-----------------------------------------------------------------------+

// Found live while verifying Part II's public/ relocation, unrelated to the
// move itself: UpgradeFeedRunner::run()'s own error path calls HtmlService::
// fatalError(), which (per Workstream C3) throws ResponseReadyException --
// this file never had a catch point for it, same gap random.php had (a raw
// top-level script, no RequestPipeline::handle() to catch it downstream).
try {
    new \Piwigo\Admin\Install\UpgradeFeedRunner()
        ->run();
} catch (\Piwigo\Http\ResponseReadyException $e) {
    new \Piwigo\Http\ResponseEmitter()
        ->emit($e->response());
}
