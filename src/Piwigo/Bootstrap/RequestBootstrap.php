<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Bootstrap\Event\Init;
use Piwigo\Bootstrap\Projection\HeaderMessagesPageContext;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ApiContext;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CoverageCollector;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilterState;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\RequestMetrics;
use Piwigo\Core\ServerTiming;
use Piwigo\Core\ThemeEntity;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Listener\CommentSpamListener;
use Piwigo\Listener\HtmlRenderingListener;
use Piwigo\Listener\SiteCleanupListener;
use Piwigo\Listener\UploadFormatListener;
use Piwigo\Mail\MailService;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContextFactory;
use Piwigo\PluginConfig\Facade\CategoryWriteFacade;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ImageWriteFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Site\SiteEntity;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * The per-request bootstrap; bootEntryPoint() is the entry point every
 * root `public/*.php` file calls directly.
 *
 * Boot proceeds in two stages: `bootEntryPoint()` itself only runs
 * configure() (Kernel::boot() + ServerTiming seed + the install-sentinel
 * redirect check) and `InstallationFlag::mark()`. The rest runs as real
 * PSR-15 middleware (`Http\Middleware\ConfigBootstrapMiddleware`/
 * `SessionMiddleware`/`PluginBootstrapMiddleware`/`Admin\
 * LoadedPluginsMiddleware`/`UserResolutionMiddleware`/`Http\Middleware\
 * LanguageMiddleware`/`FinalizeBridgeMiddleware`) inside
 * `RequestPipeline::handle()`, called by every entry point immediately
 * after `bootEntryPoint()` returns. `finalize()` itself still exists on
 * this class -- its still-legacy, Template-dependent remainder (gated on
 * Plan 2 P38/P39 for a real decomposition) is called by
 * `FinalizeBridgeMiddleware`, not by `bootEntryPoint()` directly.
 * bootConfigOnly() is a separate, lighter, standalone-callable path
 * (config + globals only, no install-check/session/DB-user machinery) that
 * `tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php` exercises
 * directly. Every reader of the app domain/URL goes through
 * Piwigo\Core\AppInfo::DOMAIN/URL or this class's own pemUrl().
 *
 * configure() calls Kernel::boot($paths) as its own first statement --
 * genuinely load-bearing, not just tidiness, since the middleware chain
 * above (and finalize(), called from within it) performs real work (DB
 * connection, plugin loading, user resolution) that depends on the
 * container already being built.
 */
final class RequestBootstrap
{
    /**
     * The real entry point every root `public/*.php` file calls directly.
     * Captures `$t2`, runs configure() with `InstallationFlag::mark()`
     * right after it, and catches `ResponseReadyException` from
     * configure()'s own short-circuit (the install-sentinel redirect --
     * the only bootstrap-phase short-circuit that can fire this early;
     * the 503 maintenance-mode check now runs later, inside
     * `Bootstrap\FinalizeBridgeMiddleware`, part of the real PSR-15
     * pipeline `RequestPipeline::handle()` runs immediately after this
     * method returns) and emits it directly.
     *
     * `i.php` never calls this method (deliberately); `install.php`/
     * `ready.php` skip straight to their own bespoke bootstrap and never
     * depend on this class -- see each file's own docblock for why.
     *
     * `SentryBootstrap::init()`/`ServerTiming`'s own 'boot' timer (seeded
     * from `$t2`, captured right here) still brackets the *whole*
     * bootstrap-phase sequence end-to-end, same as before workstream C3
     * Phase 1 -- but the two halves of that bracket no longer live in one
     * method: `start('boot', ...)` is still configure()'s own first
     * statement, while `stop('boot')` moved from this method's own
     * trailing statement to `Bootstrap\FinalizeBridgeMiddleware`, the last
     * step of the new bootstrap-phase middleware chain (see its own
     * docblock). This method's own `catch` block below still calls
     * `stop('boot')` directly, since a short-circuit here means the
     * middleware chain -- and therefore `FinalizeBridgeMiddleware` -- never
     * runs at all for this request.
     *
     * `$mountDepth`/`$isApi`/`$isAdmin` -- the pre-`Kernel::boot()` marker
     * trio (RequestMountDepth/ApiContext/AdminContext) is threaded through
     * from whichever entry-shell file called this
     * (`public/admin/popuphelp.php`, `public/admin.php`, `public/api.php`
     * -- the only 3 that pass anything other than the defaults) all the
     * way down to `Piwigo\Core\Container`'s own build() method.
     *
     * `ServerTiming` needs a different mechanism, not the container-build-
     * time binding the trio uses: its 'boot' timer must start ticking at
     * the exact instant this method begins, before `configure()`'s own
     * `Kernel::boot()` call runs -- `$t2` below captures that instant as a
     * plain local variable (same idea as `RequestMetrics::requestStart`'s own
     * pre-autoload capture), and `configure()` seeds the container-shared
     * `ServerTiming` instance with it immediately after `Kernel::boot()`
     * returns.
     */
    public static function bootEntryPoint(Paths $paths, int $mountDepth = 0, bool $isApi = false, bool $isAdmin = false): void
    {
        CoverageCollector::registerIfActive($paths);
        SentryBootstrap::init();
        TracyBootstrap::init();

        $t2 = microtime(true);

        try {
            self::configure($paths, $t2, $mountDepth, $isApi, $isAdmin);
            self::installationFlag()->mark();
        } catch (ResponseReadyException $e) {
            self::serverTiming()->stop('boot');
            new ResponseEmitter()
                ->emit($e->response());
            exit;
        }
    }

