<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Html\HtmlService;
use Piwigo\Listener\AuthListener;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Third real per-request bootstrap middleware. Runs after
 * `Http\Middleware\PluginBootstrapMiddleware`/`Admin\LoadedPluginsMiddleware`.
 *
 * Lives in `Piwigo\Bootstrap\` (L4Integration), not `Http\Middleware\`
 * (L3Presentation), unlike every other Phase 1 middleware -- this class
 * itself calls `DbConnection::build()`/`EntityManagerFactory` directly
 * to construct `AuthService`/`AuthListener`, genuine L4Integration work.
 */
final readonly class UserResolutionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HtmlService $htmlService,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private AccessLevelChecker $accessLevelChecker,
        private Lang $lang,
        private UserService $userService,
        private UrlServiceInterface $urlService,
        private DeploymentPolicy $deploymentPolicy,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $conn = DbConnection::build();

        // The TryLogUser handler is registered here, immediately before
        // UserBootstrap::initialize(), rather than alongside the other
        // default event-handler registrations still in RequestBootstrap::
        // finalize(): initialize() reaches AuthService::tryLogUser()
        // directly on its own pwg.images.uploadAsync username/password
        // credential path, before finalize() ever runs.
        // EventDispatcher::dispatch() with no matching handler
        // returns the event object unchanged, so TryLogUser's own
        // constructor-set $success (false) stays false; that credential
        // path needs the handler registered this early; every other real
        // caller of tryLogUser() (the normal pwg.session.login WS
        // dispatch, later in this same pipeline) is unaffected by this
        // ordering.
        $this->eventDispatcher->registerSubscriber(new AuthListener(new AuthService(
            new AuthRepository(EntityManagerFactory::build($conn)),
            $this->activityService($conn),
            $this->htmlService,
            $this->passwordService($conn),
            new CookieService(),
            EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class),
            $this->sessionService,
            $this->eventDispatcher,
            $this->pageState,
            $this->currentUser,
            $this->currentConfig,
            $this->paths,
            EntityManagerFactory::build($conn),
            new ConnectedWithSession(),
        )));

        new UserBootstrap(
            $this->accessLevelChecker,
            new RedirectService($this->lang, $this->userService, $this->eventDispatcher, $this->pageState),
            $this->urlService,
            $this->deploymentPolicy,
            new ConnectedWithSession(),
        )->initialize();

        return $handler->handle($request);
    }

    private function activityService(Connection $conn): ActivityService
    {
        return new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class));
    }

    private function passwordService(Connection $conn): PasswordService
    {
        return new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), $this->deploymentPolicy);
    }
}
