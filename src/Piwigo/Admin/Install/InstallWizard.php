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
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Install\Projection\InstallView;
use Piwigo\Admin\Install\Request\InstallWizardRequest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
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
    private const OVERWRITE_TOKEN_COOKIE = 'pwg_install_overwrite_token';

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

    /**
     * Set by checkDbConnection()/analyzeForm() once a real connection to
     * an existing Piwigo install is confirmed (or ruled out) --
     * {@see InstallSchemaDropper::hasExistingInstall()}'s own `?bool`
     * shape, threaded through to render()'s InstallView so a real submit
     * that hits the confirmation-required error still shows the warning
     * block on redisplay, not just the live AJAX check.
     */
    private ?bool $hasExistingInstall = null;

    /**
     * The double-submit-cookie token minted by issueOverwriteToken() the
     * moment $hasExistingInstall first becomes true -- threaded through to
     * render() so a redisplayed form's hidden `overwrite_token` field
     * carries whichever token is actually current (matching the cookie
     * just (re)issued), not a stale one from an earlier request.
     */
    private ?string $overwriteToken = null;

    /**
     * Set by boot() -- {@see InstallEnvironmentChecker::checkWritableDirectories()}'s
     * own return shape, threaded through to render()'s InstallView for the
     * always-visible writable-directory checklist.
     *
     * @var list<array{path: string, label: string, writable: bool}>
     */
    private array $writableChecks = [];

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
            TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class), ConfigRepository::class),
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
        // real chosen driver. The required-extension check itself lives in
        // analyzeForm() (see its own docblock) -- checking it here, on
        // every request including the very first GET, made the install
        // form itself unreachable on a server missing the *default*
        // driver's extension, since a fresh page load has no real
        // $_POST['dbdriver'] yet and always defaults to 'mysqli'.
        $this->dblayer = $this->request->dbdriver;

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

        $this->writableChecks = new InstallEnvironmentChecker()
            ->checkWritableDirectories($this->paths);

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
     * reusing $this->conn: this is the only remaining call site
     * (analyzeForm()), reachable even after installDbConnect() returned
     * null (a failed connection attempt) -- InstallDataSeeder/
     * InstallPostInstallSession call InstallServiceFactory directly for
     * their own userService()/passwordService() needs, both always with a
     * real connection by the time they run.
     */
    private function userService(?Connection $conn = null): UserService
    {
        return $this->installServiceFactory->userService($conn);
    }

    /**
     * Former install.php "form analyze" validation block (DB connection
     * attempt + webmaster/password/mail checks). Only called when
     * $_POST['install'] is set.
     *
     * The dblayer PHP-extension check used to live in boot() instead,
     * running unconditionally on every request (including the very first
     * GET, before any driver was actually chosen) -- a hard fatalError()
     * crash page there, not a normal validation error, meant a server
     * missing the *default* driver's extension could never even reach the
     * install form to pick a different one. Runs here instead: only once a
     * real driver choice was actually submitted, as a normal $this->errors[]
     * entry like every other check below.
     */
    /**
     * Extracted out of analyzeForm() so the live AJAX DB-check path
     * (checkDbConnection()) can share the exact same extension guard --
     * installDbConnect() calls straight into `new mysqli()`/`pg_connect()`
     * with no extension guard of its own, and a genuinely missing
     * extension throws \Error there (undefined class/function), not
     * \Exception, which its own `catch (Exception $e)` doesn't catch.
     * Skips the attempt entirely rather than crash the request.
     */
    private function attemptDbConnection(): ?Connection
    {
        $requiredExtension = $this->dblayer === 'pgsql' ? 'pgsql' : 'mysqli';
        if (! extension_loaded($requiredExtension)) {
            $this->errors[] = $this->lang->t('PHP extension "%s" is not loaded', $requiredExtension);

            return null;
        }

        return InstallService::installDbConnect($this->infos, $this->errors, $this->lang);
    }

    /**
     * Mints a fresh double-submit-cookie token the moment
     * $hasExistingInstall is first shown as `true`, from whichever path
     * (live AJAX check or a real submit) gets there first -- both
     * checkDbConnection() and analyzeForm() call this same helper, so an
     * operator who trusts the live-check warning and submits with the box
     * already checked doesn't hit a token-mismatch failure on their first
     * real attempt. `bin2hex(random_bytes(32))` -- session-cookie
     * lifetime (no explicit long-lived expiry needed), HttpOnly,
     * SameSite=Strict: install.latte has no session/CSRF infrastructure
     * this early (see this class's own docblock), so this is scoped to
     * just this one destructive action rather than a general token.
     */
    private function issueOverwriteToken(): string
    {
        $token = bin2hex(random_bytes(32));

        setcookie(self::OVERWRITE_TOKEN_COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => (bool) ini_get('session.cookie_secure'),
        ]);

        return $token;
    }

    /**
     * The real gate everywhere this is checked: confirmOverwrite alone is
     * never sufficient, only confirmOverwrite AND a matching
     * overwriteToken/cookie pair.
     */
    private function overwriteConfirmed(): bool
    {
        if (! $this->request->confirmOverwrite || $this->request->overwriteToken === null) {
            return false;
        }

        $cookieToken = $_COOKIE[self::OVERWRITE_TOKEN_COOKIE] ?? null;

        return is_string($cookieToken) && hash_equals($cookieToken, $this->request->overwriteToken);
    }

    /**
     * install.php's own `?ajax=check-db` branch -- fires while the
     * operator is still typing DB credentials, before any real submit.
     * Deliberately never assigns $this->conn: this path has no business
     * holding a connection open past its own request, unlike
     * analyzeForm() below. The existence check runs before close() --
     * querying a closed connection fails.
     *
     * @return array<string, mixed>
     */
    public function checkDbConnection(): array
    {
        $conn = $this->attemptDbConnection();

        $hasExistingInstall = null;
        $overwriteToken = null;
        if ($conn instanceof Connection) {
            $hasExistingInstall = new InstallSchemaDropper()
                ->hasExistingInstall($conn);
            if ($hasExistingInstall === true) {
                $overwriteToken = $this->issueOverwriteToken();
            }
        }

        $conn?->close();

        return [
            'errors' => $this->errors,
            'hasExistingInstall' => $hasExistingInstall,
            'overwriteToken' => $overwriteToken,
        ];
    }

    public function analyzeForm(): void
    {
        // Mirrors the exact failure InstallEnvWriter::write() would hit
        // later in performInstall() (same message, that class's own
        // untranslated convention for this one error) -- surfaced here
        // instead, before any DB work even starts, where the operator can
        // still act on it. $paths->root specifically: the one entry in
        // $this->writableChecks InstallEnvWriter::write() actually writes
        // into ({@see Env::testModeEnvFile()} resolves to a bare filename
        // directly under root).
        if (! is_writable($this->paths->root)) {
            $this->errors[] = 'Could not write ' . $this->paths->root . Env::testModeEnvFile() . ' — check filesystem permissions.';
        }

        $this->conn = $this->attemptDbConnection();

        // Never trusts the client-reported live-check result for the
        // gate itself -- re-checked here, defense in depth.
        if ($this->conn instanceof Connection) {
            $this->hasExistingInstall = new InstallSchemaDropper()
                ->hasExistingInstall($this->conn);

            if ($this->hasExistingInstall === null) {
                $this->errors[] = $this->lang->t('Connected to the database, but couldn\'t verify whether it already contains a Piwigo installation — check the database user\'s privileges to list tables');
            } elseif ($this->hasExistingInstall === true && ! $this->overwriteConfirmed()) {
                // A fresh token for this response, invalidating any
                // earlier one -- including one already displayed in
                // another tab, an accepted tradeoff for a single-operator
                // flow.
                $this->overwriteToken = $this->issueOverwriteToken();
                $this->errors[] = $this->lang->t('This database already contains a Piwigo installation. Check the box below to confirm you want to overwrite it, then submit again.');
            }
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
     * `$this->request` (private, set by boot()) exposed for the entry
     * shell's own `if (...) { analyzeForm(); ... }` gate -- only valid
     * to call after boot() has returned.
     */
    public function isInstallSubmitted(): bool
    {
        return $this->request->isInstallSubmitted;
    }

    /**
     * Same reasoning as isInstallSubmitted() -- exposed for install.php's
     * own `?ajax=check-db` gate, only valid to call after boot() has
     * returned.
     */
    public function isAjaxDbCheck(): bool
    {
        return $this->request->isAjaxDbCheck;
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

        // Re-checked once more here rather than trusting analyzeForm()'s
        // own earlier result -- reaching this point already guarantees
        // that gate passed with a definite true+confirmed or a definite
        // false, so a fresh null here (a privilege change or connection
        // hiccup between requests) aborts rather than assuming either
        // answer, same "reset to step 1" shape as the migration-failure
        // early return just below.
        $hasExistingInstall = new InstallSchemaDropper()
            ->hasExistingInstall($conn);
        if ($hasExistingInstall === null) {
            $this->errors[] = $this->lang->t('Connected to the database, but couldn\'t verify whether it already contains a Piwigo installation — check the database user\'s privileges to list tables');
            $this->step = 1;

            return;
        }

        if ($hasExistingInstall === true) {
            new InstallSchemaDropper()
                ->drop($conn);
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

        new InstallDataSeeder($this->paths, $this->lang, $this->currentConfigService, $this->currentConfig, $this->currentUser, $this->eventDispatcher, $this->installServiceFactory)
            ->seed($conn, $this->language, $this->fsLanguages[$this->language] ?? null, $this->adminName, $this->adminPass1, $this->adminMail);

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

            new ActivityService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class), ActivityRepository::class))
                ->record('system', ActivitySystem::Core, 'install', [
                    'version' => AppInfo::VERSION,
                ]);
            $this->infos[] = $this->lang->t('Congratulations, Piwigo installation is completed');

            // The former top-level code wrapped everything below in
            // `if (isset($error_copy)) { $errors[] = $error_copy; } else {...}`;
            // $error_copy was a relic of the long-removed copy-of-files
            // install step and is assigned nowhere in the whole codebase,
            // so the isset() was always false and the guard was dropped.
            new InstallPostInstallSession($this->lang, $this->currentConfig, $this->currentUser, $this->eventDispatcher, $this->pageState, $this->paths, $this->connectedWithSession, $this->installServiceFactory)
                ->run($conn, $this->language, $this->isNewsletterSubscribe, $this->adminName, $this->adminMail, $this->request->isSendCredentialsByMail);
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
            fSendCredentialsByMail: $this->request->isSendCredentialsByMail,
            lInstallHelp: $this->lang->t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', AppInfo::URL . '/forum'),
            install: $install_value,
            errors: count($this->errors) !== 0 ? $this->errors : null,
            infos: count($this->infos) !== 0 ? $this->infos : null,
            themes: $themes,
            hasExistingInstall: $this->hasExistingInstall,
            overwriteToken: $this->overwriteToken,
            writableChecks: $this->writableChecks,
            // The 3 messages install.js already mirrors inline near their
            // own field -- deduped out of the top-of-page list so a
            // failed submit doesn't show each one twice. Same
            // $this->lang->t() calls analyzeForm() itself uses, so this
            // always matches whatever it actually produced, in any
            // locale.
            dedupErrorStrings: [
                $this->lang->t('webmaster login can\'t contain characters \' or "'),
                $this->lang->t('please enter your password again'),
                $this->lang->t('mail address must be like xxx@yyy.eee (example : jack@altern.org)'),
            ],
        );

        // ------------------------------------------------- html code display
        $html = $this->renderer->render($installView);
        $body = $template->finalizeHtml((string) $html);

        echo $body;
    }
}
