<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\Method\GeneralEndpoints;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

/**
 * Resolves the current user from session, cookie, Apache auth, or auth-key.
 *
 * Replaces the former include/user.inc.php procedural script.
 * Called from AuthMiddleware::process() so that every request routed through
 * the PSR-15 pipeline has a fully-built $GLOBALS['user'] before the
 * controller runs.
 */
final class UserBootstrap
{
    private static bool $bootstrapped = false;

    public static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        /** @var array<string,mixed> $user */
        $user = &$GLOBALS['user'];
        /** @var array<string,mixed> $page */
        $page = &$GLOBALS['page'];

        // Start with guest
        $user['id'] = Config::guestId();

        if (isset($_COOKIE[session_name()])) {
            if (isset($_GET['act']) && is_scalar($_GET['act']) && (string) $_GET['act'] === 'logout') {
                AuthService::get()->logoutUser();
                redirect(get_gallery_home_url());
            } elseif (!empty($_SESSION['pwg_uid'])) {
                $user['id'] = $_SESSION['pwg_uid'];
            }
        }

        // Auto-login via remember-me cookie
        if ($user['id'] == Config::guestId()) {
            AuthService::get()->autoLogin();
        }

        // Apache authentication overrides session
        if (Config::apacheAuthentication()) {
            $remoteUser = null;
            foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $serverKey) {
                if (isset($_SERVER[$serverKey])) {
                    $remoteUser = $_SERVER[$serverKey];
                    break;
                }
            }
            if (isset($remoteUser)) {
                $remoteUserStr = is_scalar($remoteUser) ? (string) $remoteUser : '';
                $userId = UserService::get()->getUserid($remoteUserStr);
                if (!$userId) {
                    $userId = UserService::get()->registerUser($remoteUserStr, '', '', false);
                }
                $user['id'] = $userId;
            }
        }

        // Auth-key login (e.g. email confirmation links)
        if (isset($_GET['auth'])) {
            AuthService::get()->authKeyLogin(is_scalar($_GET['auth']) ? (string) $_GET['auth'] : '');
        }

        // HTTP API key (only relevant when IN_WS is defined by WsController)
        if (defined('IN_WS')
            && isset($_SERVER['HTTP_X_PIWIGO_API'])
            && !empty($_SERVER['HTTP_X_PIWIGO_API'])
            && isset($_REQUEST['method'])
        ) {
            $authHeader = is_scalar($_SERVER['HTTP_X_PIWIGO_API']) ? (string) $_SERVER['HTTP_X_PIWIGO_API'] : '';
            if ($authHeader) {
                $authenticated = AuthService::get()->authKeyLogin($authHeader, true);
                if (!$authenticated) {
                    PwgServer::boot();
                    $serviceRaw = $GLOBALS['service'] ?? null;
                    if ($serviceRaw instanceof PwgServer) {
                        $serviceRaw->sendResponse(new PwgError(401, 'Invalid api_key'));
                    }
                    exit;
                }
                define('PWG_API_KEY_REQUEST', true);
                $_POST['pwg_token'] = $_GET['pwg_token'] = get_pwg_token();
                LoggerRegistry::current()->info(
                    '[api_key][pkid=' . explode(':', $authHeader)[0] . ']'
                    . '[method=' . (is_scalar($_REQUEST['method']) ? (string) $_REQUEST['method'] : '') . ']'
                );
            }
        }

        // pwg.images.uploadAsync credential login (IN_WS only)
        if (defined('IN_WS')
            && isset($_REQUEST['method'])
            && is_scalar($_REQUEST['method'])
            && (string) $_REQUEST['method'] === 'pwg.images.uploadAsync'
            && isset($_POST['username'])
            && isset($_POST['password'])
        ) {
            PwgServer::boot();
            $credentials = [
                'username' => $_POST['username'],
                'password' => $_POST['password'],
            ];
            $serviceRaw = $GLOBALS['service'] ?? null;
            if ($serviceRaw instanceof PwgServer) {
                $login = ServiceLocator::get(GeneralEndpoints::class)->sessionLogin($credentials, $serviceRaw);
                if (true !== $login) {
                    $serviceRaw->sendResponse($login);
                    exit();
                }
            }
            $_SESSION['connected_with'] = 'pwg.images.uploadAsync';
        }

        // Cache invalidation flag
        $page['user_use_cache'] = true;
        if (defined('IN_ADMIN') ? constant('IN_ADMIN') : false) {
            $page['user_use_cache'] = false;
        } elseif (
            isset($_REQUEST['method'])
            && isset($_SERVER['HTTP_REFERER'])
            && preg_match(
                '/\/admin\.php\?page=/',
                is_scalar($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : ''
            )
        ) {
            $page['user_use_cache'] = false;
        }

        // Build full user array from DB
        $userId = is_numeric($user['id']) ? (int) $user['id'] : 0;
        $user   = UserService::get()->buildUser($userId, (bool) $page['user_use_cache']);

        // Browser-language override for guests
        if (Config::browserLanguage() && (PermissionService::get()->isAGuest() || PermissionService::get()->isGeneric())) {
            $language = PreferencesService::get()->getBrowserLanguage();
            if ($language) {
                $user['language'] = $language;
            }
        }

        trigger_notify('user_init', $user);

        // Re-attach CurrentUser after UserService::get()->buildUser() populated $GLOBALS['user']
        CurrentUser::attachGlobals();
    }

    public static function reset(): void
    {
        self::$bootstrapped = false;
    }
}
