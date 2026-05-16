<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\User\UserInit;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Ws\Method\GeneralEndpoints;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\PwgServerRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Resolves the current user from session, cookie, Apache auth, or auth-key.
 *
 * Called from AuthMiddleware::process() so that every request routed through
 * the PSR-15 pipeline has a fully populated CurrentUser singleton before
 * the controller runs.
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

        // Accumulate the user ID through auth methods
        $userId = Config::guestId();

        if (isset($_COOKIE[session_name()])) {
            if (isset($_GET['act']) && is_string($_GET['act']) && $_GET['act'] === 'logout') {
                Kernel::service(AuthService::class)->logoutUser();
                Kernel::service(RedirectResponder::class)->redirect(Kernel::service(UrlService::class)->getGalleryHomeUrl());
            } elseif (!empty($_SESSION['pwg_uid']) && is_numeric($_SESSION['pwg_uid'])) {
                $userId = (int) $_SESSION['pwg_uid'];
            }
        }

        // Auto-login via remember-me cookie
        if ($userId == Config::guestId()) {
            if (Kernel::service(AuthService::class)->autoLogin()) {
                $userId = isset($_SESSION['pwg_uid']) && is_numeric($_SESSION['pwg_uid'])
                    ? (int) $_SESSION['pwg_uid']
                    : $userId;
            }
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
                $apacheUserId = Kernel::service(UserService::class)->getUserid($remoteUserStr);
                if ($apacheUserId === false || $apacheUserId === 0) {
                    $apacheUserId = Kernel::service(UserService::class)->registerUser($remoteUserStr, '', '', false);
                }
                if ($apacheUserId !== false) {
                    $userId = $apacheUserId;
                }
            }
        }

        // Auth-key login — authKeyLogin() calls CurrentUser::setRawAttributes() internally
        if (isset($_GET['auth'])) {
            $rawAuth = $_GET['auth'];
            Kernel::service(AuthService::class)->authKeyLogin(is_string($rawAuth) ? $rawAuth : '');
            // Read back the user ID that authKeyLogin() resolved
            if (CurrentUser::isInitialized()) {
                $resolvedId = CurrentUser::get()->rawAttributes['id'] ?? $userId;
                $userId = is_numeric($resolvedId) ? (int) $resolvedId : $userId;
            }
        }

        $inWs = RequestContextRegistry::current() === RequestContext::Ws;

        // HTTP API key (only relevant under the Ws request context set by WsController)
        if ($inWs
            && isset($_SERVER['HTTP_X_PIWIGO_API'])
            && !empty($_SERVER['HTTP_X_PIWIGO_API'])
            && isset($_REQUEST['method'])
        ) {
            /** @var mixed $authHeaderRaw */
            $authHeaderRaw = $_SERVER['HTTP_X_PIWIGO_API'];
            $authHeader    = is_string($authHeaderRaw) ? $authHeaderRaw : '';
            $authenticated = Kernel::service(AuthService::class)->authKeyLogin($authHeader, true);
            if (!$authenticated) {
                PwgServer::boot();
                if (PwgServerRegistry::isInitialized()) {
                    PwgServerRegistry::current()->sendResponse(new PwgError(401, 'Invalid api_key'));
                }
                exit;
            }
            if (CurrentUser::isInitialized()) {
                $resolvedId = CurrentUser::get()->rawAttributes['id'] ?? $userId;
                $userId = is_numeric($resolvedId) ? (int) $resolvedId : $userId;
            }
            define('PWG_API_KEY_REQUEST', true);
            $_POST['pwg_token'] = $_GET['pwg_token'] = Kernel::service(CsrfService::class)->getToken();
            $requestMethodRaw = $_REQUEST['method'];
            LoggerRegistry::current()->info(
                '[api_key][pkid=' . explode(':', $authHeader)[0] . ']'
                . '[method=' . (is_string($requestMethodRaw) ? $requestMethodRaw : '') . ']'
            );
        }

        // pwg.images.uploadAsync credential login (Ws context only)
        if ($inWs
            && isset($_REQUEST['method'])
            && is_string($_REQUEST['method'])
            && $_REQUEST['method'] === 'pwg.images.uploadAsync'
            && isset($_POST['username'])
            && isset($_POST['password'])
        ) {
            PwgServer::boot();
            $credentials = [
                'username' => $_POST['username'],
                'password' => $_POST['password'],
            ];
            if (PwgServerRegistry::isInitialized()) {
                $srv   = PwgServerRegistry::current();
                $login = Kernel::service(GeneralEndpoints::class)->sessionLogin($credentials, $srv);
                if (true !== $login) {
                    $srv->sendResponse($login);
                    exit();
                }
            }
            $_SESSION['connected_with'] = 'pwg.images.uploadAsync';
        }

        // Cache invalidation flag
        $useCache = true;
        if (RequestContextRegistry::current() === RequestContext::Admin) {
            $useCache = false;
        } else {
            $referer = $_SERVER['HTTP_REFERER'] ?? null;
            if (isset($_REQUEST['method'])
                && is_string($referer)
                && preg_match('/\/admin\.php\?page=/', $referer)
            ) {
                $useCache = false;
            }
        }

        // Build full user array from DB
        $builtUser = Kernel::service(UserService::class)->buildUser($userId, $useCache);

        // Browser-language override for guests (read status from built array to avoid circular dep)
        if (Config::browserLanguage()) {
            $status = is_string($builtUser['status'] ?? null) ? $builtUser['status'] : '';
            if (in_array($status, ['guest', 'generic'], true)) {
                $language = Kernel::service(PreferencesService::class)->getBrowserLanguage();
                if ($language !== false && $language !== '') {
                    $builtUser['language'] = $language;
                }
            }
        }

        CurrentUser::set(User::fromUserArray($builtUser));

        Kernel::service(EventDispatcherInterface::class)->dispatch(new UserInit(CurrentUser::get()->rawAttributes));
    }

    public static function reset(): void
    {
        self::$bootstrapped = false;
    }
}
