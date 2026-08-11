<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Auth\CookieService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Session\SessionHandler;
use Piwigo\Session\SessionService;

/**
 * Installs Piwigo's DB-backed session save handler before session_start().
 * SessionMiddleware's docblock documents this as the thing that registers
 * SessionHandler as the save handler on every real request for the legacy
 * bootstrap path.
 *
 * Lives in Bootstrap (L4), not Piwigo\Session (L1): the body constructs
 * Piwigo\Auth\CookieService (L2a), an upward dependency L1 may not take —
 * same "only violation-free home" reasoning as UserBootstrap. Called from
 * RequestBootstrap::connect() on every request, and directly by
 * public/install.php.
 *
 * In PHP 8.4+ calling session_set_save_handler with two parameters is
 * deprecated. To correct this, we pass a SessionHandlerInterface instance.
 * https://github.com/Piwigo/Piwigo/issues/2296
 */
final class SessionBootstrap
{
    public static function register(): void
    {

        if (RequestBootstrap::currentConfig()->sessionSaveHandler === 'db'
          and self::installationFlag()->isActive()) {
            $sessionService = Kernel::container()->get(SessionService::class);
            if (! $sessionService instanceof SessionService) {
                throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
            }
            $currentLogger = Kernel::container()->get(CurrentLogger::class);
            if (! $currentLogger instanceof CurrentLogger) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
            }
            session_set_save_handler(new SessionHandler($sessionService, $currentLogger));

            if (function_exists('ini_set')) {
                $session_use_cookies = RequestBootstrap::currentConfig()->sessionUseCookies;
                ini_set('session.use_cookies', $session_use_cookies);

                $session_use_only_cookies = RequestBootstrap::currentConfig()->sessionUseOnlyCookies;
                ini_set('session.use_only_cookies', $session_use_only_cookies);

                $session_use_trans_sid = RequestBootstrap::currentConfig()->sessionUseTransSid;
                ini_set('session.use_trans_sid', intval($session_use_trans_sid));
                ini_set('session.cookie_httponly', 1);
            }

            $session_name = RequestBootstrap::currentConfig()->sessionName;
            session_name($session_name);
            session_set_cookie_params(0, new CookieService()->cookiePath());
            register_shutdown_function(session_write_close(...));
        }
    }

    /**
     * Resolves the container-shared instance -- this class already has
     * direct Kernel::container() access (arch-tested to Bootstrap/ only).
     */
    private static function installationFlag(): InstallationFlag
    {
        $installationFlag = Kernel::container()->get(InstallationFlag::class);
        if (! $installationFlag instanceof InstallationFlag) {
            throw new LogicException('Container returned an unexpected type for ' . InstallationFlag::class);
        }

        return $installationFlag;
    }
}
