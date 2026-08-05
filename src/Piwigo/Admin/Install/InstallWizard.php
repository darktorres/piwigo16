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
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\AdminUiHelper;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Auth\CookieService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\DbInfo;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\HttpClientService;
use Piwigo\Mail\MailService;
use Piwigo\Session\PwgSession;
use Piwigo\Template\Template;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * install.php's orchestration, ported verbatim from that script's former
 * top-level code (P23 sub-batch 8f-6). The install.php entry shell keeps
 * only bootstrap (env/config includes, the SEC-60-constrained define()s)
 * and drives this wizard: boot() -> [POST: analyzeForm(); no errors ->
 * shell define()s PHPWG_INSTALLED/PWG_CHARSET/DB_CHARSET/DB_COLLATE ->
 * performInstall()] -> render().
 *
 * The constructor reads data_location via LegacyFileConf::read() -- a
 * site owner's `local/config/config.inc.php` override is only visible
 * through the raw file, not through `CurrentConfig::`'s accessors (no
 * real `config` table exists yet at this point in the install flow).
 * render()'s own former `global $user;` was fully retired
 * (Legacy Coupling Retirement Phase 8 gap-closure) once every consumer,
 * including `AuthService::logUser()`/`PreferencesService`'s own methods,
 * had already moved onto `CurrentUser` in earlier phases -- $user is now
 * a plain local variable there.
 *
 * Legacy Coupling Retirement Phase 8, 8b: install.php now calls
 * InstallBootstrap::boot($paths) before this wizard is even constructed,
 * so the DI container is available throughout. Phase 8, 8d retargeted
 * every ConfigDb:: call here onto CurrentConfigService::get() -- safe
 * by the time performInstall() reaches them, since boot() already calls
 * InstallBootstrap::activateConfigService() and the config table exists
 * (the Doctrine Migrations baseline/config.sql ran immediately above).
 */
final class InstallWizard
{
    /**
     * Legacy Coupling Retirement gap-closure (install/upgrade-flow
     * constants round): used to be install.php's own
     * `define('DEFAULT_PREFIX_TABLE', 'piwigo_')` -- a fixed literal, not
     * a real global concern. install.php (which already directly
     * orchestrates this class) reads this constant too, at the one point
     * it computes the site's actual chosen prefix.
     */
    public const string DEFAULT_PREFIX_TABLE = 'piwigo_';

    private readonly string $confDataLocation;

    private readonly string $configFile;

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

    private Request\InstallWizardRequest $request;

    private int $step = 1;

    /**
     * Deliberately does NOT take SessionService via constructor injection
     * (singleton/service-locator elimination campaign, Phase 4) -- unlike
     * every other real caller in the codebase, this class discovers its
     * own real DB credentials mid-request (boot()'s own dbCredentials::
     * seed() call, from the submitted form) rather than having them
     * already settled by RequestBootstrap::connect() before any service
     * gets a chance to resolve. Resolving SessionService eagerly (whether
     * via the container or the SessionService::get() shim) as a
     * constructor argument here would build its own SessionRepository's
     * EntityManagerInterface/Connection chain -- and once PHP-DI's
     * container.php-bound Connection::class factory runs once, its
     * result is shared for the rest of this container's lifetime,
     * permanently bound to whatever (stale, pre-seed) credentials were
     * current at that moment. Real bug found this exact way: 6
     * Integration tests failed with "table ... doesn't exist" against the
     * wrong (default env) database once SessionService became a
     * constructor param here. Every real use below instead builds a
     * throwaway SessionService from the already-correct, already-resolved
     * $conn local variable -- same "no constructor dep, ~50 sites"
     * reasoning MailService/TagService already use for this exact shim.
     */
    public function __construct(
        private readonly Lang $lang,
        private readonly string $prefixeTable,
        private readonly Paths $paths,
        private readonly DbCredentials $dbCredentials,
        private readonly \Piwigo\Config\CurrentConfigService $currentConfigService,
        private readonly CurrentConfig $currentConfig,
        private readonly \Piwigo\Validation\InputValidator $inputValidator,
    ) {
        $conf_data_location = LegacyFileConf::read()['data_location'] ?? null;
        if (! is_string($conf_data_location)) {
            throw new \LogicException("Invalid \$conf['data_location'] configuration: expected a string.");
        }
        $this->confDataLocation = $conf_data_location;

        $this->configFile = $this->paths->siteLocal . 'config/database.inc.php';
    }

