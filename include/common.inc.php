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
// 1. plain-data global initialization + the config includes -- kept at
//    true top-level scope on purpose: include/config_default.inc.php and
//    a user's local/config/config.inc.php write bare variables that must
//    land in $GLOBALS, which a method-scoped include would silently
//    capture instead (the recurring AdminDispatcher bug class);
// 2. the include/env.inc.php include -- the autoload boundary. NOTHING
//    above it may reference a Piwigo\ class: thin entry points
//    (random.php, ...) never require vendor/autoload.php themselves and
//    rely entirely on this include (bug class caught live in 8f-3 via a
//    random.php smoke test);
// 3. the define() calls -- src/Piwigo/ code may not call define() (arch
//    rule SEC-60), so the constants stay here, slotted between the
//    RequestBootstrap phases exactly where the original statements sat.

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

// determine the initial instant to indicate the generation time of this page
$t2 = microtime(true);

//
// Define some basic configuration arrays this also prevents malicious
// rewriting of language and otherarray values via URI params
//
$conf = [];
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

include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

// -------------------------------------------------- autoload boundary --
include PHPWG_ROOT_PATH . 'include/env.inc.php';

// superglobal sanitization, env-file loading, static-setter wiring,
// Config seeding, install-sentinel check (redirects to install.php and
// exits when Piwigo isn't installed yet). $paths is already in scope here
// -- every real entry point mints it via Paths::fromIndex(__FILE__) before
// including this file, and PHP `include` shares the including scope.
/** @var \Piwigo\Core\Paths $paths */
\Piwigo\Bootstrap\RequestBootstrap::configure($paths);

defined('PHPWG_INSTALLED') or define('PHPWG_INSTALLED', true);

// error collector, session save handler, DB connection,
// DB-backed config, logger, plugins, current-user resolution
\Piwigo\Bootstrap\RequestBootstrap::connect();

// This fork does not call back to the real piwigo.org — upstream.example.invalid
// (.invalid TLD per RFC 2606, guaranteed not to resolve) stops it from sending
// telemetry or fetching news/updates/merged-extension lists from the upstream
// server, and makes any accidental outbound call fail fast (DNS failure) rather
// than hang waiting on a real, possibly rate-limiting host.
define('PHPWG_DOMAIN', 'upstream.example.invalid');
define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);
define('PEM_URL', \Piwigo\Bootstrap\RequestBootstrap::pemUrl());

// language loading, auth-key messages, template creation, request filter,
// default event handlers, trigger_notify('init')
\Piwigo\Bootstrap\RequestBootstrap::finalize();
