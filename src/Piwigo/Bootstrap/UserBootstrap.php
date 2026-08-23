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
use Piwigo\Bootstrap\Event\UserInit;
use Piwigo\Bootstrap\Request\UserBootstrapRequest;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
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

/**
 * Cookie/session/auto-login/Apache-auth/API-key orchestration deciding who
 * the current request's user is, finishing with a call to build_user() to
 * fully populate $user.
 *
 * A sibling to RequestBootstrap, not a method on Piwigo\Auth\AuthService:
 * AuthService is L2aCoreDomain, and this class is L4Integration (heavy
 * Kernel/container-lookup dependencies throughout). AuthService's own
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
        private DeploymentPolicy $deploymentPolicy,
        private ConnectedWithSession $connectedWithSession,
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
            $this->connectedWithSession,
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
            new CategoryService(RequestBootstrap::lang(), new CategoryRepository(EntityManagerFactory::build($conn), RequestBootstrap::currentConfig()), $permissionService, RequestBootstrap::currentConfig(), $eventDispatcher, $translator, $this->accessLevelChecker),
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

        // The still-live api-key login path is the simpler, always-active
        // $userBootstrapRequest->authKeyPresent branch above (`?auth=`
        // query param).

        // $user['id'] is always numeric here (either \Piwigo\Config\CurrentConfig::guestId(), a
        // $_SESSION['pwg_uid'] set by a prior login, or the int|false result of
        // get_userid()/register_user() coerced above); the is_numeric() check is a
        // defensive narrowing to satisfy build_user()'s int $user_id, matching the
        // guest_id fallback already used earlier in this file.
        $user_id_int = is_numeric($user['id']) ? (int) $user['id'] : $guest_id_int;

        // A session's own pwg_uid can outlive the `users` row it names --
        // the user was deleted after the session was established (e.g.
        // an admin deleting an account while it still has an active
        // session/background request in flight). buildUser() below would
        // otherwise throw. Same "unresolvable session state -> guest"
        // degradation this method already applies for a missing session
        // cookie above, not a new pattern -- and the stale id is cleared
        // so a later request on the same browser session doesn't repeat
        // the same lookup.
        if ($user_id_int !== $guest_id_int && ! $userService->userExists(UserId::from($user_id_int))) {
            $user_id_int = $guest_id_int;
            unset($_SESSION['pwg_uid']);
        }

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
        $eventDispatcher->dispatch(new UserInit($user));
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
