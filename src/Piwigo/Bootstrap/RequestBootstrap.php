<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\DBAL\Connection;
use Exception;
use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\PluginLoader;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\ApiKeyRepository;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Bootstrap\Projection\HeaderMessagesPageContext;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CoverageCollector;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\ServerTiming;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Lifecycle\Init;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\LoungeMaintenance;
use Piwigo\Lang\Translator;
use Piwigo\Listener\AuthListener;
use Piwigo\Listener\CommentSpamListener;
use Piwigo\Listener\HtmlRenderingListener;
use Piwigo\Listener\ListenerInterface;
use Piwigo\Listener\SiteCleanupListener;
use Piwigo\Listener\UploadFormatListener;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Site\SiteEntity;
use Piwigo\Template\CurrentTemplate;
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
 * Boot proceeds in three phases -- configure(), connect(), finalize() --
 * with `InstallationFlag::mark()` called between configure() and
 * connect(). bootConfigOnly() is a separate, lighter, standalone-callable
 * path (config + globals only, no install-check/session/DB-user
 * machinery) that
 * `tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php` exercises
 * directly. Every reader of the app domain/URL goes through
 * Piwigo\Core\AppInfo::DOMAIN/URL or this class's own pemUrl().
 *
 * configure() calls Kernel::boot($paths) as its own first statement --
 * genuinely load-bearing, not just tidiness, since connect() below
 * performs real work (DB connection, plugin loading, user resolution)
 * that depends on the container already being built.
 */
