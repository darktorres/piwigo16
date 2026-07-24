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

\Piwigo\Bootstrap\InstallBootstrap::boot($paths);

/** @var array<string, mixed> */
$conf = \Piwigo\Config\CurrentConfig::defaultsArray();
@include $paths->local . 'config/config.inc.php';

include $paths->siteLocal . 'config/database.inc.php';

// A real, pre-existing Piwigo installation being upgraded through this
// legacy flow has its DB credentials in database.inc.php, not .env --
// DbConnection::build() reads Piwigo\Db\DbCredentials::current()
// exclusively (env only), so without this, every real invocation of this
// script would silently attempt to connect as an empty-string user with
// an empty-string password against an empty-string database at
// 'localhost', regardless of the site's real configuration.
// migrateFromLegacyFile() re-reads database.inc.php independently
// (isolated scope, same file this shell already included above) and seeds
// the process environment (and persists into .env) only when .env
// doesn't already have these -- a no-op for a site that already upgraded
// once, or one InstallWizard itself installed.
\Piwigo\Db\DbCredentials::migrateFromLegacyFile($paths);

// $conf['dblayer'] is set by database.inc.php (just included) as a string
// -- "nothing is frozen" gap-closure (2026-07-22) confirmed config_default.
// inc.php never set it, contrary to what this comment used to claim, dblayer
// has no CurrentConfig property either (see LegacyDbLayer::value()'s own
// docblock for why: a different value space than DbCredentials::current()->driver) -- but
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

// Legacy Coupling Retirement Phase 8, 8d: must run after the credential
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
