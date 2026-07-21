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
// Piwigo\Admin\Install\InstallWizard; this file keeps only what MUST run
// at real top-level scope (the config includes write bare globals) or is
// forbidden inside src/Piwigo by Arch rule SEC-60 (every define()).
//
// The former magic-quotes block (a conditional install_sanitize_mysql_kv()
// free function) was deleted outright: get_magic_quotes_gpc() was removed
// in PHP 8.0, so on this codebase's PHP 8.4 floor the function_exists()
// guard made the whole block provably dead — same rationale as 8f-5's
// removal of common.inc.php's twin sanitize_mysql_kv().

use Piwigo\Admin\Install\InstallWizard;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Config\Config;
use Piwigo\Core\Env;
use Piwigo\Core\Paths;

// Unlike this file's own former "never requires vendor/autoload.php,
// relies entirely on include/env.inc.php" shape (Legacy Coupling
// Retirement Phase 8, 8b): Paths::fromIndex() below is a Piwigo\ class,
// so the autoloader must be required explicitly first, matching every
// other real entry point. Requiring it twice is safe (PHP's own
// realpath-keyed include cache no-ops the second require via
// include/env.inc.php below), same precedent index.php's own docblock
// documents.
require __DIR__ . '/vendor/autoload.php';

// ----------------------------------------------------------- include
$paths = Paths::fromIndex(__FILE__);
define('PHPWG_ROOT_PATH', './');

include PHPWG_ROOT_PATH . 'include/env.inc.php';
Env::loadEnvFile(PHPWG_ROOT_PATH);

// Legacy Coupling Retirement Phase 8, 8b (the "boot-first" fix, extended
// from 8a's HTTP-request-path version to install/upgrade): must run
// before the $_POST-submitted db_prefix override below, so a coincidental
// PIWIGO_DB_PREFIX env var can never clobber the value the install form
// actually submitted.
InstallBootstrap::boot($paths);

// ----------------------------------------------------- variable initialization

define('DEFAULT_PREFIX_TABLE', 'piwigo_');

if (isset($_POST['install'])) {
    // Narrow to string (and guard the possibly-missing array key) rather than
    // trusting raw POST data downstream in SQL/file-content concatenation.
    $prefixeTable = is_string($_POST['prefix'] ?? null) ? $_POST['prefix'] : DEFAULT_PREFIX_TABLE;
} else {
    $prefixeTable = DEFAULT_PREFIX_TABLE;
}

// Piwigo\Db\Tables::*() (used throughout the wizard) reads
// Config::dbPrefix() -- InstallBootstrap::boot() above only seeds
// SCHEMA defaults + env overrides, there's no database.inc.php to read a
// real db_prefix from until this wizard writes one, so the user-chosen
// $prefixeTable must be seeded into Config's static state directly here,
// or every Tables::*() call downstream would fall back to whatever
// InstallBootstrap::boot() left in place instead of the real chosen
// prefix.
Config::override('db_prefix', $prefixeTable);

include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

// Bootstrap global, set by include/config_default.inc.php.
/** @var array<string, mixed> $conf */
global $conf;

// P23 sub-batch 8f-5: the former include/functions_session.inc.php include
// became Piwigo\Bootstrap\SessionBootstrap::register() (same internal
// PHPWG_INSTALLED guard, so it stays the no-op it always was at this point
// of a fresh install). Piwigo\Config\ConfigDb (the former conf_* free
// functions) keeps its fatal-error renderer wired for this
// non-common.inc.php entry path.
\Piwigo\Bootstrap\SessionBootstrap::register();
\Piwigo\Config\ConfigDb::setHtmlRenderer(new \Piwigo\Html\HtmlService());
// Pre-existing gap, found live while verifying Legacy Coupling Retirement
// Phase 8, 8b (this file's own render() call always reaches install.tpl's
// unconditional {get_combined_scripts load='footer'} -- unrelated to 8a/8b
// themselves, just never caught because tests/Browser/RegenerateFixtureTest.php
// is excluded from routine CI runs, per its own docblock): every other
// entry path wires this via RequestBootstrap::configure(), which
// install.php never runs.
\Piwigo\Template\ScriptLoader::setUrlService(new \Piwigo\Url\UrlService(new \Piwigo\Html\HtmlService()));

// See include/common.inc.php for why this fork never points PHPWG_DOMAIN at
// the real piwigo.org.
define('PHPWG_DOMAIN', 'upstream.example.invalid');
define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

// ---------------------------------------------------------------- orchestration
$wizard = new InstallWizard($prefixeTable);
$wizard->boot();

if (isset($_POST['install'])) {
    $wizard->analyzeForm();

    if (! $wizard->hasErrors()) {
        // SEC-60 keeps these define()s out of src/Piwigo: performInstall()
        // (and everything it reaches, e.g. the themes class needing
        // PWG_CHARSET to build fs_themes) relies on them exactly like the
        // former top-level step-2 block that define()d them at this same
        // point of the flow.
        define('PHPWG_INSTALLED', true);
        define('PWG_CHARSET', 'utf-8');
        define('DB_CHARSET', 'utf8');
        define('DB_COLLATE', '');

        $wizard->performInstall();
    }
}

$wizard->render();
