<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 sub-batch 8f-5: thin include seam. Every entry point still includes
// this exact path (the include contract this file must keep), but the real
// per-request bootstrap orchestration lives on
// Piwigo\Bootstrap\RequestBootstrap. What remains here, in original order:
//
// 1. the include/env.inc.php include -- the autoload boundary. NOTHING
//    above it may reference a Piwigo\ class: thin entry points
//    (random.php, ...) never require vendor/autoload.php themselves and
//    rely entirely on this include (bug class caught live in 8f-3 via a
//    random.php smoke test);
// 2. Piwigo\Core\InstallationFlag::mark() -- slotted between the
//    RequestBootstrap phases exactly where the original define('PHPWG_
//    INSTALLED', true) statement sat (install.php's own performInstall()
//    step still does raw define() there, matching PWG_CHARSET/DB_CHARSET/
//    DB_COLLATE's own established Admin\Install\DbPatch\UpgradeCharset
//    precedent -- InstallationFlag::isActive() checks defined() as a
//    fallback for that one remaining case). The former PHPWG_DOMAIN/
//    PHPWG_URL/PEM_URL define()s that used to sit here too are gone
//    entirely (Legacy Coupling Retirement gap-closure, entry-shell
//    define()/include round, Part 0b);
// 3. the try/catch wrapping RequestBootstrap::configure()/connect()/
//    finalize() below (Workstream C3, catch point 1) -- every
//    bootstrap-phase short-circuit (install-redirect, upgrade-redirect,
//    the 503 maintenance page) now throws Piwigo\Http\ResponseReadyException
//    instead of exiting directly; this is the one place that needs to
//    catch it for both dispatch contexts that include this seam (see
//    that exception class's own docblock).
//
// The former $page/$user/$lang/$filter top-level array seeding that used
// to sit here (feeding PageState::attachGlobals()/Lang::attachGlobals()'s
// own $GLOBALS['page']/['header_msgs']/['header_notes']/['lang'] mirrors)
// is gone -- re-investigated and confirmed dead: nothing outside those two
// classes' own bridge code ever read the raw globals (PageHeaderRenderer.php/
// RequestBootstrap.php, the two real consumers, already went through
// PageState::current()->headerNotes/headerMessages, never the raw global;
// the free function l10n() that used to justify mirroring $lang was
// already deleted). See PageState.php/Lang.php's own docblocks for the
// same finding on the write side.

/**
 * @var \Piwigo\Core\Paths $paths every real entry point mints this via
 *      Paths::fromIndex(__FILE__)/fromRoot() before including this file,
 *      and PHP `include` shares the including scope -- same mechanism
 *      RequestBootstrap::configure($paths, $t2) below already relies on
 *      explicitly. Guarded at runtime, not just documented: this file must
 *      never run standalone (e.g. requested directly, or included out of
 *      order), the same intent the former defined('PHPWG_ROOT_PATH')
 *      guard had.
 */
if (! isset($paths) || ! $paths instanceof \Piwigo\Core\Paths) {
    trigger_error('Hacking attempt!', E_USER_ERROR);
}

// determine the initial instant to indicate the generation time of this page
$t2 = microtime(true);

// -------------------------------------------------- autoload boundary --
include $paths->root . 'include/env.inc.php';

// Workstream C3, catch point 1: every bootstrap-phase short-circuit
// (configure()'s install-redirect, connect()'s upgrade-redirect/
// fatalError(), finalize()'s 503 maintenance page) now throws
// Piwigo\Http\ResponseReadyException instead of exiting directly -- see
// that exception class's own docblock for the real bug this fixes (PHP's
// exit()/die() skip pending `finally` blocks, so SentryMiddleware's
// transaction never finished and ServerTimingMiddleware's header was
// silently skipped on every one of these paths). This is the *only*
// catch point needed for the bootstrap phase; it covers both the
// pipeline-routed root files and admin.php/admin/popuphelp.php, since
// both dispatch contexts include this same seam file.
try {
    // superglobal sanitization, env-file loading, static-setter wiring,
    // Config seeding, install-sentinel check (redirects to install.php and
    // exits when Piwigo isn't installed yet). $t2, captured above at true
    // top-level scope for maximum precision, is passed straight through
    // instead of relying on a `global $t2;` bridge.
    \Piwigo\Bootstrap\RequestBootstrap::configure($paths, $t2);

    \Piwigo\Core\InstallationFlag::mark();

    // error collector, session save handler, DB connection,
    // DB-backed config, logger, plugins, current-user resolution
    \Piwigo\Bootstrap\RequestBootstrap::connect();

    // Legacy Coupling Retirement gap-closure (entry-shell define()/include
    // round, Part 0b): the former PHPWG_DOMAIN/PHPWG_URL/PEM_URL define()s
    // are gone -- every real reader now goes through Piwigo\Core\AppInfo::
    // DOMAIN/URL (fixed consts) or Bootstrap\RequestBootstrap::pemUrl()
    // (computed fresh at each read site; cheap and side-effect-free, so no
    // per-request cache is needed the way Piwigo\Core\CurrentPaths needs one).

    // language loading, auth-key messages, template creation, request filter,
    // default event handlers, trigger_notify('init')
    \Piwigo\Bootstrap\RequestBootstrap::finalize();
} catch (\Piwigo\Http\ResponseReadyException $e) {
    new \Piwigo\Http\ResponseEmitter()
        ->emit($e->response());
    exit;
}