final class RequestBootstrap
{
    /**
     * The real entry point every root `public/*.php` file calls directly.
     * Captures `$t2`, runs the three phases below with
     * `InstallationFlag::mark()` slotted between configure() and
     * connect(), catches `ResponseReadyException` from any bootstrap-phase
     * short-circuit (install-redirect, the 503 maintenance page) and
     * emits it directly.
     *
     * `i.php` never calls this method (deliberately); `install.php`/
     * `ready.php` skip straight to their own bespoke bootstrap and never
     * depend on this class -- see each file's own docblock for why.
     *
     * `SentryBootstrap::init()`/`ServerTiming`'s own 'boot' timer (seeded
     * from `$t2`, captured right here, and stopped at both this method's
     * own exit points below) bracket this method's entire body, so Sentry
     * sees any error raised anywhere in this method's own body (the bulk
     * of real per-request boot work -- DB connect, config load, plugin
     * load, user resolution), and the 'boot' Server-Timing entry reflects
     * that real work.
     *
     * `$mountDepth`/`$isWs`/`$isAdmin` -- the pre-`Kernel::boot()` marker
     * trio (RequestMountDepth/WsContext/AdminContext) is threaded through
     * from whichever entry-shell file called this
     * (`public/admin/popuphelp.php`, `public/ws.php`, `public/admin.php`
     * -- the only 3 that pass anything other than the defaults) all the
     * way down to `Piwigo\Core\Container`'s own build() method.
     *
     * `ServerTiming` needs a different mechanism, not the container-build-
     * time binding the trio uses: its 'boot' timer must start ticking at
     * the exact instant this method begins, before `configure()`'s own
     * `Kernel::boot()` call runs -- `$t2` below captures that instant as a
     * plain local variable (same idea as `PageState::requestStart`'s own
     * pre-autoload capture), and `configure()` seeds the container-shared
     * `ServerTiming` instance with it immediately after `Kernel::boot()`
     * returns.
     */
    public static function bootEntryPoint(Paths $paths, int $mountDepth = 0, bool $isWs = false, bool $isAdmin = false): void
    {
        CoverageCollector::registerIfActive($paths);
        SentryBootstrap::init();

        $t2 = microtime(true);

        try {
            self::configure($paths, $t2, $mountDepth, $isWs, $isAdmin);
            self::installationFlag()->mark();
            self::connect();
            self::finalize();
        } catch (ResponseReadyException $e) {
            self::serverTiming()->stop('boot');
            new ResponseEmitter()
                ->emit($e->response());
            exit;
        }

        self::serverTiming()->stop('boot');
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
    public static function configure(Paths $paths, float $requestStart, int $mountDepth = 0, bool $isWs = false, bool $isAdmin = false): void
    {
        Kernel::boot($paths, $mountDepth, $isWs, $isAdmin);

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
        // parameter -- this is the one-time handoff into PageState, which
        // every other consumer reads from instead.
        self::pageState()->requestStart = $requestStart;

        // @set_magic_quotes_runtime(0); // Disable magic_quotes_runtime
        //
        // addslashes to vars if magic_quotes_gpc is off this is a security
        // precaution to prevent someone trying to break out of a SQL statement.
        //
        // The magic quote feature has been disabled since php 5.4
        // but function get_magic_quotes_gpc was always replying false.
        // Since php 8 the function get_magic_quotes_gpc is also removed
        // but we stil want to sanitize user input variables.
        if (! function_exists('get_magic_quotes_gpc') or ! @get_magic_quotes_gpc()) {
            array_walk_recursive($_GET, self::sanitizeMysqlKv(...));
            array_walk_recursive($_POST, self::sanitizeMysqlKv(...));
            array_walk_recursive($_COOKIE, self::sanitizeMysqlKv(...));
        }
        if (! in_array($_SERVER['PATH_INFO'] ?? null, [null, false, 0, '0', '', []], true) && is_string($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = addslashes($_SERVER['PATH_INFO']);
        }

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
     * Error collector installation, session bootstrap, DB connection,
     * DB-backed config, logger, plugin loading, and current-user
     * resolution (through UserBootstrap::initialize()).
     */
    public static function connect(): void
    {
        // Route errors to DevTools (X-PHP-Error-N response headers) instead
        // of inline output, which corrupts JSON/XML/binary responses -- and
        // is also load-bearing for HtmlService::fatalError()'s own
        // recordFatal()+throw sequence (see
        // ErrorCollector::installIfConfigured()'s own docblock).
        self::errorCollector()->installIfConfigured();

        if (self::currentConfig()->sessionGcProbability > 0) {
            @ini_set('session.gc_divisor', 100);
            $gc_probability = self::currentConfig()->sessionGcProbability;
            @ini_set('session.gc_probability', min($gc_probability, 100));
        }

        SessionBootstrap::register();

        self::pageState()->executionUuid = self::sessionService()->generateKey(10);

        // Database connection. DbConnection::build() itself deliberately
        // never touches the session-level ONLY_FULL_GROUP_BY server mode.
        // Built eagerly (not left to DBAL's own lazy first-query connect)
        // so an unreachable DB surfaces here as a friendly fatalError()
        // page, not a raw exception from whatever call happens to run
        // first. Shared for every repository/service constructed for the
        // rest of this method -- DbConnection::build() returns a fresh
        // Connection on every call (no internal caching), so reusing this
        // one avoids opening a separate physical DB connection per
        // repository.
        $conn = DbConnection::build();
        $db_password = self::dbCredentials()->password;
        try {
            $conn->getNativeConnection();
        } catch (Exception $e) {
            self::htmlService()
                ->fatalError(self::lang()->t($e->getMessage()));
        }

        // ConfigService::loadConfFromDb() writes CurrentConfig's own
        // properties directly. CurrentConfigService::set() here makes
        // this resolved instance reachable via CurrentConfigService::get()
        // for the rest of this request (finalize() below, and every
        // Tier 2 static-utility caller) -- see its own docblock.
        $configService = Kernel::container()->get(ConfigService::class);
        if (! $configService instanceof ConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . ConfigService::class);
        }
        self::currentConfigService()->set($configService);
        $configService->loadConfFromDb();

        $log_data_location = self::currentConfig()->dataLocation;
        $log_dir = self::currentConfig()->logDir;

        self::currentLogger()->set(new Logger([
            'directory' => self::paths()->root . $log_data_location . $log_dir,
            'severity' => self::currentConfig()->logLevel,
            // we use an hashed filename to prevent direct file access, and we salt with
            // the db_password instead of secret_key because the log must be usable in i.php
            // (secret_key is in the database)
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $db_password) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => self::currentConfig()->logArchiveDays,
        ]));

        self::imageStdParams();

        session_start();
        PluginLoader::loadPlugins(self::loadedPlugins(), self::eventDispatcher(), self::activityService($conn), self::currentConfig(), self::wsContext(), self::accessControl(), self::pageState(), self::paths());

        if (self::currentConfig()->piwigoInstalledVersion === null) {
            $configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        } elseif (self::currentConfig()->piwigoInstalledVersion !== AppInfo::VERSION) {
            // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
            self::activityService($conn)->record('system', ActivitySystem::Core, 'autoupdate', [
                'from_version' => self::currentConfig()->piwigoInstalledVersion,
                'to_version' => AppInfo::VERSION,
            ]);
            $configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        }

        // Check if last major update conf is set if not set it
        if (self::currentConfig()->lastMajorUpdate === null) {
            $dbnow = Env::now()->format('Y-m-d H:i:s');
            $configService->confUpdateParam('last_major_update', $dbnow, updateGlobal: true);
        }

        // users can have defined a custom order pattern, incompatible with GUI form.
        // order_by_custom/order_by_inside_category_custom are raw "ORDER BY ..."
        // SQL-fragment strings, same shape as order_by/order_by_inside_category
        // themselves (not the structured {field,dir}[] shape the old SCHEMA
        // entry implied) -- CurrentConfig::orderByCustom()/
        // orderByInsideCategoryCustom() are real typed (nullable) accessors now.
        $orderByCustom = self::currentConfig()->orderByCustom;
        if ($orderByCustom !== null) {
            self::currentConfig()->orderBy = $orderByCustom;
        }
        $orderByInsideCategoryCustom = self::currentConfig()->orderByInsideCategoryCustom;
        if ($orderByInsideCategoryCustom !== null) {
            self::currentConfig()->orderByInsideCategory = $orderByInsideCategoryCustom;
        }

        if (LoungeMaintenance::needsEmptying(self::currentConfig())) {
            new ImageService(self::lang(), EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), self::activityService($conn), self::sessionService(), self::eventDispatcher(), self::currentConfig(), self::translator(), self::paths())
                ->emptyLounge();
        }

        // UserBootstrap::initialize() resolves the real per-request user
        // (build_user()/AuthService::autoLogin()/auth_key_login()) and
        // calls CurrentUser::set() itself.
        //
        // The TryLogUser handler is registered here, immediately before
        // UserBootstrap::initialize(), rather than in finalize() where
        // every other real caller's handler is registered: initialize()
        // reaches AuthService::tryLogUser() directly on its own
        // pwg.images.uploadAsync username/password credential path (see
        // that method's own docblock), before finalize() ever runs.
        // EventDispatcher::triggerChange() with no matching handler
        // returns its own $default (false) unmodified, so that credential
        // path needs the handler registered this early; every other real
        // caller of tryLogUser() (the normal pwg.session.login WS
        // dispatch, which runs during RequestPipeline::handle() -- after
        // bootEntryPoint() has fully returned) is unaffected by this
        // ordering.
        self::registerListener(new AuthListener(new AuthService(
            new AuthRepository(EntityManagerFactory::build($conn)),
            self::activityService($conn),
            self::htmlService(),
            self::passwordService($conn),
            new CookieService(),
            EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class),
            self::sessionService(),
            self::eventDispatcher(),
            self::pageState(),
            self::currentUser(),
            self::currentConfig(),
            self::paths(),
        )));
        new UserBootstrap(
            self::accessLevelChecker(),
            new RedirectService(self::lang(), self::userService(), self::eventDispatcher(), self::pageState()),
            self::urlService(),
            self::apiKeyRequestFlag(),
            self::currentLogger(),
            self::wsContext(),
            self::deploymentPolicy(),
        )->initialize();
    }

    /**
     * The PEM (piwigo extension market) base URL -- cheap and
     * side-effect-free (a Config read plus a string concat), so
     * recomputing at each read site is simpler than a per-request cache
     * and behaviourally identical (Config doesn't change mid-request).
     */
    public static function pemUrl(): string
    {

        if (self::currentConfig()->alternativePemUrl !== '') {
            return self::currentConfig()->alternativePemUrl;
        }

        return AppInfo::URL . '/ext';
    }

    /**
     * Language loading, auth-key messages, template creation,
     * no-photo-yet, maintenance/upgrade notices, request filter, and the
     * default event-handler registrations.
     */
    public static function finalize(): void
    {
        // Shared for every repository/service constructed for the rest of
        // this method -- same "one Connection per method, not per
        // repository" reasoning as connect() above.
        $conn = DbConnection::build();

        // language files
        self::lang()->setDefaultLanguageProvider(new UserService(
            self::lang(),
            new UserRepository(EntityManagerFactory::build($conn), self::eventDispatcher(), self::currentConfig()),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            self::mailService(),
            self::activityService($conn),
            self::htmlService(),
            $conn,
            self::sessionService(),
            self::eventDispatcher(),
            self::deploymentPolicy(),
            self::currentUser(),
            self::currentConfig(),
            self::installationFlag(),
            self::processCache(),
            self::paths(),
        ));
        self::lang()->load('common.lang');
        if (self::accessLevelChecker()->isAdmin() || self::adminContext()->isActive()) {
            self::lang()->load('admin.lang');
            // Add language for temporary strings for new popup, from piwigo 15
            self::lang()->load('whats_new_' . VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.lang');
        }
        self::eventDispatcher()->dispatchNotify(new LoadingLang());
        self::lang()->load('lang', self::paths()->siteLocal, [
            'no_fallback' => true,
            'local' => true,
        ]);

        // only now we can set the localized username of the guest user (and not in
        // UserBootstrap::initialize())
        if (self::accessLevelChecker()->isAGuest()) {
            // Second CurrentUser sync point (the first is inside
            // UserBootstrap::initialize()) -- isAGuest() itself already
            // reads CurrentUser (synced there with the pre-localization
            // username), so only the localized-username case needs a
            // second sync; the non-guest path never mutates CurrentUser
            // again after initialize()'s own sync.
            self::currentUser()->set(self::currentUser()->get()->withUsername(Username::from(self::lang()->t('guest'))));
        }

        $pageState = self::pageState();

        // in case an auth key was provided and is no longer valid, we must wait to
        // be here, with language loaded, to prepare the message
        if ($pageState->authKeyInvalid) {
            $pageState->addError(
                self::lang()->t('Your authentication key is no longer valid.')
              . sprintf(' <a href="%s">%s</a>', self::urlService()->getRootUrl() . 'identification.php', self::lang()->t('Login'))
            );
        }

        // check if we need to notified user about api_key expiration
        $notify_api_key_expiration = $pageState->notifyApiKeyExpiration;
        // This account data, though read from CurrentUser, is exactly as
        // much a "could be malformed/incomplete" boundary as raw input --
        // a real fixture/legacy account can have an empty email or
        // username -- so tryFrom() + a graceful skip, not a hard
        // requirement.
        $notify_username = $notify_api_key_expiration !== null ? Username::tryFrom(self::currentUser()->get()->username) : null;
        $notify_email = $notify_api_key_expiration !== null ? Email::tryFrom(self::currentUser()->get()->email) : null;
        if ($notify_api_key_expiration !== null && $notify_username instanceof Username && $notify_email instanceof Email) {
            $apiKeyRepo = new ApiKeyRepository(EntityManagerFactory::build($conn));
            $is_mail_send = new ApiKeyService(self::lang(), self::mailService(), $apiKeyRepo, self::passwordService($conn), self::urlService(), self::sessionService(), self::currentConfig())
                ->notifyExpiration($notify_username, $notify_email, $notify_api_key_expiration['days_left']);

            if ($is_mail_send) {
                $apiKeyRepo->updateLastNotifiedOn(
                    $notify_api_key_expiration['auth_key'],
                    self::currentUser()->get()->id->value,
                    $notify_api_key_expiration['dbnow'],
                );
            }

            $pageState->notifyApiKeyExpiration = null;
        }

        // template instance
        if (self::adminContext()->isActive()) {// Admin template
            $admin_theme = ThemeId::from(
                new PreferencesService(new UserRepository(EntityManagerFactory::build($conn), self::eventDispatcher(), self::currentConfig()), self::currentUser())
                    ->getAdminThemePref() ?? self::currentConfig()->adminTheme
            );
            $template = new Template(self::currentConfig(), self::lang(), self::adminContext(), self::eventDispatcher(), self::errorCollector(), self::processCache(), self::currentConfigService(), self::paths(), self::accessLevelChecker(), self::sessionService(), self::paths()->root . 'themes/admin', $admin_theme);
        } else { // Classic template
            $theme = self::currentUser()->get()->theme;
            if (PageFilterHelper::scriptBasename(self::currentConfig()) !== 'ws' and DeviceHelper::mobileTheme(self::sessionService(), self::currentConfig())) {
                $theme = ThemeId::from(self::currentConfig()->mobileTheme);
            }
            $template = new Template(self::currentConfig(), self::lang(), self::adminContext(), self::eventDispatcher(), self::errorCollector(), self::processCache(), self::currentConfigService(), self::paths(), self::accessLevelChecker(), self::sessionService(), self::paths()->root . 'themes', $theme);
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
            new NoPhotoYetRenderer(self::lang(), self::accessLevelChecker(), EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), self::currentConfigService()->get(), new RedirectService(self::lang(), self::userService(), self::eventDispatcher(), self::pageState()), self::urlService(), self::paths(), self::adminContext(), self::sessionService(), self::eventDispatcher(), self::currentUser(), self::currentTemplate(), self::currentConfig(), self::errorCollector(), self::processCache(), self::currentConfigService())
                ->render();
        }

        $user_internal_status = self::currentUser()->get()->internalStatus;
        if (($user_internal_status['guest_must_be_guest'] ?? false) === true) {
            $pageState->addHeaderMessage(self::lang()->t('Bad status for user "guest", using default status. Please notify the webmaster.'));
        }

        if (self::currentConfig()->galleryLocked) {
            $pageState->addHeaderMessage(self::lang()->t('The gallery is locked for maintenance. Please, come back later.'));

            if (PageFilterHelper::scriptBasename(self::currentConfig()) !== 'identification' and ! self::accessLevelChecker()->isAdmin()) {
                // Throws instead of calling header()+echo+exit() directly
                // -- caught by bootEntryPoint()'s own try/catch.
                $body = '<a href="' . self::urlService()->getAbsoluteRootUrl(false) . 'identification.php">' . self::lang()->t('The gallery is locked for maintenance. Please, come back later.') . '</a>';
                $body .= str_repeat(' ', 512); // IE6 doesn't error output if below a size
                throw new ResponseReadyException(ResponseFactory::raw($body, [
                    'Retry-After' => '900',
                    'Content-Type' => 'text/html; charset=utf-8',
                ], 503));
            }
        }

        if ($pageState->headerMessages !== []) {
            $template->assignContext(new HeaderMessagesPageContext($pageState->headerMessages));
            $pageState->headerMessages = [];
        }

        if (self::currentConfig()->filterPages !== [] and (bool) PageFilterHelper::getFilterPageValue(self::currentConfig(), 'used')) {
            new FilterService(self::filterState(), self::sessionService(), self::translator(), self::lang(), self::currentConfig(), self::eventDispatcher(), $conn)
                ->initializeFromRequest(self::pageState(), self::currentUser());
        } else {
            self::filterState()->set(false);
        }

        $pageState->headerNotes = array_merge($pageState->headerNotes, self::currentConfig()->headerNotes);

        // Default event handlers -- extracted into Piwigo\Listener\*
        // classes (P27.0). Must stay after PluginLoader::loadPlugins()
        // (in connect() above) so a plugin's own 'upload_file' handler
        // (if any) keeps first crack in the trigger_change() chain.
        // The 2 dead 'pwg_image_resize' registrations this block used to
        // carry (UploadImageResize/UploadThumbnailResize -- no function
        // by that name exists anywhere in this codebase, neither event is
        // ever triggered) are deleted outright rather than ported: no
        // Listener\ListenerInterface shape can express "register a string
        // that isn't callable yet and only fail lazily," and preserving
        // genuinely dead code isn't worth contorting the new mechanism
        // for.
        self::registerListener(new HtmlRenderingListener(self::htmlService(), self::currentConfig()));
        // checkForSpam() is an instance method (unlike UploadFormatListener's
        // static upload_file handlers below) -- CommentService is built
        // here, reusing the request's own shared Connection, and handed
        // to the listener rather than autowired fresh.
        self::registerListener(new CommentSpamListener(new CommentService(self::lang(), EntityManagerFactory::build($conn)->getRepository(CommentEntity::class), new EphemeralKeyService(self::currentConfig()), self::mailService(), self::htmlService(), self::urlService(), self::eventDispatcher(), self::pageState(), self::currentUser(), self::currentConfig(), self::accessLevelChecker())));
        self::registerListener(new SiteCleanupListener(EntityManagerFactory::build($conn)->getRepository(SiteEntity::class)));
        self::registerListener(new UploadFormatListener());
        self::eventDispatcher()->dispatchNotify(new Init());

        // CurrentUser's/PageState's own `??=` guards are already
        // satisfied by this point on the real HTTP path
        // (UserBootstrap::initialize() in connect(), pageState()'s own
        // resolution in configure()), so both calls are no-ops here in
        // practice; kept for parity with callers that reach finalize()
        // without having run those earlier steps. Lang::attachGlobals()
        // is the one with a real ordering requirement -- it snapshots
        // Translator's already-loaded strings, so it must run after this
        // method's own lang()->load() calls above, not before.
        self::currentUser()->attachGlobals();
        self::pageState();
        self::lang()->attachGlobals();
    }

    /**
     * Constructed identically at 4 call sites across connect()/finalize()
     * (each a static method with no shared instance state to inject into)
     * -- same "inline-construct a one-off dependency behind a named method"
     * precedent as Tag\TagService::newImageService()/Image\ImageService::
     * categoryService(), applied here to eliminate the literal duplicated
     * construction chain rather than to avoid a constructor-param ripple.
     */
    private static function activityService(Connection $conn): ActivityService
    {
        return new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class));
    }

    private static function passwordService(Connection $conn): PasswordService
    {
        return new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), self::deploymentPolicy());
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
     * Resolves the container-shared instance, same reasoning as paths()
     * above.
     */
    private static function dbCredentials(): DbCredentials
    {
        $dbCredentials = Kernel::container()->get(DbCredentials::class);
        if (! $dbCredentials instanceof DbCredentials) {
            throw new LogicException('Container returned an unexpected type for ' . DbCredentials::class);
        }

        return $dbCredentials;
    }

    /**
     * Resolves the container-shared instance (not `new ApiKeyRequestFlag()`)
     * so that `UserBootstrap::initialize()`'s `activate()` call is visible
     * to every other consumer holding the same shared instance -- see that
     * class's own docblock.
     */
    private static function apiKeyRequestFlag(): ApiKeyRequestFlag
    {
        $flag = Kernel::container()->get(ApiKeyRequestFlag::class);
        if (! $flag instanceof ApiKeyRequestFlag) {
            throw new LogicException('Container returned an unexpected type for ' . ApiKeyRequestFlag::class);
        }

        return $flag;
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
     * Resolves the container-shared instance so that `PluginLoader::
     * loadPlugins()`'s writes are visible to every other consumer holding
     * the same shared instance -- `PluginLoader` itself stays static and
     * lives outside `Bootstrap/`, so the instance is threaded through as an
     * explicit parameter rather than resolved from inside `PluginLoader`.
     */
    private static function loadedPlugins(): LoadedPlugins
    {
        $loadedPlugins = Kernel::container()->get(LoadedPlugins::class);
        if (! $loadedPlugins instanceof LoadedPlugins) {
            throw new LogicException('Container returned an unexpected type for ' . LoadedPlugins::class);
        }

        return $loadedPlugins;
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
     * shared instance.
     */
    private static function currentLogger(): CurrentLogger
    {
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        return $currentLogger;
    }

    /**
     * Resolves the container-shared instance -- its factory binding
     * (config/container.php) already calls loadFromDb() at construction,
     * so simply resolving it here (rather than a bare
     * ImageStdParams::loadFromDb() static call) is enough to preserve
     * this method's own "called every request, very early" semantics.
     */
    private static function imageStdParams(): ImageStdParams
    {
        $imageStdParams = Kernel::container()->get(ImageStdParams::class);
        if (! $imageStdParams instanceof ImageStdParams) {
            throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
        }

        return $imageStdParams;
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
     * Resolves the container-shared, immutable instance.
     */
    private static function wsContext(): WsContext
    {
        $wsContext = Kernel::container()->get(WsContext::class);
        if (! $wsContext instanceof WsContext) {
            throw new LogicException('Container returned an unexpected type for ' . WsContext::class);
        }

        return $wsContext;
    }

    /**
     * Resolves the container-shared instance. Public: PluginLoader::
     * loadPlugins() genuinely needs the full AccessControl (real
     * checkStatus()/accessDenied() enforcement, passed through to
     * third-party PluginMaintain subclasses); public/admin.php and
     * public/random.php need the same full class for their own
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

    /**
     * Resolves the container-shared, immutable instance. Public (unlike
     * most resolver helpers here): public/install.php's own
     * `new InstallWizard(...)` manual construction needs this to satisfy
     * Template's own new required collaborators.
     */
    public static function adminContext(): AdminContext
    {
        $adminContext = Kernel::container()->get(AdminContext::class);
        if (! $adminContext instanceof AdminContext) {
            throw new LogicException('Container returned an unexpected type for ' . AdminContext::class);
        }

        return $adminContext;
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
     * Leaf values recursed into by array_walk_recursive() from $_GET/
     * $_POST/$_COOKIE are always strings in practice (HTTP request data
     * never contains scalars other than strings; arrays are recursed
     * into rather than passed to the callback), but the parameter is
     * typed mixed so we narrow rather than force-cast it.
     */
    private static function sanitizeMysqlKv(mixed &$v, int|string $k): void
    {
        if (is_string($v)) {
            $v = addslashes($v);
        }
    }

    /**
     * Registers every entry of a Piwigo\Listener\* instance's own
     * subscribedEvents() map onto EventDispatcher::addTypedHandler() --
     * the glue P27.0's Listener extraction replaces inline
     * addTypedHandler() calls with. $listener is already fully
     * constructed (with real dependencies, reusing the request's shared
     * Connection where relevant) by the caller; this method only wires
     * its declared events onto the dispatcher, in whatever order
     * subscribedEvents() returns them. Entries are already-bound Closures
     * (see ListenerInterface's own docblock for why -- this codebase's
     * phpstan-strict-rules config bans variable method calls outright, so
     * there's no method-name string left to resolve here.
     */
    private static function registerListener(ListenerInterface $listener): void
    {
        foreach ($listener->subscribedEvents() as $eventClass => $handlers) {
            foreach (is_array($handlers) ? $handlers : [$handlers] as $handler) {
                self::eventDispatcher()->addTypedHandler($eventClass, $handler);
            }
        }
    }
}
