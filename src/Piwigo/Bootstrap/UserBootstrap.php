<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Ws\PwgCore;
use Piwigo\Ws\PwgError;

/**
 * Ported from include/user.inc.php -- cookie/session/auto-login/Apache-
 * auth/API-key orchestration deciding who the current request's user is,
 * finishing with a call to build_user() to fully populate $user.
 *
 * A sibling to CommonBootstrap, not a method on Piwigo\Auth\AuthService:
 * AuthService is L2aCoreDomain, and this orchestration's WS API-key branch
 * instantiates Piwigo\Ws\PwgError (L4Integration) -- Bootstrap and Ws are
 * matched by the same deptrac collector (same layer), so this is the only
 * violation-free home for the whole orchestration. AuthService's own
 * login/logout/remember-me building blocks (autoLogin()/logUser()/
 * logoutUser()) are called directly, not through their free-function
 * wrappers (auto_login()/logout_user()), since this class sits right next
 * to the real service already.
 *
 * The original file already declared its own `global $conf, $user;` /
 * `global $service;` at true top-level script scope (index.php ->
 * common.inc.php -> user.inc.php, all raw `include`s) -- but its own
 * `$page['user_use_cache'] = ...` write (no `global $page;` in the
 * original's own header) only worked because common.inc.php's own
 * top-level `$page` was directly shared via include()'s scope-sharing
 * behavior. That bare reference is real and load-bearing -- a `global
 * $page;` declaration is added here that the original file never needed.
 */
