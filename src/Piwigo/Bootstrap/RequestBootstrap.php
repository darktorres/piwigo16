<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\PluginLoader;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Mail\MailService;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * The per-request bootstrap. Used to be the orchestration body of
 * include/common.inc.php (ported verbatim, P23 sub-batch 8f-5), with that
 * file kept on as a thin include seam every entry point targeted. The seam
 * is gone (P23 batch: include/+admin/ deletion) — bootEntryPoint() below is
 * now the real, sole entry point every root `public/*.php` file calls
 * directly, and it does the one thing the seam file's own top-level scope
 * used to be needed for (the bare-variable `local/config/config.inc.php`
 * include) via Piwigo\Config\ConfigLoader::applyLocalFileOverrides()
 * instead, called from inside configure() below.
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
 * standalone-callable contract `tests/Unit/Bootstrap/
 * CommonBootstrapTest.php` exercises directly. The former PHPWG_DOMAIN/
 * PHPWG_URL/PEM_URL define()s that used to sit here too (after
 * UserBootstrap, before language loading) are gone entirely (Legacy
 * Coupling Retirement gap-closure, entry-shell define()/include round,
 * Part 0b) -- every real reader now goes through Piwigo\Core\AppInfo::
 * DOMAIN/URL or this class's own pemUrl(), neither of which needs
 * seam-file sequencing at all.
 *
 * Legacy Coupling Retirement Phase 8, 8a (the "boot-first" fix):
 * configure() now calls Kernel::boot($paths) as its own first statement --
 * genuinely load-bearing, not just tidiness, since connect() below has a
 * real production call site (the "database needs upgrading" redirect)
 * that used to run before CommonBootstrap::run() (index.php/admin.php and
 * the other P22 roots call it right after the common.inc.php include,
 * previously the *only* Kernel::boot() call on this path) ever executed.
 * CommonBootstrap::run()'s own Kernel::boot() call is now a harmless
 * idempotent no-op on this path (Kernel::boot() itself guards on
 * self::$booted) -- kept there for the standalone-callable contract
 * tests/Unit/Bootstrap/CommonBootstrapTest.php exercises directly.
 *
 * Legacy Coupling Retirement Phase 8, 8d: every real ConfigDb:: call in
 * this file has been retargeted onto a container-resolved ConfigService
 * (connect() resolves it once and reuses the same instance for all 3
 * writes) -- safe only because 8c first retargeted every real
 * `$conf[...]` read out of this file onto Config:: accessors;
 * ConfigService::loadConfFromDb() only ever writes Config::override(),
 * never $conf, unlike ConfigDb::loadConfFromDb()'s dual-write.
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
     */
    public static function bootEntryPoint(Paths $paths): void
    {
        $t2 = microtime(true);

        try {
            self::configure($paths, $t2);
            \Piwigo\Core\InstallationFlag::mark();
            self::connect();
            self::finalize();
        } catch (\Piwigo\Http\ResponseReadyException $e) {
            new \Piwigo\Http\ResponseEmitter()
                ->emit($e->response());
            exit;
        }
    }

    /**
     * Phase 1 — superglobal sanitization, env-file loading, static-setter
     * wiring, Config seeding, install-sentinel check
     * (include/common.inc.php's former lines up to the install redirect).
     *
     * The seam file defines PHPWG_INSTALLED right after this returns.
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
    public static function configure(Paths $paths, float $requestStart): void
    {
        Kernel::boot($paths);

        // Legacy Coupling Retirement Phase 8, 8h: the true start of each
        // request -- resets ActivityService::record()'s "was a real user
        // resolved this request" flag before anything else can mark it
        // (UserBootstrap::initialize()). Monotonic within a request, so it
        // needs a real reset here rather than relying on
        // CurrentUser::reset() (arch-test-restricted to tests/).
        \Piwigo\Users\CurrentUser::resetRealUserResolvedFlag();

        // include/common.inc.php captures $requestStart = microtime(true)
        // at true top-level scope (before this class is even autoloadable)
        // for maximum precision, and passes it straight through as a
        // parameter -- this is the one-time handoff into PageState, which
        // every other consumer reads from instead.
        \Piwigo\Core\PageState::current()->requestStart = $requestStart;

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
        // consumers (Piwigo\Core class-level fatal-error/access-denied paths
        // that can't take constructor/per-method injection without an
        // unreasonable call-site ripple, same reasoning as
        // Piwigo\Core\Lang::setDefaultLanguageProvider() in finalize()
        // below). AccessControl::setRedirectService() (Legacy Coupling
        // Retirement Phase 4b) follows right alongside its own
        // setHtmlRenderer() call, same reasoning. Safely post-autoload here: the seam file only calls this
        // class after its include/env.inc.php include, which is what
        // actually requires vendor/autoload.php -- some entry points (e.g.
        // random.php) rely entirely on that include to make every Piwigo\
        // class autoloadable and never require the autoloader themselves
        // beforehand, unlike admin.php/index.php's own explicit up-front
        // require (ordering bug caught live via a random.php smoke test).
        \Piwigo\Auth\AccessControl::setHtmlRenderer(new HtmlService());
        \Piwigo\Auth\AccessControl::setRedirectService(new RedirectService());
        \Piwigo\Core\FilesystemHelper::setHtmlRenderer(new HtmlService());
        Lang::setHtmlRenderer(new HtmlService());
        \Piwigo\Validation\InputValidator::setHtmlRenderer(new HtmlService());
        \Piwigo\Image\SrcImage::setHtmlRenderer(new HtmlService());
        \Piwigo\Image\SrcImage::setImageRepository(new ImageRepository(DbConnection::build()));
        \Piwigo\Image\SrcImage::setUrlService(new UrlService(new HtmlService()));
        \Piwigo\Image\DerivativeImage::setUrlService(new UrlService(new HtmlService()));
        \Piwigo\Template\ScriptLoader::setUrlService(new UrlService(new HtmlService()));

        // Piwigo\Db\Tables::*()/other Piwigo\Config\Config::* accessors used
        // further down in this bootstrap's own body (not just by code that
        // runs after full boot) read Config's static state --
        // CommonBootstrap::run() (index.php, after this whole bootstrap has
        // already executed) is normally what seeds it via these same two
        // calls, too late for callers here. Both are idempotent (verified:
        // re-running never overwrites an already-set key), so calling them
        // again from CommonBootstrap::run() right after is safe.
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
     * The seam file defines PHPWG_DOMAIN/PHPWG_URL/PEM_URL right after
     * this returns (the original defined them at exactly this point,
     * between UserBootstrap and the language loading in finalize()).
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
        // trigger_error(E_USER_ERROR)+throw sequence (see
        // ErrorCollector::installIfConfigured()'s own docblock).
        ErrorCollector::installIfConfigured();

        if (\Piwigo\Config\Config::sessionGcProbability() > 0) {
            @ini_set('session.gc_divisor', 100);
            $gc_probability = \Piwigo\Config\Config::sessionGcProbability();
            @ini_set('session.gc_probability', min($gc_probability, 100));
        }

        SessionBootstrap::register();

        \Piwigo\Core\PageState::current()->executionUuid = SessionService::get()->generateKey(10);

        \Piwigo\Cache\CurrentPersistentCache::set(new PersistentFileCache());

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
        $db_password = \Piwigo\Config\Config::dbPassword();
        try {
            $conn->getNativeConnection();
        } catch (\Exception $e) {
            new HtmlService()
                ->fatalError(Lang::t($e->getMessage()));
        }

        // Legacy Coupling Retirement Phase 8, 8d: safe now that 8c retargeted
        // every $conf[...] read out of this file -- ConfigService::loadConfFromDb()
        // only ever writes Config::override(), never global $conf, unlike
        // ConfigDb::loadConfFromDb()'s dual-write. CurrentConfigService::set()
        // here lets CommonBootstrap::run() (which runs later in the same
        // request) reuse this same instance instead of resolving+loading a
        // second time -- see its own docblock.
        $configService = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\ConfigService::class);
        if (! $configService instanceof \Piwigo\Config\ConfigService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\ConfigService::class);
        }
        \Piwigo\Config\CurrentConfigService::set($configService);
        $configService->loadConfFromDb();

        $log_data_location = \Piwigo\Config\Config::dataLocation();
        $log_dir = \Piwigo\Config\Config::logDir();

        \Piwigo\Core\CurrentLogger::set(new Logger([
            'directory' => CurrentPaths::get()->root . $log_data_location . $log_dir,
            'severity' => \Piwigo\Config\Config::logLevel(),
            // we use an hashed filename to prevent direct file access, and we salt with
            // the db_password instead of secret_key because the log must be usable in i.php
            // (secret_key is in the database)
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $db_password) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => \Piwigo\Config\Config::logArchiveDays(),
        ]));

        if (! \Piwigo\Config\Config::checkUpgradeFeed()) {
            if (! \Piwigo\Config\Config::has('piwigo_db_version') or \Piwigo\Config\Config::piwigoDbVersion() !== \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION)) {
                new RedirectService()
                    ->redirect(new UrlService(new HtmlService())->getRootUrl() . 'upgrade.php');
            }
        }

        ImageStdParams::load_from_db();

        session_start();
        PluginLoader::loadPlugins();

        if (! \Piwigo\Config\Config::has('piwigo_installed_version')) {
            $configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        } elseif (\Piwigo\Config\Config::piwigoInstalledVersion() !== AppInfo::VERSION) {
            // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
            self::activityService($conn)->record('system', ActivitySystem::Core, 'autoupdate', [
                'from_version' => \Piwigo\Config\Config::piwigoInstalledVersion(),
                'to_version' => AppInfo::VERSION,
            ]);
            $configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        }

        // Check if last major update conf is set if not set it
        if (! \Piwigo\Config\Config::has('last_major_update')) {
            $dbnow = $conn->fetchOne('SELECT NOW()');
            assert(is_string($dbnow));
            $configService->confUpdateParam('last_major_update', $dbnow, updateGlobal: true);
        }

        // users can have defined a custom order pattern, incompatible with GUI form.
        // Config::orderByCustom()/orderByInsideCategoryCustom() (the typed SCHEMA
        // accessors) model a structured {field,dir}[] shape that no real code
        // writes -- these are actually stored as raw "ORDER BY ..." SQL fragments
        // (see ConfigDb::loadConfFromDb()'s own docblock), so read/write them via
        // the untyped bag like ConfigService::confGetParam() does for keys
        // without a compatible accessor.
        if (\Piwigo\Config\Config::has('order_by_custom')) {
            \Piwigo\Config\Config::override('order_by', \Piwigo\Config\Config::all()['order_by_custom'] ?? null);
        }
        if (\Piwigo\Config\Config::has('order_by_inside_category_custom')) {
            \Piwigo\Config\Config::override('order_by_inside_category', \Piwigo\Config\Config::all()['order_by_inside_category_custom'] ?? null);
        }

        if (\Piwigo\Core\LoungeMaintenance::needsEmptying()) {
            new ImageService(new ImageRepository($conn), self::activityService($conn))
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
        new UserBootstrap(new RedirectService(), new UrlService(new HtmlService()))
            ->initialize();
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

        if (\Piwigo\Config\Config::has('alternative_pem_url') and \Piwigo\Config\Config::alternativePemUrl() !== '') {
            return \Piwigo\Config\Config::alternativePemUrl();
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
            new UserRepository($conn),
            new GroupRepository($conn),
            new MailService(),
            self::activityService($conn),
            new HtmlService(),
            $conn,
        ));
        Lang::load('common.lang');
        if (\Piwigo\Auth\AccessControl::isAdmin() || \Piwigo\Core\AdminContext::isActive()) {
            Lang::load('admin.lang');
            // Add language for temporary strings for new popup, from piwigo 15
            Lang::load('whats_new_' . \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.lang');
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loading_lang');
        Lang::load('lang', CurrentPaths::get()->siteLocal, [
            'no_fallback' => true,
            'local' => true,
        ]);

        // only now we can set the localized username of the guest user (and not in
        // UserBootstrap::initialize())
        if (\Piwigo\Auth\AccessControl::isAGuest()) {
            // Second CurrentUser sync point (the first is inside
            // UserBootstrap::initialize()) -- isAGuest() itself already
            // reads CurrentUser (synced there with the pre-localization
            // username), so only the localized-username case needs a
            // second sync; the non-guest path never mutates CurrentUser
            // again after initialize()'s own sync.
            CurrentUser::set(CurrentUser::get()->withUsername(Lang::t('guest')));
        }

        $pageState = \Piwigo\Core\PageState::current();

        // in case an auth key was provided and is no longer valid, we must wait to
        // be here, with language loaded, to prepare the message
        if ($pageState->authKeyInvalid) {
            $pageState->addError(
                Lang::t('Your authentication key is no longer valid.')
              . sprintf(' <a href="%s">%s</a>', new UrlService(new HtmlService())->getRootUrl() . 'identification.php', Lang::t('Login'))
            );
        }

        // check if we need to notified user about api_key expiration
        $notify_api_key_expiration = $pageState->notifyApiKeyExpiration;
        if ($notify_api_key_expiration !== null) {
            $notify_username = CurrentUser::get()->username;
            $notify_email = CurrentUser::get()->email;
            $apiKeyRepo = new \Piwigo\Auth\ApiKeyRepository($conn);
            $is_mail_send = new \Piwigo\Auth\ApiKeyService(new MailService(), $apiKeyRepo, new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($conn)), new UrlService(new HtmlService()))
                ->notifyExpiration($notify_username, $notify_email, $notify_api_key_expiration['days_left']);

            if ($is_mail_send) {
                $notify_dbnow = $notify_api_key_expiration['dbnow'];
                $notify_auth_key = $notify_api_key_expiration['auth_key'];
                $apiKeyRepo->updateLastNotifiedOn(
                    is_string($notify_auth_key) ? $notify_auth_key : '',
                    CurrentUser::get()->id,
                    is_string($notify_dbnow) ? $notify_dbnow : '',
                );
            }

            $pageState->notifyApiKeyExpiration = null;
        }

        // template instance
        if (\Piwigo\Core\AdminContext::isActive()) {// Admin template
            // getParam() has no return type declaration (its own value
            // comes from CurrentUser::get()->preferences[$param], an
            // untyped array<string, mixed>), so its return is inferred as
            // mixed; narrow to the same Config::adminTheme() fallback
            // already passed as the default value.
            $admin_theme = new \Piwigo\Users\PreferencesService(new UserRepository($conn))
                ->getParam('admin_theme', \Piwigo\Config\Config::adminTheme());
            $admin_theme = is_string($admin_theme) ? $admin_theme : \Piwigo\Config\Config::adminTheme();
            $template = new Template(CurrentPaths::get()->root . 'themes/admin', $admin_theme);
        } else { // Classic template
            $theme = CurrentUser::get()->theme;
            if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'ws' and \Piwigo\Core\DeviceHelper::mobileTheme()) {
                $theme = \Piwigo\Config\Config::mobilTheme();
            }
            $template = new Template(CurrentPaths::get()->root . 'themes', $theme);
        }

        // Legacy Coupling Retirement Track A / Phase 2 global-residual
        // sweep: CurrentTemplate is now the sole target -- the former
        // `global $template;` dual-write bridge was retired once a
        // repo-wide scan confirmed every other consumer had already been
        // retargeted onto CurrentTemplate::get() (this was the last site).
        \Piwigo\Template\CurrentTemplate::set($template);

        // P23 batch 8f-4: SrcImage (L2aCoreDomain) reads theme conf through
        // Piwigo\Core\ThemeConfProviderInterface (implemented by Template)
        // instead of the deleted get_themeconf() free function's
        // $GLOBALS['template'] read. Wired here, not in the setHtmlRenderer
        // block in configure() -- the provider IS the request's $template
        // instance, which only exists from this point on (the deleted free
        // function had the exact same availability window).
        \Piwigo\Image\SrcImage::setThemeConfProvider($template);

        if (! \Piwigo\Config\Config::has('no_photo_yet')) {
            // Formerly include/no_photo_yet.inc.php, a seam of exactly this
            // one call (deleted, P23 sub-batch 8f-5). render() exits itself
            // when it decides to take over the page. CurrentConfigService::get()
            // reuses the instance connect() already resolved earlier in the
            // same request (Legacy Coupling Retirement Phase 8, 8d).
            new NoPhotoYetRenderer($conn, \Piwigo\Config\CurrentConfigService::get(), new RedirectService(), new UrlService(new HtmlService()), CurrentPaths::get())
                ->render();
        }

        $user_internal_status = CurrentUser::get()->internalStatus;
        if (($user_internal_status['guest_must_be_guest'] ?? false) === true) {
            $pageState->addHeaderMessage(Lang::t('Bad status for user "guest", using default status. Please notify the webmaster.'));
        }

        if (\Piwigo\Config\Config::galleryLocked()) {
            $pageState->addHeaderMessage(Lang::t('The gallery is locked for maintenance. Please, come back later.'));

            if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'identification' and ! \Piwigo\Auth\AccessControl::isAdmin()) {
                // Workstream C3, catch point 1: throws instead of the
                // former raw header()+echo+exit() -- caught in
                // include/common.inc.php, the one seam both dispatch
                // contexts that reach this code (pipeline-routed root
                // files and admin.php/admin/popuphelp.php) include.
                $body = '<a href="' . new UrlService(new HtmlService())->getAbsoluteRootUrl(false) . 'identification.php">' . Lang::t('The gallery is locked for maintenance. Please, come back later.') . '</a>';
                $body .= str_repeat(' ', 512); // IE6 doesn't error output if below a size
                throw new \Piwigo\Http\ResponseReadyException(\Piwigo\Http\ResponseFactory::raw($body, [
                    'Retry-After' => '900',
                    'Content-Type' => 'text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset(),
                ], 503));
            }
        }

        if (\Piwigo\Config\Config::checkUpgradeFeed()) {
            // Formerly `include_once .../functions_upgrade.php` + bare
            // check_upgrade_feed() (migrated to a real class, P23 sub-batch
            // 8f-6; the legacy file now only carries frozen-script
            // delegates this path doesn't need).
            if (\Piwigo\Admin\Install\UpgradeService::checkUpgradeFeed($conn)) {
                $pageState->addHeaderMessage('Some database upgrades are missing, '
                  . '<a href="' . new UrlService(new HtmlService())->getAbsoluteRootUrl(false) . 'upgrade_feed.php">upgrade now</a>');
            }
        }

        if ($pageState->headerMessages !== []) {
            $template->assign('header_msgs', $pageState->headerMessages);
            $pageState->headerMessages = [];
        }

        if (\Piwigo\Config\Config::filterPages() !== [] and (bool) \Piwigo\Core\PageFilterHelper::getFilterPageValue('used')) {
            // Formerly a conditional `include PHPWG_ROOT_PATH .
            // 'include/filter.inc.php';` (deleted, P23 sub-batch 8f-5).
            new FilterService($conn)
                ->initializeFromRequest();
        } else {
            \Piwigo\Core\FilterState::set(false);
        }

        if (\Piwigo\Config\Config::has('header_notes')) {
            $pageState->headerNotes = array_merge($pageState->headerNotes, \Piwigo\Config\Config::headerNotes());
        }

        // default event handlers
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('render_category_literal_description', new HtmlService()->renderCategoryLiteralDescription(...));
        if (! \Piwigo\Config\Config::allowHtmlDescriptions()) {
            \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('render_category_description', new HtmlService()->pwgNl2br(...));
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('render_comment_content', new HtmlService()->renderCommentContent(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('render_comment_author', 'strip_tags');
        // Was registered as the bare string 'str2url' -- dead since some earlier
        // phase migrated the global function to StringHelper::str2url() without
        // updating this one string-literal reference (add_event_handler() doesn't
        // get caught by a normal call-site grep). Dormant until P23 batch 8d's
        // Tags sub-batch's live curl verification actually exercised a real
        // trigger_change('render_tag_url', ...) call and hit "Event handler ...
        // is not callable" -- every prior tag-creation activity-log row in the
        // fixture is static SQL data, never actually round-tripped through this
        // handler.
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('render_tag_url', StringHelper::str2url(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('blockmanager_register_blocks', new HtmlService()->registerDefaultMenubarBlocks(...));
        // Relocated from include/functions_comment.inc.php (deleted, P23 batch 8c)
        // -- that file's own top-level add_event_handler() call only ever ran via
        // its include_once at each real caller, all of which now construct
        // CommentService directly instead, so this registration has to live
        // somewhere that always executes. checkForSpam() is an instance method
        // (unlike UploadService's static upload_file handlers below), hence the
        // bound first-class-callable form rather than a bare [Class::class, 'method']
        // array.
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('user_comment_check', new CommentService(new CommentRepository($conn), new EphemeralKeyService(), new MailService(), new HtmlService(), new UrlService(new HtmlService()))->checkForSpam(...));
        // Relocated from include/functions_user.inc.php (deleted, P23 batch 8d) --
        // same reasoning as user_comment_check above: every real caller of
        // AuthService::tryLogUser() now constructs AuthService directly instead of
        // including the old file, so this registration has to live somewhere that
        // always executes. pwgLogin() is a bound instance method, same
        // first-class-callable shape as checkForSpam() above.
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('try_log_user', new AuthService(
            new AuthRepository($conn),
            self::activityService($conn),
            new HtmlService(),
            new PasswordService(new PasswordRepository($conn)),
            new CookieService(),
        )->pwgLogin(...));
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
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_image_resize', 'pwg_image_resize');
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_thumbnail_resize', 'pwg_image_resize');
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_file', UploadService::uploadFilePdf(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_file', UploadService::uploadFileHeic(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_file', UploadService::uploadFileTiff(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_file', UploadService::uploadFileVideo(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_file', UploadService::uploadFilePsd(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('upload_file', UploadService::uploadFileEps(...));
        if (\Piwigo\Config\Config::originalUrlProtection() !== '') {
            \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('get_element_url', new HtmlService()->getElementUrlProtectionHandler(...));
            \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('get_src_image_url', new HtmlService()->getSrcImageUrlProtectionHandler(...));
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('init');
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
        return new ActivityService(new ActivityRepository($conn));
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
