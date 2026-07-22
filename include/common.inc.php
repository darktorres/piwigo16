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
// 1. plain-data global initialization -- kept at true top-level scope on
//    purpose: a method-scoped assignment would silently land in the
//    method's own scope instead of $GLOBALS (the recurring AdminDispatcher
//    bug class). The former include/config_default.inc.php +
//    local/config/config.inc.php includes that used to sit here are gone
//    (Legacy Coupling Retirement gap-closure, entry-shell define()/include
//    round): they built a bare $conf array nothing downstream of this file
//    ever read (confirmed via a repo-wide grep) -- Config::SCHEMA already
//    carries the real defaults (ConfigLoader::applyDefaults()), and
//    Config\LocalConfigOverrides (via ConfigLoader::applyLocalFileOverrides(),
//    called later from CommonBootstrap::run()) already re-reads a site's
//    real local/config/config.inc.php properly, in its own scoped context
//    -- this file's own copy was a redundant, unread second read. Same
//    cleanup install.php's own history already applied to itself;
//    i.php gets it too in the same round;
// 2. the include/env.inc.php include -- the autoload boundary. NOTHING
//    above it may reference a Piwigo\ class: thin entry points
//    (random.php, ...) never require vendor/autoload.php themselves and
//    rely entirely on this include (bug class caught live in 8f-3 via a
//    random.php smoke test);
// 3. the define() calls -- src/Piwigo/ code may not call define() (arch
//    rule SEC-60), so the constants stay here, slotted between the
//    RequestBootstrap phases exactly where the original statements sat.

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

//
// Define some basic configuration arrays this also prevents malicious
// rewriting of language and otherarray values via URI params
//
$page = [
    'infos' => [],
    'errors' => [],
    'warnings' => [],
    'messages' => [],
    'body_classes' => [],
    'body_data' => [],
];
$user = [];
$lang = [];
$filter = [];

// -------------------------------------------------- autoload boundary --
include $paths->root . 'include/env.inc.php';

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