final class UserBootstrap
{
    public function initialize(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         * @var array<string, mixed> $page
         */
        global $conf, $user, $page;
        // Set by Piwigo\Ws\WsInitializer::init(), called conditionally below
        // (it publishes the shared PwgServer to $GLOBALS['service'] itself,
        // preserving the deleted include/ws_init.inc.php's top-level
        // global-scope contract).
        global $service;

        $authService = new AuthService(new AuthRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService());

        $guest_id_int = is_numeric($conf['guest_id'] ?? null) ? (int) $conf['guest_id'] : 2;

        // by default we start with guest
        $user['id'] = $conf['guest_id'];

        $session_cookie_name = session_name();
        $session_cookie_name = is_string($session_cookie_name) ? $session_cookie_name : '';

        if ($session_cookie_name !== '' && isset($_COOKIE[$session_cookie_name])) {
            if (($_GET['act'] ?? null) === 'logout') { // logout
                $authService->logoutUser();
                // get_gallery_home_url() is declared to return `mixed` (include/functions_url.inc.php);
                // every real branch of its body actually returns a string, so this narrows locally
                // rather than widening redirect()'s $url parameter.
                $gallery_home_url = get_gallery_home_url();
                redirect(is_string($gallery_home_url) ? $gallery_home_url : '/');
            } else {
                $session_pwg_uid = $_SESSION['pwg_uid'] ?? null;
                if (! self::emptyValue($session_pwg_uid)) {
                    $user['id'] = $session_pwg_uid;
                }
            }
        }

        // Now check the auto-login
        $user_id_int = is_numeric($user['id']) ? (int) $user['id'] : $guest_id_int;
        if ($user_id_int === $guest_id_int) {
            $authService->autoLogin();
        }

        // using Apache authentication override the above user search
        if ((bool) $conf['apache_authentication']) {
            $remote_user = self::resolveApacheRemoteUser($_SERVER);

            if ($remote_user !== null) {
                if (! (bool) ($user['id'] = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getUserId($remote_user))) {
                    $user['id'] = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))
                        ->registerUser($remote_user, '', '', false)['userId'] ?? false;
                }
            }
        }

        // automatic login by authentication key
        if (isset($_GET['auth'])) {
            (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->authKeyLogin($_GET['auth']);
        }

        // HTTP_AUTHORIZATION api_key
        if (
            defined('IN_WS')
            and isset($_SERVER['HTTP_X_PIWIGO_API'])
            and is_string($_SERVER['HTTP_X_PIWIGO_API'])
            and ! self::emptyValue($_SERVER['HTTP_X_PIWIGO_API'])
            and isset($_REQUEST['method'])
            and is_string($_REQUEST['method'])
        ) {
            $auth_header = \Piwigo\Db\MysqliDb::realEscapeString($_SERVER['HTTP_X_PIWIGO_API']) ?? null;

            if ((bool) $auth_header) {
                $authenticate = (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->authKeyLogin($auth_header, true);
                if (! $authenticate) {
                    $service = \Piwigo\Ws\WsInitializer::init();
                    $service->sendResponse(new PwgError(401, 'Invalid api_key'));
                    exit;
                }
                ApiKeyRequestFlag::activate();

                // set pwg_token for api_key request
                $_POST['pwg_token'] = $_GET['pwg_token'] = (new \Piwigo\Csrf\CsrfService())->getToken();

                // logger
                /** @var Logger $logger */
                global $logger;
                $logger->info('[api_key][pkid=' . explode(':', $auth_header)[0] . '][method=' . $_REQUEST['method'] . ']');
            }
        }

        if (
            defined('IN_WS')
            and is_string($_REQUEST['method'] ?? null)
            and $_REQUEST['method'] === 'pwg.images.uploadAsync'
            and is_string($_POST['username'] ?? null)
            and is_string($_POST['password'] ?? null)
        ) {
            $service = \Piwigo\Ws\WsInitializer::init();

            $credentials = [
                'username' => $_POST['username'],
                'password' => $_POST['password'],
            ];

            $login = PwgCore::sessionLogin($credentials, $service);

            if ($login !== true) {
                $service->sendResponse($login);
                exit();
            }
            $_SESSION['connected_with'] = 'pwg.images.uploadAsync';
        }

        $http_referer = $_SERVER['HTTP_REFERER'] ?? null;
        $page['user_use_cache'] = self::shouldUseUserCache(
            defined('IN_ADMIN') && (bool) IN_ADMIN,
            isset($_REQUEST['method']),
            is_string($http_referer) ? $http_referer : null,
        );

        // $user['id'] is always numeric here (either $conf['guest_id'], a
        // $_SESSION['pwg_uid'] set by a prior login, or the int|false result of
        // get_userid()/register_user() coerced above); the is_numeric() check is a
        // defensive narrowing to satisfy build_user()'s int $user_id, matching the
        // guest_id fallback already used earlier in this file.
        $user_id_int = is_numeric($user['id']) ? (int) $user['id'] : $guest_id_int;

        $user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->buildUser($user_id_int, $page['user_use_cache']);
        // Legacy Coupling Retirement Track A batch A3: sync CurrentUser here,
        // not only in RequestBootstrap::connect() after this method returns
        // -- AccessControl::isAGuest()/isGeneric() right below already read
        // CurrentUser, and common.inc.php (every classic HTTP entry point)
        // never calls CommonBootstrap::run()/CurrentUser::attachGlobals(),
        // so without this sync CurrentUser::get() throws "not initialised"
        // the first time any retargeted consumer runs within this same
        // request (caught via a live Contract-test HTTP 500, not a unit
        // test -- the Integration/Unit harnesses independently seed
        // CurrentUser in their own setUp(), masking the gap).
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));

        if ((bool) $conf['browser_language'] and (\Piwigo\Auth\AccessControl::isAGuest() or \Piwigo\Auth\AccessControl::isGeneric()) and (bool) ($language = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getBrowserLanguage())) {
            $user['language'] = $language;
            if (is_string($language)) {
                \Piwigo\Users\CurrentUser::updateLanguage($language);
            }
        }
        trigger_notify('user_init', $user);
    }

    /**
     * The $user_use_cache decision (originally inline in
     * include/user.inc.php) -- extracted as a pure function so it's
     * directly Unit-testable, same "extract the one real piece of pure
     * logic" precedent as every prior P23 batch's own extractions.
     */
    public static function shouldUseUserCache(bool $inAdmin, bool $methodRequested, ?string $httpReferer): bool
    {
        if ($inAdmin) {
            return false;
        }

        if ($methodRequested && $httpReferer !== null && preg_match('/\/admin\.php\?page=/', $httpReferer) === 1) {
            return false;
        }

        return true;
    }

    /**
     * The Apache-authentication REMOTE_USER/REDIRECT_REMOTE_USER
     * resolution loop (originally inline in include/user.inc.php) --
     * extracted as a pure function for the same reason as
     * shouldUseUserCache() above.
     *
     * @param  array<int|string, mixed>  $server
     */
    public static function resolveApacheRemoteUser(array $server): ?string
    {
        foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $server_key) {
            $value = $server[$server_key] ?? null;
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     * Same helper as Piwigo\Section\SectionInitializer::emptyValue() /
     * Piwigo\Section\SectionPopulator::emptyValue() (kept as its own
     * private copy rather than shared, matching this codebase's
     * per-class-small-helper convention).
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
