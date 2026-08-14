<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Bootstrap\Request\UserBootstrapRequest;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\User\UserInit;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\Core;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsInitializer;

/**
 * Cookie/session/auto-login/Apache-auth/API-key orchestration deciding who
 * the current request's user is, finishing with a call to build_user() to
 * fully populate $user.
 *
 * A sibling to RequestBootstrap, not a method on Piwigo\Auth\AuthService:
 * AuthService is L2aCoreDomain, and this orchestration's WS API-key branch
 * instantiates Piwigo\Ws\WsErrorResponse (L4Integration) -- Bootstrap and Ws are
 * matched by the same deptrac collector (same layer), so this is the only
 * violation-free home for the whole orchestration. AuthService's own
 * login/logout/remember-me building blocks (autoLogin()/logUser()/
 * logoutUser()) are called directly, not through free-function wrappers,
 * since this class sits right next to the real service already.
 */
final readonly class UserBootstrap
{
    public function __construct(
        private AccessLevelChecker $accessLevelChecker,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ApiKeyRequestFlag $apiKeyRequestFlag,
        private CurrentLogger $currentLogger,
        private WsContext $wsContext,
        private DeploymentPolicy $deploymentPolicy,
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
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $installationFlag = Kernel::container()->get(InstallationFlag::class);
        if (! $installationFlag instanceof InstallationFlag) {
            throw new LogicException('Container returned an unexpected type for ' . InstallationFlag::class);
        }
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }
        $passwordService = new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), $this->deploymentPolicy);
        $authService = new AuthService(
            new AuthRepository(EntityManagerFactory::build($conn)),
            new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
            RequestBootstrap::htmlService(),
            $passwordService,
            new CookieService(),
            EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class),
            $sessionService,
            $eventDispatcher,
            $pageState,
            $currentUser,
            RequestBootstrap::currentConfig(),
            $paths,
            EntityManagerFactory::build($conn),
        );
        $filterState = RequestBootstrap::filterState();
        $translator = Kernel::container()->get(Translator::class);
        if (! $translator instanceof Translator) {
            throw new LogicException('Container returned an unexpected type for ' . Translator::class);
        }
        $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), RequestBootstrap::currentConfig()), $currentUser, $filterState, $this->accessLevelChecker);
        $userService = new UserService(
            RequestBootstrap::lang(),
            new UserRepository(EntityManagerFactory::build($conn), $eventDispatcher, RequestBootstrap::currentConfig()),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
            RequestBootstrap::htmlService(),
            $sessionService,
            $eventDispatcher,
            $this->deploymentPolicy,
            $currentUser,
            RequestBootstrap::currentConfig(),
            $installationFlag,
            RequestBootstrap::processCache(),
            $paths,
            EntityManagerFactory::build($conn),
            $permissionService,
            new CategoryService(RequestBootstrap::lang(), new CategoryRepository(EntityManagerFactory::build($conn), RequestBootstrap::currentConfig()), $permissionService, RequestBootstrap::currentConfig(), $eventDispatcher, $translator, $this->accessLevelChecker, new UserRepository(EntityManagerFactory::build($conn), $eventDispatcher, RequestBootstrap::currentConfig())),
            $passwordService,
        );

        $guest_id_int = RequestBootstrap::currentConfig()->guestId;

        // by default we start with guest
        $user = [];
        $user['id'] = RequestBootstrap::currentConfig()->guestId;

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
                if (! (bool) ($user['id'] = $remoteUsername instanceof Username ? $userService->getUserId($remoteUsername)?->value : null)) {
                    $urlService = Kernel::container()->get(UrlServiceInterface::class);
                    if (! $urlService instanceof UrlServiceInterface) {
                        throw new LogicException('Container returned an unexpected type for ' . UrlServiceInterface::class);
                    }
                    $user['id'] = $userService
                        ->registerUser($remote_user, '', '', $urlService, $mailer, false)
                        ->userId ?? false;
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
            // parameter. authKeyLogin() itself validates this value against
            // a strict regex ([a-z0-9]{30} or pkid-... only), so it can't
            // contain anything needing SQL escaping in the first place.
            $auth_header = $_SERVER['HTTP_X_PIWIGO_API'];
            assert(is_string($auth_header));

            if ((bool) $auth_header) {
                $authenticate = $authService->authKeyLogin($auth_header, true);
                if (! $authenticate) {
                    // A plain local read of WsInitializer::init()'s return
                    // value -- no `global $service;` needed.
                    $wsInitializer = Kernel::container()->get(WsInitializer::class);
                    if (! $wsInitializer instanceof WsInitializer) {
                        throw new LogicException('Container returned an unexpected type for ' . WsInitializer::class);
                    }
                    $service = $wsInitializer->init();
                    $service->sendResponse(new WsErrorResponse(401, 'Invalid api_key'));
                    exit;
                }
                $this->apiKeyRequestFlag->activate();

                // set pwg_token for api_key request -- load-bearing write, not
                // covered by Request\UserBootstrapRequest (see its own
                // docblock): must stay visible to later $_POST/$_GET reads
                // in this same request (Ws\Request\WsRawRequest::fromGlobals()
                // and any downstream CSRF check).
                $_POST['pwg_token'] = $_GET['pwg_token'] = new CsrfService($currentConfig)->getToken();

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

            $pwgCore = Kernel::container()->get(Core::class);
            if (! $pwgCore instanceof Core) {
                throw new LogicException('Container returned an unexpected type for ' . Core::class);
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
        // CurrentUser is synced here, not only in RequestBootstrap::connect()
        // after this method returns -- AccessControl::isAGuest()/isGeneric()
        // right below already read CurrentUser, and this method runs (from
        // RequestBootstrap::connect()) well before RequestBootstrap::
        // finalize()'s own CurrentUser::attachGlobals() call, so without
        // this sync CurrentUser::get() throws "not initialised" the first
        // time any consumer runs within this same request.
        $currentUser->set(User::fromUserArray($user));
        // This is the only real per-request user resolver, so this is where
        // ActivityService::record()'s "was a real user ever resolved this
        // request" flag gets marked -- see CurrentUser::wasRealUserResolved()'s
        // own docblock for why isInitialized() can't substitute.
        $currentUser->markRealUserResolved();

        if (RequestBootstrap::currentConfig()->browserLanguage and ($this->accessLevelChecker->isAGuest() or $this->accessLevelChecker->isGeneric()) and (bool) ($language = $userService->getBrowserLanguage())) {
            $user['language'] = $language;
            $currentUser->updateLanguage(LangCode::from($language));
        }
        $eventDispatcher->dispatchNotify(new UserInit($user));
    }

    /**
     * The Apache-authentication REMOTE_USER/REDIRECT_REMOTE_USER
     * resolution loop, extracted as a pure function so it's directly
     * Unit-testable.
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
