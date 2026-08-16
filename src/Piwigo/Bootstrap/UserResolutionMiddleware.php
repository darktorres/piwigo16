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
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
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
 * Third real per-request bootstrap middleware (workstream C3 Phase 1) --
 * verbatim-ported from the last block of `RequestBootstrap::connect()`
 * (the `TryLogUser` handler registration + `UserBootstrap::initialize()`
 * call). Runs after `Http\Middleware\PluginBootstrapMiddleware`/
 * `Admin\LoadedPluginsMiddleware`, matching `connect()`'s own ordering.
 *
 * Lives in `Piwigo\Bootstrap\` (L4Integration), not `Http\Middleware\`
 * (L3Presentation), unlike every other Phase 1 middleware --
 * `UserBootstrap` itself genuinely cannot move to L3: its own class
 * docblock explains its WS api_key branch instantiates `Ws\
 * WsErrorResponse` (L4Integration, matched by the same deptrac collector
 * as `Bootstrap\*`), so L4 is "the only violation-free home for the whole
 * orchestration." This is the P25 Stage 2 item 5 WS-auth logic finally
 * running as real middleware -- its 2 api_key/uploadAsync branches already
 * throw `ResponseReadyException` (P25 Stage 2's own fix), safe now that
 * workstream C3 Phase 0 makes that propagate correctly through
 * `SecurityHeadersMiddleware`/`ServerTimingMiddleware` regardless of which
 * middleware throws it.
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
        private ApiKeyRequestFlag $apiKeyRequestFlag,
        private CurrentLogger $currentLogger,
        private WsContext $wsContext,
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
        // EventDispatcher::dispatchChange() with no matching handler
        // returns the event object unchanged, so TryLogUser's own
        // constructor-set $success (false) stays false; that credential
        // path needs the handler registered this early; every other real
        // caller of tryLogUser() (the normal pwg.session.login WS
        // dispatch, later in this same pipeline) is unaffected by this
        // ordering.
        foreach (new AuthListener(new AuthService(
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
        ))->subscribedEvents() as $eventClass => $listenerHandlers) {
            foreach (is_array($listenerHandlers) ? $listenerHandlers : [$listenerHandlers] as $listenerHandler) {
                $this->eventDispatcher->addTypedHandler($eventClass, $listenerHandler);
            }
        }

        new UserBootstrap(
            $this->accessLevelChecker,
            new RedirectService($this->lang, $this->userService, $this->eventDispatcher, $this->pageState),
            $this->urlService,
            $this->apiKeyRequestFlag,
            $this->currentLogger,
            $this->wsContext,
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
