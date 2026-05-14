<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Util;
use Piwigo\Session\SessionService;

/**
 * Installs Piwigo's session handler before session_start().
 *
 * Honors the session_save_handler config (default 'db' → SessionService,
 * 'files' → save_path under _data/sessions/). Without this, PHP falls
 * through to its SAPI default (commonly /var/lib/php/sessions on
 * Debian/Ubuntu, which the distro restricts so its own GC can't open
 * the dir, producing log spam on every request).
 */
final class SessionBootstrap
{
    public static function bootstrap(): void
    {
        if (Config::sessionSaveHandler() === 'db') {
            session_set_save_handler(Kernel::service(SessionService::class));
        } else {
            $session_dir = PHPWG_ROOT_PATH . Config::dataLocation() . 'sessions';
            if (!is_dir($session_dir) && !Util::mkgetdir($session_dir, MKGETDIR_RECURSIVE) && !is_dir($session_dir)) {
                // Could not create the dir and it still doesn't exist — fall through and let
                // PHP raise the actual error from session_start() with the failed save_path.
            }
            session_save_path($session_dir);
        }

        if (function_exists('ini_set')) {
            ini_set('session.use_cookies', Config::sessionUseCookies() ? '1' : '0');
            ini_set('session.use_only_cookies', Config::sessionUseOnlyCookies() ? '1' : '0');
            ini_set('session.use_trans_sid', Config::sessionUseTransSid() ? '1' : '0');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_probability', (string) Config::sessionGcProbability());
        }

        session_name(Config::sessionName());
        session_set_cookie_params(0, CookieService::cookiePath());
        register_shutdown_function(session_write_close(...));
    }
}
