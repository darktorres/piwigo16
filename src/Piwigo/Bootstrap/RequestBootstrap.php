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
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
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
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * The legacy per-request bootstrap — the entire orchestration body of
 * include/common.inc.php, ported verbatim (P23 sub-batch 8f-5). That file
 * survives as the thin include seam every entry point already targets; it
 * initializes the plain-data globals ($t2/$debug/$conf/$page/...), pulls
 * in include/config_default.inc.php + local/config/config.inc.php (kept
 * at real top-level scope so a user config file writing arbitrary bare
 * variables still lands in $GLOBALS), requires the autoloader via
 * include/env.inc.php, and then calls the three phases below in order.
 *
 * Why three phases instead of one run(): src/Piwigo/ code may not call
 * define() (arch rule SEC-60), and the legacy bootstrap defines
 * PHPWG_INSTALLED mid-sequence (after the install-redirect check, before
 * the session handler registration that tests defined('PHPWG_INSTALLED'))
 * and PHPWG_DOMAIN/PHPWG_URL/PEM_URL later (after UserBootstrap, before
 * language loading). Those define() calls stay in the seam file, slotted
 * between the phases, preserving the original statement order exactly.
 *
 * Runs before Kernel::boot(): CommonBootstrap::run() (index.php/admin.php
 * and the other P22 roots call it right after the common.inc.php include)
 * still owns the Kernel/DI-container side of the request; this class owns
 * the legacy $GLOBALS side, same division of labor as before this port.
 */