    /**
     * The standalone-callable, config+globals-only boot path. No real
     * production route needs this method -- kept as the lighter path (no
     * install-check/session/DB-user machinery) that
     * tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php exercises
     * directly, and any future route that only needs config, not the full
     * request machinery, can reach for.
     */
    public static function bootConfigOnly(Paths $paths): void
    {
        SentryBootstrap::init();
        TracyBootstrap::init();
        $bootStart = microtime(true);

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot($paths);
        self::serverTiming()->start('boot', $bootStart);
        $currentConfigService = self::currentConfigService();
        if (! $currentConfigService->isSet()) {
            $configService = Kernel::container()->get(ConfigService::class);
            if (! $configService instanceof ConfigService) {
                throw new LogicException('Container returned an unexpected type for ' . ConfigService::class);
            }
            $currentConfigService->set($configService);
            $configService->loadConfFromDb();
        }
        self::currentUser()->attachGlobals();
        self::pageState();
        self::lang()->attachGlobals();

        self::serverTiming()->stop('boot');
    }

    /**
     * Superglobal sanitization, env-file loading, static-setter wiring,
     * Config seeding, and the install-sentinel check.
     *
     * bootEntryPoint() calls InstallationFlag::mark() right after this
     * returns.
     *
     * Kernel::boot($paths) runs as the very first statement, ahead of
     * everything else in this method -- see this class's own docblock for
     * why. The container-building call it makes internally only registers
     * lazy PHP-DI factory closures with zero eager side effects, so
     * booting this early changes nothing observable until something
     * actually resolves a service.
     */
    public static function configure(Paths $paths, float $requestStart, int $mountDepth = 0, bool $isApi = false, bool $isAdmin = false): void
    {
        Kernel::boot($paths, $mountDepth, $isApi, $isAdmin);

        // Seeds the 'boot' timer with the same instant $requestStart itself
        // captures (both taken back-to-back, before this method's own
        // Kernel::boot() call above -- see bootEntryPoint()'s own docblock)
        // -- there is no container-shared ServerTiming instance to write
        // into any earlier than this.
        self::serverTiming()->start('boot', $requestStart);

        // The true start of each request -- resets ActivityService::
        // record()'s "was a real user resolved this request" flag before
        // anything else can mark it (UserBootstrap::initialize()).
        // Monotonic within a request, so it needs a real reset here
        // rather than relying on CurrentUser's own full reset (restricted
        // to tests/ by an arch test).
        self::currentUser()->resetRealUserResolvedFlag();

        // include/common.inc.php captures $requestStart = microtime(true)
        // at true top-level scope (before this class is even autoloadable)
        // for maximum precision, and passes it straight through as a
        // parameter -- this is the one-time handoff into RequestMetrics,
        // which every other consumer reads from instead.
        self::requestMetrics()->requestStart = $requestStart;

        Env::loadEnvFile($paths->root);

        // Piwigo\Config\Config::* accessors used further down in this
        // bootstrap's own body (not just by code that runs after full
        // boot) read Config's static state -- these two
        // calls must seed it before any of that later code runs. Both are
        // idempotent (re-running never overwrites an already-set key), so
        // bootConfigOnly()'s own copy of these same two calls
        // (its standalone-callable path, not chained after this one on any
        // real request) is a harmless no-op if it ever runs in the same
        // process.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        if (! file_exists($paths->siteLocal . Env::testModeInstalledStamp())) {
            // Throws instead of calling header()+exit() directly -- see
            // the 503 maintenance-page site below for the same pattern
            // and why.
            throw new ResponseReadyException(ResponseFactory::redirect('install.php'));
        }
    }

    /**
     * The PEM (piwigo extension market) base URL -- cheap and
     * side-effect-free (a Config read plus a string concat), so
     * recomputing at each read site is simpler than a per-request cache
     * and behaviourally identical (Config doesn't change mid-request).
     *
     * $type selects a per-type override, read directly from the process
     * environment (PIWIGO_ALT_PLUGINS_PEM_URL/PIWIGO_ALT_THEMES_PEM_URL/
     * PIWIGO_ALT_LANGUAGES_PEM_URL) -- same DbCredentials::env()-style
     * precedent used for the PIWIGO_DB_* vars, deliberately not routed
     * through CurrentConfig/ConfigLoader::applyEnvOverrides() (a genuine
     * no-op today, called with zero arguments at over 100 real call
     * sites -- see its own docblock). Lets each sibling repo's local
     * extension mirror be pointed at independently of the
     * single, generic $alternativePemUrl override, which stays exactly
     * as-is for every caller that doesn't pass $type.
     */
    public static function pemUrl(?ExtensionType $type = null): string
    {
        $envVar = match ($type) {
            ExtensionType::Plugin => 'PIWIGO_ALT_PLUGINS_PEM_URL',
            ExtensionType::Theme => 'PIWIGO_ALT_THEMES_PEM_URL',
            ExtensionType::Language => 'PIWIGO_ALT_LANGUAGES_PEM_URL',
            null => null,
        };
        if ($envVar !== null) {
            $typedOverride = getenv($envVar);
            if ($typedOverride !== false && $typedOverride !== '') {
                return $typedOverride;
            }
        }

        if (self::currentConfig()->alternativePemUrl !== '') {
            return self::currentConfig()->alternativePemUrl;
        }

        return AppInfo::URL . '/ext';
    }

    /**
     * Template creation, no-photo-yet, maintenance/upgrade notices, request
     * filter, and the default event-handler registrations -- the
     * Template-dependent remainder `Bootstrap\FinalizeBridgeMiddleware`
     * calls (workstream C3 Phase 1). Language loading and auth-key/
     * api-key-expiration messages, formerly this method's own first half,
     * now run earlier in the same pipeline as `Http\Middleware\
     * LanguageMiddleware` -- see that class's own docblock.
     */
    public static function finalize(): void
    {
        // Shared for every repository/service constructed for the rest of
        // this method -- same "one Connection per method, not per
        // repository" reasoning `Http\Middleware\ConfigBootstrapMiddleware`/
        // `Http\Middleware\PluginBootstrapMiddleware`/`Http\Middleware\
        // LanguageMiddleware` each keep independently, now that the rest of
        // this method's former body runs as real middleware ahead of this
        // one (workstream C3 Phase 1) -- see `Bootstrap\
        // FinalizeBridgeMiddleware`, which calls this method as the last
        // step of that same chain, right before routing.
        $conn = DbConnection::build();
        $layoutState = self::layoutState();

        // template instance
        if (self::adminContext()->isActive()) {// Admin template
            $admin_theme = ThemeId::from(
                new PreferencesService(new UserRepository(EntityManagerFactory::build($conn), self::eventDispatcher(), self::currentConfig()), self::currentUser())
                    ->getAdminThemePref() ?? self::currentConfig()->adminTheme
            );
            $template = new Template(self::currentConfig(), self::lang(), self::eventDispatcher(), self::errorCollector(), self::processCache(), self::currentConfigService(), self::paths(), self::accessLevelChecker(), self::sessionService(), self::urlService(), self::pageState(), self::htmlRenderer(), self::imageStdParams(), self::paths()->root . 'themes/admin', $admin_theme);
        } else { // Classic template
            $theme = self::currentUser()->get()->theme;
            if (DeviceHelper::mobileTheme(self::sessionService(), self::currentConfig())) {
                $theme = ThemeId::from(self::currentConfig()->mobileTheme);
            }
            // PluginConfig\ThemeRegistry::bootCurrent() -- only the
            // classic (public-gallery) theme, never the admin theme above:
            // themes/admin/ is a separate, non-manifest-scanned directory
            // tree (Admin\Extensions\ExtensionType::scanDirectory()'s own
            // Theme case already scans $currentConfig->themesPath, the same
            // tree ThemeRegistry scans -- themes/admin/ was never part of
            // that catalog to begin with). Right after resolving $theme,
            // before Template is constructed, so a theme's subscribedEvents()
            // are live before anything in the same request could fire them.
            self::themeRegistry($conn)->bootCurrent($theme);
            $template = new Template(self::currentConfig(), self::lang(), self::eventDispatcher(), self::errorCollector(), self::processCache(), self::currentConfigService(), self::paths(), self::accessLevelChecker(), self::sessionService(), self::urlService(), self::pageState(), self::htmlRenderer(), self::imageStdParams(), self::paths()->root . 'themes', $theme);
        }

        self::currentTemplate()->set($template);
        // Image\SrcImage (L2aCoreDomain) reads theme conf through
        // Piwigo\Core\ThemeConfProviderInterface (implemented by Template)
        // instead of depending on Template directly (deptrac) -- wired
        // here, not earlier, because the provider IS the request's
        // $template instance, which only exists from this point on.
        // Piwigo\Core\CurrentThemeConfProvider is a separate
        // container-shared wrapper from CurrentTemplate above, not a
        // delegate to it -- see that wrapper's own docblock.
        $currentThemeConfProvider = Kernel::container()->get(CurrentThemeConfProvider::class);
        if (! $currentThemeConfProvider instanceof CurrentThemeConfProvider) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentThemeConfProvider::class);
        }
        $currentThemeConfProvider->set($template);

        if (self::currentConfig()->noPhotoYet === null) {
            // render() exits itself when it decides to take over the
            // page. CurrentConfigService::get() reuses the instance
            // connect() already resolved earlier in the same request.
            new NoPhotoYetRenderer(self::lang(), self::accessLevelChecker(), EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), self::currentConfigService()->get(), new RedirectService(self::lang(), self::userService(), self::eventDispatcher(), self::layoutState(), new Renderer(self::currentTemplate())), self::urlService(), self::paths(), self::adminContext(), self::apiContext(), self::sessionService(), self::eventDispatcher(), self::currentUser(), self::currentTemplate(), self::currentConfig(), self::errorCollector(), self::processCache(), self::currentConfigService(), new Renderer(self::currentTemplate()), self::pageState(), self::htmlRenderer(), self::imageStdParams())
                ->render();
        }

        $user_internal_status = self::currentUser()->get()->internalStatus;
        if (($user_internal_status['guest_must_be_guest'] ?? false) === true) {
            $layoutState->addHeaderMessage(self::lang()->t('Bad status for user "guest", using default status. Please notify the webmaster.'));
        }

        if (self::currentConfig()->galleryLocked) {
            $layoutState->addHeaderMessage(self::lang()->t('The gallery is locked for maintenance. Please, come back later.'));

            if (PageFilterHelper::scriptBasename(self::currentConfig()) !== 'identification' and ! self::accessLevelChecker()->isAdmin()) {
                // Throws instead of calling header()+echo+exit() directly
                // -- caught by MiddlewarePipeline::handle() itself
                // (workstream C3 Phase 0), the same mechanism every other
                // middleware-thrown ResponseReadyException in this
                // pipeline goes through now, not a special case local to
                // this one call site.
                $body = '<a href="' . self::urlService()->getAbsoluteRootUrl(false) . 'identification.php">' . self::lang()->t('The gallery is locked for maintenance. Please, come back later.') . '</a>';
                $body .= str_repeat(' ', 512); // IE6 doesn't error output if below a size
                throw new ResponseReadyException(ResponseFactory::raw($body, [
                    'Retry-After' => '900',
                    'Content-Type' => 'text/html; charset=utf-8',
                ], 503));
            }
        }

        if ($layoutState->headerMessages !== []) {
            $template->assignContext(new HeaderMessagesPageContext($layoutState->headerMessages));
            $layoutState->headerMessages = [];
        }

        if (self::currentConfig()->filterPages !== [] and (bool) PageFilterHelper::getFilterPageValue(self::currentConfig(), 'used')) {
            new FilterService(self::filterState(), self::sessionService(), self::translator(), self::lang(), self::currentConfig(), self::eventDispatcher(), EntityManagerFactory::build($conn))
                ->initializeFromRequest($layoutState, self::currentUser());
        } else {
            self::filterState()->set(false);
        }

        $layoutState->headerNotes = array_merge($layoutState->headerNotes, self::currentConfig()->headerNotes);

        // Default event handlers -- extracted into Piwigo\Listener\*
        // classes. Must stay after `Http\Middleware\
        // PluginBootstrapMiddleware`'s own `PluginRegistry::bootActive()`
        // call, earlier in the same pipeline, so a plugin's own
        // 'upload_file' handler (if any) keeps first crack in the
        // trigger_change() chain.
        self::eventDispatcher()->registerSubscriber(new HtmlRenderingListener(self::htmlService(), self::currentConfig()));
        // checkForSpam() is an instance method (matching UploadFormatListener's
        // own now-instance upload_file handlers below, both container-shared
        // instances rather than static calls) -- CommentService is built
        // here, reusing the request's own shared Connection, and handed
        // to the listener rather than autowired fresh.
        self::eventDispatcher()->registerSubscriber(new CommentSpamListener(new CommentService(self::lang(), EntityManagerFactory::build($conn)->getRepository(CommentEntity::class), new EphemeralKeyService(self::currentConfig()), self::mailService(), self::htmlService(), self::urlService(), self::eventDispatcher(), self::pageState(), self::currentUser(), self::currentConfig(), self::accessLevelChecker())));
        self::eventDispatcher()->registerSubscriber(new SiteCleanupListener(EntityManagerFactory::build($conn)->getRepository(SiteEntity::class)));
        // self::uploadService() resolves the container-shared instance --
        // see that method's own docblock for why every real UploadService
        // consumer (this listener included) now resolves the same object
        // instead of constructing its own (standard container hygiene,
        // not an event-dedup concern).
        self::eventDispatcher()->registerSubscriber(new UploadFormatListener(self::uploadService()));
        self::eventDispatcher()->dispatch(new Init());

        // CurrentUser's/PageState's own `??=` guards are already
        // satisfied by this point on the real HTTP path
        // (UserBootstrap::initialize(), now `Bootstrap\
        // UserResolutionMiddleware`, earlier in the same pipeline;
        // pageState()'s own resolution in configure()), so both calls are
        // no-ops here in practice; kept for parity with callers that reach
        // finalize() without having run those earlier steps. Lang::attachGlobals()
        // is the one with a real ordering requirement -- it snapshots
        // Translator's already-loaded strings, so it must run after this
        // method's own lang()->load() calls above, not before.
        self::currentUser()->attachGlobals();
        self::pageState();
        self::lang()->attachGlobals();
    }

    /**
     * Resolves the container-shared instance -- this class already has
     * direct Kernel::container() access (arch-tested to Bootstrap/ only).
     */
    private static function redirectService(): RedirectServiceInterface
    {
        $redirectService = Kernel::container()->get(RedirectServiceInterface::class);
        if (! $redirectService instanceof RedirectServiceInterface) {
            throw new LogicException('Container returned an unexpected type for ' . RedirectServiceInterface::class);
        }

        return $redirectService;
    }

    /**
     * Narrow, purpose-built read facade for PluginConfig\ExtensionContext
     * -- reuses the request's own shared Connection, same
     * "no extra physical DB connection" discipline as every other
     * repository accessor here. Piwigo\Category\CategoryRepository isn't
     * a Doctrine EntityRepository subclass (a plain service wrapping
     * EntityManagerInterface directly), unlike CaddieRepository/
     * ImageRepository, hence the different construction shape for that
     * one argument.
     */
    private static function imageReadFacade(Connection $conn): ImageReadFacade
    {
        return new ImageReadFacade(
            EntityManagerFactory::build($conn)->getRepository(CaddieEntity::class),
            EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
            new CategoryRepository(EntityManagerFactory::build($conn), self::currentConfig()),
        );
    }

    private static function userReadFacade(Connection $conn): UserReadFacade
    {
        return new UserReadFacade(new UserRepository(EntityManagerFactory::build($conn), self::eventDispatcher(), self::currentConfig()));
    }

    private static function themeReadFacade(Connection $conn): ThemeReadFacade
    {
        return new ThemeReadFacade(EntityManagerFactory::build($conn)->getRepository(ThemeEntity::class));
    }

    /**
     * P29.6 -- verbatim-mirrors `Http\Middleware\
     * PluginBootstrapMiddleware`'s own identically-named private helper
     * (that class's own docblock explains why these aren't shared via
     * DI); this file had no `categoryService()`/`permissionService()`/
     * `activityService()`/`tagService()` of its own before, since
     * nothing here needed them until `ExtensionContext` gained the write
     * facades below. `buildImageService()` is the one exception --
     * `PluginBootstrapMiddleware`'s own equivalent is named
     * `imageService()`, but this file already has a *different*,
     * pre-existing `public static function imageService(): ImageService`
     * (no `$conn` param, resolves the container's own shared singleton
     * instance for `public/admin.php`'s own legacy construction) that a
     * same-named `$conn`-scoped private method would collide with.
     */
    private static function activityService(Connection $conn): ActivityService
    {
        return new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class));
    }

    private static function permissionService(Connection $conn): PermissionService
    {
        return new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), self::currentConfig()), self::currentUser(), self::filterState(), self::accessLevelChecker());
    }

    private static function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService(self::lang(), new CategoryRepository(EntityManagerFactory::build($conn), self::currentConfig()), self::permissionService($conn), self::currentConfig(), self::eventDispatcher(), self::translator(), self::accessLevelChecker(), new UserRepository(EntityManagerFactory::build($conn), self::eventDispatcher(), self::currentConfig()));
    }

    private static function buildImageService(Connection $conn): ImageService
    {
        return new ImageService(
            EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
            self::activityService($conn),
            self::sessionService(),
            self::eventDispatcher(),
            self::currentConfig(),
            self::paths(),
            self::categoryService($conn),
        );
    }

    private static function tagService(Connection $conn): TagService
    {
        return new TagService(
            self::lang(),
            EntityManagerFactory::build($conn)->getRepository(TagEntity::class),
            self::permissionService($conn),
            self::activityService($conn),
            self::eventDispatcher(),
            self::currentUser(),
            self::currentConfig(),
            self::currentLogger(),
            self::buildImageService($conn),
        );
    }

    private static function imageWriteFacade(Connection $conn): ImageWriteFacade
    {
        return new ImageWriteFacade(self::buildImageService($conn), self::tagService($conn), self::urlService());
    }

    private static function categoryWriteFacade(Connection $conn): CategoryWriteFacade
    {
        return new CategoryWriteFacade(self::categoryService($conn));
    }

    /**
     * Builds a fresh ExtensionContextFactory -- cheap, pure
     * composition of already-resolved accessors, no I/O of its own, so
     * building one per registry-construction call site (`themeRegistry()`
     * below -- `pluginRegistry()` was already migrated to `Http\
     * Middleware\PluginBootstrapMiddleware`, see this file's own P29.6
     * history) rather than caching a single shared instance across
     * `finalize()` isn't worth the extra state to avoid.
     */
    private static function extensionContextFactory(Connection $conn): ExtensionContextFactory
    {
        $configService = Kernel::container()->get(ConfigService::class);
        if (! $configService instanceof ConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . ConfigService::class);
        }

        $mailService = Kernel::container()->get(MailService::class);
        if (! $mailService instanceof MailService) {
            throw new LogicException('Container returned an unexpected type for ' . MailService::class);
        }

        $csrfService = Kernel::container()->get(CsrfService::class);
        if (! $csrfService instanceof CsrfService) {
            throw new LogicException('Container returned an unexpected type for ' . CsrfService::class);
        }

        return new ExtensionContextFactory(
            self::currentTemplate(),
            self::currentConfig(),
            self::currentUser(),
            self::userService(),
            self::lang(),
            self::urlService(),
            self::redirectService(),
            self::adminContext(),
            self::apiContext(),
            self::eventDispatcher(),
            self::sessionService(),
            self::imageReadFacade($conn),
            self::paths(),
            $configService,
            EntityManagerFactory::build($conn),
            $mailService,
            self::userReadFacade($conn),
            self::themeReadFacade($conn),
            $csrfService,
            self::htmlService(),
            self::accessControl(),
            self::imageWriteFacade($conn),
            self::categoryWriteFacade($conn),
        );
    }

    /**
     * PluginConfig\ThemeRegistry::bootCurrent() -- called once
     * per request in finalize(), right after the classic (non-admin)
     * theme is resolved and before Template is constructed.
     */
    private static function themeRegistry(Connection $conn): ThemeRegistry
    {
        return new ThemeRegistry(
            EntityManagerFactory::build($conn)->getRepository(ThemeEntity::class),
            self::eventDispatcher(),
            self::extensionContextFactory($conn),
            self::currentConfig(),
            self::paths(),
        );
    }

    /**
     * Resolves the container-shared instance -- this class already has
     * direct Kernel::container() access (arch-tested to Bootstrap/ only).
     */
    private static function paths(): Paths
    {
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }

    /**
     * Resolves the container-shared instance (not `new InstallationFlag()`)
     * so that this method's own `mark()` call is visible to every other
     * consumer holding the same shared instance -- see that class's own
     * docblock. Public: `public/install.php` calls this directly (its
     * `InstallBootstrap::boot()` already runs `Kernel::boot()` first, so the
     * container is up), the same shape as every other public accessor here.
     */
    public static function installationFlag(): InstallationFlag
    {
        $flag = Kernel::container()->get(InstallationFlag::class);
        if (! $flag instanceof InstallationFlag) {
            throw new LogicException('Container returned an unexpected type for ' . InstallationFlag::class);
        }

        return $flag;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `FilterService::initializeFromRequest()`/direct `set(false)` writes
     * are visible to every other consumer holding the same shared
     * instance. Public: config/messenger.php's own
     * `new MetadataService(...)` construction needs it.
     */
    public static function filterState(): FilterState
    {
        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }

        return $filterState;
    }

    /**
     * Resolves the container-shared instance for this method's own local
     * `new FilterService(...)` construction.
     */
    private static function translator(): Translator
    {
        $translator = Kernel::container()->get(Translator::class);
        if (! $translator instanceof Translator) {
            throw new LogicException('Container returned an unexpected type for ' . Translator::class);
        }

        return $translator;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * attachGlobals()/load()/setDefaultLanguageProvider() writes are
     * visible to every other consumer holding the same shared instance.
     */
    public static function lang(): Lang
    {
        $lang = Kernel::container()->get(Lang::class);
        if (! $lang instanceof Lang) {
            throw new LogicException('Container returned an unexpected type for ' . Lang::class);
        }

        return $lang;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `set()` write is visible to every other consumer holding the same
     * shared instance. Public (unlike most resolver helpers here):
     * `public/install.php`'s own `new Http\SessionBootstrap(...)` manual
     * construction needs this, the same reason several other resolvers in
     * this class are public.
     */
    public static function currentLogger(): CurrentLogger
    {
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        return $currentLogger;
    }

    /**
     * Public (unlike the private resolver helpers above): public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a way
     * to obtain the same container-shared instance every other PageState
     * consumer receives via constructor injection, without calling
     * Kernel::container() directly itself (arch-tested to Bootstrap/ only)
     * -- same "public accessor on this class" shape as coreTabs()/
     * filesystemIntegrityChecker()/sessionService()/eventDispatcher()/
     * deploymentPolicy() above.
     */
    public static function pageState(): PageState
    {
        $pageState = Kernel::container()->get(PageState::class);
        if (! $pageState instanceof PageState) {
            throw new LogicException('Container returned an unexpected type for ' . PageState::class);
        }

        return $pageState;
    }

    /**
     * Private (unlike pageState()/layoutState() above): only used
     * internally, by configure()'s own requestStart handoff (P41,
     * docs/PLAN.md's PageState split) -- no public/ script needs it.
     */
    private static function requestMetrics(): RequestMetrics
    {
        $requestMetrics = Kernel::container()->get(RequestMetrics::class);
        if (! $requestMetrics instanceof RequestMetrics) {
            throw new LogicException('Container returned an unexpected type for ' . RequestMetrics::class);
        }

        return $requestMetrics;
    }

    /**
     * Public, same reasoning as pageState() above: public/admin.php's own
     * legacy-style `new RedirectService(...)`/`new AdminShell(...)` manual
     * construction needs a way to obtain the same container-shared
     * instance every other LayoutState consumer receives via constructor
     * injection (P41, docs/PLAN.md's PageState split).
     */
    public static function layoutState(): LayoutState
    {
        $layoutState = Kernel::container()->get(LayoutState::class);
        if (! $layoutState instanceof LayoutState) {
            throw new LogicException('Container returned an unexpected type for ' . LayoutState::class);
        }

        return $layoutState;
    }

    /**
     * Public (unlike most resolver helpers here): public/admin.php's own
     * legacy-style `new AdminShell(...)` manual construction needs a way to
     * obtain the same container-shared instance every other
     * EntityManagerInterface consumer receives via constructor injection,
     * without calling Kernel::container() directly itself.
     */
    public static function entityManager(): EntityManagerInterface
    {
        $entityManager = Kernel::container()->get(EntityManagerInterface::class);
        if (! $entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }

        return $entityManager;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `install()` write (registering the real error handler/shutdown
     * function) is visible to every other consumer holding the same
     * shared instance. Public (unlike most resolver helpers here):
     * public/install.php's own `new InstallWizard(...)` manual
     * construction needs this to satisfy Template's own new required
     * collaborators.
     */
    public static function errorCollector(): ErrorCollector
    {
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if (! $errorCollector instanceof ErrorCollector) {
            throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
        }

        return $errorCollector;
    }

    /**
     * Public (unlike most resolver helpers here): public/install.php's own
     * `new InstallWizard(...)` manual construction needs this to satisfy
     * Template's own new required collaborators.
     */
    public static function processCache(): ProcessCache
    {
        $processCache = Kernel::container()->get(ProcessCache::class);
        if (! $processCache instanceof ProcessCache) {
            throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
        }

        return $processCache;
    }

    /**
     * Public (unlike most resolver helpers here): public/admin.php's own
     * legacy-style `new AdminShell(...)` manual construction needs a way to
     * obtain the same container-shared instance every other CurrentUser
     * consumer receives via constructor injection, without calling
     * Kernel::container() directly itself (arch-tested to Bootstrap/ only)
     * -- same "public accessor on this class" shape as coreTabs()/
     * filesystemIntegrityChecker()/pageState() above.
     */
    public static function currentUser(): CurrentUser
    {
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }

        return $currentUser;
    }

    /**
     * Public (unlike most resolver helpers here): public/admin.php's own
     * legacy-style `new AdminShell(...)` manual construction needs a way to
     * obtain the same container-shared instance every other CurrentTemplate
     * consumer receives via constructor injection, without calling
     * Kernel::container() directly itself (arch-tested to Bootstrap/ only)
     * -- same "public accessor on this class" shape as coreTabs()/
     * currentUser()/pageState() above.
     */
    public static function currentTemplate(): CurrentTemplate
    {
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
        }

        return $currentTemplate;
    }

    /**
     * Public, same reasoning as currentTemplate() above: public/admin.php's
     * and public/random.php's own legacy-style `new RedirectService(...)`
     * manual construction needs a way to obtain a Renderer without calling
     * Kernel::container() directly.
     */
    public static function templateRenderer(): Renderer
    {
        return new Renderer(self::currentTemplate());
    }

    public static function currentConfig(): CurrentConfig
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }

        return $currentConfig;
    }

    /**
     * Public, same reason as currentTemplate()/currentConfig() above:
     * public/admin.php's own legacy-style `new AdminShell(...)` manual
     * construction needs a way to reach a container-shared InputValidator
     * without calling Kernel::container() directly itself.
     */
    public static function inputValidator(): InputValidator
    {
        $inputValidator = Kernel::container()->get(InputValidator::class);
        if (! $inputValidator instanceof InputValidator) {
            throw new LogicException('Container returned an unexpected type for ' . InputValidator::class);
        }

        return $inputValidator;
    }

    /**
     * Public, same reason as currentTemplate() above: public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a
     * way to obtain the same container-shared instance every other
     * CurrentConfigService consumer receives via constructor injection,
     * without calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only).
     */
    public static function currentConfigService(): CurrentConfigService
    {
        $currentConfigService = Kernel::container()->get(CurrentConfigService::class);
        if (! $currentConfigService instanceof CurrentConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
        }

        return $currentConfigService;
    }

    /**
     * Public, same reason as currentTemplate()/currentConfigService()
     * above: public/admin.php's/install.php's/random.php's own manual
     * construction needs a way to obtain the same container-shared
     * UrlService every other consumer receives via constructor injection.
     * Resolving the interface (not the concrete UrlService) matches every
     * other real UrlServiceInterface consumer in the codebase.
     */
    public static function urlService(): UrlServiceInterface
    {
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new LogicException('Container returned an unexpected type for ' . UrlServiceInterface::class);
        }

        return $urlService;
    }

    /**
     * Public, same reason as urlService()/pageState() above --
     * public/install.php's own manual InstallWizard construction needs
     * this to satisfy Template's own required collaborators.
     */
    public static function htmlRenderer(): HtmlRenderingInterface
    {
        $htmlRenderer = Kernel::container()->get(HtmlRenderingInterface::class);
        if (! $htmlRenderer instanceof HtmlRenderingInterface) {
            throw new LogicException('Container returned an unexpected type for ' . HtmlRenderingInterface::class);
        }

        return $htmlRenderer;
    }

    /**
     * Public, same reason as htmlRenderer() above -- public/install.php's
     * own manual InstallWizard construction needs this too. Safe to
     * resolve unconditionally: the container factory (config/container.php)
     * already tolerates a missing table or an unavailable connection,
     * degrading to ImageStdParams' own "not yet loaded" baseline rather
     * than throwing.
     */
    public static function imageStdParams(): ImageStdParams
    {
        $imageStdParams = Kernel::container()->get(ImageStdParams::class);
        if (! $imageStdParams instanceof ImageStdParams) {
            throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
        }

        return $imageStdParams;
    }

    /**
     * Resolves the container-shared MailService instance (via its
     * MailerInterface binding). MailService's own
     * switchLangTo()/switchLangBack() language-switch stack and
     * template-render cache are real per-request state that must be the
     * SAME instance across every call within one request, not a fresh
     * `new MailService()` per site (see MailService's own class docblock).
     */
    private static function mailService(): MailerInterface
    {
        $mailer = Kernel::container()->get(MailerInterface::class);
        if (! $mailer instanceof MailerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . MailerInterface::class);
        }

        return $mailer;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `start()`/`stop()` writes are visible to every other consumer holding
     * the same shared instance.
     */
    private static function serverTiming(): ServerTiming
    {
        $serverTiming = Kernel::container()->get(ServerTiming::class);
        if (! $serverTiming instanceof ServerTiming) {
            throw new LogicException('Container returned an unexpected type for ' . ServerTiming::class);
        }

        return $serverTiming;
    }

    /**
     * Resolves the container-shared instance. Public: public/admin.php
     * and public/random.php need the full AccessControl (real
     * checkStatus()/accessDenied() enforcement) for their own
     * checkStatus() calls, reaching it via this accessor after their own
     * RequestBootstrap::bootEntryPoint() call has already run -- the same
     * established "real entry-shell caller reaches a RequestBootstrap
     * public accessor" pattern every other RequestBootstrap::x() public
     * method already serves.
     */
    public static function accessControl(): AccessControl
    {
        $accessControl = Kernel::container()->get(AccessControl::class);
        if (! $accessControl instanceof AccessControl) {
            throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }

        return $accessControl;
    }

    /**
     * Cheap, no-Doctrine-dependency counterpart to accessControl() above.
     * Every real caller in this file only ever needs
     * isAdmin()/isAGuest(), never checkStatus()/accessDenied(), so this
     * builds AccessLevelChecker directly rather than resolving the full
     * AccessControl through the container.
     */
    private static function accessLevelChecker(): AccessLevelChecker
    {
        return new AccessLevelChecker(self::currentUser(), self::currentConfig());
    }

    private static function adminContext(): AdminContext
    {
        $adminContext = Kernel::container()->get(AdminContext::class);
        if (! $adminContext instanceof AdminContext) {
            throw new LogicException('Container returned an unexpected type for ' . AdminContext::class);
        }

        return $adminContext;
    }

    private static function apiContext(): ApiContext
    {
        $apiContext = Kernel::container()->get(ApiContext::class);
        if (! $apiContext instanceof ApiContext) {
            throw new LogicException('Container returned an unexpected type for ' . ApiContext::class);
        }

        return $apiContext;
    }

    /**
     * Public (unlike the resolver helpers above): public/admin.php's own
     * legacy-style `new AdminShell(...)` manual construction doesn't route
     * through RequestPipeline's container-backed controller resolution, so
     * it needs a way to obtain the same container-shared instance
     * Controller\Admin\IntroSubController will later receive, without
     * calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only). Same "public accessor on this class" shape as
     * pemUrl() above, generalised to a container resolution -- load-bearing
     * for FilesystemIntegrityChecker::fsQuickCheck()'s own per-request
     * run-once guard, which only actually guards anything if admin.php and
     * IntroSubController share the same instance.
     */
    public static function filesystemIntegrityChecker(): FilesystemIntegrityChecker
    {
        $filesystemIntegrityChecker = Kernel::container()->get(FilesystemIntegrityChecker::class);
        if (! $filesystemIntegrityChecker instanceof FilesystemIntegrityChecker) {
            throw new LogicException('Container returned an unexpected type for ' . FilesystemIntegrityChecker::class);
        }

        return $filesystemIntegrityChecker;
    }

    /**
     * Public (unlike the resolver helpers above): public/admin.php's own
     * legacy-style `new AdminShell(...)` manual construction needs a way to
     * obtain the same container-shared instance every `*SubController`/
     * `*PageRenderer` writer file will later receive, without calling
     * Kernel::container() directly itself (arch-tested to Bootstrap/ only)
     * -- same "public accessor on this class" shape as
     * filesystemIntegrityChecker() above. Load-bearing for CoreTabs::
     * setContext()/addCoreTabs()'s own request-scoped bridge, which only
     * works if every writer file and the 'tabsheet_before_select' event
     * registration below share the same instance.
     */
    public static function coreTabs(): CoreTabs
    {
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $coreTabs instanceof CoreTabs) {
            throw new LogicException('Container returned an unexpected type for ' . CoreTabs::class);
        }

        return $coreTabs;
    }

    /**
     * Public (unlike the private resolver helpers above): public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a way
     * to obtain the same container-shared instance every other
     * SessionService consumer receives via constructor injection, without
     * calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only) -- same "public accessor on this class" shape as
     * coreTabs()/filesystemIntegrityChecker() above.
     */
    public static function sessionService(): SessionService
    {
        $sessionService = Kernel::container()->get(SessionService::class);
        if (! $sessionService instanceof SessionService) {
            throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
        }

        return $sessionService;
    }

    /**
     * Public (unlike the private resolver helpers above): public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a way
     * to obtain the same container-shared instance every other
     * EventDispatcher consumer receives via constructor injection, without
     * calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only) -- same "public accessor on this class" shape as
     * coreTabs()/filesystemIntegrityChecker()/sessionService() above.
     */
    public static function eventDispatcher(): EventDispatcher
    {
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
        }

        return $eventDispatcher;
    }

    /**
     * Public (unlike the private resolver helpers above): public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a way
     * to obtain the same container-shared instance every other
     * DeploymentPolicy consumer receives via constructor injection, without
     * calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only) -- same "public accessor on this class" shape as
     * coreTabs()/filesystemIntegrityChecker()/sessionService()/eventDispatcher()
     * above.
     */
    public static function deploymentPolicy(): DeploymentPolicy
    {
        $deploymentPolicy = Kernel::container()->get(DeploymentPolicy::class);
        if (! $deploymentPolicy instanceof DeploymentPolicy) {
            throw new LogicException('Container returned an unexpected type for ' . DeploymentPolicy::class);
        }

        return $deploymentPolicy;
    }

    /**
     * Public (unlike the private resolver helpers above): public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a way
     * to obtain the same container-shared instance every other
     * CommentService consumer receives via constructor injection, without
     * calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only) -- same "public accessor on this class" shape as
     * coreTabs()/sessionService()/eventDispatcher()/deploymentPolicy() above.
     */
    public static function commentService(): CommentService
    {
        $commentService = Kernel::container()->get(CommentService::class);
        if (! $commentService instanceof CommentService) {
            throw new LogicException('Container returned an unexpected type for ' . CommentService::class);
        }

        return $commentService;
    }

    /**
     * Public (unlike the private resolver helpers above): public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a way
     * to obtain the same container-shared instance every other ImageService
     * consumer receives via constructor injection, without calling
     * Kernel::container() directly itself (arch-tested to Bootstrap/ only)
     * -- same "public accessor on this class" shape as coreTabs()/
     * sessionService()/eventDispatcher()/deploymentPolicy()/commentService()
     * above.
     */
    public static function imageService(): ImageService
    {
        $imageService = Kernel::container()->get(ImageService::class);
        if (! $imageService instanceof ImageService) {
            throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
        }

        return $imageService;
    }

    /**
     * Same reasoning as commentService()/imageService() above.
     */
    public static function preferencesService(): PreferencesService
    {
        $preferencesService = Kernel::container()->get(PreferencesService::class);
        if (! $preferencesService instanceof PreferencesService) {
            throw new LogicException('Container returned an unexpected type for ' . PreferencesService::class);
        }

        return $preferencesService;
    }

    /**
     * Same reasoning as commentService()/imageService() above.
     */
    public static function userService(): UserService
    {
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    /**
     * Same reasoning as commentService()/imageService() above.
     */
    public static function htmlService(): HtmlService
    {
        $htmlService = Kernel::container()->get(HtmlService::class);
        if (! $htmlService instanceof HtmlService) {
            throw new LogicException('Container returned an unexpected type for ' . HtmlService::class);
        }

        return $htmlService;
    }

    /**
     * bootEventHandlers()'s own sole caller -- registers its 6
     * uploadFileXxx() handlers against this container-shared instance, the
     * same one every other real UploadService consumer
     * (Admin\PhotosAddDirectPageRenderer, Job\Handler\BatchUploadHandler,
     * Controller\Admin\ConfigurationSubController,
     * Controller\Admin\PhotosAddSubController) now resolves too, instead
     * of each constructing its own -- standard container hygiene, not an
     * event-dedup concern.
     */
    private static function uploadService(): UploadService
    {
        $uploadService = Kernel::container()->get(UploadService::class);
        if (! $uploadService instanceof UploadService) {
            throw new LogicException('Container returned an unexpected type for ' . UploadService::class);
        }

        return $uploadService;
    }
}
