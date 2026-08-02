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
 * RequestBootstrap::connect() on every request, and directly by
 * public/install.php (upgrade.php/upgrade_feed.php were deleted in full by
 * the Stage 0 legacy in-place upgrade chain removal; no upgrade entry
 * script remains).
 *
 * In PHP 8.4+ calling session_set_save_handler with two parameters is
 * deprecated. To correct this, we pass a SessionHandlerInterface instance.
 * https://github.com/Piwigo/Piwigo/issues/2296
 */
final class SessionBootstrap
{
    public static function register(): void
    {

        if (\Piwigo\Config\CurrentConfig::sessionSaveHandler() === 'db'
          and \Piwigo\Core\InstallationFlag::isActiveStatic()) {
            session_set_save_handler(new PwgSession());

            if (function_exists('ini_set')) {
                $session_use_cookies = \Piwigo\Config\CurrentConfig::sessionUseCookies();
                ini_set('session.use_cookies', $session_use_cookies);

                $session_use_only_cookies = \Piwigo\Config\CurrentConfig::sessionUseOnlyCookies();
                ini_set('session.use_only_cookies', $session_use_only_cookies);

                $session_use_trans_sid = \Piwigo\Config\CurrentConfig::sessionUseTransSid();
                ini_set('session.use_trans_sid', intval($session_use_trans_sid));
                ini_set('session.cookie_httponly', 1);
            }

            $session_name = \Piwigo\Config\CurrentConfig::sessionName();
            session_name($session_name);
            session_set_cookie_params(0, new CookieService()->cookiePath());
            register_shutdown_function(session_write_close(...));
        }
    }
}
