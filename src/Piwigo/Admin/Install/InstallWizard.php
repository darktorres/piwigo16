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
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\AdminUiHelper;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Install\Projection\InstallRenderPageContext;
use Piwigo\Admin\Install\Request\InstallWizardRequest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AdminContext;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\VersionHelper;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\DbInfo;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Http\HttpClientService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionHandler;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

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
     * ExtensionScanner::scan()'s own declared return type is a generic
     * array<string, array<string, mixed>> dispatch shape by design (see
     * that method's own docblock) -- every real reader here follows its
     * documented convention and reads specific keys defensively instead.
     *
     * @var array<string, array<string, mixed>>
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
        private readonly AdminContext $adminContext,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly ErrorCollector $errorCollector,
        private readonly ProcessCache $processCache,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentUser $currentUser,
        private readonly ConnectedWithSession $connectedWithSession,
    ) {}

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
            ->scan(ExtensionType::Language, PresentationAccessor::urlService(), $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, EntityManagerFactory::build(DbConnection::build()), 'utf-8');

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
        $template = new Template($this->currentConfig, $this->lang, $this->adminContext, $this->eventDispatcher, $this->errorCollector, $this->processCache, $this->currentConfigService, $this->paths, new AccessLevelChecker($this->currentUser, $this->currentConfig), new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), $this->currentConfig), $this->paths->root . 'themes/admin', ThemeId::from('clear'));
        $this->currentTemplate->set($template);
        $this->template = $template;
    }

    /**
     * Builds a UserService, matching the same "private helper takes the
     * already-available Connection as a parameter" shape as
     * Bootstrap\RequestBootstrap::activityService(). $conn defaults to a
     * fresh DbConnection::build() rather than reusing $this->conn:
     * analyzeForm()'s own call site can legitimately run after
     * installDbConnect() returned null (a failed connection attempt),
     * unlike the two performInstall() call sites below, which always
     * have a real connection by the time they run.
     */
    private function userService(?Connection $conn = null): UserService
    {
        $conn ??= DbConnection::build();
        $accessLevelChecker = new AccessLevelChecker($this->currentUser, $this->currentConfig);
        $filterState = new FilterState();
        $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $this->currentUser, $filterState, $accessLevelChecker);

        return new UserService($this->lang, new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)), PresentationAccessor::htmlService(), new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $this->currentConfig), $this->eventDispatcher, $this->deploymentPolicy, $this->currentUser, $this->currentConfig, new InstallationFlag(), new ProcessCache(), $this->paths, EntityManagerFactory::build($conn), $permissionService, new CategoryService($this->lang, new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $permissionService, $this->currentConfig, $this->eventDispatcher, new Translator($this->currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), $accessLevelChecker, new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig)), $this->passwordService($conn));
    }

    /**
     * Builds a PasswordService. Same "private helper takes the
     * already-available Connection as a parameter" shape as
     * userService() above -- avoids a repeated multi-dependency
     * construction chain, which an arch test forbids.
     */
    private function passwordService(Connection $conn): PasswordService
    {
        return new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), $this->deploymentPolicy);
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

        // Write .env (or .env.test in test mode) with DB credentials — atomic
        // rename, preserving any line this block doesn't manage (e.g. a
        // re-install's PIWIGO_TEST_NOW — see Piwigo\Core\Env::now()).
        $env_file = $this->paths->root . Env::testModeEnvFile();
        $env_values = [
            'PIWIGO_DB_HOST' => $this->dbhost,
            'PIWIGO_DB_USER' => $this->dbuser,
            'PIWIGO_DB_PASSWORD' => $this->dbpasswd,
            'PIWIGO_DB_BASE' => $this->dbname,
            'PIWIGO_DB_DRIVER' => $this->dblayer,
        ];
        // Only written when the operator actually chose a non-default port
        // (the driver's own default applies otherwise, same as before this
        // field existed) -- mergeIntoEnvFile()'s own $values shape is
        // array<string, string>, so a null port is omitted rather than passed.
        if ($this->dbport !== null) {
            $env_values['PIWIGO_DB_PORT'] = (string) $this->dbport;
        }
        // In test mode, also record the base URL so e2e runners know where to connect.
        if (Env::testModeIsActive()) {
            $scheme = (! in_array($_SERVER['HTTPS'] ?? null, [null, false, 0, '0', ''], true) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = is_string($host) ? $host : 'localhost';
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $script = is_string($script) ? $script : '';
            $base_url = rtrim($scheme . '://' . $host . dirname($script), '/');
            if ($base_url !== '') {
                $env_values['PIWIGO_BASE_URL'] = $base_url;
            }
        }

        if (! Env::mergeIntoEnvFile($env_file, $env_values)) {
            $this->errors[] = 'Could not write ' . $env_file . ' — check filesystem permissions.';
        }
        $this->dbCredentials->reload();

        // Create install sentinel stamp file.
        if (count($this->errors) === 0) {
            touch($this->paths->siteLocal . Env::testModeInstalledStamp());
        }

        // tables creation, driven by the real Doctrine Migrations baseline
        // (src/Piwigo/Migrations/) instead of a static SQL file -- see
        // this class's own constructor docblock for why the
        // DependencyFactory below is built directly from this
        // already-seeded $conn rather than resolved via the container
        // (config/container.php's own DependencyFactory::class entry
        // backs bin/piwigo migrations:migrate's CLI usage only). Runs the
        // real MigrateCommand programmatically (ArrayInput/setInteractive
        // (false) skips its confirmation prompt, matching --no-interaction)
        // rather than calling AliasResolver::resolveVersionAlias()/
        // Migrator::migrate()/MigratorConfiguration directly -- all 3 are
        // internal Doctrine APIs (method.internalInterface/new.internalClass),
        // off limits from outside the Doctrine root namespace; MigrateCommand itself is
        // the sanctioned public entry point, and running it this way also
        // means a future point release adding a new migration file here
        // becomes the real upgrade path for an existing install (bin/
        // piwigo migrations:migrate), not just a fresh-install mechanism.
        $migrationsEm = EntityManagerFactory::build($conn);
        $dependencyFactory = MigrationDependencyFactory::build($migrationsEm);
        $migrateInput = new ArrayInput([
            'version' => 'latest',
            '--allow-no-migration' => true,
        ]);
        $migrateInput->setInteractive(false);
        $migrateOutput = new BufferedOutput();
        // MigrateCommand::run() is called directly (see this block's own
        // docblock above for why), not through a full Symfony Application --
        // unlike a real CLI invocation, that does NOT guarantee every
        // failure is caught internally and converted to a plain exit code.
        // A driver-level exception (mysqli's own exception-throwing mode,
        // e.g. a genuine "table already exists") can still escape run()
        // uncaught. public/install.php's own
        // top-level catch only handles ResponseReadyException, so letting
        // that propagate would reach the client as a raw fatal error/blank
        // page instead of the installer's own error UI -- caught here and
        // folded into the same "exit code" failure path below instead.
        try {
            $migrateExitCode = new MigrateCommand($dependencyFactory)
                ->run($migrateInput, $migrateOutput);
        } catch (Throwable $e) {
            $migrateExitCode = 1;
            $migrateOutput->writeln($e->getMessage());
        }
        if ($migrateExitCode !== 0) {
            // A schema left in an unknown/partial state past this point --
            // unlike the env-write failure above (which still lets the rest
            // of the install proceed), continuing to seed config.sql/create
            // the webmaster user against a migration that didn't finish
            // would only cascade into more, harder-to-diagnose failures.
            // Resets to step 1 so render() (called next by the entry shell)
            // shows the initial form with this error, not a false "step 2
            // succeeded" page.
            $this->errors[] = 'Schema migration failed (migrations:migrate exit code ' . $migrateExitCode . '): ' . $migrateOutput->fetch();
            $this->step = 1;

            return;
        }

        // We fill the tables with basic informations
        InstallService::executeSqlfile(
            $conn,
            $this->paths->root . 'install/config.sql',
        );

        // config.value is JSON -- json_encode() the value (not the bare
        // hex string) so
        // ConfigService::hydrate()'s json_decode() read side gets back a
        // real string instead of failing to parse it.
        $secretKeyJson = json_encode(sha1(random_bytes(1000)));
        assert($secretKeyJson !== false);

        $query = <<<SQL
            INSERT INTO config (param,value,comment)
               VALUES ('secret_key', :secretKey,
               'a secret key specific to the gallery for internal use');
            SQL;
        $conn->executeStatement($query, [
            'secretKey' => $secretKeyJson,
        ]);

        $configService = $this->currentConfigService->get();
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
        InstallService::activateCorePlugins($this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig);

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
            ->createUserInfos([UserId::from(1), UserId::from(2)], [
                'language' => $this->language,
            ]);
    }

    /**
     * Former install.php "start template output" through final pparse():
     * form rendering on step 1, or the post-install session/login/
     * newsletter/mail sequence on step 2.
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
            $fs_language_name = $fs_language['name'] ?? null;
            $languages_options[$language_code] = is_string($fs_language_name) ? $fs_language_name : $language_code;
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
                        [
                            'subject' => $this->lang->t('Just another Piwigo gallery'),
                            'content' => $this->lang->args($keyargs_content),
                            'content_format' => 'text/plain',
                        ]
                    );
            }
        }
        $template->assignContext(new InstallRenderPageContext(
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
            email: '<span class="adminEmail">' . $this->adminMail . '</span>',
            fNewsletterSubscribe: $this->isNewsletterSubscribe,
            lInstallHelp: $this->lang->t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', AppInfo::URL . '/forum'),
            install: $install_value,
            errors: count($this->errors) !== 0 ? $this->errors : null,
            infos: count($this->infos) !== 0 ? $this->infos : null,
        ));

        // ------------------------------------------------- html code display
        $template->pparse('install.latte');
    }
}