    /**
     * Everything the former install.php top level did before the
     * "form analyze" section: the ?dl= database-config download, $_POST
     * narrowing + Config seeding, environment checks, language pick +
     * Lang loads, and template initialization.
     */
    public function boot(): void
    {
        // download database config file if exists
        $this->request = Request\InstallWizardRequest::fromGlobals($this->inputValidator);

        $dl_param = $this->request->dl;
        if ($dl_param !== null && file_exists($this->paths->root . $this->confDataLocation . 'pwg_' . $dl_param)) {
            $filename = $this->paths->root . $this->confDataLocation . 'pwg_' . $dl_param;
            // Real bug, found while adding coverage for this branch: a raw
            // header()/echo/exit() sequence can't be exercised from inside
            // this same PHP process without exit()-ing the whole test
            // runner -- exactly the problem this class's own sibling
            // checks a few lines below (the mysqli-extension/already-
            // installed guards) already solved by throwing
            // ResponseReadyException instead of terminating directly (see
            // that exception's own docblock: "instead of terminating the
            // process directly via header()+echo+exit()/die()"). This
            // branch was simply never migrated to the same pattern.
            // install.php's own entry shell already wraps every boot()/
            // analyzeForm()/performInstall()/render() call in a `catch
            // (ResponseReadyException $e)` block that emits whatever
            // response it carries, so throwing here instead of exit()ing
            // needs no change on that end at all.
            $fileContent = file_get_contents($filename);
            if ($fileContent === false) {
                $fileContent = '';
            }
            $response = \Piwigo\Http\ResponseFactory::raw($fileContent, [
                'Cache-Control' => 'no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Content-Disposition' => 'attachment; filename="database.inc.php"',
                'Content-Transfer-Encoding' => 'binary',
                'Content-Length' => (string) strlen($fileContent),
            ]);
            unlink($filename);

            throw new \Piwigo\Http\ResponseReadyException($response);
        }

        // Obtain various vars
        $this->dbhost = $this->request->dbhost;
        $this->dbuser = $this->request->dbuser;
        $this->dbpasswd = $this->request->dbpasswd;
        $this->dbname = $this->request->dbname;
        $this->dbport = $this->request->dbport;

        // Same reasoning as the db_prefix seeding in the install.php entry
        // shell: any code reached later in this same request that resolves
        // a DB connection via Piwigo\Db\DbConnection::build() (which reads
        // DbCredentials::current()) would otherwise silently see whatever
        // was already in the process environment instead of the real
        // submitted credentials. Found live: get_default_user_value() ->
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
        // active (Legacy Coupling Retirement Phase 8, 8d -- Template's
        // data_dir_checked write), and that construction happens before
        // install.php regains control. Placed after the credential seeding
        // above, not before, for the same stale-credentials reason
        // InstallBootstrap::activateConfigService()'s own docblock
        // documents.
        \Piwigo\Bootstrap\InstallBootstrap::activateConfigService();

        // Same reasoning again, different dependency: this request never
        // goes through RequestBootstrap::bootEntryPoint()/bootConfigOnly()
        // (only InstallBootstrap::boot(), which doesn't touch CurrentUser),
        // so CurrentUser is never guest-
        // initialized either. Found live (real
        // fixture-regen run, not assumed): InstallService::
        // activateCoreThemes() -> ExtensionScanner::scan()'s missing-
        // screenshot fallback -> PreferencesService::getParam() ->
        // CurrentUser::get() -> uncaught "CurrentUser not initialised".
        // attachGlobals() is exactly the safe guest default this no-boot
        // path needs (idempotent; a later real CurrentUser::set() in
        // render() is never clobbered by this).
        \Piwigo\Users\CurrentUser::current()->attachGlobals();

        // Same no-boot gap, third dependency: CurrentLogger. Originally found
        // live one step later than CurrentUser (render()'s
        // UserService::buildUser() -> getUserData() -> CurrentLogger::get())
        // -- that specific call chain is gone (gap-closure Stage 4g deleted
        // getUserData()'s own CurrentLogger use along with the lock/wait/503
        // mechanism it logged), but whether some other consumer on this
        // no-boot install path still needs CurrentLogger set this early is
        // unverified and out of that stage's scope -- left in place rather
        // than removed on an unaudited assumption. Same construction recipe
        // as RequestBootstrap::connect()'s (the normal request pipeline's
        // own site) -- no DB access needed, just Config/DbCredentials reads
        // already valid this early (the DB password was just seeded above).
        \Piwigo\Bootstrap\InstallBootstrap::currentLogger()->set(new Logger([
            'directory' => $this->paths->root . $this->currentConfig->dataLocation() . $this->currentConfig->logDir(),
            'severity' => $this->currentConfig->logLevel(),
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $this->dbCredentials->password) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => $this->currentConfig->logArchiveDays(),
        ]));

