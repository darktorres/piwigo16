<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\PluginLoader;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Comment\CommentService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CoverageCollector;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\ServerTiming;
use Piwigo\Core\StringHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Lifecycle\Init;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Event\Picture\GetElementUrl;
use Piwigo\Event\Picture\UploadFile;
use Piwigo\Event\Picture\UploadImageResize;
use Piwigo\Event\Picture\UploadThumbnailResize;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryLiteralDescription;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Event\User\TryLogUser;
use Piwigo\Event\User\UserCommentCheck;
use Piwigo\Filter\FilterService;
use Piwigo\Html\HtmlService;
use Piwigo\Image\Event\GetSrcImageUrl;
use Piwigo\Image\ImageService;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * The per-request bootstrap. Used to be the orchestration body of
 * include/common.inc.php (ported verbatim, P23 sub-batch 8f-5), with that
 * file kept on as a thin include seam every entry point targeted. The seam
 * is gone (P23 batch: include/+admin/ deletion) — bootEntryPoint() below is
 * now the real, sole entry point every root `public/*.php` file calls
 * directly.
 *
 * Why three phases instead of one run(): the legacy bootstrap used to mark
 * Piwigo\Core\InstallationFlag active mid-sequence via a raw
 * `defined('PHPWG_INSTALLED') or define('PHPWG_INSTALLED', true);` guard
 * (after the install-redirect check, before the session handler
 * registration that reads InstallationFlag::isActive()) — src/Piwigo/ code
 * may not call define() (arch rule SEC-60), so that call had to live
 * outside this class, in the seam file, slotted between the phases.
 * InstallationFlag::mark() replaced the raw define() itself (Legacy
 * Coupling Retirement gap-closure, entry-shell define()/include round,
 * Part 0b) — a normal, safe static call, no longer subject to SEC-60 —
 * which is what lets bootEntryPoint() now call it directly instead of
 * needing an external seam to slot it in. The phases stay separate methods
 * regardless, preserving the original statement order exactly and the
 * standalone-callable contract
 * `tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php` exercises
 * directly. The former PHPWG_DOMAIN/
 * PHPWG_URL/PEM_URL define()s that used to sit here too (after
 * UserBootstrap, before language loading) are gone entirely (Legacy
 * Coupling Retirement gap-closure, entry-shell define()/include round,
 * Part 0b) -- every real reader now goes through Piwigo\Core\AppInfo::
 * DOMAIN/URL or this class's own pemUrl(), neither of which needs
 * seam-file sequencing at all.
 *
 * Legacy Coupling Retirement Phase 8, 8a (the "boot-first" fix):
 * configure() now calls Kernel::boot($paths) as its own first statement --
 * genuinely load-bearing, not just tidiness, since connect() below
 * performs real work (DB connection, plugin loading, user resolution)
 * that used to run before the former Piwigo\Bootstrap\CommonBootstrap::run()
 * (index.php/admin.php and the other P22 roots called it right after the
 * common.inc.php include, previously the *only* Kernel::boot() call on
 * this path) ever executed. That class is retired now (see
 * bootEntryPoint()'s own docblock below); bootConfigOnly()'s own
 * Kernel::boot() call is the harmless idempotent no-op today (Kernel::boot()
 * itself guards on self::$booted) -- kept there for the standalone-
 * callable contract tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php exercises
 * directly.
 *
 * Legacy Coupling Retirement Phase 8, 8d: every real ConfigDb:: call in
 * this file has been retargeted onto a container-resolved ConfigService
 * (connect() resolves it once and reuses the same instance for all 3
 * writes) -- safe only because 8c first retargeted every real
 * `$conf[...]` read out of this file onto CurrentConfig:: accessors;
 * ConfigService::loadConfFromDb() only ever writes CurrentConfig's own
 * properties directly, never $conf, unlike ConfigDb::loadConfFromDb()'s
 * dual-write.
 */
final class RequestBootstrap
{
    /**
     * The real entry point every root `public/*.php` file calls directly —
     * replaces the former `include $paths->root . 'include/common.inc.php';`
     * line (P23 batch: include/+admin/ deletion). Exact same statement
     * order as that seam file's own body: capture $t2, run the three
     * phases below with `InstallationFlag::mark()` slotted between
     * configure() and connect() (matching the original file's statement
     * order precisely), catch `ResponseReadyException` from any
     * bootstrap-phase short-circuit (install-redirect, upgrade-redirect,
     * the 503 maintenance page) and emit it directly.
     *
     * Callers that never included `common.inc.php` in the first place
     * (`i.php`, deliberately) or that skip straight to their own bespoke
     * bootstrap (`install.php`/`upgrade.php`/`upgrade_feed.php`/
     * `ready.php`, none of which ever depended on this class) do not call
     * this method — see each file's own docblock for why.
     *
     * Boot sequence consolidation (Config generic-accessor removal
     * follow-up): SentryBootstrap::init()/ServerTiming's own 'boot' timer
     * (seeded from `$t2`, captured right here, and stopped at both this
     * method's own exit points below) now bracket this method's entire
     * body -- formerly they only
     * bracketed the former Piwigo\Bootstrap\CommonBootstrap::run() (a
     * second call every `public/*.php` root file made right after this
     * one), which on the real HTTP path only ever repeated
     * already-idempotent work (see configure()/connect()'s own
     * docblocks). A real, found-not-assumed bug: that meant Sentry never
     * saw an error raised anywhere in this method's own body (the bulk of
     * real per-request boot work -- DB connect, config load, plugin
     * load, user resolution), and the 'boot' Server-Timing entry measured
     * almost nothing real. CommonBootstrap is retired; every one of its
     * real steps (Sentry, timing, the config/ConfigService bootstrap, and
     * the CurrentUser/PageState/Lang attachGlobals() calls) now lives
     * directly in this class -- see bootConfigOnly() below for the
     * lighter, standalone-callable equivalent that used to be that
     * class's own run().
     *
     * $mountDepth/$isWs/$isAdmin -- singleton/service-locator elimination
     * campaign, Phase 3: the pre-`Kernel::boot()` marker trio
     * (RequestMountDepth/WsContext/AdminContext), threaded through from
     * whichever entry-shell file called this (`public/admin/popuphelp.php`,
     * `public/ws.php`, `public/admin.php` -- the only 3 that pass anything
     * other than the defaults) all the way down to `Piwigo\Core\Container`'s
     * own build() method, replacing those 3 classes' former pre-boot
     * `X::mark()`/`X::set()`
     * calls (there is no container yet at the point those used to run).
     *
     * `ServerTiming` (also converted in Phase 3, moved from Phase 1 for the
     * identical pre-boot entanglement, but only actually converted once the
     * above trio's own work landed) needed a different mechanism, not the
     * container-build-time binding the trio uses: its 'boot' timer must
     * start ticking at the exact instant this method begins, before
     * `configure()`'s own `Kernel::boot()` call runs -- `$t2` below captures
     * that instant as a plain local variable (same idea as
     * `PageState::requestStart`'s own pre-autoload capture), and
     * `configure()` seeds the container-shared `ServerTiming` instance with
     * it immediately after `Kernel::boot()` returns.
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
        } catch (\Piwigo\Http\ResponseReadyException $e) {
            self::serverTiming()->stop('boot');
            new \Piwigo\Http\ResponseEmitter()
                ->emit($e->response());
            exit;
        }

        self::serverTiming()->stop('boot');
    }

    /**
     * The standalone-callable, config+globals-only boot path -- formerly
     * Piwigo\Bootstrap\CommonBootstrap::run(), moved here verbatim when
     * that class was retired. Its only real callers were the 23 root
     * `public/*.php` files (each already calling bootEntryPoint() first,
     * making this a same-request repeat of already-idempotent work) and
     * this method's own test -- bootEntryPoint() above now performs every
     * one of these steps itself in the right place (Sentry/timing bracket
     * its whole body; the config/ConfigService bootstrap already lived in
     * configure()/connect(); the attachGlobals() calls now live at the end
     * of finalize(), after language loading, matching Lang::attachGlobals()'s
     * own real ordering requirement). No real production route needs this
     * method anymore -- kept as the lighter path (no install-check/
     * session/DB-user machinery) tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php
     * exercises directly, and any future route that only needs config, not
     * the full request machinery, can reach for.
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
            $configService = Kernel::container()->get(\Piwigo\Config\ConfigService::class);
            if (! $configService instanceof \Piwigo\Config\ConfigService) {
                throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\ConfigService::class);
            }
            $currentConfigService->set($configService);
            $configService->loadConfFromDb();
        }
        self::currentUser()->attachGlobals();
        self::pageState();
        Lang::attachGlobals();

        self::serverTiming()->stop('boot');
    }

    /**
     * Phase 1 — superglobal sanitization, env-file loading, static-setter
     * wiring, Config seeding, install-sentinel check
     * (include/common.inc.php's former lines up to the install redirect).
     *
     * bootEntryPoint() calls InstallationFlag::mark() right after this
     * returns -- the former seam file's raw PHPWG_INSTALLED define(), now
     * gone entirely (see this class's own docblock).
     *
     * One deliberate ordering deviation from the original file: the
     * superglobal sanitization used to run before the
     * config_default/local-config includes; it now runs right after them
     * (the seam's includes must stay at real top-level scope, and this
     * class only starts executing post-autoload). Equivalent for every
     * real input: the config includes never read $_GET/$_POST/$_COOKIE/
     * PATH_INFO, and Env's own header read ($_SERVER['HTTP_X_PIWIGO_ENV'])
     * was never touched by the sanitizer.
     *
     * Kernel::boot($paths) runs as the very first statement, ahead of
     * everything else in this method -- see this class's own docblock for
     * why. The container-building call it makes internally only registers
     * lazy PHP-DI factory closures, confirmed zero eager side effects, so
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

        // Legacy Coupling Retirement Phase 8, 8h: the true start of each
        // request -- resets ActivityService::record()'s "was a real user
        // resolved this request" flag before anything else can mark it
        // (UserBootstrap::initialize()). Monotonic within a request, so it
        // needs a real reset here rather than relying on CurrentUser's own
        // full reset (restricted to tests/ by an arch test).
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

        // P23 batch 8f-3: wires the static-setter HtmlRenderingInterface
        // consumer (Piwigo\Core class-level fatal-error paths that can't
        // take constructor/per-method injection without an unreasonable
        // call-site ripple). Safely post-autoload here: the seam file only
        // calls this class after its include/env.inc.php include, which is
        // what actually requires vendor/autoload.php -- some entry points
        // (e.g. random.php) rely entirely on that include to make every
        // Piwigo\ class autoloadable and never require the autoloader
        // themselves beforehand, unlike admin.php/index.php's own explicit
        // up-front require (ordering bug caught live via a random.php
        // smoke test). AccessControl::setHtmlRenderer()/setRedirectService()
        // used to sit here too -- removed (singleton/service-locator
        // elimination campaign, Phase 7): AccessControl is now a real,
        // container-shared instance, autowired with zero manual wiring.
        Lang::setHtmlRenderer(new HtmlService());

        // Piwigo\Db\Tables::*()/other Piwigo\Config\Config::* accessors used
        // further down in this bootstrap's own body (not just by code that
        // runs after full boot) read Config's static state -- these two
        // calls must seed it before any of that later code runs. Both are
        // idempotent (verified: re-running never overwrites an already-set
        // key), so bootConfigOnly()'s own copy of these same two calls
        // (its standalone-callable path, not chained after this one on any
        // real request) is a harmless no-op if it ever runs in the same
        // process.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        if (! file_exists($paths->siteLocal . Env::testModeInstalledStamp())) {
            // Workstream C3, catch point 1: throws instead of the former
            // raw header()+exit() -- see the 503 maintenance-page site
            // below for the same conversion and why.
            throw new \Piwigo\Http\ResponseReadyException(\Piwigo\Http\ResponseFactory::redirect('install.php'));
        }
    }

    /**
     * Phase 2 — dblayer include, error collector, session bootstrap, DB
     * connection, DB-backed config, logger, plugins, and the current-user
     * resolution (include/common.inc.php's former middle section, from the
     * dblayer include through UserBootstrap::initialize()).
     *
     * The former seam file used to define PHPWG_DOMAIN/PHPWG_URL/PEM_URL
     * right after this returned (between UserBootstrap and the language
     * loading in finalize()); those defines are gone entirely now (Legacy
     * Coupling Retirement gap-closure, entry-shell define()/include round,
     * Part 0b) -- see this class's own docblock.
     */
    public static function connect(): void
    {
        // P23 sub-batch 8g-6: the dynamic include of include/dblayer/
        // functions_<dblayer>.inc.php is gone -- the file's 45 facades died
        // with the frozen install/db scripts and its top-level define()s
        // became MysqliDb class constants, so nothing on this path needs it.

        // Route errors to DevTools (X-PHP-Error-N response headers) instead
        // of inline output, which corrupts JSON/XML/binary responses -- and
        // is also load-bearing for HtmlService::fatalError()'s own
        // recordFatal()+throw sequence (see
        // ErrorCollector::installIfConfigured()'s own docblock).
        self::errorCollector()->installIfConfigured();

        if (\Piwigo\Config\CurrentConfig::sessionGcProbability() > 0) {
            @ini_set('session.gc_divisor', 100);
            $gc_probability = \Piwigo\Config\CurrentConfig::sessionGcProbability();
            @ini_set('session.gc_probability', min($gc_probability, 100));
        }

        SessionBootstrap::register();

        self::pageState()->executionUuid = self::sessionService()->generateKey(10);

        // Database connection. DbConnection::build() itself deliberately
        // never touches the session-level ONLY_FULL_GROUP_BY server mode
        // the legacy dblayer used to strip (see that factory's own
        // docblock) -- every request path has run without it since the
        // earlier domain migrations. Built eagerly (not left to DBAL's own
        // lazy first-query connect) so an unreachable DB surfaces here as
        // the same friendly fatalError() page the legacy connect used to
        // produce, not a raw exception from whatever call happens to run
        // first. Shared for every repository/service constructed for the
        // rest of this method -- DbConnection::build() returns a fresh
        // Connection on every call (no internal caching), so reusing this
        // one avoids opening a separate physical DB connection per
        // repository, matching the established pattern from the Search/
        // Section/Category domain migrations.
        $conn = DbConnection::build();
        $db_password = \Piwigo\Db\DbCredentials::current()->password;
        try {
            $conn->getNativeConnection();
        } catch (\Exception $e) {
            new HtmlService()
                ->fatalError(Lang::t($e->getMessage()));
        }

        // Legacy Coupling Retirement Phase 8, 8d: safe now that 8c retargeted
        // every $conf[...] read out of this file -- ConfigService::loadConfFromDb()
        // only ever writes CurrentConfig's own properties directly, never
        // global $conf, unlike ConfigDb::loadConfFromDb()'s dual-write.
        // CurrentConfigService::set() here makes this resolved instance
        // reachable via CurrentConfigService::get() for the rest of this
        // request (finalize() below, and every Tier 2 static-utility
        // caller) -- see its own docblock.
        $configService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\ConfigService::class);
        if (! $configService instanceof \Piwigo\Config\ConfigService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\ConfigService::class);
        }
        self::currentConfigService()->set($configService);
        $configService->loadConfFromDb();

        $log_data_location = \Piwigo\Config\CurrentConfig::dataLocation();
        $log_dir = \Piwigo\Config\CurrentConfig::logDir();

        self::currentLogger()->set(new Logger([
            'directory' => CurrentPaths::get()->root . $log_data_location . $log_dir,
            'severity' => \Piwigo\Config\CurrentConfig::logLevel(),
            // we use an hashed filename to prevent direct file access, and we salt with
            // the db_password instead of secret_key because the log must be usable in i.php
            // (secret_key is in the database)
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $db_password) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => \Piwigo\Config\CurrentConfig::logArchiveDays(),
        ]));

        self::imageStdParams();

        session_start();
        PluginLoader::loadPlugins(self::loadedPlugins(), \Piwigo\PluginConfig\EventDispatcher::get(), self::activityService($conn));

        if (\Piwigo\Config\CurrentConfig::piwigoInstalledVersion() === null) {
            $configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        } elseif (\Piwigo\Config\CurrentConfig::piwigoInstalledVersion() !== AppInfo::VERSION) {
            // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
            self::activityService($conn)->record('system', ActivitySystem::Core, 'autoupdate', [
                'from_version' => \Piwigo\Config\CurrentConfig::piwigoInstalledVersion(),
                'to_version' => AppInfo::VERSION,
            ]);
            $configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        }

        // Check if last major update conf is set if not set it
        if (\Piwigo\Config\CurrentConfig::lastMajorUpdate() === null) {
            $dbnow = Env::now()->format('Y-m-d H:i:s');
            $configService->confUpdateParam('last_major_update', $dbnow, updateGlobal: true);
        }

        // users can have defined a custom order pattern, incompatible with GUI form.
        // order_by_custom/order_by_inside_category_custom are raw "ORDER BY ..."
        // SQL-fragment strings, same shape as order_by/order_by_inside_category
        // themselves (not the structured {field,dir}[] shape the old SCHEMA
        // entry implied) -- CurrentConfig::orderByCustom()/
        // orderByInsideCategoryCustom() are real typed (nullable) accessors now.
        $orderByCustom = \Piwigo\Config\CurrentConfig::orderByCustom();
        if ($orderByCustom !== null) {
            \Piwigo\Config\CurrentConfig::setOrderBy($orderByCustom);
        }
        $orderByInsideCategoryCustom = \Piwigo\Config\CurrentConfig::orderByInsideCategoryCustom();
        if ($orderByInsideCategoryCustom !== null) {
            \Piwigo\Config\CurrentConfig::setOrderByInsideCategory($orderByInsideCategoryCustom);
        }

        if (\Piwigo\Image\LoungeMaintenance::needsEmptying()) {
            new ImageService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class), self::activityService($conn), self::sessionService(), \Piwigo\PluginConfig\EventDispatcher::get())
                ->emptyLounge();
        }

        // Piwigo\Bootstrap\UserBootstrap::initialize() resolves the real
        // per-request user (build_user()/AuthService::autoLogin()/
        // auth_key_login()) and calls CurrentUser::set() itself -- Legacy
        // Coupling Retirement Phase 8 gap-closure retired the former
        // `global $user` dual-write bridge this method used to pre-seed
        // and re-sync around this call (Track A batch A3 kept it live
        // "until every consumer is retargeted off the raw global";
        // Bucket C's own consumer-side work, 8h/8i, was the last one).
        //
        // Real bug, found live: try_log_user's own handler used to be
        // registered down in finalize() (relocated there from the deleted
        // include/functions_user.inc.php, "has to live somewhere that
        // always executes"), which runs strictly *after* connect()
        // returns -- but UserBootstrap::initialize() right below reaches
        // AuthService::tryLogUser() directly on its own
        // pwg.images.uploadAsync username/password credential path (see
        // that method's own docblock), well before finalize() ever gets a
        // chance to register anything. EventDispatcher::triggerChange()
        // with no matching handler just returns its own $default (false)
        // unmodified, so every real uploadAsync credential login silently
        // failed with "Invalid username/password" regardless of how
        // correct the credentials were -- confirmed live via a raw curl
        // reproduction against this exact request shape. Registering here
        // instead, immediately before the one call site that needs it
        // this early, fixes that without disturbing finalize()'s own
        // still-later registration timing for every other real caller of
        // tryLogUser() (the normal pwg.session.login WS dispatch, which
        // runs during RequestPipeline::handle() -- after bootEntryPoint()
        // (connect()+finalize()) has fully returned, so it was never
        // actually affected by this ordering bug).
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(TryLogUser::class, new AuthService(
            new AuthRepository(\Piwigo\Db\EntityManagerFactory::build($conn)),
            self::activityService($conn),
            new HtmlService(),
            new PasswordService(new PasswordRepository($conn), self::deploymentPolicy()),
            new CookieService(),
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Auth\UserFailedLoginEntity::class),
            self::sessionService(),
            \Piwigo\PluginConfig\EventDispatcher::get(),
            self::pageState(),
            self::currentUser(),
        )->pwgLogin(...));
        new UserBootstrap(
            new RedirectService(self::userService()),
            self::urlService(),
            self::apiKeyRequestFlag(),
            self::currentLogger(),
            self::wsContext(),
            self::deploymentPolicy(),
        )->initialize();
    }

    /**
     * The PEM (piwigo extension market) base URL every real reader now
     * calls directly instead of reading a cached PEM_URL constant (Legacy
     * Coupling Retirement gap-closure, entry-shell define()/include
     * round, Part 0b) -- cheap and side-effect-free (a Config read plus a
     * string concat), so recomputing at each read site is simpler than a
     * per-request cache and behaviourally identical (Config doesn't
     * change mid-request).
     */
    public static function pemUrl(): string
    {

        if (\Piwigo\Config\CurrentConfig::alternativePemUrl() !== '') {
            return \Piwigo\Config\CurrentConfig::alternativePemUrl();
        }

        return AppInfo::URL . '/ext';
    }

    /**
     * Phase 3 — language loading, auth-key messages, template creation,
     * no-photo-yet, maintenance/upgrade notices, request filter, and the
     * default event-handler registrations (include/common.inc.php's former
     * tail, from the Lang wiring through trigger_notify('init')).
     */
    public static function finalize(): void
    {
        // Shared for every repository/service constructed for the rest of
        // this method -- same "one Connection per method, not per
        // repository" reasoning as connect() above.
        $conn = DbConnection::build();

        // language files
        Lang::setDefaultLanguageProvider(new UserService(
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Users\UserInfoEntity::class),
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class),
            self::mailService(),
            self::activityService($conn),
            new HtmlService(),
            $conn,
            self::sessionService(),
            \Piwigo\PluginConfig\EventDispatcher::get(),
            self::deploymentPolicy(),
            self::currentUser(),
        ));
        Lang::load('common.lang');
        if (\Piwigo\Auth\AccessControl::current()->isAdmin() || self::adminContext()->isActive()) {
            Lang::load('admin.lang');
            // Add language for temporary strings for new popup, from piwigo 15
            Lang::load('whats_new_' . \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.lang');
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LoadingLang());
        Lang::load('lang', CurrentPaths::get()->siteLocal, [
            'no_fallback' => true,
            'local' => true,
        ]);

        // only now we can set the localized username of the guest user (and not in
        // UserBootstrap::initialize())
        if (\Piwigo\Auth\AccessControl::current()->isAGuest()) {
            // Second CurrentUser sync point (the first is inside
            // UserBootstrap::initialize()) -- isAGuest() itself already
            // reads CurrentUser (synced there with the pre-localization
            // username), so only the localized-username case needs a
            // second sync; the non-guest path never mutates CurrentUser
            // again after initialize()'s own sync.
            self::currentUser()->set(self::currentUser()->get()->withUsername(Lang::t('guest')));
        }

        $pageState = self::pageState();

        // in case an auth key was provided and is no longer valid, we must wait to
        // be here, with language loaded, to prepare the message
        if ($pageState->authKeyInvalid) {
            $pageState->addError(
                Lang::t('Your authentication key is no longer valid.')
              . sprintf(' <a href="%s">%s</a>', self::urlService()->getRootUrl() . 'identification.php', Lang::t('Login'))
            );
        }

        // check if we need to notified user about api_key expiration
        $notify_api_key_expiration = $pageState->notifyApiKeyExpiration;
        if ($notify_api_key_expiration !== null) {
            $notify_username = self::currentUser()->get()->username;
            $notify_email = self::currentUser()->get()->email;
            $apiKeyRepo = new \Piwigo\Auth\ApiKeyRepository(\Piwigo\Db\EntityManagerFactory::build($conn));
            $is_mail_send = new \Piwigo\Auth\ApiKeyService(self::mailService(), $apiKeyRepo, new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($conn), self::deploymentPolicy()), self::urlService(), self::sessionService())
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
            // getParam() has no return type declaration (its own value
            // comes from CurrentUser::get()->preferences[$param], an
            // untyped array<string, mixed>), so its return is inferred as
            // mixed; narrow to the same CurrentConfig::adminTheme() fallback
            // already passed as the default value.
            $admin_theme = new \Piwigo\Users\PreferencesService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Users\UserInfoEntity::class), self::currentUser())
                ->getParam('admin_theme', \Piwigo\Config\CurrentConfig::adminTheme());
            $admin_theme = is_string($admin_theme) ? $admin_theme : \Piwigo\Config\CurrentConfig::adminTheme();
            $template = new Template(CurrentPaths::get()->root . 'themes/admin', $admin_theme);
        } else { // Classic template
            $theme = self::currentUser()->get()->theme;
            if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'ws' and \Piwigo\Core\DeviceHelper::mobileTheme()) {
                $theme = \Piwigo\Config\CurrentConfig::mobilTheme();
            }
            $template = new Template(CurrentPaths::get()->root . 'themes', $theme);
        }

        // Legacy Coupling Retirement Track A / Phase 2 global-residual
        // sweep: CurrentTemplate is now the sole target -- the former
        // `global $template;` dual-write bridge was retired once a
        // repo-wide scan confirmed every other consumer had already been
        // retargeted onto CurrentTemplate::get() (this was the last site).
        self::currentTemplate()->set($template);
        // Image\SrcImage (L2aCoreDomain) reads theme conf through
        // Piwigo\Core\ThemeConfProviderInterface (implemented by Template)
        // instead of depending on Template directly (deptrac) -- wired
        // here, not earlier, for the same "the provider IS the request's
        // $template instance, which only exists from this point on"
        // reason the deleted get_themeconf() free function's own
        // $GLOBALS['template'] read had. Piwigo\Core\CurrentThemeConfProvider
        // (singleton/service-locator elimination campaign, Phase 6) is a
        // separate container-shared wrapper from CurrentTemplate above,
        // not a delegate to it -- see that wrapper's own docblock.
        $currentThemeConfProvider = Kernel::container()->get(\Piwigo\Core\CurrentThemeConfProvider::class);
        if (! $currentThemeConfProvider instanceof \Piwigo\Core\CurrentThemeConfProvider) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\CurrentThemeConfProvider::class);
        }
        $currentThemeConfProvider->set($template);

        if (\Piwigo\Config\CurrentConfig::noPhotoYet() === null) {
            // Formerly include/no_photo_yet.inc.php, a seam of exactly this
            // one call (deleted, P23 sub-batch 8f-5). render() exits itself
            // when it decides to take over the page. CurrentConfigService::get()
            // reuses the instance connect() already resolved earlier in the
            // same request (Legacy Coupling Retirement Phase 8, 8d).
            new NoPhotoYetRenderer(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class), self::currentConfigService()->get(), new RedirectService(self::userService()), self::urlService(), CurrentPaths::get(), self::adminContext(), self::sessionService(), \Piwigo\PluginConfig\EventDispatcher::get(), self::deploymentPolicy(), self::currentUser(), self::currentTemplate(), self::mailService())
                ->render();
        }

        $user_internal_status = self::currentUser()->get()->internalStatus;
        if (($user_internal_status['guest_must_be_guest'] ?? false) === true) {
            $pageState->addHeaderMessage(Lang::t('Bad status for user "guest", using default status. Please notify the webmaster.'));
        }

        if (\Piwigo\Config\CurrentConfig::galleryLocked()) {
            $pageState->addHeaderMessage(Lang::t('The gallery is locked for maintenance. Please, come back later.'));

            if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'identification' and ! \Piwigo\Auth\AccessControl::current()->isAdmin()) {
                // Workstream C3, catch point 1: throws instead of the
                // former raw header()+echo+exit() -- caught in
                // include/common.inc.php, the one seam both dispatch
                // contexts that reach this code (pipeline-routed root
                // files and admin.php/admin/popuphelp.php) include.
                $body = '<a href="' . self::urlService()->getAbsoluteRootUrl(false) . 'identification.php">' . Lang::t('The gallery is locked for maintenance. Please, come back later.') . '</a>';
                $body .= str_repeat(' ', 512); // IE6 doesn't error output if below a size
                throw new \Piwigo\Http\ResponseReadyException(\Piwigo\Http\ResponseFactory::raw($body, [
                    'Retry-After' => '900',
                    'Content-Type' => 'text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset(),
                ], 503));
            }
        }

        if ($pageState->headerMessages !== []) {
            $template->assign('header_msgs', $pageState->headerMessages);
            $pageState->headerMessages = [];
        }

        if (\Piwigo\Config\CurrentConfig::filterPages() !== [] and (bool) \Piwigo\Core\PageFilterHelper::getFilterPageValue('used')) {
            // Formerly a conditional `include PHPWG_ROOT_PATH .
            // 'include/filter.inc.php';` (deleted, P23 sub-batch 8f-5).
            new FilterService(self::filterState(), self::sessionService(), self::translator(), $conn)
                ->initializeFromRequest(self::pageState(), self::currentUser());
        } else {
            self::filterState()->set(false);
        }

        $pageState->headerNotes = array_merge($pageState->headerNotes, \Piwigo\Config\CurrentConfig::headerNotes());

        // default event handlers
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(RenderCategoryLiteralDescription::class, new HtmlService()->renderCategoryLiteralDescription(...));
        if (! \Piwigo\Config\CurrentConfig::allowHtmlDescriptions()) {
            // pwgNl2br() is a generic string transform reused by
            // RenderElementDescription's own default handler too -- a thin
            // adapter closure per event, leaving pwgNl2br() itself untouched,
            // since one method can't be typed for two different event classes.
            \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(
                RenderCategoryDescription::class,
                static function (RenderCategoryDescription $e): RenderCategoryDescription {
                    $result = new HtmlService()
                        ->pwgNl2br($e->categoryDescription);
                    $e->categoryDescription = is_string($result) ? $result : null;

                    return $e;
                },
            );
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(RenderCommentContent::class, new HtmlService()->renderCommentContent(...));
        // 'strip_tags' is PHP's own native function -- can't be retyped, so
        // this is a thin adapter closure instead, same reasoning as
        // pwgNl2br() above.
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(
            RenderCommentAuthor::class,
            static function (RenderCommentAuthor $e): RenderCommentAuthor {
                $e->commentAuthor = strip_tags($e->commentAuthor);

                return $e;
            },
        );
        // Was registered as the bare string 'str2url' -- dead since some earlier
        // phase migrated the global function to StringHelper::str2url() without
        // updating this one string-literal reference (add_event_handler() doesn't
        // get caught by a normal call-site grep). Dormant until P23 batch 8d's
        // Tags sub-batch's live curl verification actually exercised a real
        // trigger_change('render_tag_url', ...) call and hit "Event handler ...
        // is not callable" -- every prior tag-creation activity-log row in the
        // fixture is static SQL data, never actually round-tripped through this
        // handler.
        // StringHelper::str2url() is called directly from 6+ unrelated
        // production sites -- a thin adapter closure keeps its own signature
        // untouched, same reasoning as pwgNl2br()/strip_tags() above.
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(
            RenderTagUrl::class,
            static function (RenderTagUrl $e): RenderTagUrl {
                $e->tagName = StringHelper::str2url($e->tagName);

                return $e;
            },
        );
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(BlockManagerRegisterBlocks::class, new HtmlService()->registerDefaultMenubarBlocks(...));
        // Relocated from include/functions_comment.inc.php (deleted, P23 batch 8c)
        // -- that file's own top-level add_event_handler() call only ever ran via
        // its include_once at each real caller, all of which now construct
        // CommentService directly instead, so this registration has to live
        // somewhere that always executes. checkForSpam() is an instance method
        // (unlike UploadService's static upload_file handlers below), hence the
        // bound first-class-callable form rather than a bare [Class::class, 'method']
        // array.
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(UserCommentCheck::class, new CommentService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Comment\CommentEntity::class), new EphemeralKeyService(), self::mailService(), new HtmlService(), self::urlService(), \Piwigo\PluginConfig\EventDispatcher::get(), self::pageState(), self::currentUser())->checkForSpam(...));
        // try_log_user's own handler is registered in connect() instead,
        // before UserBootstrap::initialize() -- see that registration's
        // own comment for why.
        // Relocated from admin/include/functions_upload.inc.php (deleted in P23
        // sub-batch 8b-3) -- must stay after PluginLoader::loadPlugins() (in
        // connect() above) so a plugin's own 'upload_file' handler (if any)
        // keeps first crack in the trigger_change() chain, matching the
        // original file's own registration timing (its include_once always
        // fired well after plugin loading).
        //
        // 'pwg_image_resize' doesn't exist as a function anywhere in this
        // codebase and neither event is ever triggered -- a confirmed pre-existing
        // dead-but-harmless registration, already documented in
        // Piwigo\PluginConfig\EventDispatcher's own class docblock. Preserved
        // unchanged rather than "fixed", per that same documented decision.
        // addEventHandler(), not addTypedHandler() -- 'pwg_image_resize'
        // doesn't exist as a function anywhere in this codebase, so it
        // can't satisfy addTypedHandler()'s own callable(T): (T|void)
        // signature check; addEventHandler()'s untyped string|array|object
        // parameter keeps this exactly as harmless (lazy, never eagerly
        // validated) as it was before this typed conversion.
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler(UploadImageResize::class, 'pwg_image_resize');
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler(UploadThumbnailResize::class, 'pwg_image_resize');
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(UploadFile::class, UploadService::uploadFilePdf(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(UploadFile::class, UploadService::uploadFileHeic(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(UploadFile::class, UploadService::uploadFileTiff(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(UploadFile::class, UploadService::uploadFileVideo(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(UploadFile::class, UploadService::uploadFilePsd(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(UploadFile::class, UploadService::uploadFileEps(...));
        if (\Piwigo\Config\CurrentConfig::originalUrlProtection() !== '') {
            \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(GetElementUrl::class, new HtmlService()->getElementUrlProtectionHandler(...));
            \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(GetSrcImageUrl::class, new HtmlService()->getSrcImageUrlProtectionHandler(...));
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new Init());

        // Formerly the tail of Piwigo\Bootstrap\CommonBootstrap::run(),
        // called after this whole bootstrap already completed -- moved
        // here (still last) when that class was retired. CurrentUser's/
        // PageState's own `??=` guards are already satisfied by this
        // point on the real HTTP path (UserBootstrap::initialize() in
        // connect(), pageState()'s own resolution in configure()), so both
        // calls are no-ops here in practice; kept for parity with callers
        // that reach finalize() without having run those earlier steps.
        // Lang::attachGlobals() is the one with a real ordering
        // requirement -- it snapshots Translator's already-loaded strings,
        // so it must run after this method's own Lang::load() calls above,
        // not before.
        self::currentUser()->attachGlobals();
        self::pageState();
        Lang::attachGlobals();
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
        return new ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class));
    }

    /**
     * Resolves the container-shared instance (not `new ApiKeyRequestFlag()`)
     * so that `UserBootstrap::initialize()`'s `activate()` call is visible
     * to every other consumer holding the same shared instance -- see that
     * class's own docblock (singleton/service-locator elimination campaign,
     * Phase 1).
     */
    private static function apiKeyRequestFlag(): \Piwigo\Core\ApiKeyRequestFlag
    {
        $flag = Kernel::container()->get(\Piwigo\Core\ApiKeyRequestFlag::class);
        if (! $flag instanceof \Piwigo\Core\ApiKeyRequestFlag) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\ApiKeyRequestFlag::class);
        }

        return $flag;
    }

    /**
     * Resolves the container-shared instance (not `new InstallationFlag()`)
     * so that this method's own `mark()` call is visible to every other
     * consumer holding the same shared instance -- see that class's own
     * docblock (singleton/service-locator elimination campaign, Phase 1).
     */
    private static function installationFlag(): \Piwigo\Core\InstallationFlag
    {
        $flag = Kernel::container()->get(\Piwigo\Core\InstallationFlag::class);
        if (! $flag instanceof \Piwigo\Core\InstallationFlag) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\InstallationFlag::class);
        }

        return $flag;
    }

    /**
     * Resolves the container-shared instance so that `PluginLoader::
     * loadPlugins()`'s writes are visible to every other consumer holding
     * the same shared instance -- `PluginLoader` itself stays static and
     * lives outside `Bootstrap/`, so the instance is threaded through as an
     * explicit parameter rather than resolved from inside `PluginLoader`
     * (singleton/service-locator elimination campaign, Phase 1).
     */
    private static function loadedPlugins(): \Piwigo\Admin\LoadedPlugins
    {
        $loadedPlugins = Kernel::container()->get(\Piwigo\Admin\LoadedPlugins::class);
        if (! $loadedPlugins instanceof \Piwigo\Admin\LoadedPlugins) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Admin\LoadedPlugins::class);
        }

        return $loadedPlugins;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `FilterService::initializeFromRequest()`/direct `set(false)` writes
     * are visible to every other consumer holding the same shared instance
     * (singleton/service-locator elimination campaign, Phase 2).
     */
    private static function filterState(): \Piwigo\Core\FilterState
    {
        $filterState = Kernel::container()->get(\Piwigo\Core\FilterState::class);
        if (! $filterState instanceof \Piwigo\Core\FilterState) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\FilterState::class);
        }

        return $filterState;
    }

    /**
     * Resolves the container-shared instance for this method's own local
     * `new FilterService(...)` construction (singleton/service-locator
     * elimination campaign, Phase 4).
     */
    private static function translator(): \Piwigo\Lang\Translator
    {
        $translator = Kernel::container()->get(\Piwigo\Lang\Translator::class);
        if (! $translator instanceof \Piwigo\Lang\Translator) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Lang\Translator::class);
        }

        return $translator;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `set()` write is visible to every other consumer holding the same
     * shared instance (singleton/service-locator elimination campaign,
     * Phase 2).
     */
    private static function currentLogger(): \Piwigo\Core\CurrentLogger
    {
        $currentLogger = Kernel::container()->get(\Piwigo\Core\CurrentLogger::class);
        if (! $currentLogger instanceof \Piwigo\Core\CurrentLogger) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\CurrentLogger::class);
        }

        return $currentLogger;
    }

    /**
     * Resolves the container-shared instance -- its factory binding
     * (config/container.php) already calls load_from_db() at construction,
     * so simply resolving it here (rather than a bare
     * ImageStdParams::load_from_db() static call) is enough to preserve
     * this method's own "called every request, very early" semantics
     * (singleton/service-locator elimination campaign, Phase 4).
     */
    private static function imageStdParams(): \Piwigo\Image\ImageStdParams
    {
        $imageStdParams = Kernel::container()->get(\Piwigo\Image\ImageStdParams::class);
        if (! $imageStdParams instanceof \Piwigo\Image\ImageStdParams) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Image\ImageStdParams::class);
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
     * deploymentPolicy() above (singleton/service-locator elimination
     * campaign, Phase 4).
     */
    public static function pageState(): \Piwigo\Core\PageState
    {
        $pageState = Kernel::container()->get(\Piwigo\Core\PageState::class);
        if (! $pageState instanceof \Piwigo\Core\PageState) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\PageState::class);
        }

        return $pageState;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `install()` write (registering the real error handler/shutdown
     * function) is visible to every other consumer holding the same
     * shared instance (singleton/service-locator elimination campaign,
     * Phase 2).
     */
    private static function errorCollector(): ErrorCollector
    {
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if (! $errorCollector instanceof ErrorCollector) {
            throw new \LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
        }

        return $errorCollector;
    }

    /**
     * Public (unlike most resolver helpers here): public/admin.php's own
     * legacy-style `new AdminShell(...)` manual construction needs a way to
     * obtain the same container-shared instance every other CurrentUser
     * consumer receives via constructor injection, without calling
     * Kernel::container() directly itself (arch-tested to Bootstrap/ only)
     * -- same "public accessor on this class" shape as coreTabs()/
     * filesystemIntegrityChecker()/pageState() above (singleton/
     * service-locator elimination campaign, Phase 5).
     */
    public static function currentUser(): CurrentUser
    {
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentUser::class);
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
     * currentUser()/pageState() above (singleton/service-locator
     * elimination campaign, Phase 5).
     */
    public static function currentTemplate(): \Piwigo\Template\CurrentTemplate
    {
        $currentTemplate = Kernel::container()->get(\Piwigo\Template\CurrentTemplate::class);
        if (! $currentTemplate instanceof \Piwigo\Template\CurrentTemplate) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Template\CurrentTemplate::class);
        }

        return $currentTemplate;
    }

    /**
     * Public, same reason as currentTemplate() above: public/admin.php's
     * own legacy-style `new AdminShell(...)` manual construction needs a
     * way to obtain the same container-shared instance every other
     * CurrentConfigService consumer receives via constructor injection,
     * without calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only) -- singleton/service-locator elimination campaign,
     * Phase 5.
     */
    public static function currentConfigService(): \Piwigo\Config\CurrentConfigService
    {
        $currentConfigService = Kernel::container()->get(\Piwigo\Config\CurrentConfigService::class);
        if (! $currentConfigService instanceof \Piwigo\Config\CurrentConfigService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfigService::class);
        }

        return $currentConfigService;
    }

    /**
     * Public, same reason as currentTemplate()/currentConfigService()
     * above: public/admin.php's/install.php's/random.php's own manual
     * construction needs a way to obtain the same container-shared
     * UrlService every other consumer receives via constructor injection
     * -- singleton/service-locator elimination campaign, Phase 6.
     * Resolving the interface (not the concrete UrlService) matches every
     * other real UrlServiceInterface consumer in the codebase.
     */
    public static function urlService(): \Piwigo\Core\UrlServiceInterface
    {
        $urlService = Kernel::container()->get(\Piwigo\Core\UrlServiceInterface::class);
        if (! $urlService instanceof \Piwigo\Core\UrlServiceInterface) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\UrlServiceInterface::class);
        }

        return $urlService;
    }

    /**
     * Resolves the container-shared MailService instance (via its
     * MailerInterface binding) -- singleton/service-locator elimination
     * campaign, Phase 6: MailService's own switchLangTo()/switchLangBack()
     * language-switch stack and template-render cache are real per-request
     * state that must be the SAME instance across every call within one
     * request, not a fresh `new MailService()` per site (see MailService's
     * own class docblock).
     */
    private static function mailService(): \Piwigo\Core\MailerInterface
    {
        $mailer = Kernel::container()->get(\Piwigo\Core\MailerInterface::class);
        if (! $mailer instanceof \Piwigo\Core\MailerInterface) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\MailerInterface::class);
        }

        return $mailer;
    }

    /**
     * Resolves the container-shared instance so that this method's own
     * `start()`/`stop()` writes are visible to every other consumer holding
     * the same shared instance (singleton/service-locator elimination
     * campaign, Phase 3).
     */
    private static function serverTiming(): ServerTiming
    {
        $serverTiming = Kernel::container()->get(ServerTiming::class);
        if (! $serverTiming instanceof ServerTiming) {
            throw new \LogicException('Container returned an unexpected type for ' . ServerTiming::class);
        }

        return $serverTiming;
    }

    /**
     * Resolves the container-shared, immutable instance -- singleton/
     * service-locator elimination campaign, Phase 3.
     */
    private static function wsContext(): \Piwigo\Core\WsContext
    {
        $wsContext = Kernel::container()->get(\Piwigo\Core\WsContext::class);
        if (! $wsContext instanceof \Piwigo\Core\WsContext) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\WsContext::class);
        }

        return $wsContext;
    }

    /**
     * Resolves the container-shared, immutable instance -- singleton/
     * service-locator elimination campaign, Phase 3.
     */
    private static function adminContext(): \Piwigo\Core\AdminContext
    {
        $adminContext = Kernel::container()->get(\Piwigo\Core\AdminContext::class);
        if (! $adminContext instanceof \Piwigo\Core\AdminContext) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\AdminContext::class);
        }

        return $adminContext;
    }

    /**
     * Public (unlike the resolver helpers above): public/admin.php's own
     * legacy-style `new AdminShell(...)` manual construction -- unlike
     * every P22 controller root file, admin.php doesn't route through
     * RequestPipeline's container-backed controller resolution, so it
     * needs a way to obtain the same container-shared instance
     * Controller\Admin\IntroSubController will later receive, without
     * calling Kernel::container() directly itself (arch-tested to
     * Bootstrap/ only). Same "public accessor on this class" shape as
     * pemUrl() above, generalised to a container resolution (singleton/
     * service-locator elimination campaign, Phase 2) -- load-bearing for
     * FilesystemIntegrityChecker::fsQuickCheck()'s own per-request
     * run-once guard, which only actually guards anything if admin.php and
     * IntroSubController share the same instance.
     */
    public static function filesystemIntegrityChecker(): \Piwigo\Admin\Maintenance\FilesystemIntegrityChecker
    {
        $filesystemIntegrityChecker = Kernel::container()->get(\Piwigo\Admin\Maintenance\FilesystemIntegrityChecker::class);
        if (! $filesystemIntegrityChecker instanceof \Piwigo\Admin\Maintenance\FilesystemIntegrityChecker) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Admin\Maintenance\FilesystemIntegrityChecker::class);
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
     * filesystemIntegrityChecker() above (singleton/service-locator
     * elimination campaign, Phase 3). Load-bearing for CoreTabs::
     * setContext()/addCoreTabs()'s own request-scoped bridge, which only
     * works if every writer file and the 'tabsheet_before_select' event
     * registration below share the same instance.
     */
    public static function coreTabs(): CoreTabs
    {
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $coreTabs instanceof CoreTabs) {
            throw new \LogicException('Container returned an unexpected type for ' . CoreTabs::class);
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
     * coreTabs()/filesystemIntegrityChecker() above (singleton/service-
     * locator elimination campaign, Phase 4).
     */
    public static function sessionService(): SessionService
    {
        $sessionService = Kernel::container()->get(SessionService::class);
        if (! $sessionService instanceof SessionService) {
            throw new \LogicException('Container returned an unexpected type for ' . SessionService::class);
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
     * coreTabs()/filesystemIntegrityChecker()/sessionService() above
     * (singleton/service-locator elimination campaign, Phase 4).
     */
    public static function eventDispatcher(): \Piwigo\PluginConfig\EventDispatcher
    {
        $eventDispatcher = Kernel::container()->get(\Piwigo\PluginConfig\EventDispatcher::class);
        if (! $eventDispatcher instanceof \Piwigo\PluginConfig\EventDispatcher) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\PluginConfig\EventDispatcher::class);
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
     * above (singleton/service-locator elimination campaign, Phase 4).
     */
    public static function deploymentPolicy(): \Piwigo\Config\DeploymentPolicy
    {
        $deploymentPolicy = Kernel::container()->get(\Piwigo\Config\DeploymentPolicy::class);
        if (! $deploymentPolicy instanceof \Piwigo\Config\DeploymentPolicy) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\DeploymentPolicy::class);
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
     * coreTabs()/sessionService()/eventDispatcher()/deploymentPolicy() above
     * (singleton/service-locator elimination campaign, Phase 6).
     */
    public static function commentService(): \Piwigo\Comment\CommentService
    {
        $commentService = Kernel::container()->get(\Piwigo\Comment\CommentService::class);
        if (! $commentService instanceof \Piwigo\Comment\CommentService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Comment\CommentService::class);
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
     * above (singleton/service-locator elimination campaign, Phase 6).
     */
    public static function imageService(): \Piwigo\Image\ImageService
    {
        $imageService = Kernel::container()->get(\Piwigo\Image\ImageService::class);
        if (! $imageService instanceof \Piwigo\Image\ImageService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Image\ImageService::class);
        }

        return $imageService;
    }

    /**
     * Same reasoning as commentService()/imageService() above.
     */
    public static function preferencesService(): \Piwigo\Users\PreferencesService
    {
        $preferencesService = Kernel::container()->get(\Piwigo\Users\PreferencesService::class);
        if (! $preferencesService instanceof \Piwigo\Users\PreferencesService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Users\PreferencesService::class);
        }

        return $preferencesService;
    }

    /**
     * Same reasoning as commentService()/imageService() above.
     */
    public static function userService(): \Piwigo\Users\UserService
    {
        $userService = Kernel::container()->get(\Piwigo\Users\UserService::class);
        if (! $userService instanceof \Piwigo\Users\UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Users\UserService::class);
        }

        return $userService;
    }

    /**
     * Same reasoning as commentService()/imageService() above.
     */
    public static function htmlService(): \Piwigo\Html\HtmlService
    {
        $htmlService = Kernel::container()->get(\Piwigo\Html\HtmlService::class);
        if (! $htmlService instanceof \Piwigo\Html\HtmlService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Html\HtmlService::class);
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
}