final class RequestBootstrap
{
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
     */
    public static function configure(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        /**
         * @var float
         */
        global $t2;

        // include/common.inc.php captures $t2 = microtime(true) at true
        // top-level scope (before this class is even autoloadable) for
        // maximum precision; this is the one-time handoff into
        // PageState, which every other consumer reads from instead.
        \Piwigo\Core\PageState::current()->requestStart = $t2;

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
        if (! empty($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = addslashes($_SERVER['PATH_INFO']);
        }

        Env::loadEnvFile(PHPWG_ROOT_PATH);
        // Env::applyEnvToConf(array &$conf, string &$prefixeTable)'s
        // second by-ref param is dead here (Legacy Coupling Retirement
        // Track A gap-fill batch G5: every real $prefixeTable read was
        // already the same value as Piwigo\Config\Config::dbPrefix(),
        // synced independently a few lines below via
        // ConfigLoader::applyEnvOverrides(), so consumers were retargeted
        // there directly) -- kept only to satisfy the by-ref signature.
        $prefixeTable_unused = '';
        Env::applyEnvToConf($conf, $prefixeTable_unused);

        // P23 batch 8f-3: wires the static-setter HtmlRenderingInterface
        // consumers (Piwigo\Core class-level fatal-error/access-denied paths
        // that can't take constructor/per-method injection without an
        // unreasonable call-site ripple, same reasoning as
        // Piwigo\Core\Lang::setDefaultLanguageProvider() in finalize()
        // below). Safely post-autoload here: the seam file only calls this
        // class after its include/env.inc.php include, which is what
        // actually requires vendor/autoload.php -- some entry points (e.g.
        // random.php) rely entirely on that include to make every Piwigo\
        // class autoloadable and never require the autoloader themselves
        // beforehand, unlike admin.php/index.php's own explicit up-front
        // require (ordering bug caught live via a random.php smoke test).
        \Piwigo\Auth\AccessControl::setHtmlRenderer(new HtmlService());
        \Piwigo\Core\FilesystemHelper::setHtmlRenderer(new HtmlService());
        Lang::setHtmlRenderer(new HtmlService());
        \Piwigo\Db\MysqliDb::setHtmlRenderer(new HtmlService());
        \Piwigo\Validation\InputValidator::setHtmlRenderer(new HtmlService());
        \Piwigo\Image\SrcImage::setHtmlRenderer(new HtmlService());
        \Piwigo\Image\SrcImage::setImageRepository(new ImageRepository(DbConnection::build()));
        \Piwigo\Config\ConfigDb::setHtmlRenderer(new HtmlService());

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

        if (! file_exists(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . Env::testModeInstalledStamp())) {
            header('Location: install.php');
            exit;
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
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $user;

        // P23 sub-batch 8g-6: the dynamic include of include/dblayer/
        // functions_<dblayer>.inc.php is gone -- the file's 45 facades died
        // with the frozen install/db scripts and its top-level define()s
        // became MysqliDb class constants, so nothing on this path needs it.

        if (\Piwigo\Config\Config::has('show_php_errors') && ! empty(\Piwigo\Config\Config::showPhpErrors())) {
            if (is_scalar(\Piwigo\Config\Config::showPhpErrors())) {
                @ini_set('error_reporting', \Piwigo\Config\Config::showPhpErrors());
            }
            if (\Piwigo\Config\Config::showPhpErrorsOnFrontend()) {
                // Route errors to DevTools (X-PHP-Error-N response headers)
                // instead of inline output, which corrupts JSON/XML/binary
                // responses (see Piwigo\Core\ErrorCollector).
                ErrorCollector::install();
            }
        }

        if (\Piwigo\Config\Config::sessionGcProbability() > 0) {
            @ini_set('session.gc_divisor', 100);
            $gc_probability = \Piwigo\Config\Config::sessionGcProbability();
            @ini_set('session.gc_probability', min($gc_probability, 100));
        }

        SessionBootstrap::register();

        \Piwigo\Core\PageState::current()->executionUuid = SessionService::get()->generateKey(10);

        \Piwigo\Cache\CurrentPersistentCache::set(new PersistentFileCache());

        // Database connection
        try {
            $db_host = \Piwigo\Config\Config::dbHost();
            $db_user = \Piwigo\Config\Config::dbUser();
            $db_password = \Piwigo\Config\Config::dbPassword();
            $db_base = \Piwigo\Config\Config::dbName();
            if (! is_string($db_host) || ! is_string($db_user) || ! is_string($db_password) || ! is_string($db_base)) {
                throw new \Exception("Invalid database configuration: \\Piwigo\Config\Config::dbHost(), 'db_user', 'db_password' and 'db_base' must be strings.");
            }
            \Piwigo\Db\MysqliDb::connect(
                $db_host,
                $db_user,
                $db_password,
                $db_base
            );
        } catch (\Exception $e) {
            \Piwigo\Db\MysqliDb::myError(l10n($e->getMessage()), true);
        }

        \Piwigo\Db\MysqliDb::checkCharset();

        // in Piwigo 15, configuration setting webmaster_id is moved from config files
        // to database. It may be undefined at some point, with Piwigo 15+ scripts and
        // a Piwigo 14 database schema not upgraded yet. Let's avoid any problem.
        $conf['webmaster_id'] ??= 1;

        \Piwigo\Config\ConfigDb::loadConfFromDb();

        // \Piwigo\Config\Config::dataLocation()/'log_dir' lost their specific string types the same
        // way $conf['dblayer'] did above (see comment near the dblayer include); we
        // already validated 'db_password' is a string above ($db_password), so it is
        // reused here rather than re-narrowed.
        $log_data_location = \Piwigo\Config\Config::dataLocation();
        $log_dir = \Piwigo\Config\Config::logDir();
        if (! is_string($log_data_location) || ! is_string($log_dir)) {
            new HtmlService()
                ->fatalError("Invalid \\Piwigo\Config\Config::dataLocation()/'log_dir' configuration: expected strings.");
        }

        \Piwigo\Core\CurrentLogger::set(new Logger([
            'directory' => PHPWG_ROOT_PATH . $log_data_location . $log_dir,
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
                redirect(get_root_url() . 'upgrade.php');
            }
        }

        ImageStdParams::load_from_db();

        session_start();
        PluginLoader::loadPlugins();

        // Shared for every repository/service constructed for the rest of
        // this method -- DbConnection::build() returns a fresh Connection
        // on every call (no internal caching), so constructing one here and
        // reusing it avoids opening a separate physical DB connection per
        // repository, matching the established pattern from the Search/
        // Section/Category domain migrations.
        $conn = DbConnection::build();

        if (! \Piwigo\Config\Config::has('piwigo_installed_version')) {
            \Piwigo\Config\ConfigDb::confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        } elseif (\Piwigo\Config\Config::piwigoInstalledVersion() !== AppInfo::VERSION) {
            // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
            self::activityService($conn)->record('system', ActivitySystem::Core, 'autoupdate', [
                'from_version' => \Piwigo\Config\Config::piwigoInstalledVersion(),
                'to_version' => AppInfo::VERSION,
            ]);
            \Piwigo\Config\ConfigDb::confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        }

        // Check if last major update conf is set if not set it
        if (! \Piwigo\Config\Config::has('last_major_update')) {
            $dbnow = $conn->fetchOne('SELECT NOW()');
            assert(is_string($dbnow));
            \Piwigo\Config\ConfigDb::confUpdateParam('last_major_update', $dbnow, true);
        }

        // 2022-02-25 due to escape on "rank" (becoming a mysql keyword in version 8), the (\Piwigo\Config\Config::all()['order_by'] ?? null) might
        // use a "rank", even if admin/configuration.php should have removed it. We must remove it.
        // TODO remove this data update as soon as 2025 arrives
        $conf_order_by = (\Piwigo\Config\Config::all()['order_by'] ?? null);
        if (is_string($conf_order_by) && (bool) preg_match('/(, )?`rank` ASC/', $conf_order_by)) {
            $order_by = preg_replace('/(, )?`rank` ASC/', '', $conf_order_by);
            if ($order_by == 'ORDER BY ') {
                $order_by = 'ORDER BY id ASC';
            }
            \Piwigo\Config\ConfigDb::confUpdateParam('order_by', $order_by, true);
        }

        // users can have defined a custom order pattern, incompatible with GUI form.
        // Config::orderByCustom()/orderByInsideCategoryCustom() (the typed SCHEMA
        // accessors) model a structured {field,dir}[] shape that no real code
        // writes -- these are actually stored as raw "ORDER BY ..." SQL fragments
        // (see ConfigDb::loadConfFromDb()'s own docblock), so read/write them via
        // the untyped bag like ConfigService::confGetParam() does for keys
        // without a compatible accessor.
        if (\Piwigo\Config\Config::has('order_by_custom')) {
            $conf['order_by'] = \Piwigo\Config\Config::all()['order_by_custom'] ?? null;
            \Piwigo\Config\Config::override('order_by', $conf['order_by']);
        }
        if (\Piwigo\Config\Config::has('order_by_inside_category_custom')) {
            $conf['order_by_inside_category'] = \Piwigo\Config\Config::all()['order_by_inside_category_custom'] ?? null;
            \Piwigo\Config\Config::override('order_by_inside_category', $conf['order_by_inside_category']);
        }

        if (\Piwigo\Core\LoungeMaintenance::needsEmptying()) {
            new ImageService(new ImageRepository($conn), self::activityService($conn))
                ->emptyLounge();
        }

        // Piwigo\Bootstrap\UserBootstrap::initialize() sets these by calling
        // build_user()/AuthService::autoLogin()/auth_key_login(), the latter
        // mutating the $user global via its own `global` declaration and
        // PageState::current()'s authKeyInvalid/notifyApiKeyExpiration
        // (Legacy Coupling Retirement Track A batch A5.2i) -- $user's keys
        // are always overwritten before use in every real path; PageState's
        // own typed defaults (false/null) already cover the "no auth key
        // presented" case, so no explicit reset is needed here.
        $user['id'] = \Piwigo\Config\Config::guestId();
        $user['email'] = null;
        $user['theme'] = '';

        new UserBootstrap()
            ->initialize();

        // The original file followed this call with a get_defined_vars()
        // based re-read of $user/$page -- pure PHPStan narrowing
        // scaffolding (a self-assignment at runtime), dropped in this port:
        // inside a method the `global` declaration above already carries
        // array<string, mixed>, which is all the old dance re-established.
        // ($page itself is gone from this method as of batch A5.2i --
        // auth_key_invalid/notify_api_key_expiration moved to PageState.)

        // Legacy Coupling Retirement Track A batch A3: CurrentUser is the
        // real target every retargeted consumer reads from now; `global
        // $user` stays live alongside it (dual-write) until every consumer
        // is retargeted off the raw global, matching CurrentTemplate's own
        // migration shape (Track A batch A1). Synced here (real
        // id/status/etc. from UserBootstrap::initialize()'s build_user()
        // call, just above) AND again in finalize() below, since the
        // guest's localized username is only known once the language is
        // loaded -- not a redundant call, a second real mutation point.
        CurrentUser::set(User::fromUserArray($user));
    }

    /**
     * The PEM_URL value the seam file define()s right after connect() —
     * kept as a method so the config-conditional lives on the class, not
     * in the seam (which keeps only the define() call itself, SEC-60).
     */
    public static function pemUrl(): string
    {

        if (\Piwigo\Config\Config::has('alternative_pem_url') and \Piwigo\Config\Config::alternativePemUrl() != '') {
            $alternative_pem_url = \Piwigo\Config\Config::alternativePemUrl();
            return is_scalar($alternative_pem_url) ? (string) $alternative_pem_url : '';
        }

        return 'https://' . PHPWG_DOMAIN . '/ext';
    }

    /**
     * Phase 3 — language loading, auth-key messages, template creation,
     * no-photo-yet, maintenance/upgrade notices, request filter, and the
     * default event-handler registrations (include/common.inc.php's former
     * tail, from the Lang wiring through trigger_notify('init')).
     */
    public static function finalize(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $user;
        global $template;
        global $filter;

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
        if (\Piwigo\Auth\AccessControl::isAdmin() || (defined('IN_ADMIN') and IN_ADMIN)) {
            Lang::load('admin.lang');
            // Add language for temporary strings for new popup, from piwigo 15
            Lang::load('whats_new_' . \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.lang');
        }
        trigger_notify('loading_lang');
        Lang::load('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
            'no_fallback' => true,
            'local' => true,
        ]);

        // only now we can set the localized username of the guest user (and not in
        // UserBootstrap::initialize())
        if (\Piwigo\Auth\AccessControl::isAGuest()) {
            $user['username'] = l10n('guest');
            // Second CurrentUser sync point -- see connect()'s own comment.
            // isAGuest() itself already reads CurrentUser (synced there with
            // the pre-localization username), so only the localized-username
            // case needs a second sync; the non-guest path never mutates
            // $user again after connect()'s sync.
            CurrentUser::set(User::fromUserArray($user));
        }

        $pageState = \Piwigo\Core\PageState::current();

        // in case an auth key was provided and is no longer valid, we must wait to
        // be here, with language loaded, to prepare the message
        if ($pageState->authKeyInvalid) {
            $pageState->addError(
                l10n('Your authentication key is no longer valid.')
              . sprintf(' <a href="%s">%s</a>', get_root_url() . 'identification.php', l10n('Login'))
            );
        }

        // check if we need to notified user about api_key expiration
        $notify_api_key_expiration = $pageState->notifyApiKeyExpiration;
        if ($notify_api_key_expiration !== null) {
            // build_user() always populates 'username'/'email' from the database (see
            // getuserdata()), so these are real strings on every path that reaches
            // here (an auth key was just validated); the is_string() checks are a
            // defensive narrowing, not expected to ever fall back.
            $notify_username = $user['username'];
            $notify_username = is_string($notify_username) ? $notify_username : '';
            $notify_email = $user['email'];
            $notify_email = is_string($notify_email) ? $notify_email : '';
            $apiKeyRepo = new \Piwigo\Auth\ApiKeyRepository($conn);
            $is_mail_send = new \Piwigo\Auth\ApiKeyService(new MailService(), $apiKeyRepo, new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($conn)))
                ->notifyExpiration($notify_username, $notify_email, $notify_api_key_expiration['days_left']);

            if ($is_mail_send) {
                $notify_dbnow = $notify_api_key_expiration['dbnow'];
                $notify_auth_key = $notify_api_key_expiration['auth_key'];
                $notify_user_id = $user['id'];
                $apiKeyRepo->updateLastNotifiedOn(
                    is_string($notify_auth_key) ? $notify_auth_key : '',
                    is_numeric($notify_user_id) ? (int) $notify_user_id : 0,
                    is_string($notify_dbnow) ? $notify_dbnow : '',
                );
            }

            $pageState->notifyApiKeyExpiration = null;
        }

        // template instance
        if (defined('IN_ADMIN') and IN_ADMIN) {// Admin template
            // getParam() has no return type declaration (its own value
            // comes from the equally-untyped global $user['preferences'][$param]),
            // so its return is inferred as mixed; narrow to the same 'clear'
            // fallback already passed as the default value.
            $admin_theme = new \Piwigo\Users\PreferencesService(new UserRepository($conn))
                ->getParam('admin_theme', 'clear');
            $admin_theme = is_string($admin_theme) ? $admin_theme : 'clear';
            $template = new Template(PHPWG_ROOT_PATH . 'admin/themes', $admin_theme);
        } else { // Classic template
            $theme = $user['theme'];
            if (\Piwigo\Core\PageFilterHelper::scriptBasename() != 'ws' and \Piwigo\Core\DeviceHelper::mobileTheme()) {
                $theme = \Piwigo\Config\Config::mobilTheme();
            }
            $template = new Template(PHPWG_ROOT_PATH . 'themes', $theme);
        }

        // Legacy Coupling Retirement Track A: CurrentTemplate is the real
        // target every retargeted consumer reads from now; `global
        // $template` stays live alongside it (dual-write) until every
        // consumer is retargeted off the raw global, matching how
        // CurrentUser/PageState's own attachGlobals() bridges worked during
        // their migration.
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
            // when it decides to take over the page.
            new NoPhotoYetRenderer($conn)
                ->render();
        }

        $user_internal_status = $user['internal_status'] ?? null;
        if (is_array($user_internal_status) && ($user_internal_status['guest_must_be_guest'] ?? false) === true) {
            $pageState->addHeaderMessage(l10n('Bad status for user "guest", using default status. Please notify the webmaster.'));
        }

        if (\Piwigo\Config\Config::galleryLocked()) {
            $pageState->addHeaderMessage(l10n('The gallery is locked for maintenance. Please, come back later.'));

            if (\Piwigo\Core\PageFilterHelper::scriptBasename() != 'identification' and ! \Piwigo\Auth\AccessControl::isAdmin()) {
                new HtmlService()
                    ->setStatusHeader(503, 'Service Unavailable');
                @header('Retry-After: 900');
                header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
                echo '<a href="' . get_absolute_root_url(false) . 'identification.php">' . l10n('The gallery is locked for maintenance. Please, come back later.') . '</a>';
                echo str_repeat(' ', 512); // IE6 doesn't error output if below a size
                exit();
            }
        }

        if (\Piwigo\Config\Config::checkUpgradeFeed()) {
            // Formerly `include_once .../functions_upgrade.php` + bare
            // check_upgrade_feed() (migrated to a real class, P23 sub-batch
            // 8f-6; the legacy file now only carries frozen-script
            // delegates this path doesn't need).
            if (\Piwigo\Admin\Install\UpgradeService::checkUpgradeFeed($conn)) {
                $pageState->addHeaderMessage('Some database upgrades are missing, '
                  . '<a href="' . get_absolute_root_url(false) . 'upgrade_feed.php">upgrade now</a>');
            }
        }

        if ($pageState->headerMessages !== []) {
            $template->assign('header_msgs', $pageState->headerMessages);
            $pageState->headerMessages = [];
        }

        if (! empty(\Piwigo\Config\Config::filterPages()) and (bool) \Piwigo\Core\PageFilterHelper::getFilterPageValue('used')) {
            // Formerly a conditional `include PHPWG_ROOT_PATH .
            // 'include/filter.inc.php';` (deleted, P23 sub-batch 8f-5).
            new FilterService()
                ->initializeFromRequest();
        } else {
            $filter['enabled'] = false;
        }

        if (\Piwigo\Config\Config::has('header_notes') && is_array(\Piwigo\Config\Config::headerNotes())) {
            $pageState->headerNotes = array_merge($pageState->headerNotes, \Piwigo\Config\Config::headerNotes());
        }

        // default event handlers
        add_event_handler('render_category_literal_description', new HtmlService()->renderCategoryLiteralDescription(...));
        if (! \Piwigo\Config\Config::allowHtmlDescriptions()) {
            add_event_handler('render_category_description', new HtmlService()->pwgNl2br(...));
        }
        add_event_handler('render_comment_content', new HtmlService()->renderCommentContent(...));
        add_event_handler('render_comment_author', 'strip_tags');
        // Was registered as the bare string 'str2url' -- dead since some earlier
        // phase migrated the global function to StringHelper::str2url() without
        // updating this one string-literal reference (add_event_handler() doesn't
        // get caught by a normal call-site grep). Dormant until P23 batch 8d's
        // Tags sub-batch's live curl verification actually exercised a real
        // trigger_change('render_tag_url', ...) call and hit "Event handler ...
        // is not callable" -- every prior tag-creation activity-log row in the
        // fixture is static SQL data, never actually round-tripped through this
        // handler.
        add_event_handler('render_tag_url', StringHelper::str2url(...));
        add_event_handler('blockmanager_register_blocks', new HtmlService()->registerDefaultMenubarBlocks(...));
        // Relocated from include/functions_comment.inc.php (deleted, P23 batch 8c)
        // -- that file's own top-level add_event_handler() call only ever ran via
        // its include_once at each real caller, all of which now construct
        // CommentService directly instead, so this registration has to live
        // somewhere that always executes. checkForSpam() is an instance method
        // (unlike UploadService's static upload_file handlers below), hence the
        // bound first-class-callable form rather than a bare [Class::class, 'method']
        // array.
        add_event_handler('user_comment_check', new CommentService(new CommentRepository($conn), new EphemeralKeyService(), new MailService(), new HtmlService())->checkForSpam(...));
        // Relocated from include/functions_user.inc.php (deleted, P23 batch 8d) --
        // same reasoning as user_comment_check above: every real caller of
        // AuthService::tryLogUser() now constructs AuthService directly instead of
        // including the old file, so this registration has to live somewhere that
        // always executes. pwgLogin() is a bound instance method, same
        // first-class-callable shape as checkForSpam() above.
        add_event_handler('try_log_user', new AuthService(
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
        add_event_handler('upload_image_resize', 'pwg_image_resize');
        add_event_handler('upload_thumbnail_resize', 'pwg_image_resize');
        add_event_handler('upload_file', UploadService::uploadFilePdf(...));
        add_event_handler('upload_file', UploadService::uploadFileHeic(...));
        add_event_handler('upload_file', UploadService::uploadFileTiff(...));
        add_event_handler('upload_file', UploadService::uploadFileVideo(...));
        add_event_handler('upload_file', UploadService::uploadFilePsd(...));
        add_event_handler('upload_file', UploadService::uploadFileEps(...));
        if (! empty(\Piwigo\Config\Config::originalUrlProtection())) {
            add_event_handler('get_element_url', new HtmlService()->getElementUrlProtectionHandler(...));
            add_event_handler('get_src_image_url', new HtmlService()->getSrcImageUrlProtectionHandler(...));
        }
        trigger_notify('init');
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
