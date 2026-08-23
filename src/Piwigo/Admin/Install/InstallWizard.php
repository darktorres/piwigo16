<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\AdminUiHelper;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Install\Projection\InstallView;
use Piwigo\Admin\Install\Request\InstallWizardRequest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\Projection\MailArgs;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\DbInfo;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Http\HttpClientService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Http\SessionBootstrap;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionHandler;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * install.php's orchestration. The install.php entry shell keeps only
 * bootstrap (autoload, env/config includes) and drives this wizard:
 * boot() -> [POST: analyzeForm(); no errors -> shell marks
 * InstallationFlag active -> performInstall()] -> render().
 *
 * install.php calls InstallBootstrap::boot($paths) before this wizard is
 * constructed, so the DI container is available throughout. Every
 * CurrentConfigService::get() call here is safe because boot() already
 * builds a ConfigService directly (see that method's own docblock on why
 * this isn't InstallBootstrap::activateConfigService()) and the config
 * table exists by then (the Doctrine Migrations baseline/config.sql runs
 * immediately before).
 */
final class InstallWizard
{
    private string $dbhost = 'localhost';

    private string $dbuser = '';

    private string $dbpasswd = '';

    private string $dbname = '';

    private string $dblayer = 'mysqli';

    private ?int $dbport = null;

    private string $adminName = '';

    private string $adminPass1 = '';

    private string $adminPass2 = '';

    private string $adminMail = '';

    private bool $isNewsletterSubscribe = true;

    /**
     * @var array<int, string>
     */
    private array $infos = [];

    /**
     * @var array<int, string>
     */
    private array $errors = [];

    /**
     * @var array<string, LanguageScanRow>
     */
    private array $fsLanguages;

    /**
     * Built by analyzeForm() -> InstallService::installDbConnect(); non-null once hasErrors() is false.
     */
    private ?Connection $conn = null;

    private string $language = 'en_UK';

    private Template $template;

    private InstallWizardRequest $request;

    private int $step = 1;

    private readonly InstallServiceFactory $installServiceFactory;

    /**
     * Deliberately does not take SessionService via constructor injection.
     * Unlike other callers, this class discovers its real DB credentials
     * mid-request (boot()'s own DbCredentials::seed() call, from the
     * submitted form) rather than having them already settled before any
     * service resolves. Resolving SessionService eagerly here would build
     * its own SessionRepository's EntityManagerInterface/Connection
     * chain, and PHP-DI's container-bound Connection::class factory
     * permanently binds its result to whatever (stale, pre-seed)
     * credentials were current at that point in the container's
     * lifetime. Every use below instead builds a throwaway
     * SessionService from the already-correct, already-resolved $conn
     * local variable.
     */
    public function __construct(
        private readonly Lang $lang,
        private readonly Paths $paths,
        private readonly DbCredentials $dbCredentials,
        private readonly CurrentConfigService $currentConfigService,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly ProcessCache $processCache,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentUser $currentUser,
        private readonly ConnectedWithSession $connectedWithSession,
        private readonly Renderer $renderer,
        private readonly UrlServiceInterface $urlService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly ImageStdParams $imageStdParams,
    ) {
        $this->installServiceFactory = new InstallServiceFactory($this->lang, $this->currentUser, $this->eventDispatcher, $this->deploymentPolicy, $this->currentConfig, $this->paths);
    }

    /**
     * Everything the former install.php top level did before the
     * "form analyze" section: $_POST narrowing + Config seeding,
     * environment checks, language pick + Lang loads, and template
     * initialization.
     */
    public function boot(): void
    {
        $this->request = InstallWizardRequest::fromGlobals($this->inputValidator);

        // Obtain various vars
        $this->dbhost = $this->request->dbhost;
        $this->dbuser = $this->request->dbuser;
        $this->dbpasswd = $this->request->dbpasswd;
        $this->dbname = $this->request->dbname;
        $this->dbport = $this->request->dbport;

        // Any code reached later in this same request that resolves a DB
        // connection via Piwigo\Db\DbConnection::build() (which reads
        // DbCredentials::current()) would otherwise silently see whatever
        // was already in the process environment instead of the real
        // submitted credentials -- e.g. get_default_user_value() ->
        // UserService -> UserRepository -> DbConnection::build(), reached
        // from InstallService::activateCoreThemes() during step-2 theme
        // activation, fatals with "Access denied for user ''@'localhost'"
        // without this. PIWIGO_DB_DRIVER/PIWIGO_DB_PORT are seeded from the
        // request's own dbdriver/dbport too, ahead of the extension check
        // below, so DbConnection::params() picks the real chosen driver for
        // every DB touch downstream, not just mysqli's default.
        $this->dbCredentials->seed([
            'PIWIGO_DB_HOST' => $this->dbhost,
            'PIWIGO_DB_USER' => $this->dbuser,
            'PIWIGO_DB_PASSWORD' => $this->dbpasswd,
            'PIWIGO_DB_BASE' => $this->dbname,
            'PIWIGO_DB_DRIVER' => $this->request->dbdriver,
            'PIWIGO_DB_PORT' => $this->dbport !== null ? (string) $this->dbport : null,
        ]);

        // Must run right here, not from install.php after boot() returns:
        // this method's own Template construction at the end of its body
        // (self::$template below) needs CurrentConfigService already
        // active (Template's data_dir_checked write), and that
        // construction happens before install.php regains control.
        //
        // Deliberately NOT InstallBootstrap::activateConfigService()
        // (which resolves ConfigService from the DI container): by the
        // time boot() reaches this line, Connection::class may
        // already be memoized in the container from an earlier,
        // unavoidable resolution -- $this->lang (a constructor param,
        // resolved by the caller before this object even exists) reaches
        // HtmlRenderingInterface -> HtmlService, whose own constructor
        // eagerly needs EntityManagerInterface, so PHP-DI's
        // container-bound Connection::class factory can permanently bind
        // its result to whatever (stale, pre-seed) credentials were
        // current at that point -- the exact same class of bug this
        // class's own constructor docblock already documents for
        // SessionService below. A ConfigService built directly from a
        // fresh DbConnection::build() call here is immune to that
        // staleness the same way every other throwaway service in this
        // method already is.
        $configService = new ConfigService(
            EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class),
            $this->currentConfig,
        );
        $this->currentConfigService->set($configService);

        // Same reasoning again, different dependency: this request never
        // goes through RequestBootstrap::bootEntryPoint()/bootConfigOnly()
        // (only InstallBootstrap::boot(), which doesn't touch CurrentUser),
        // so CurrentUser is never guest-initialized either -- without this,
        // InstallService::activateCoreThemes() -> ExtensionScanner::scan()'s
        // missing-screenshot fallback -> PreferencesService::getParam() ->
        // CurrentUser::get() throws uncaught "CurrentUser not initialised".
        // attachGlobals() is exactly the safe guest default this no-boot
        // path needs (idempotent; a later real CurrentUser::set() in
        // render() is never clobbered by this).
        $this->currentUser->attachGlobals();

        // Same no-boot gap, third dependency: CurrentLogger -- not
        // initialized through the normal boot path either. Whether any
        // consumer on this no-boot install path still needs CurrentLogger
        // set this early is unverified; left in place rather than removed
        // on an unaudited assumption. Same construction recipe as
        // RequestBootstrap::connect()'s (the normal request pipeline's
        // own site) -- no DB access needed, just Config/DbCredentials reads
        // already valid this early (the DB password was just seeded above).
        InstallBootstrap::currentLogger()->set(new Logger([
            'directory' => $this->paths->root . $this->currentConfig->dataLocation . $this->currentConfig->logDir,
            'severity' => $this->currentConfig->logLevel,
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $this->dbCredentials->password) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => $this->currentConfig->logArchiveDays,
        ]));

        // dblayer -- was unconditionally hardcoded to 'mysqli' regardless of
        // what the form submitted (the real reason a real pgsql install was
        // never reachable through this wizard before); now reflects the
        // real chosen driver, extension-checked accordingly.
        $this->dblayer = $this->request->dbdriver;
        $requiredExtension = $this->dblayer === 'pgsql' ? 'pgsql' : 'mysqli';
        if (! extension_loaded($requiredExtension)) {
            PresentationAccessor::htmlService()
                ->fatalError('PHP extension "' . $requiredExtension . '" is not loaded');
        }

        $this->adminName = $this->request->adminName;
        $this->adminPass1 = $this->request->adminPass1;
        $this->adminPass2 = $this->request->adminPass2;
        $this->adminMail = $this->request->adminMail;

        $this->isNewsletterSubscribe = $this->request->isNewsletterSubscribe;

        // Is Piwigo already installed ? This is the normal steady state of
        // any live deployment, not a fatal error -- fatalError() always
        // throws a 500 (see its own docblock, no status override
        // available), so this builds the ResponseReadyException directly
        // instead, mirroring RequestBootstrap::configure()'s own
        // inverse-case redirect. Plain, untranslated string, matching the
        // "PHP extension ... not loaded" check just above: $this->lang
        // hasn't loaded any catalog yet at this point in boot().
        if (file_exists($this->paths->siteLocal . Env::testModeInstalledStamp())) {
            $indexUrl = PresentationAccessor::urlService()
                ->makeIndexUrl();
            throw new ResponseReadyException(ResponseFactory::html(
                '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                . '<h1>Piwigo is already installed</h1>'
                . '<p><a href="' . htmlspecialchars($indexUrl) . '">Home</a></p>',
                200,
            ));
        }

        $this->fsLanguages = new ExtensionScanner()
            ->scanLanguages($this->paths, $this->currentConfig, EntityManagerFactory::build(DbConnection::build()), 'utf-8');

        if ($this->request->languageParam !== null) {
            $language = $this->request->languageParam;

            if (! in_array($language, array_keys($this->fsLanguages), true)) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $language = 'en_UK';
            // Try to get browser language
            $accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
            $accept_language = is_string($accept_language) ? $accept_language : '';
            foreach ($this->fsLanguages as $language_code => $fs_language) {
                if (substr($language_code, 0, 2) === substr($accept_language, 0, 2)) {
                    $language = $language_code;
                    break;
                }
            }
        }
        $this->language = $language;

        $this->lang->load('common.lang', '', [
            'language' => $language,
        ]);
        $this->lang->load('admin.lang', '', [
            'language' => $language,
        ]);
        $this->lang->load('install.lang', '', [
            'language' => $language,
        ]);

        header('Content-Type: text/html; charset=UTF-8');
        // ----------------------------------------------- check php version
        if (version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION, '<')) {
            $this->errors[] = $this->lang->t('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION);
        }

        // --------------------------------------------- template initialization
        // Throwaway SessionService built directly from a fresh
        // DbConnection::build() call, not a constructor property -- this
        // class deliberately never holds SessionService itself (see this
        // class's own docblock: real DB credentials aren't known yet at
        // this point in boot()). DbConnection::build() returns a brand
        // new, non-cached Connection every call (DriverManager::
        // getConnection() is a factory, not a singleton lookup) and DBAL
        // connections are lazy (no real socket opens until a query
        // actually runs), so this is safe even before real credentials
        // are submitted -- Template's own get_device modifier is never
        // actually invoked by install.latte, so the DB is never touched in
        // practice either.
        //
        // applyThemeBase: false -- install.latte is the one real top-level
        // page in the whole app that doesn't extend layout.latte (confirmed
        // via grep: it's the only *.latte file besides the 3 real
        // layout.latte's own themselves with its own <!DOCTYPE html>), a
        // deliberately minimal standalone <head> since the full admin
        // chrome (fontello icons, utilities.css, component styles) isn't
        // wanted this early. The theme-base "unconditional admin-layout
        // assets" piece (docs/PLAN.md's P42-A) would otherwise register
        // them regardless, a real regression caught via golden-html.
        $template = new Template($this->currentConfig, $this->lang, $this->eventDispatcher, $this->processCache, $this->currentConfigService, $this->paths, new AccessLevelChecker($this->currentUser, $this->currentConfig), $this->urlService, $this->pageState, $this->htmlRenderer, $this->imageStdParams, $this->paths->root . 'themes/admin', ThemeId::from('clear'), applyThemeBase: false);
        $this->currentTemplate->set($template);
        $this->template = $template;
    }

    /**
     * Delegates to InstallServiceFactory (extracted out of this class so
     * InstallDataSeeder/InstallPostInstallSession can share it without
     * repeating the same multi-dependency service construction chain,
     * which tests/Arch/StructuralTest.php's own
     * findDuplicateServiceConstructionChains() forbids verbatim within one
     * file). $conn defaults to a fresh DbConnection::build() rather than
     * reusing $this->conn: analyzeForm()'s own call site can legitimately
     * run after installDbConnect() returned null (a failed connection
     * attempt), unlike the performInstall()/render() call sites, which
     * always have a real connection by the time they run.
     */
    private function userService(?Connection $conn = null): UserService
    {
        return $this->installServiceFactory->userService($conn);
    }

    /**
     * Delegates to InstallServiceFactory. Same reasoning as userService() above.
     */
    private function passwordService(Connection $conn): PasswordService
    {
        return $this->installServiceFactory->passwordService($conn);
    }

    /**
     * Former install.php "form analyze" validation block (DB connection
     * attempt + webmaster/password/mail checks). Only called when
     * $_POST['install'] is set.
     */
    public function analyzeForm(): void
    {
        $this->conn = InstallService::installDbConnect($this->infos, $this->errors, $this->lang);

        if (count($this->errors) > 0) {
            print_r($this->errors);
        }

        $webmaster = trim((string) preg_replace('/\s{2,}/', ' ', $this->adminName));
        if ($webmaster === '') {
            $this->errors[] = $this->lang->t('enter a login for webmaster');
        } elseif ((bool) preg_match('/[\'"]/', $webmaster)) {
            $this->errors[] = $this->lang->t('webmaster login can\'t contain characters \' or "');
        }
        if ($this->adminPass1 !== $this->adminPass2 || $this->adminPass1 === '') {
            $this->errors[] = $this->lang->t('please enter your password again');
        }
        if ($this->adminMail === '') {
            $this->errors[] = $this->lang->t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        } else {
            $error_mail_address = $this->userService()
                ->validateMailAddress(null, $this->adminMail);
            if ($error_mail_address !== '') {
                $this->errors[] = $error_mail_address;
            }
        }
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Former install.php step-2 block: .env writing, schema + base-data
     * creation, core theme/plugin activation, webmaster user creation and
     * upgrade-table pre-fill. The entry shell marks InstallationFlag active
     * immediately before calling this, exactly where the former top-level
     * code's raw `define('PHPWG_INSTALLED', true)` sat.
     *
     * @psalm-suppress RedundantCondition
     * @psalm-suppress TypeDoesNotContainType Psalm's $_SERVER superglobal
     *   stub is typed more optimistically than reality: HTTP_HOST/
     *   SCRIPT_NAME are never guaranteed present or string the way Psalm's
     *   stub assumes.
     */
    public function performInstall(): void
    {
        // Only called by the entry shell after hasErrors() is false, which
        // means analyzeForm() -> InstallService::installDbConnect() already
        // built this successfully.
        $conn = $this->conn;
        if (! $conn instanceof Connection) {
            throw new LogicException('performInstall() called before a successful analyzeForm() connection.');
        }

        $this->step = 2;

        $envError = new InstallEnvWriter($this->paths, $this->dbCredentials)
            ->write($this->dbhost, $this->dbuser, $this->dbpasswd, $this->dbname, $this->dblayer, $this->dbport);
        if ($envError !== null) {
            $this->errors[] = $envError;
        }

        // A schema left in an unknown/partial state past this point --
        // unlike the env-write failure above (which still lets the rest of
        // the install proceed), continuing to seed config.sql/create the
        // webmaster user against a migration that didn't finish would only
        // cascade into more, harder-to-diagnose failures. Resets to step 1
        // so render() (called next by the entry shell) shows the initial
        // form with this error, not a false "step 2 succeeded" page.
        $migrationError = new InstallSchemaMigrator()
            ->migrate($conn);
        if ($migrationError !== null) {
            $this->errors[] = $migrationError;
            $this->step = 1;

            return;
        }

        // We fill the tables with basic informations
        InstallService::executeSqlfile(
            $conn,
            $this->paths->root . 'install/config.sql',
        );

        $configService = $this->currentConfigService->get();
        $configService->confUpdateParam('secret_key', sha1(random_bytes(1000)));
        $configService->confUpdateParam('gallery_title', $this->lang->t('Just another Piwigo gallery'));

        $configService->confUpdateParam(
            'page_banner',
            '<h1>%gallery_title%</h1>' . "\n\n<p>" . $this->lang->t('Welcome to my photo gallery') . '</p>'
        );

        // fill languages table, only activate the current language
        // Deliberately a fresh DbConnection::build(), not the outer $conn
        // (still needed as $this->conn, unshadowed, by BatchWriter/
        // PasswordRepository/userService() calls further down this same
        // method) -- matches this call's own pre-existing "fresh
        // connection" shape, just extended to the new repository too.
        $urlService = PresentationAccessor::urlService();
        $languageActivationConn = DbConnection::build();
        new ExtensionLifecycle(
            $this->lang,
            new ExtensionRepository(EntityManagerFactory::build($languageActivationConn)),
            new PemCatalog(new ZipExtractor(), InstallBootstrap::currentLogger(), $this->paths, $this->currentConfig),
            $urlService,
            $configService,
            ExtendedDomainAccessor::activityService(),
            CoreDomainAccessor::userService(),
            PresentationAccessor::htmlService(),
            $this->currentConfig,
            $this->paths,
            $this->currentUser,
            $this->eventDispatcher,
            PresentationAccessor::pluginRegistry(),
            PresentationAccessor::themeRegistry(),
            EntityManagerFactory::build($languageActivationConn),
        )->performAction(ExtensionType::Language, 'activate', $this->language, $this->fsLanguages[$this->language] ?? null);

        // fill CurrentConfig::$data from the freshly-seeded config table
        $configService->loadConfFromDb();

        InstallService::activateCoreThemes($this->lang, $this->currentUser, $this->currentConfigService, $this->currentConfig, $this->paths, $this->eventDispatcher);
        InstallService::activateCorePlugins($this->paths, $this->currentUser, $this->currentConfig);

        $insert = [
            'id' => 1,
            'galleries_url' => $this->paths->root . 'galleries/',
        ];
        new BatchWriter($conn)
            ->massInsert('sites', array_keys($insert), [$insert]);
        new DbInfo($conn)
            ->resyncIdentitySequence('sites');

        // webmaster admin user
        $inserts = [
            [
                'id' => 1, // must be the same value as webmaster_id in config.sql
                'username' => $this->adminName,
                'password' => $this->passwordService($conn)
                    ->hash($this->adminPass1),
                'mail_address' => $this->adminMail,
            ],
            [
                'id' => 2,
                'username' => 'guest',
            ],
        ];
        new BatchWriter($conn)
            ->massInsert('users', array_keys($inserts[0]), $inserts);
        new DbInfo($conn)
            ->resyncIdentitySequence('users');

        $this->userService($conn)
            ->createUserInfos([UserId::from(1), UserId::from(2)], LangCode::from($this->language));

        // Create install sentinel stamp file -- moved here (after schema
        // migration, config.sql seeding, extension activation, the sites
        // row, and webmaster/guest user creation all succeeded) instead of
        // right after .env is written, so a mid-install failure (the
        // migration-failure early return above, or an uncaught exception
        // from any step since) never leaves RequestBootstrap.php's own
        // file_exists() check treating a partially-completed install as
        // done. Still gated on $this->errors staying empty: the one other
        // failure mode that reaches this point without returning early is
        // a failed .env write above, which would otherwise leave
        // DbCredentials unable to reload on the next real request even
        // though the DB itself is now fully seeded.
        if (count($this->errors) === 0) {
            touch($this->paths->siteLocal . Env::testModeInstalledStamp());
        }
    }

    /**
     * Former install.php "start template output" through final render:
     * form rendering on step 1, or the post-install session/login/
     * newsletter/mail sequence on step 2. `install.latte` is a genuinely
     * self-contained document (P41, docs/PLAN.md) -- no `{layout}`
     * needed, just `Renderer::render()` + `Template::finalizeHtml()` in
     * place of the old `assignContext()`/`pparse()` pair.
     */
    public function render(): void
    {
        $template = $this->template;

        $languages_options = [];
        $language_selection = null;
        foreach ($this->fsLanguages as $language_code => $fs_language) {
            if ($this->language === $language_code) {
                $language_selection = $language_code;
            }
            $languages_options[$language_code] = $fs_language->name;
        }

        // -------------------------------------------- errors & infos display
        $install_value = null;
        if ($this->step === 1) {
            $install_value = true;
        } else {
            // Only reached once performInstall() (step 2) already ran
            // successfully with this same connection.
            $conn = $this->conn;
            if (! $conn instanceof Connection) {
                throw new LogicException('render() reached step 2 before a successful analyzeForm() connection.');
            }

            new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class))
                ->record('system', ActivitySystem::Core, 'install', [
                    'version' => AppInfo::VERSION,
                ]);
            $this->infos[] = $this->lang->t('Congratulations, Piwigo installation is completed');

            // The former top-level code wrapped everything below in
            // `if (isset($error_copy)) { $errors[] = $error_copy; } else {...}`;
            // $error_copy was a relic of the long-removed copy-of-files
            // install step and is assigned nowhere in the whole codebase,
            // so the isset() was always false and the guard was dropped.

            // See Piwigo\Http\SessionBootstrap (kept inline here: at
            // this point of the install InstallationFlag was only just
            // marked active and this block ran unconditionally in the
            // original, without SessionBootstrap::register()'s
            // session_save_handler === 'db' guard)
            session_set_save_handler(new SessionHandler(new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $this->currentConfig), InstallBootstrap::currentLogger()));
            if (function_exists('ini_set')) {
                ini_set('session.use_cookies', $this->currentConfig->sessionUseCookies);
                ini_set('session.use_only_cookies', $this->currentConfig->sessionUseOnlyCookies);
                ini_set('session.use_trans_sid', (int) $this->currentConfig->sessionUseTransSid);
                ini_set('session.cookie_httponly', 1);
                // [P44-M] Mirrors Piwigo\Http\SessionBootstrap::register()'s
                // own secure/samesite hardening -- see that method's
                // docblock for why.
                if (SessionBootstrap::requestIsHttps()) {
                    ini_set('session.cookie_secure', 1);
                }

                ini_set('session.cookie_samesite', 'Lax');
            }
            session_name($this->currentConfig->sessionName);
            session_set_cookie_params(0, new CookieService()->cookiePath());
            register_shutdown_function(session_write_close(...));

            $user = $this->userService($conn)
                ->buildUser(UserId::from(1));
            // build_user() returns array<string, mixed>; the 'id' key we just set
            // to the literal user id 1 doesn't retain that literal type through
            // the return, so narrow to what log_user() actually accepts.
            $raw_login_user_id = $user['id'];
            if (is_int($raw_login_user_id)) {
                $login_user_id = $raw_login_user_id;
            } elseif (is_string($raw_login_user_id) && is_numeric($raw_login_user_id)) {
                $login_user_id = $raw_login_user_id;
            } else {
                $login_user_id = false;
            }
            // This install flow never goes
            // through Bootstrap\UserBootstrap::initialize() (the only place
            // that normally syncs Users\CurrentUser from the session for a
            // request), so ActivityService::record()'s own
            // CurrentUser::wasRealUserResolved() check sees "no real user
            // resolved yet" for every activity logged this request --
            // including the 'login' row logUser() itself records
            // internally, which is why this sync must happen BEFORE
            // calling it, not after. $user (built just above, same array
            // shape UserBootstrap::initialize() uses) is already the right
            // data; this mirrors that method's own two calls verbatim.
            $this->currentUser->set(User::fromUserArray($user));
            $this->currentUser->markRealUserResolved();
            new AuthService(new AuthRepository(EntityManagerFactory::build($conn)), new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)), PresentationAccessor::htmlService(), $this->passwordService($conn), new CookieService(), EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class), new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $this->currentConfig), $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentConfig, $this->paths, EntityManagerFactory::build($conn), $this->connectedWithSession)
                ->logUser($login_user_id, false);
            $this->connectedWithSession->set(ConnectedWith::PwgUi);

            // Same reason: narrow 'preferences' to array without discarding
            // whatever getuserdata() already populated it with.
            $preferences = $user['preferences'] ?? null;
            $preferences = is_array($preferences) ? $preferences : [];
            $preferences['show_whats_new_' . VersionHelper::getBranchFromVersion(AppInfo::VERSION)] = false;
            $user['preferences'] = $preferences;

            // newsletter subscription
            if ($this->isNewsletterSubscribe) {
                // Fire-and-forget: the response content is never read, only the
                // request's side effect (subscribing $admin_mail) matters.
                HttpClientService::fetch(
                    AdminUiHelper::getNewsletterSubscribeBaseUrl($this->language) . $this->adminMail,
                    $this->currentConfig,
                    [],
                    [
                        'origin' => 'installation',
                    ]
                );

                $preferences['show_newsletter_subscription'] = false;
                $user['preferences'] = $preferences;
            }

            // Sync CurrentUser before PreferencesService::save() reads it --
            // this install-time $user is a fresh buildUser(1) result, never
            // routed through RequestBootstrap/UserBootstrap's own sync calls.
            $this->currentUser->set(User::fromUserArray($user));

            new PreferencesService(new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig), $this->currentUser)
                ->save();

            // email notification
            if ($this->request->isSendCredentialsByMail) {
                $keyargs_content = [
                    $this->lang->buildArgs('Hello %s,', $this->adminName),
                    $this->lang->buildArgs('Welcome to your new installation of Piwigo!', ''),
                    $this->lang->buildArgs('', ''),
                    $this->lang->buildArgs('Here are your connection settings', ''),
                    $this->lang->buildArgs('', ''),
                    $this->lang->buildArgs('Link: %s', PresentationAccessor::urlService()->getAbsoluteRootUrl()),
                    $this->lang->buildArgs('Username: %s', $this->adminName),
                    $this->lang->buildArgs('Password: ********** (no copy by email)', ''),
                    $this->lang->buildArgs('Email: %s', $this->adminMail),
                    $this->lang->buildArgs('', ''),
                    $this->lang->buildArgs('Don\'t hesitate to consult our forums for any help: %s', AppInfo::URL),
                ];

                PresentationAccessor::mailService()
                    ->mail(
                        $this->adminMail,
                        new MailArgs(
                            subject: $this->lang->t('Just another Piwigo gallery'),
                            content: $this->lang->args($keyargs_content),
                            contentFormat: 'text/plain',
                        )
                    );
            }
        }
        $rawThemes = $this->template->getTemplateVars('themes');
        $themes = is_array($rawThemes) ? array_values($rawThemes) : [];

        $installView = new InstallView(
            languageSelection: $language_selection,
            languageOptions: $languages_options,
            tContentEncoding: 'utf-8',
            release: AppInfo::VERSION,
            fAction: 'install.php?language=' . $this->language,
            fDbHost: $this->dbhost,
            fDbUser: $this->dbuser,
            fDbName: $this->dbname,
            fDbDriver: $this->dblayer,
            fDbPort: $this->dbport,
            fAdmin: $this->adminName,
            fAdminEmail: $this->adminMail,
            // [P44-A] $this->adminMail is the raw, just-submitted install
            // form field, echoed back into this live-preview <span> --
            // assembled as a raw HTML string entirely in PHP, outside any
            // single Latte print, so it needs its own escaping here (Latte
            // never sees the dynamic part separately once this whole
            // fragment is wrapped Html/noescape'd downstream).
            email: '<span class="adminEmail">' . htmlspecialchars($this->adminMail) . '</span>',
            fNewsletterSubscribe: $this->isNewsletterSubscribe,
            lInstallHelp: $this->lang->t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', AppInfo::URL . '/forum'),
            install: $install_value,
            errors: count($this->errors) !== 0 ? $this->errors : null,
            infos: count($this->infos) !== 0 ? $this->infos : null,
            themes: $themes,
        );

        // ------------------------------------------------- html code display
        $html = $this->renderer->render($installView);
        $body = $template->finalizeHtml((string) $html);

        echo $body;
    }
}
