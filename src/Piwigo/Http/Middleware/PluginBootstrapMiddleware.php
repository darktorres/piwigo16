<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AdminContext;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Env;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\ThemeEntity;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Image\LoungeMaintenance;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\CurrentPluginRegistry;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContextFactory;
use Piwigo\PluginConfig\Facade\CategoryWriteFacade;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ImageWriteFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\PluginConfig\PluginEntity;
use Piwigo\PluginConfig\PluginMigrationEntity;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Second real per-request bootstrap middleware (workstream C3 Phase 1) --
 * verbatim-ported from the second half of `Bootstrap\RequestBootstrap::
 * connect()` (plugin registry boot through lounge-emptying), same
 * statements, same order, `self::xxx()` static resolvers replaced with
 * constructor-injected dependencies. Runs after `ConfigBootstrapMiddleware`
 * (DB/config/logger already ready) and `Http\Middleware\SessionMiddleware`
 * (session already started, matching `connect()`'s own `session_start()`
 * position immediately before this block).
 *
 * Does NOT include `connect()`'s own `Admin\LoadedPlugins` repopulation
 * step, even though it's textually adjacent in the original method --
 * `PluginConfig\PluginRegistry` (this class's own main dependency) is
 * L3Presentation and `Admin\LoadedPlugins` is L4Integration, and
 * `connect()`'s own comment already explains why that glue lived in
 * `RequestBootstrap` (L4) rather than next to `PluginRegistry` itself: an
 * L3->L4 dependency isn't allowed, so `Http\Middleware\*` (also
 * L3Presentation) can't take it on either. See `Admin\
 * LoadedPluginsMiddleware`, a separate real middleware living in `Admin\`
 * (L4) specifically so it can depend on `Admin\LoadedPlugins`, reading the
 * `PluginRegistry` this class already published to `CurrentPluginRegistry`
 * below. Positioned immediately after this middleware in `RequestPipeline::
 * DEFAULT_MIDDLEWARE`.
 *
 * The `$conn`-scoped private helper methods below
 * (`pluginRegistry()`/`extensionContextFactory()`/`imageReadFacade()`/
 * `userReadFacade()`/`themeReadFacade()`/`activityService()`/
 * `categoryService()`/`permissionService()`) are verbatim duplicates of
 * `RequestBootstrap`'s own identically-named private methods, not shared
 * via DI -- matching this codebase's own established precedent for
 * request-Connection-scoped value construction (`activityService()`'s own
 * docblock: "same inline-construct a one-off dependency behind a named
 * method precedent as Tag\TagService::newImageService()/Image\
 * ImageService::categoryService()"). `imageService()`/`tagService()`/
 * `imageWriteFacade()`/`categoryWriteFacade()` (P29.6) are new, not
 * `RequestBootstrap` duplicates -- `imageService()` replaces what used
 * to be an inline `new ImageService(...)` right here for `emptyLounge()`
 * (identical construction, now named and reused); `tagService()`
 * mirrors the identical real construction already used independently in
 * `Menu\MenubarRenderer`/`Metadata\MetadataService`/`Url\UrlService`.
 * These are pure "assemble a value from
 * already-available inputs" constructors, not stateful/security-relevant
 * like `Http\SessionBootstrap` was (which genuinely needed relocating
 * rather than duplicating, since duplicating session cookie/save-handler
 * logic risks silent security drift a type-checked constructor call
 * doesn't).
 */
final readonly class PluginBootstrapMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private Paths $paths,
        private CurrentTemplate $currentTemplate,
        private CurrentUser $currentUser,
        private UserService $userService,
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private RedirectServiceInterface $redirectService,
        private AdminContext $adminContext,
        private SessionService $sessionService,
        private ConfigService $configService,
        private MailService $mailService,
        private CsrfService $csrfService,
        private HtmlService $htmlService,
        private AccessControl $accessControl,
        private FilterState $filterState,
        private Translator $translator,
        private EntityManagerInterface $entityManager,
        private CurrentPluginRegistry $currentPluginRegistry,
        private CurrentLogger $currentLogger,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $conn = DbConnection::build();

        $pluginRegistry = $this->pluginRegistry($conn);
        $pluginRegistry->bootActive();
        $this->currentPluginRegistry->set($pluginRegistry);

        if ($this->currentConfig->piwigoInstalledVersion === null) {
            $this->configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        } elseif ($this->currentConfig->piwigoInstalledVersion !== AppInfo::VERSION) {
            // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
            $this->activityService($conn)
                ->record('system', ActivitySystem::Core, 'autoupdate', [
                    'from_version' => $this->currentConfig->piwigoInstalledVersion,
                    'to_version' => AppInfo::VERSION,
                ]);
            $this->configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        }

        // Check if last major update conf is set if not set it
        if ($this->currentConfig->lastMajorUpdate === null) {
            $dbnow = Env::now()->format('Y-m-d H:i:s');
            $this->configService->confUpdateParam('last_major_update', $dbnow, updateGlobal: true);
        }

        if (LoungeMaintenance::needsEmptying($this->currentConfig, $this->entityManager)) {
            $this->imageService($conn)->emptyLounge();
        }

        return $handler->handle($request);
    }

    private function activityService(Connection $conn): ActivityService
    {
        return new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class));
    }

    private function permissionService(Connection $conn): PermissionService
    {
        return new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $this->currentUser, $this->filterState, $this->accessLevelChecker());
    }

    private function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService($this->lang, new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $this->permissionService($conn), $this->currentConfig, $this->eventDispatcher, $this->translator, $this->accessLevelChecker(), new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig));
    }

    private function accessLevelChecker(): AccessLevelChecker
    {
        return new AccessLevelChecker($this->currentUser, $this->currentConfig);
    }

    private function imageReadFacade(Connection $conn): ImageReadFacade
    {
        return new ImageReadFacade(
            EntityManagerFactory::build($conn)->getRepository(CaddieEntity::class),
            EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
            new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig),
        );
    }

    private function userReadFacade(Connection $conn): UserReadFacade
    {
        return new UserReadFacade(new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig));
    }

    private function themeReadFacade(Connection $conn): ThemeReadFacade
    {
        return new ThemeReadFacade(EntityManagerFactory::build($conn)->getRepository(ThemeEntity::class));
    }

    private function imageService(Connection $conn): ImageService
    {
        return new ImageService(
            EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
            $this->activityService($conn),
            $this->sessionService,
            $this->eventDispatcher,
            $this->currentConfig,
            $this->paths,
            $this->categoryService($conn),
        );
    }

    private function tagService(Connection $conn): TagService
    {
        return new TagService(
            $this->lang,
            EntityManagerFactory::build($conn)->getRepository(TagEntity::class),
            $this->permissionService($conn),
            $this->activityService($conn),
            $this->eventDispatcher,
            $this->currentUser,
            $this->currentConfig,
            $this->currentLogger,
            $this->imageService($conn),
        );
    }

    private function imageWriteFacade(Connection $conn): ImageWriteFacade
    {
        return new ImageWriteFacade($this->imageService($conn), $this->tagService($conn), $this->urlService);
    }

    private function categoryWriteFacade(Connection $conn): CategoryWriteFacade
    {
        return new CategoryWriteFacade($this->categoryService($conn));
    }

    private function extensionContextFactory(Connection $conn): ExtensionContextFactory
    {
        return new ExtensionContextFactory(
            $this->currentTemplate,
            $this->currentConfig,
            $this->currentUser,
            $this->userService,
            $this->lang,
            $this->urlService,
            $this->redirectService,
            $this->adminContext,
            $this->eventDispatcher,
            $this->sessionService,
            $this->imageReadFacade($conn),
            $this->paths,
            $this->configService,
            EntityManagerFactory::build($conn),
            $this->mailService,
            $this->userReadFacade($conn),
            $this->themeReadFacade($conn),
            $this->csrfService,
            $this->htmlService,
            $this->accessControl,
            $this->imageWriteFacade($conn),
            $this->categoryWriteFacade($conn),
        );
    }

    private function pluginRegistry(Connection $conn): PluginRegistry
    {
        return new PluginRegistry(
            EntityManagerFactory::build($conn)->getRepository(PluginEntity::class),
            EntityManagerFactory::build($conn)->getRepository(PluginMigrationEntity::class),
            $this->eventDispatcher,
            $this->extensionContextFactory($conn),
            $this->currentConfig,
            $this->paths,
            $conn,
        );
    }
}
