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
// Piwigo\Admin\Install\InstallWizard; this file keeps only what's
// forbidden inside src/Piwigo by Arch rule SEC-60 (every define()) or
// must run before InstallWizard is constructed (the db_prefix override).
//
// The former magic-quotes block (a conditional install_sanitize_mysql_kv()
// free function) was deleted outright: get_magic_quotes_gpc() was removed
// in PHP 8.0, so on this codebase's PHP 8.4 floor the function_exists()
// guard made the whole block provably dead — same rationale as 8f-5's
// removal of common.inc.php's twin sanitize_mysql_kv().

use Piwigo\Admin\Install\InstallWizard;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Core\Env;
use Piwigo\Core\Paths;
use Piwigo\Db\DbCredentials;

// vendor/autoload.php must be required directly here -- Paths::fromRoot()
// below is a Piwigo\ class, so the autoloader must already be active
// before it's referenced, matching every other real entry point.
require __DIR__ . '/../vendor/autoload.php';

$paths = Paths::fromRoot(dirname(__DIR__));

Env::loadEnvFile($paths->root);

InstallBootstrap::boot($paths);

// ----------------------------------------------------- variable initialization

if (isset($_POST['install'])) {
    // Narrow to string (and guard the possibly-missing array key) rather than
    // trusting raw POST data downstream in SQL/file-content concatenation.
    $post_prefix = $_POST['prefix'] ?? null;
    $prefixeTable = is_string($post_prefix) ? $post_prefix : InstallWizard::DEFAULT_PREFIX_TABLE;
} else {
    $prefixeTable = InstallWizard::DEFAULT_PREFIX_TABLE;
}

// Piwigo\Db\Tables::*() (used throughout the wizard) reads
// DbCredentials::current()->prefix -- there's no database.inc.php/.env to
// read a real db_prefix from until this wizard writes one, so the
// user-chosen $prefixeTable must be seeded into the process environment
// directly here, or every Tables::*() call downstream would fall back to
// whatever's already there (a coincidental PIWIGO_DB_PREFIX env var, or
// the 'piwigo_' default) instead of the real chosen prefix.
$dbCredentials = DbCredentials::current();
$dbCredentials->seed([
    'PIWIGO_DB_PREFIX' => $prefixeTable,
]);

// P23 sub-batch 8f-5: the former include/functions_session.inc.php include
// became Piwigo\Bootstrap\SessionBootstrap::register() (same internal
// PHPWG_INSTALLED guard, so it stays the no-op it always was at this point
// of a fresh install).
\Piwigo\Bootstrap\SessionBootstrap::register();

// ---------------------------------------------------------------- orchestration
$wizard = new InstallWizard($prefixeTable, $paths, $dbCredentials, \Piwigo\Config\CurrentConfigService::current());

// Found live while verifying Part II's public/ relocation, unrelated to the
// move itself: InstallWizard::boot()'s own "PHP extension mysqli is not
// loaded"/"Piwigo is already installed" checks call HtmlService::
// fatalError(), which (per Workstream C3) throws ResponseReadyException --
// this file never had a catch point for it, same gap random.php had (a raw
// top-level script, no RequestPipeline::handle() to catch it downstream).
try {
    // InstallWizard::boot() itself calls InstallBootstrap::
    // activateConfigService() partway through its own body (Legacy
    // Coupling Retirement Phase 8, 8d) -- its own Template construction at
    // the end needs it active before this call returns, so it can't wait
    // until here.
    $wizard->boot();

    if (isset($_POST['install'])) {
        $wizard->analyzeForm();

        if (! $wizard->hasErrors()) {
            // SEC-60 keeps these define()s out of src/Piwigo: performInstall()
            // (and everything it reaches, e.g. the themes class needing
            // PWG_CHARSET to build fs_themes) relies on them exactly like the
            // former top-level step-2 block that define()d them at this same
            // point of the flow.
            defined('PHPWG_INSTALLED') or define('PHPWG_INSTALLED', true);
            defined('PWG_CHARSET') or define('PWG_CHARSET', 'utf-8');
            defined('DB_CHARSET') or define('DB_CHARSET', 'utf8');
            defined('DB_COLLATE') or define('DB_COLLATE', '');

            $wizard->performInstall();
        }
    }

    $wizard->render();
} catch (\Piwigo\Http\ResponseReadyException $e) {
    new \Piwigo\Http\ResponseEmitter()
        ->emit($e->response());
}