        // dblayer -- was unconditionally hardcoded to 'mysqli' regardless of
        // what the form submitted (the real reason a real pgsql install was
        // never reachable through this wizard before); now reflects the
        // real chosen driver, extension-checked accordingly.
        $this->dblayer = $this->request->dbdriver;
        $requiredExtension = $this->dblayer === 'pgsql' ? 'pgsql' : 'mysqli';
        if (! extension_loaded($requiredExtension)) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('PHP extension "' . $requiredExtension . '" is not loaded');
        }

        $this->adminName = $this->request->adminName;
        $this->adminPass1 = $this->request->adminPass1;
        $this->adminPass2 = $this->request->adminPass2;
        $this->adminMail = $this->request->adminMail;

        $this->isNewsletterSubscribe = $this->request->isNewsletterSubscribe;

        // Is Piwigo already installed ?
        if (file_exists($this->paths->siteLocal . Env::testModeInstalledStamp())) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('Piwigo is already installed');
        }

        $this->fsLanguages = new ExtensionScanner()
            ->scan(ExtensionType::Language, \Piwigo\Bootstrap\PresentationAccessor::urlService(), $this->lang, 'utf-8');

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
        $template = new Template($this->paths->root . 'themes/admin', 'clear');
        \Piwigo\Template\CurrentTemplate::current()->set($template);
        $template->set_filenames([
            'install' => 'install.tpl',
        ]);
        $this->template = $template;
    }

    /**
     * DRY-extracted (Legacy Coupling Retirement Phase 8, 8b) -- was 3
     * identical `new UserService(new UserRepository($c), new
     * GroupRepository($c), \Piwigo\Bootstrap\PresentationAccessor::mailService(), new ActivityService(new
     * ActivityRepository($c)), \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $c)` chains inline
     * below, matching the same "private helper takes the already-available
     * Connection as a parameter" shape as
     * Bootstrap\RequestBootstrap::activityService(). $conn defaults to a
     * fresh DbConnection::build() rather than reusing $this->conn: unlike
     * the two performInstall() call sites below (which always have a real
     * connection by the time they run), analyzeForm()'s own call site can
     * legitimately run after installDbConnect() returned null (a failed
     * connection attempt) -- the original code always attempted its own
     * independent connection there regardless, a behavior this preserves
     * exactly.
     */
    private function userService(?Connection $conn = null): UserService
    {
        $conn ??= DbConnection::build();
        return new UserService($this->lang, new \Piwigo\Users\UserRepository(\Piwigo\Db\EntityManagerFactory::build($conn), \Piwigo\PluginConfig\EventDispatcher::get(), $this->currentConfig), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Bootstrap\PresentationAccessor::mailService(), new ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)), \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $conn, new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Session\SessionEntity::class), $this->currentConfig), \Piwigo\PluginConfig\EventDispatcher::get(), \Piwigo\Config\DeploymentPolicy::current(), \Piwigo\Users\CurrentUser::current(), $this->currentConfig);
    }

    /**
     * DRY-extracted (singleton/service-locator elimination campaign,
     * Phase 4) -- adding DeploymentPolicy as PasswordService's 2nd
     * constructor arg turned the 2 previously-distinct (1-arg)
     * `new PasswordService(new PasswordRepository($conn))` call sites
     * below into an identical 2-arg chain, tripping the "no repeated
     * multi-dependency construction chain" arch test. Same "private
     * helper takes the already-available Connection as a parameter"
     * shape as userService() above.
     */
    private function passwordService(Connection $conn): \Piwigo\Auth\PasswordService
    {
        return new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\EntityManagerFactory::build($conn)), \Piwigo\Config\DeploymentPolicy::current());
    }

    /**
     * Former install.php "form analyze" validation block (DB connection
     * attempt + prefix/webmaster/password/mail checks). Only called when
     * $_POST['install'] is set.
     */
    public function analyzeForm(): void
    {
        $this->conn = InstallService::installDbConnect($this->infos, $this->errors);

        if (count($this->errors) > 0) {
            print_r($this->errors);
        }

        if (
            strlen($this->prefixeTable) > 20
            or (bool) preg_match('/^\d/', $this->prefixeTable)
            or ! (bool) preg_match('/^[a-zA-Z0-9_$]*$/u', $this->prefixeTable)
        ) {
            $this->errors[] = 'invalid table prefix';
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
     * Former install.php step-2 block: .env / database.inc.php writing,
     * schema + base-data creation, core theme/plugin activation, webmaster
     * user creation and upgrade-table pre-fill. The entry shell define()s
     * PHPWG_INSTALLED/PWG_CHARSET/DB_CHARSET/DB_COLLATE immediately before
     * calling this (SEC-60 forbids define() in src/Piwigo), exactly where
     * the former top-level code did.
     */
    public function performInstall(): void
    {
        // Only called by the entry shell after hasErrors() is false, which
        // means analyzeForm() -> InstallService::installDbConnect() already
        // built this successfully.
        $conn = $this->conn;
        if (! $conn instanceof Connection) {
            throw new \LogicException('performInstall() called before a successful analyzeForm() connection.');
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
            'PIWIGO_DB_PREFIX' => $this->prefixeTable,
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

        // Also write legacy database.inc.php in prod mode so upgrade.php and other
        // not-yet-migrated scripts keep working (see docs/PLAN.md P13).
        if (! Env::testModeIsActive() && count($this->errors) === 0) {
            $file_content = '<?php
$conf[\'dblayer\'] = \'' . $this->dblayer . '\';
$conf[\'db_base\'] = \'' . $this->dbname . '\';
$conf[\'db_user\'] = \'' . $this->dbuser . '\';
$conf[\'db_password\'] = \'' . $this->dbpasswd . '\';
$conf[\'db_host\'] = \'' . $this->dbhost . '\';

$prefixeTable = \'' . $this->prefixeTable . '\';

define(\'PHPWG_INSTALLED\', true);
define(\'PWG_CHARSET\', \'utf-8\');
define(\'DB_CHARSET\', \'utf8\');
define(\'DB_COLLATE\', \'\');

?>';

            // umask() returns the PREVIOUS mask, so this both saves and
            // sets it in one call -- restored below once the config-file
            // write is done. Never restoring it (the original bug) leaks
            // 0111 into every later mkdir()/fopen() call for the rest of
            // this process's lifetime; unlike fopen()-created regular
            // files (which default to mode 0666, no execute bits for
            // 0111 to strip), mkdir()-created directories DO carry
            // execute bits by default, and losing them makes the
            // directory untraversable.
            $originalUmask = @umask(0111);
            // writing the configuration file
            if (! (bool) ($fp = @fopen($this->configFile, 'w'))) {
                // make sure nobody can list files of _data directory
                \Piwigo\Core\FilesystemHelper::secureDirectory($this->paths->root . $this->confDataLocation);

                $tmp_filename = md5(uniqid((string) time()));
                $fh = @fopen($this->paths->root . $this->confDataLocation . 'pwg_' . $tmp_filename, 'w');
                if ($fh !== false) {
                    @fputs($fh, $file_content, strlen($file_content));
                    @fclose($fh);
                }

                $this->template->assign(
                    [
                        'config_creation_failed' => true,
                        'config_url' => 'install.php?dl=' . $tmp_filename,
                        'config_file_content' => $file_content,
                    ]
                );
            } else {
                @fputs($fp, $file_content, strlen($file_content));
                @fclose($fp);
            }
            @umask($originalUmask);
        }

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
        // Migrator::migrate()/MigratorConfiguration directly -- confirmed
        // via a real PHPStan run that all 3 are internal Doctrine APIs
        // (method.internalInterface/new.internalClass), off limits from
        // outside the Doctrine root namespace; MigrateCommand itself is
        // the sanctioned public entry point, and running it this way also
        // means a future point release adding a new migration file here
        // becomes the real upgrade path for an existing install (bin/
        // piwigo migrations:migrate), not just a fresh-install mechanism.
        $migrationsEm = EntityManagerFactory::build($conn);
        $dependencyFactory = MigrationDependencyFactory::build($migrationsEm, $this->dbCredentials);
        $migrateInput = new ArrayInput([
            'version' => 'latest',
            '--allow-no-migration' => true,
        ]);
        $migrateInput->setInteractive(false);
        $migrateOutput = new BufferedOutput();
        $migrateExitCode = new MigrateCommand($dependencyFactory)
            ->run($migrateInput, $migrateOutput);
        if ($migrateExitCode !== 0) {
            throw new \RuntimeException(
                'Schema migration failed (migrations:migrate exit code ' . $migrateExitCode . '): ' . $migrateOutput->fetch()
            );
        }

        // We fill the tables with basic informations
        InstallService::executeSqlfile(
            $conn,
            $this->paths->root . 'install/config.sql',
            self::DEFAULT_PREFIX_TABLE,
            $this->prefixeTable,
        );

        // gap-closure Stage 1a-bis item 5: config.value is JSON now --
        // json_encode() the value (not the bare hex string) so
        // ConfigService::hydrate()'s json_decode() read side gets back a
        // real string instead of failing to parse it.
        $secretKeyJson = json_encode(sha1(random_bytes(1000)));
        assert($secretKeyJson !== false);

        $configTable = $this->prefixeTable . 'config';
        $query = <<<SQL
            INSERT INTO {$configTable} (param,value,comment)
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
        $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();
        $languageActivationConn = DbConnection::build();
        new ExtensionLifecycle(
            $this->lang,
            new ExtensionRepository(\Piwigo\Db\EntityManagerFactory::build($languageActivationConn)),
            new PemCatalog(new ZipExtractor(), \Piwigo\Bootstrap\InstallBootstrap::currentLogger()),
            $urlService,
            $configService,
            \Piwigo\Db\EntityManagerFactory::build($languageActivationConn)->getRepository(\Piwigo\Admin\Extensions\PluginMigrationEntity::class),
            \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
            \Piwigo\Bootstrap\CoreDomainAccessor::userService(),
            \Piwigo\Bootstrap\PresentationAccessor::htmlService(),
            $this->currentConfig,
        )->performAction(ExtensionType::Language, 'activate', $this->language, $this->fsLanguages[$this->language] ?? null);

        // fill CurrentConfig::$data from the freshly-seeded config table
        $configService->loadConfFromDb();

        // PWG_CHARSET (required for building the fs_themes array in the
        // themes class) is guaranteed defined here: the entry shell
        // define()s it right before calling this method, so the former
        // `if (! defined('PWG_CHARSET'))` re-guard that sat here was
        // provably dead and was dropped in the 8f-6 port (SEC-60 forbids
        // define() in src/Piwigo anyway).
        InstallService::activateCoreThemes();
        InstallService::activateCorePlugins();

        $insert = [
            'id' => 1,
            'galleries_url' => $this->paths->root . 'galleries/',
        ];
        new BatchWriter($conn)
            ->massInsert(Tables::sites(), array_keys($insert), [$insert]);
        new DbInfo($conn)
            ->resyncIdentitySequence(Tables::sites());

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
            ->massInsert(Tables::users(), array_keys($inserts[0]), $inserts);
        new DbInfo($conn)
            ->resyncIdentitySequence(Tables::users());

        $this->userService($conn)
            ->createUserInfos([\Piwigo\Common\ValueObject\UserId::from(1), \Piwigo\Common\ValueObject\UserId::from(2)], [
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
        foreach ($this->fsLanguages as $language_code => $fs_language) {
            if ($this->language === $language_code) {
                $template->assign('language_selection', $language_code);
            }
            $fs_language_name = $fs_language['name'] ?? null;
            $languages_options[$language_code] = is_string($fs_language_name) ? $fs_language_name : $language_code;
        }
        $template->assign('language_options', $languages_options);

        $template->assign(
            [
                'T_CONTENT_ENCODING' => 'utf-8',
                'RELEASE' => AppInfo::VERSION,
                'F_ACTION' => 'install.php?language=' . $this->language,
                'F_DB_HOST' => $this->dbhost,
                'F_DB_USER' => $this->dbuser,
                'F_DB_NAME' => $this->dbname,
                'F_DB_PREFIX' => $this->prefixeTable,
                'F_DB_DRIVER' => $this->dblayer,
                'F_DB_PORT' => $this->dbport,
                'F_ADMIN' => $this->adminName,
                'F_ADMIN_EMAIL' => $this->adminMail,
                'EMAIL' => '<span class="adminEmail">' . $this->adminMail . '</span>',
                'F_NEWSLETTER_SUBSCRIBE' => $this->isNewsletterSubscribe,
                'L_INSTALL_HELP' => $this->lang->t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', AppInfo::URL . '/forum'),
            ]
        );

        // -------------------------------------------- errors & infos display
        if ($this->step === 1) {
            $template->assign('install', true);
        } else {
            // Only reached once performInstall() (step 2) already ran
            // successfully with this same connection.
            $conn = $this->conn;
            if (! $conn instanceof Connection) {
                throw new \LogicException('render() reached step 2 before a successful analyzeForm() connection.');
            }

            new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class))
                ->record('system', ActivitySystem::Core, 'install', [
                    'version' => AppInfo::VERSION,
                ]);
            $this->infos[] = $this->lang->t('Congratulations, Piwigo installation is completed');

            // The former top-level code wrapped everything below in
            // `if (isset($error_copy)) { $errors[] = $error_copy; } else {...}`;
            // $error_copy was a relic of the long-removed copy-of-files
            // install step and is assigned nowhere in the whole codebase
            // (verified by full-repo grep in the 8f-6 port), so the isset()
            // was always false and the guard was dropped.

            // See Piwigo\Bootstrap\SessionBootstrap (kept inline here: at
            // this point of the install PHPWG_INSTALLED was only just
            // define()d and this block ran unconditionally in the original,
            // without SessionBootstrap::register()'s
            // session_save_handler === 'db' guard)
            session_set_save_handler(new PwgSession(new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Session\SessionEntity::class), $this->currentConfig), \Piwigo\Bootstrap\InstallBootstrap::currentLogger()));
            if (function_exists('ini_set')) {
                ini_set('session.use_cookies', $this->currentConfig->sessionUseCookies());
                ini_set('session.use_only_cookies', $this->currentConfig->sessionUseOnlyCookies());
                ini_set('session.use_trans_sid', (int) $this->currentConfig->sessionUseTransSid());
                ini_set('session.cookie_httponly', 1);
            }
            session_name($this->currentConfig->sessionName());
            session_set_cookie_params(0, new CookieService()->cookiePath());
            register_shutdown_function(session_write_close(...));

            $user = $this->userService($conn)
                ->buildUser(\Piwigo\Common\ValueObject\UserId::from(1));
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
            // Real bug, found via a fixture-regeneration discrepancy (an
            // activity row expected to be attributed to the new admin came
            // back performed_by=NULL instead): this install flow never goes
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
            \Piwigo\Users\CurrentUser::current()->set(\Piwigo\Users\User::fromUserArray($user));
            \Piwigo\Users\CurrentUser::current()->markRealUserResolved();
            new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\EntityManagerFactory::build($conn)), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)), \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->passwordService($conn), new \Piwigo\Auth\CookieService(), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Auth\UserFailedLoginEntity::class), new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Session\SessionEntity::class), $this->currentConfig), \Piwigo\PluginConfig\EventDispatcher::get(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), $this->currentConfig)
                ->logUser($login_user_id, false);
            $_SESSION['connected_with'] = 'pwg_ui';

            // Same reason: narrow 'preferences' to array without discarding
            // whatever getuserdata() already populated it with.
            $preferences = $user['preferences'] ?? null;
            $preferences = is_array($preferences) ? $preferences : [];
            $preferences['show_whats_new_' . \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION)] = false;
            $user['preferences'] = $preferences;

            // newsletter subscription
            if ($this->isNewsletterSubscribe) {
                // Fire-and-forget: the response content is never read, only the
                // request's side effect (subscribing $admin_mail) matters.
                HttpClientService::fetch(
                    AdminUiHelper::getNewsletterSubscribeBaseUrl($this->language) . $this->adminMail,
                    [],
                    [
                        'origin' => 'installation',
                    ]
                );

                $preferences['show_newsletter_subscription'] = false;
                $user['preferences'] = $preferences;
            }

            // Legacy Coupling Retirement Phase 8 gap-closure: sync CurrentUser
            // before PreferencesService::save() reads it -- this install-time
            // $user is a fresh buildUser(1) result, never routed
            // through RequestBootstrap/UserBootstrap's own sync calls. (The
            // raw global $user bridge this comment used to reference was
            // fully retired once every consumer -- including this one --
            // moved onto CurrentUser.)
            \Piwigo\Users\CurrentUser::current()->set(\Piwigo\Users\User::fromUserArray($user));

            new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\EntityManagerFactory::build($conn), \Piwigo\PluginConfig\EventDispatcher::get(), $this->currentConfig), \Piwigo\Users\CurrentUser::current())
                ->save();

            // email notification
            if ($this->request->isSendCredentialsByMail) {
                $keyargs_content = [
                    $this->lang->buildArgs('Hello %s,', $this->adminName),
                    $this->lang->buildArgs('Welcome to your new installation of Piwigo!', ''),
                    $this->lang->buildArgs('', ''),
                    $this->lang->buildArgs('Here are your connection settings', ''),
                    $this->lang->buildArgs('', ''),
                    $this->lang->buildArgs('Link: %s', \Piwigo\Bootstrap\PresentationAccessor::urlService()->getAbsoluteRootUrl()),
                    $this->lang->buildArgs('Username: %s', $this->adminName),
                    $this->lang->buildArgs('Password: ********** (no copy by email)', ''),
                    $this->lang->buildArgs('Email: %s', $this->adminMail),
                    $this->lang->buildArgs('', ''),
                    $this->lang->buildArgs('Don\'t hesitate to consult our forums for any help: %s', AppInfo::URL),
                ];

                \Piwigo\Bootstrap\PresentationAccessor::mailService()
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
        if (count($this->errors) !== 0) {
            $template->assign('errors', $this->errors);
        }

        if (count($this->infos) !== 0) {
            $template->assign('infos', $this->infos);
        }

        // ------------------------------------------------- html code display
        $template->pparse('install');
    }
}
