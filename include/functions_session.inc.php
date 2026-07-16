<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 8c: this file's 12 free functions were all pure delegates to
// Piwigo\Session\SessionService (already-typed, already-real) -- every
// real caller retargeted directly to SessionService::get()->method(), 6
// dead ones dropped outright (pwg_session_open/close/read/write/destroy --
// PwgSession implements SessionHandlerInterface directly and PHP's own
// session mechanism never called these bare-function wrappers;
// get_remote_addr_session_hash() had zero real callers anywhere). Only
// this file's own top-level bootstrap body survives -- SessionMiddleware's
// own docblock documents it as the thing that registers PwgSession as the
// save handler "on every real request" for the legacy (non-P22-pipeline)
// bootstrap path, real session-security-relevant orchestration this pass
// deliberately doesn't disturb. Relocating it into common.inc.php itself
// (so this file can be deleted too) is deferred to P23 batch 8f, alongside
// common.inc.php's own consolidation -- kept here, relocate-only, same
// precedent as functions_mysqli.inc.php (finding 2) and
// derivative_std_params.inc.php.

use Piwigo\Auth\CookieService;
use Piwigo\Session\PwgSession;

// In PHP 8.4+ calling session_set_save_handler with
// two parameters is deprecated. To correct this,
// we pass a SessionHandlerInterface instance.
// https://github.com/Piwigo/Piwigo/issues/2296

/** @var array<string, mixed> $conf */
if (isset($conf['session_save_handler'])
  and ($conf['session_save_handler'] === 'db')
  and defined('PHPWG_INSTALLED')) {
    session_set_save_handler(new PwgSession());

    if (function_exists('ini_set')) {
        $session_use_cookies = $conf['session_use_cookies'];
        $session_use_cookies = is_scalar($session_use_cookies) ? $session_use_cookies : null;
        ini_set('session.use_cookies', $session_use_cookies);

        $session_use_only_cookies = $conf['session_use_only_cookies'];
        $session_use_only_cookies = is_scalar($session_use_only_cookies) ? $session_use_only_cookies : null;
        ini_set('session.use_only_cookies', $session_use_only_cookies);

        $session_use_trans_sid = $conf['session_use_trans_sid'];
        $session_use_trans_sid = is_scalar($session_use_trans_sid) ? $session_use_trans_sid : 0;
        ini_set('session.use_trans_sid', intval($session_use_trans_sid));
        ini_set('session.cookie_httponly', 1);
    }

    $session_name = $conf['session_name'];
    $session_name = is_string($session_name) ? $session_name : null;
    session_name($session_name);
    session_set_cookie_params(0, new CookieService()->cookiePath());
    register_shutdown_function(session_write_close(...));
}
