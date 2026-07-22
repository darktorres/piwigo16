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
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\AdminUiHelper;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Install\DbPatch\LegacyFileConf;
use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\HttpClientService;
use Piwigo\Mail\MailService;
use Piwigo\Session\PwgSession;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

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
 * through the raw file, not through `Config::`'s accessors, same
 * reasoning as the DbPatch/VersionUpgrade file family. render()'s own
 * former `global $user;` was fully retired
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
 * (piwigo_structure-mysql.sql/config.sql ran immediately above).
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
     * @var array<string, array<string, mixed>>
     */
    private array $fsLanguages;

    /**
     * Built by analyzeForm() -> InstallService::installDbConnect(); non-null once hasErrors() is false.
     */
    private ?Connection $conn = null;

    private string $language = 'en_UK';

    private Template $template;

    private int $step = 1;

    public function __construct(
        private readonly string $prefixeTable,
        private readonly Paths $paths,
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
     * "form analyze" section: the ?dl= database-config download (may
     * exit()), $_POST narrowing + Config seeding, environment checks,
     * language pick + Lang loads, and template initialization.
     */
    public function boot(): void
    {
        // download database config file if exists
        new \Piwigo\Validation\InputValidator()
            ->validate('dl', $_GET, false, '/^[a-f0-9]{32}$/');

        $dl_param = $_GET['dl'] ?? null;
        if (is_string($dl_param) && $dl_param !== '' && file_exists($this->paths->root . $this->confDataLocation . 'pwg_' . $dl_param)) {
            $filename = $this->paths->root . $this->confDataLocation . 'pwg_' . $dl_param;
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Content-Disposition: attachment; filename="database.inc.php"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . filesize($filename));
            echo file_get_contents($filename);
            unlink($filename);
            exit();
        }

        // Obtain various vars
        $this->dbhost = (is_string($_POST['dbhost'] ?? null) && $_POST['dbhost'] !== '') ? $_POST['dbhost'] : 'localhost';
        $this->dbuser = (is_string($_POST['dbuser'] ?? null) && $_POST['dbuser'] !== '') ? $_POST['dbuser'] : '';
        $this->dbpasswd = (is_string($_POST['dbpasswd'] ?? null) && $_POST['dbpasswd'] !== '') ? $_POST['dbpasswd'] : '';
        $this->dbname = (is_string($_POST['dbname'] ?? null) && $_POST['dbname'] !== '') ? $_POST['dbname'] : '';

        // Same reasoning as the db_prefix seeding in the install.php entry
        // shell: InstallBootstrap::boot() (Legacy Coupling Retirement Phase
        // 8, 8b) only seeds SCHEMA defaults + env overrides before this
        // point, so any code reached later in this same request that
        // resolves a DB connection via Piwigo\Db\DbConnection::build()
        // (which reads Config::dbHost()/dbUser()/dbPassword()/dbName())
        // would otherwise silently see those instead of the real submitted
        // credentials. Found live: get_default_user_value() -> UserService ->
        // UserRepository -> DbConnection::build(), reached from
        // InstallService::activateCoreThemes() during step-2 theme
        // activation, fatals with "Access denied for user ''@'localhost'"
        // without this.
        Config::override('db_host', $this->dbhost);
        Config::override('db_user', $this->dbuser);
        Config::override('db_password', $this->dbpasswd);
        Config::override('db_base', $this->dbname);

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
        // goes through CommonBootstrap::run() (only InstallBootstrap::boot(),
        // which doesn't touch CurrentUser), so CurrentUser is never guest-
        // initialized either. Found live (real
        // fixture-regen run, not assumed): InstallService::
        // activateCoreThemes() -> ExtensionScanner::scan()'s missing-
        // screenshot fallback -> PreferencesService::getParam() ->
        // CurrentUser::get() -> uncaught "CurrentUser not initialised".
        // attachGlobals() is exactly the safe guest default this no-boot
        // path needs (idempotent; a later real CurrentUser::set() in
        // render() is never clobbered by this).
        \Piwigo\Users\CurrentUser::attachGlobals();

        // Same no-boot gap, third dependency: CurrentLogger. Found live one
        // step later than CurrentUser (render()'s UserService::buildUser()
        // -> getUserData() -> CurrentLogger::get()). Same construction
        // recipe as RequestBootstrap::connect()'s (the normal request
        // pipeline's own site) -- no DB access needed, just Config reads
        // already valid this early (db_password was just overridden
        // above). Unlike RequestBootstrap's equivalent site, these Config::
        // calls are direct (no by-ref Env::applyEnvToConf() narrowing loss
        // in between), so their declared `string` return types are already
        // certain -- no is_string() re-guard needed.
        \Piwigo\Core\CurrentLogger::set(new Logger([
            'directory' => $this->paths->root . Config::dataLocation() . Config::logDir(),
            'severity' => Config::logLevel(),
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => Config::logArchiveDays(),
        ]));

        // dblayer
        if (! extension_loaded('mysqli')) {
            new HtmlService()
                ->fatalError('PHP extension "mysqli" is not loaded');
        }
        $this->dblayer = 'mysqli';

        $this->adminName = (is_string($_POST['admin_name'] ?? null) && $_POST['admin_name'] !== '') ? $_POST['admin_name'] : '';
        $this->adminPass1 = (is_string($_POST['admin_pass1'] ?? null) && $_POST['admin_pass1'] !== '') ? $_POST['admin_pass1'] : '';
        $this->adminPass2 = (is_string($_POST['admin_pass2'] ?? null) && $_POST['admin_pass2'] !== '') ? $_POST['admin_pass2'] : '';
        $this->adminMail = (is_string($_POST['admin_mail'] ?? null) && $_POST['admin_mail'] !== '') ? $_POST['admin_mail'] : '';

        if (isset($_POST['install'])) {
            $this->isNewsletterSubscribe = isset($_POST['newsletter_subscribe']);
        }

        // Is Piwigo already installed ?
        if (file_exists($this->paths->siteLocal . Env::testModeInstalledStamp())) {
            new HtmlService()
                ->fatalError('Piwigo is already installed');
        }

        $this->fsLanguages = new ExtensionScanner()
            ->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');

        if (isset($_GET['language']) && is_string($_GET['language'])) {
            $language = strip_tags($_GET['language']);

            if (! in_array($language, array_keys($this->fsLanguages))) {
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

        Lang::load('common.lang', '', [
            'language' => $language,
        ]);
        Lang::load('admin.lang', '', [
            'language' => $language,
        ]);
        Lang::load('install.lang', '', [
            'language' => $language,
        ]);

        header('Content-Type: text/html; charset=UTF-8');
        // ----------------------------------------------- check php version
        if (version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION, '<')) {
            $this->errors[] = Lang::t('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION);
        }

        // --------------------------------------------- template initialization
        $template = new Template($this->paths->root . 'admin/themes', 'clear');
        \Piwigo\Template\CurrentTemplate::set($template);
        $template->set_filenames([
            'install' => 'install.tpl',
        ]);
        $this->template = $template;
    }

    /**
     * DRY-extracted (Legacy Coupling Retirement Phase 8, 8b) -- was 3
     * identical `new UserService(new UserRepository($c), new
     * GroupRepository($c), new MailService(), new ActivityService(new
     * ActivityRepository($c)), new HtmlService(), $c)` chains inline
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
        return new UserService(new UserRepository($conn), new GroupRepository($conn), new MailService(), new ActivityService(new ActivityRepository($conn)), new HtmlService(), $conn);
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
            $this->errors[] = Lang::t('enter a login for webmaster');
        } elseif ((bool) preg_match('/[\'"]/', $webmaster)) {
            $this->errors[] = Lang::t('webmaster login can\'t contain characters \' or "');
        }
        if ($this->adminPass1 !== $this->adminPass2 || $this->adminPass1 === '') {
            $this->errors[] = Lang::t('please enter your password again');
        }
        if ($this->adminMail === '') {
            $this->errors[] = Lang::t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
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

        // Write .env (or .env.test in test mode) with DB credentials — atomic rename.
        $env_file = $this->paths->root . Env::testModeEnvFile();
        // Strip line-breaks to prevent .env injection via crafted POST values.
        $env_vals = array_map(
            fn (string $v): string => str_replace(["\n", "\r", "\0"], '', $v),
            [$this->dbhost, $this->dbuser, $this->dbpasswd, $this->dbname, $this->prefixeTable]
        );
        $env_body = 'PIWIGO_DB_HOST=' . $env_vals[0] . "\n" . 'PIWIGO_DB_USER=' . $env_vals[1] . "\n"
                  . 'PIWIGO_DB_PASSWORD=' . $env_vals[2] . "\n" . 'PIWIGO_DB_BASE=' . $env_vals[3] . "\n"
                  . 'PIWIGO_DB_PREFIX=' . $env_vals[4] . "\n";
        // In test mode, also record the base URL so e2e runners know where to connect.
        if (Env::testModeIsActive()) {
            $scheme = (! in_array($_SERVER['HTTPS'] ?? null, [null, false, 0, '0', ''], true) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = is_string($host) ? $host : 'localhost';
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $script = is_string($script) ? $script : '';
            $base_url = rtrim($scheme . '://' . $host . dirname($script), '/');
            if ($base_url !== '') {
                $env_body .= 'PIWIGO_BASE_URL=' . $base_url . "\n";
            }
        }

        // Re-installing (e.g. tests/Browser/RegenerateFixtureTest.php) must not
        // silently drop custom vars a previous write left in this same file
        // (e.g. PIWIGO_TEST_NOW — see Piwigo\Core\Env::now()). Preserve
        // any line whose key isn't one this block itself manages.
        $env_managed_keys = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX', 'PIWIGO_BASE_URL'];
        if (is_file($env_file)) {
            $existing_lines = @file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($existing_lines !== false ? $existing_lines : [] as $existing_line) {
                $existing_key = strtok($existing_line, '=');
                if ($existing_key !== false && ! in_array($existing_key, $env_managed_keys, true)) {
                    $env_body .= $existing_line . "\n";
                }
            }
        }

        $env_tmp = $env_file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($env_tmp, $env_body) === false || ! rename($env_tmp, $env_file)) {
            @unlink($env_tmp);
            $this->errors[] = 'Could not write ' . $env_file . ' — check filesystem permissions.';
        }

        // Also write legacy database.inc.php in prod mode so upgrade.php and other
        // not-yet-migrated scripts keep working (see docs/PLAN-REPLAY.md P13).
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

            @umask(0111);
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
        }

        // Create install sentinel stamp file.
        if (count($this->errors) === 0) {
            touch($this->paths->siteLocal . Env::testModeInstalledStamp());
        }

        // tables creation, based on piwigo_structure.sql
        InstallService::executeSqlfile(
            $conn,
            $this->paths->root . 'install/piwigo_structure-mysql.sql',
            self::DEFAULT_PREFIX_TABLE,
            $this->prefixeTable,
            'mysql'
        );
        // We fill the tables with basic informations
        InstallService::executeSqlfile(
            $conn,
            $this->paths->root . 'install/config.sql',
            self::DEFAULT_PREFIX_TABLE,
            $this->prefixeTable,
            'mysql'
        );

        $query = '
INSERT INTO ' . $this->prefixeTable . 'config (param,value,comment)
   VALUES (\'secret_key\',\'' . sha1(random_bytes(1000)) . '\',
   \'a secret key specific to the gallery for internal use\');';
        $conn->executeStatement($query);

        $configService = \Piwigo\Config\CurrentConfigService::get();
        $configService->confUpdateParam('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));
        $configService->confUpdateParam('gallery_title', Lang::t('Just another Piwigo gallery'));

        $configService->confUpdateParam(
            'page_banner',
            '<h1>%gallery_title%</h1>' . "\n\n<p>" . Lang::t('Welcome to my photo gallery') . '</p>'
        );

        // fill languages table, only activate the current language
        $urlService = new UrlService(new HtmlService());
        new ExtensionLifecycle(
            new ExtensionRepository(DbConnection::build()),
            new PemCatalog(new ZipExtractor()),
            $urlService,
            $configService,
        )->performAction(ExtensionType::Language, 'activate', $this->language, $this->fsLanguages[$this->language] ?? null);

        // fill Config::$data from the freshly-seeded config table
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

        // webmaster admin user
        $inserts = [
            [
                'id' => 1, // must be the same value as webmaster_id in config.sql
                'username' => $this->adminName,
                'password' => new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($conn))
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

        $this->userService($conn)
            ->createUserInfos([1, 2], [
                'language' => $this->language,
            ]);

        // Available upgrades must be ignored after a fresh installation. To
        // make PWG avoid upgrading, we must tell it upgrades have already been
        // made.
        $row = $conn->fetchNumeric('SELECT NOW();');
        assert($row !== false);
        // Former top-level code define()d CURRENT_DATE from this value;
        // SEC-60 forbids define() in src/Piwigo and nothing else on the
        // install path ever reads that constant (its only real readers are
        // the frozen install/upgrade_*.php scripts, which never run during
        // a fresh install), so it stays a plain local here.
        [$dbnow] = $row;
        $datas = [];
        foreach (UpgradeService::getAvailableUpgradeIds() as $upgrade_id) {
            $datas[] = [
                'id' => $upgrade_id,
                'applied' => $dbnow,
                'description' => 'upgrade included in installation',
            ];
        }
        new BatchWriter($conn)
            ->massInsert(
                Tables::upgrade(),
                array_keys($datas[0]),
                $datas
            );
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
            $languages_options[$language_code] = $fs_language['name'];
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
                'F_ADMIN' => $this->adminName,
                'F_ADMIN_EMAIL' => $this->adminMail,
                'EMAIL' => '<span class="adminEmail">' . $this->adminMail . '</span>',
                'F_NEWSLETTER_SUBSCRIBE' => $this->isNewsletterSubscribe,
                'L_INSTALL_HELP' => Lang::t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', AppInfo::URL . '/forum'),
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

            new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn))
                ->record('system', ActivitySystem::Core, 'install', [
                    'version' => AppInfo::VERSION,
                ]);
            $this->infos[] = Lang::t('Congratulations, Piwigo installation is completed');

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
            session_set_save_handler(new PwgSession());
            if (function_exists('ini_set')) {
                ini_set('session.use_cookies', Config::sessionUseCookies());
                ini_set('session.use_only_cookies', Config::sessionUseOnlyCookies());
                ini_set('session.use_trans_sid', (int) Config::sessionUseTransSid());
                ini_set('session.cookie_httponly', 1);
            }
            session_name(Config::sessionName());
            session_set_cookie_params(0, new CookieService()->cookiePath());
            register_shutdown_function(session_write_close(...));

            // we don't load user cache because since Piwigo 15.4.0 the calculation of user
            // cache requires $logger which is not instanciated
            $user = $this->userService($conn)
                ->buildUser(1, false);
            // build_user() returns array<string, mixed>; the 'id' key we just set
            // to the literal user id 1 doesn't retain that literal type through
            // the return, so narrow to what log_user() actually accepts.
            $login_user_id = $user['id'];
            $login_user_id = is_int($login_user_id) || (is_string($login_user_id) && is_numeric($login_user_id)) ? $login_user_id : false;
            new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository($conn), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn)), new HtmlService(), new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($conn)), new \Piwigo\Auth\CookieService())
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
            // $user is a fresh buildUser(1, false) result, never routed
            // through RequestBootstrap/UserBootstrap's own sync calls. (The
            // raw global $user bridge this comment used to reference was
            // fully retired once every consumer -- including this one --
            // moved onto CurrentUser.)
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));

            new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository($conn))
                ->save();

            // email notification
            if (isset($_POST['send_credentials_by_mail'])) {
                $keyargs_content = [
                    Lang::buildArgs('Hello %s,', $this->adminName),
                    Lang::buildArgs('Welcome to your new installation of Piwigo!', ''),
                    Lang::buildArgs('', ''),
                    Lang::buildArgs('Here are your connection settings', ''),
                    Lang::buildArgs('', ''),
                    Lang::buildArgs('Link: %s', new UrlService(new HtmlService())->getAbsoluteRootUrl()),
                    Lang::buildArgs('Username: %s', $this->adminName),
                    Lang::buildArgs('Password: ********** (no copy by email)', ''),
                    Lang::buildArgs('Email: %s', $this->adminMail),
                    Lang::buildArgs('', ''),
                    Lang::buildArgs('Don\'t hesitate to consult our forums for any help: %s', AppInfo::URL),
                ];

                new MailService()
                    ->mail(
                        $this->adminMail,
                        [
                            'subject' => Lang::t('Just another Piwigo gallery'),
                            'content' => Lang::args($keyargs_content),
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
