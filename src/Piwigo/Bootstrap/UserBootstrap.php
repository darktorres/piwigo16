<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbConnection;
use Piwigo\Event\User\UserInit;
use Piwigo\Html\HtmlService;
use Piwigo\Session\SessionService;
use Piwigo\Url\UrlService;
use Piwigo\Ws\PwgCore;
use Piwigo\Ws\PwgError;

/**
 * Ported from include/user.inc.php -- cookie/session/auto-login/Apache-
 * auth/API-key orchestration deciding who the current request's user is,
 * finishing with a call to build_user() to fully populate $user.
 *
 * A sibling to RequestBootstrap, not a method on Piwigo\Auth\AuthService:
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
 * common.inc.php -> user.inc.php, all raw `include`s). Its own
 * `$page['user_use_cache']` write and the shouldUseUserCache() helper that
 * fed it were removed entirely once Stage 4g dropped buildUser()'s
 * $useCache parameter and the user_cache lock/wait mechanism it supported.
 */
final class UserBootstrap
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ApiKeyRequestFlag $apiKeyRequestFlag,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly WsContext $wsContext,
    ) {}

    public function initialize(): void
    {
        $userBootstrapRequest = Request\UserBootstrapRequest::fromGlobals();

        $conn = DbConnection::build();
        $sessionService = Kernel::container()->get(SessionService::class);
        if (! $sessionService instanceof SessionService) {
            throw new \LogicException('Container returned an unexpected type for ' . SessionService::class);
        }
        $authService = new AuthService(
            new AuthRepository(\Piwigo\Db\EntityManagerFactory::build($conn)),
            new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)),
            new HtmlService(),
            new PasswordService(new PasswordRepository($conn)),
            new CookieService(),
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Auth\UserFailedLoginEntity::class),
            $sessionService,
        );
        $userService = new \Piwigo\Users\UserService(
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Users\UserInfoEntity::class),
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class),
            new \Piwigo\Mail\MailService(),
            new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)),
            new HtmlService(),
            $conn,
            $sessionService,
        );

        $guest_id_int = \Piwigo\Config\CurrentConfig::guestId();

        // by default we start with guest
        $user = [];
        $user['id'] = \Piwigo\Config\CurrentConfig::guestId();

        $session_cookie_name = session_name();
        $session_cookie_name = is_string($session_cookie_name) ? $session_cookie_name : '';

        if ($session_cookie_name !== '' && isset($_COOKIE[$session_cookie_name])) {
            if ($userBootstrapRequest->logoutRequested) { // logout
                $authService->logoutUser();
                $this->redirectService->redirect($this->urlService->getGalleryHomeUrl());
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
        if (\Piwigo\Config\DeploymentPolicy::current()->apacheAuthentication) {
            $remote_user = self::resolveApacheRemoteUser($_SERVER);

            if ($remote_user !== null) {
                $remoteUsername = \Piwigo\Common\ValueObject\Username::tryFrom($remote_user);
                if (! (bool) ($user['id'] = $remoteUsername === null ? null : $userService->getUserId($remoteUsername)?->value)) {
                    $user['id'] = $userService
                        ->registerUser($remote_user, '', '', new UrlService(new HtmlService()), false)['userId'] ?? false;
                }
            }
        }

        // automatic login by authentication key
        if ($userBootstrapRequest->authKeyPresent) {
            $authService->authKeyLogin($userBootstrapRequest->authKey);
        }

        // HTTP_AUTHORIZATION api_key
        if (
            $this->wsContext->isActive()
            and isset($_SERVER['HTTP_X_PIWIGO_API'])
            and is_string($_SERVER['HTTP_X_PIWIGO_API'])
            and ! self::emptyValue($_SERVER['HTTP_X_PIWIGO_API'])
            and $userBootstrapRequest->wsMethod !== null
        ) {
            // $_SERVER['HTTP_X_PIWIGO_API'] is already known non-empty by the
            // enclosing condition; AuthRepository::findAuthKeyDetails() (what
            // authKeyLogin() below ultimately calls) uses a real bound DBAL
            // parameter, so the real_escape_string()-style pre-escaping this
            // line used to apply was dead weight even before the DBAL
            // migration -- the regex authKeyLogin() itself validates this
            // against ([a-z0-9]/pkid-.../ only) can't contain anything
            // needing SQL escaping in the first place.
            $auth_header = $_SERVER['HTTP_X_PIWIGO_API'];

            if ((bool) $auth_header) {
                $authenticate = $authService->authKeyLogin($auth_header, true);
                if (! $authenticate) {
                    // A plain local read of WsInitializer::init()'s return
                    // value -- never needed `global $service;` (see that
                    // class's own docblock for the now-removed
                    // $GLOBALS['service'] publish this predates).
                    $service = \Piwigo\Ws\WsInitializer::init();
                    $service->sendResponse(new PwgError(401, 'Invalid api_key'));
                    exit;
                }
                $this->apiKeyRequestFlag->activate();

                // set pwg_token for api_key request -- load-bearing write, not
                // covered by Request\UserBootstrapRequest (see its own
                // docblock): must stay visible to later $_POST/$_GET reads
                // in this same request (Ws\Request\WsRawRequest::fromGlobals()
                // and any downstream CSRF check).
                $_POST['pwg_token'] = $_GET['pwg_token'] = new \Piwigo\Csrf\CsrfService()->getToken();

                // logger
                $logger = $this->currentLogger->get();
                $logger->info('[api_key][pkid=' . explode(':', $auth_header)[0] . '][method=' . $userBootstrapRequest->wsMethod . ']');
            }
        }

        if (
            $this->wsContext->isActive()
            and $userBootstrapRequest->wsMethod === 'pwg.images.uploadAsync'
            and $userBootstrapRequest->username !== null
            and $userBootstrapRequest->password !== null
        ) {
            $service = \Piwigo\Ws\WsInitializer::init();

            $credentials = [
                'username' => $userBootstrapRequest->username,
                'password' => $userBootstrapRequest->password,
            ];

            $login = PwgCore::sessionLogin($credentials, $service);

            if ($login !== true) {
                $service->sendResponse($login);
                exit();
            }
            $_SESSION['connected_with'] = 'pwg.images.uploadAsync';
        }

        // $user['id'] is always numeric here (either \Piwigo\Config\CurrentConfig::guestId(), a
        // $_SESSION['pwg_uid'] set by a prior login, or the int|false result of
        // get_userid()/register_user() coerced above); the is_numeric() check is a
        // defensive narrowing to satisfy build_user()'s int $user_id, matching the
        // guest_id fallback already used earlier in this file.
        $user_id_int = is_numeric($user['id']) ? (int) $user['id'] : $guest_id_int;

        $user = $userService->buildUser(\Piwigo\Common\ValueObject\UserId::from($user_id_int));
        // Legacy Coupling Retirement Track A batch A3: sync CurrentUser here,
        // not only in RequestBootstrap::connect() after this method returns
        // -- AccessControl::isAGuest()/isGeneric() right below already read
        // CurrentUser, and this method runs (from RequestBootstrap::connect())
        // well before RequestBootstrap::finalize()'s own
        // CurrentUser::attachGlobals() call, so without this sync
        // CurrentUser::get() throws "not initialised"
        // the first time any retargeted consumer runs within this same
        // request (caught via a live Contract-test HTTP 500, not a unit
        // test -- the Integration/Unit harnesses independently seed
        // CurrentUser in their own setUp(), masking the gap).
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));
        // Legacy Coupling Retirement Phase 8, 8h: this is the only real
        // per-request user resolver, so this is where ActivityService::
        // record()'s "was a real user ever resolved this request" flag
        // gets marked -- see CurrentUser::wasRealUserResolved()'s own
        // docblock for why isInitialized() can't substitute.
        \Piwigo\Users\CurrentUser::markRealUserResolved();

        if (\Piwigo\Config\CurrentConfig::browserLanguage() and (\Piwigo\Auth\AccessControl::isAGuest() or \Piwigo\Auth\AccessControl::isGeneric()) and (bool) ($language = $userService->getBrowserLanguage())) {
            $user['language'] = $language;
            \Piwigo\Users\CurrentUser::updateLanguage($language);
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new UserInit($user));
    }

    /**
     * The Apache-authentication REMOTE_USER/REDIRECT_REMOTE_USER
     * resolution loop (originally inline in include/user.inc.php) --
     * extracted as a pure function so it's directly Unit-testable, same
     * "extract the one real piece of pure logic" precedent as every prior
     * P23 batch's own extractions.
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
