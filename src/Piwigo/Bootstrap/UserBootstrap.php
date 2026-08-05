<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Bootstrap\Request\UserBootstrapRequest;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\User\UserInit;
use Piwigo\Group\GroupEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgCore;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\WsInitializer;

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
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ApiKeyRequestFlag $apiKeyRequestFlag,
        private readonly CurrentLogger $currentLogger,
        private readonly WsContext $wsContext,
        private readonly DeploymentPolicy $deploymentPolicy,
    ) {}

    public function initialize(): void
    {
        $userBootstrapRequest = UserBootstrapRequest::fromGlobals();

        $conn = DbConnection::build();
        $sessionService = Kernel::container()->get(SessionService::class);
        if (! $sessionService instanceof SessionService) {
            throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
        }
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
        }
        $pageState = Kernel::container()->get(PageState::class);
        if (! $pageState instanceof PageState) {
            throw new LogicException('Container returned an unexpected type for ' . PageState::class);
        }
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $mailer = Kernel::container()->get(MailerInterface::class);
        if (! $mailer instanceof MailerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . MailerInterface::class);
        }
        $installationFlag = Kernel::container()->get(InstallationFlag::class);
        if (! $installationFlag instanceof InstallationFlag) {
            throw new LogicException('Container returned an unexpected type for ' . InstallationFlag::class);
        }
        $authService = new AuthService(
            new AuthRepository(EntityManagerFactory::build($conn)),
            new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
            RequestBootstrap::htmlService(),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), $this->deploymentPolicy),
            new CookieService(),
            EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class),
            $sessionService,
            $eventDispatcher,
            $pageState,
            $currentUser,
            RequestBootstrap::currentConfig(),
        );
        $userService = new UserService(
            RequestBootstrap::lang(),
            new UserRepository(EntityManagerFactory::build($conn), $eventDispatcher, RequestBootstrap::currentConfig()),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            $mailer,
            new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
            RequestBootstrap::htmlService(),
            $conn,
            $sessionService,
            $eventDispatcher,
            $this->deploymentPolicy,
            $currentUser,
            RequestBootstrap::currentConfig(),
            $installationFlag,
            RequestBootstrap::processCache(),
        );

        $guest_id_int = RequestBootstrap::currentConfig()->guestId();

        // by default we start with guest
        $user = [];
        $user['id'] = RequestBootstrap::currentConfig()->guestId();

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
        if ($this->deploymentPolicy->apacheAuthentication) {
            $remote_user = self::resolveApacheRemoteUser($_SERVER);

            if ($remote_user !== null) {
                $remoteUsername = Username::tryFrom($remote_user);
                if (! (bool) ($user['id'] = $remoteUsername === null ? null : $userService->getUserId($remoteUsername)?->value)) {
                    $urlService = Kernel::container()->get(UrlServiceInterface::class);
                    if (! $urlService instanceof UrlServiceInterface) {
                        throw new LogicException('Container returned an unexpected type for ' . UrlServiceInterface::class);
                    }
                    $user['id'] = $userService
                        ->registerUser($remote_user, '', '', $urlService, false)['userId'] ?? false;
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
                    $wsInitializer = Kernel::container()->get(WsInitializer::class);
                    if (! $wsInitializer instanceof WsInitializer) {
                        throw new LogicException('Container returned an unexpected type for ' . WsInitializer::class);
                    }
                    $service = $wsInitializer->init();
                    $service->sendResponse(new PwgError(401, 'Invalid api_key'));
                    exit;
                }
                $this->apiKeyRequestFlag->activate();

                // set pwg_token for api_key request -- load-bearing write, not
                // covered by Request\UserBootstrapRequest (see its own
                // docblock): must stay visible to later $_POST/$_GET reads
                // in this same request (Ws\Request\WsRawRequest::fromGlobals()
                // and any downstream CSRF check).
                $_POST['pwg_token'] = $_GET['pwg_token'] = new CsrfService()->getToken();

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
            $wsInitializer = Kernel::container()->get(WsInitializer::class);
            if (! $wsInitializer instanceof WsInitializer) {
                throw new LogicException('Container returned an unexpected type for ' . WsInitializer::class);
            }
            $service = $wsInitializer->init();

            $credentials = [
                'username' => $userBootstrapRequest->username,
                'password' => $userBootstrapRequest->password,
            ];

            $pwgCore = Kernel::container()->get(PwgCore::class);
            if (! $pwgCore instanceof PwgCore) {
                throw new LogicException('Container returned an unexpected type for ' . PwgCore::class);
            }
            $login = $pwgCore->sessionLogin($credentials, $service);

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

        $user = $userService->buildUser(UserId::from($user_id_int));
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
        $currentUser->set(User::fromUserArray($user));
        // Legacy Coupling Retirement Phase 8, 8h: this is the only real
        // per-request user resolver, so this is where ActivityService::
        // record()'s "was a real user ever resolved this request" flag
        // gets marked -- see CurrentUser::wasRealUserResolved()'s own
        // docblock for why isInitialized() can't substitute.
        $currentUser->markRealUserResolved();

        if (RequestBootstrap::currentConfig()->browserLanguage() and ($this->accessControl->isAGuest() or $this->accessControl->isGeneric()) and (bool) ($language = $userService->getBrowserLanguage())) {
            $user['language'] = $language;
            $currentUser->updateLanguage($language);
        }
        $eventDispatcher->dispatchNotify(new UserInit($user));
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
