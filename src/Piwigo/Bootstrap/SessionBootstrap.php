<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Auth\CookieService;
use Piwigo\Session\PwgSession;

/**
 * Installs Piwigo's DB-backed session save handler before session_start()
 * — the top-level bootstrap body of the deleted
 * include/functions_session.inc.php (P23 sub-batch 8f-5; that file's own
 * docblock deferred exactly this relocation to this sub-batch), ported
 * verbatim. SessionMiddleware's docblock documents this as the thing that
 * registers PwgSession as the save handler "on every real request" for
 * the legacy (non-P22-pipeline) bootstrap path.
 *
 * Lives in Bootstrap (L4), not Piwigo\Session (L1): the body constructs
 * Piwigo\Auth\CookieService (L2a), an upward dependency L1 may not take —
 * same "only violation-free home" reasoning as UserBootstrap. Called from
 * RequestBootstrap::connect() on every request, and directly by the
 * install.php/upgrade.php/upgrade_feed.php entry scripts (which used to
 * include_once the deleted file at the same point).
 *
 * In PHP 8.4+ calling session_set_save_handler with two parameters is
 * deprecated. To correct this, we pass a SessionHandlerInterface instance.
 * https://github.com/Piwigo/Piwigo/issues/2296
 */
final class SessionBootstrap
{
    public static function register(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

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
    }
}
