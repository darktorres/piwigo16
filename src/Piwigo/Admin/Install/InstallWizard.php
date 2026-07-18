<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Piwigo\Admin\AdminUiHelper;
use Piwigo\Admin\languages;
use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\HttpClientService;
use Piwigo\Mail\MailService;
use Piwigo\Session\PwgSession;
use Piwigo\Template\Template;

/**
 * install.php's orchestration, ported verbatim from that script's former
 * top-level code (P23 sub-batch 8f-6). The install.php entry shell keeps
 * only bootstrap (env/config includes, the SEC-60-constrained define()s)
 * and drives this wizard: boot() -> [POST: analyzeForm(); no errors ->
 * shell define()s PHPWG_INSTALLED/PWG_CHARSET/DB_CHARSET/DB_COLLATE ->
 * performInstall()] -> render().
 *
 * The methods declare the globals the former top-level scope shared with
 * the code they call ($conf everywhere; $user in render() --
 * AuthService::logUser() and PreferencesService's methods all read/write
 * `global $user`; $template because updates/mail-era template helpers
 * historically resolve the shared instance through the global).
 */
class InstallWizard
{
    private string $confDataLocation;

    private string $configFile;

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

    private languages $languages;

    private string $language = 'en_UK';

    private Template $template;

    private int $step = 1;

    public function __construct(
        private readonly string $prefixeTable,
    ) {
        /** @var array<string, mixed> $conf */
        global $conf;

        // $conf['data_location'] needs narrowing here specifically (used to
        // build on-disk paths below); narrowed once and reused for every use
        // in this request, same pattern as feed.php/i.php/common.inc.php.
        $conf_data_location = $conf['data_location'] ?? null;
        if (! is_string($conf_data_location)) {
            die("Invalid \$conf['data_location'] configuration: expected a string.");
        }
        $this->confDataLocation = $conf_data_location;

        $this->configFile = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php';
    }

    /**
     * Everything the former install.php top level did before the
     * "form analyze" section: the ?dl= database-config download (may
     * exit()), $_POST narrowing + Config seeding, environment checks,
     * language pick + Lang loads, and template initialization.
     */
    public function boot(): void
    {
        global $template;

        // download database config file if exists
        (new \Piwigo\Validation\InputValidator())->validate('dl', $_GET, false, '/^[a-f0-9]{32}$/');

        $dl_param = $_GET['dl'] ?? null;
        if (is_string($dl_param) && $dl_param !== '' && file_exists(PHPWG_ROOT_PATH . $this->confDataLocation . 'pwg_' . $dl_param)) {
            $filename = PHPWG_ROOT_PATH . $this->confDataLocation . 'pwg_' . $dl_param;
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
        $this->dbhost = (! empty($_POST['dbhost']) && is_string($_POST['dbhost'])) ? $_POST['dbhost'] : 'localhost';
        $this->dbuser = (! empty($_POST['dbuser']) && is_string($_POST['dbuser'])) ? $_POST['dbuser'] : '';
        $this->dbpasswd = (! empty($_POST['dbpasswd']) && is_string($_POST['dbpasswd'])) ? $_POST['dbpasswd'] : '';
        $this->dbname = (! empty($_POST['dbname']) && is_string($_POST['dbname'])) ? $_POST['dbname'] : '';

        // Same reasoning as the db_prefix seeding in the install.php entry
        // shell: this request never goes through Kernel::boot()/ConfigLoader,
        // so any code reached later in this same request that resolves a DB
        // connection via Piwigo\Db\DbConnection::build() (which reads
        // Config::dbHost()/dbUser()/dbPassword()/dbName()) would otherwise
        // silently see SCHEMA defaults instead of the real submitted
        // credentials. Found live: get_default_user_value() -> UserService ->
        // UserRepository -> DbConnection::build(), reached from
        // InstallService::activateCoreThemes() during step-2 theme
        // activation, fatals with "Access denied for user ''@'localhost'"
        // without this.
        Config::override('db_host', $this->dbhost);
        Config::override('db_user', $this->dbuser);
        Config::override('db_password', $this->dbpasswd);
        Config::override('db_base', $this->dbname);

        // dblayer
        if (! extension_loaded('mysqli')) {
            new HtmlService()
                ->fatalError('PHP extension "mysqli" is not loaded');
        }
        $this->dblayer = 'mysqli';

        $this->adminName = (! empty($_POST['admin_name']) && is_string($_POST['admin_name'])) ? $_POST['admin_name'] : '';
        $this->adminPass1 = (! empty($_POST['admin_pass1']) && is_string($_POST['admin_pass1'])) ? $_POST['admin_pass1'] : '';
        $this->adminPass2 = (! empty($_POST['admin_pass2']) && is_string($_POST['admin_pass2'])) ? $_POST['admin_pass2'] : '';
        $this->adminMail = (! empty($_POST['admin_mail']) && is_string($_POST['admin_mail'])) ? $_POST['admin_mail'] : '';

        if (isset($_POST['install'])) {
            $this->isNewsletterSubscribe = isset($_POST['newsletter_subscribe']);
        }

        // Is Piwigo already installed ?
        if (file_exists(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . Env::testModeInstalledStamp())) {
            die('Piwigo is already installed');
        }

        $this->languages = new languages('utf-8');

        if (isset($_GET['language']) && is_string($_GET['language'])) {
            $language = strip_tags($_GET['language']);

            if (! in_array($language, array_keys($this->languages->fs_languages))) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $language = 'en_UK';
            // Try to get browser language
            $accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
            $accept_language = is_string($accept_language) ? $accept_language : '';
            foreach ($this->languages->fs_languages as $language_code => $fs_language) {
                if (substr($language_code, 0, 2) == substr($accept_language, 0, 2)) {
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
            $this->errors[] = l10n('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION);
        }

        // --------------------------------------------- template initialization
        $template = new Template(PHPWG_ROOT_PATH . 'admin/themes', 'clear');
        \Piwigo\Template\CurrentTemplate::set($template);
        $template->set_filenames([
            'install' => 'install.tpl',
        ]);
        $this->template = $template;
    }

    /**
     * Former install.php "form analyze" validation block (DB connection
     * attempt + prefix/webmaster/password/mail checks). Only called when
     * $_POST['install'] is set.
     */
    public function analyzeForm(): void
    {
        InstallService::installDbConnect($this->infos, $this->errors);

        if (count($this->errors) > 0) {
            print_r($this->errors);
        }

        \Piwigo\Db\MysqliDb::checkCharset();

        if (
            strlen($this->prefixeTable) > 20
            or (bool) preg_match('/^\d/', $this->prefixeTable)
            or ! (bool) preg_match('/^[a-zA-Z0-9_$]*$/u', $this->prefixeTable)
        ) {
            $this->errors[] = 'invalid table prefix';
        }

        $webmaster = trim((string) preg_replace('/\s{2,}/', ' ', $this->adminName));
        if (empty($webmaster)) {
            $this->errors[] = l10n('enter a login for webmaster');
        } elseif ((bool) preg_match('/[\'"]/', $webmaster)) {
            $this->errors[] = l10n('webmaster login can\'t contain characters \' or "');
        }
        if ($this->adminPass1 != $this->adminPass2 || empty($this->adminPass1)) {
            $this->errors[] = l10n('please enter your password again');
        }
        if (empty($this->adminMail)) {
            $this->errors[] = l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        } else {
            $error_mail_address = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->validateMailAddress(null, $this->adminMail);
            if (! empty($error_mail_address)) {
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
        /** @var array<string, mixed> $conf */
        global $conf;

        $this->step = 2;

        // Write .env (or .env.test in test mode) with DB credentials — atomic rename.
        $env_file = PHPWG_ROOT_PATH . Env::testModeEnvFile();
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
            $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
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
        if (! Env::testModeIsActive() && count($this->errors) == 0) {
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
                \Piwigo\Core\FilesystemHelper::secureDirectory(PHPWG_ROOT_PATH . $this->confDataLocation);

                $tmp_filename = md5(uniqid((string) time()));
                $fh = @fopen(PHPWG_ROOT_PATH . $this->confDataLocation . 'pwg_' . $tmp_filename, 'w');
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
        if (count($this->errors) == 0) {
            touch(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . Env::testModeInstalledStamp());
        }

        // tables creation, based on piwigo_structure.sql
        InstallService::executeSqlfile(
            PHPWG_ROOT_PATH . 'install/piwigo_structure-mysql.sql',
            DEFAULT_PREFIX_TABLE,
            $this->prefixeTable,
            'mysql'
        );
        // We fill the tables with basic informations
        InstallService::executeSqlfile(
            PHPWG_ROOT_PATH . 'install/config.sql',
            DEFAULT_PREFIX_TABLE,
            $this->prefixeTable,
            'mysql'
        );

        $query = '
INSERT INTO ' . $this->prefixeTable . 'config (param,value,comment)
   VALUES (\'secret_key\',\'' . sha1(random_bytes(1000)) . '\',
   \'a secret key specific to the gallery for internal use\');';
        \Piwigo\Db\MysqliDb::query($query);

        \Piwigo\Config\ConfigDb::confUpdateParam('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));
        \Piwigo\Config\ConfigDb::confUpdateParam('gallery_title', \Piwigo\Db\MysqliDb::realEscapeString(l10n('Just another Piwigo gallery')));

        \Piwigo\Config\ConfigDb::confUpdateParam(
            'page_banner',
            '<h1>%gallery_title%</h1>' . "\n\n<p>" . \Piwigo\Db\MysqliDb::realEscapeString(l10n('Welcome to my photo gallery')) . '</p>'
        );

        // fill languages table, only activate the current language
        $this->languages->perform_action('activate', $this->language);

        // fill $conf global array
        \Piwigo\Config\ConfigDb::loadConfFromDb();

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
            'galleries_url' => PHPWG_ROOT_PATH . 'galleries/',
        ];
        \Piwigo\Db\MysqliDb::massInserts(Tables::sites(), array_keys($insert), [$insert]);

        // webmaster admin user
        $inserts = [
            [
                'id' => 1, // must be the same value as webmaster_id in config.sql
                'username' => $this->adminName,
                'password' => (new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build())))->hash($this->adminPass1),
                'mail_address' => $this->adminMail,
            ],
            [
                'id' => 2,
                'username' => 'guest',
            ],
        ];
        \Piwigo\Db\MysqliDb::massInserts(Tables::users(), array_keys($inserts[0]), $inserts);

        (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->createUserInfos([1, 2], [
            'language' => $this->language,
        ]);

        // Available upgrades must be ignored after a fresh installation. To
        // make PWG avoid upgrading, we must tell it upgrades have already been
        // made.
        $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query('SELECT NOW();'));
        assert($row !== null);
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
        \Piwigo\Db\MysqliDb::massInserts(
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
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $user;

        $template = $this->template;

        $languages_options = [];
        foreach ($this->languages->fs_languages as $language_code => $fs_language) {
            if ($this->language == $language_code) {
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
                'L_INSTALL_HELP' => l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
            ]
        );

        // -------------------------------------------- errors & infos display
        if ($this->step == 1) {
            $template->assign('install', true);
        } else {
            (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('system', ActivitySystem::Core, 'install', [
                'version' => AppInfo::VERSION,
            ]);
            $this->infos[] = l10n('Congratulations, Piwigo installation is completed');

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
                $session_use_cookies = $conf['session_use_cookies'];
                $session_use_cookies = is_scalar($session_use_cookies) ? $session_use_cookies : null;
                ini_set('session.use_cookies', $session_use_cookies);

                $session_use_only_cookies = $conf['session_use_only_cookies'];
                $session_use_only_cookies = is_scalar($session_use_only_cookies) ? $session_use_only_cookies : null;
                ini_set('session.use_only_cookies', $session_use_only_cookies);

                $session_use_trans_sid = $conf['session_use_trans_sid'];
                $session_use_trans_sid = is_scalar($session_use_trans_sid) ? $session_use_trans_sid : 0;
                ini_set('session.use_trans_sid', intval($session_use_trans_sid));
                ini_set('session.cookie_httponly', 1);
            }
            $session_name = $conf['session_name'];
            $session_name = is_string($session_name) ? $session_name : null;
            session_name($session_name);
            session_set_cookie_params(0, new CookieService()->cookiePath());
            register_shutdown_function(session_write_close(...));

            // we don't load user cache because since Piwigo 15.4.0 the calculation of user
            // cache requires $logger which is not instanciated
            $user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->buildUser(1, false);
            // build_user() returns array<string, mixed>; the 'id' key we just set
            // to the literal user id 1 doesn't retain that literal type through
            // the return, so narrow to what log_user() actually accepts.
            $login_user_id = $user['id'];
            $login_user_id = is_int($login_user_id) || (is_string($login_user_id) && is_numeric($login_user_id)) ? $login_user_id : false;
            (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->logUser($login_user_id, false);
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

            // Legacy Coupling Retirement Track A batch A3: sync CurrentUser
            // (dual-write alongside the still-live global $user, matching
            // RequestBootstrap's own sync points) before PreferencesService::
            // save() reads it -- this install-time $user is a fresh
            // buildUser(1, false) result, never routed through
            // RequestBootstrap/UserBootstrap's own sync calls.
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));

            (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->save();

            // email notification
            if (isset($_POST['send_credentials_by_mail'])) {
                $keyargs_content = [
                    Lang::buildArgs('Hello %s,', $this->adminName),
                    Lang::buildArgs('Welcome to your new installation of Piwigo!', ''),
                    Lang::buildArgs('', ''),
                    Lang::buildArgs('Here are your connection settings', ''),
                    Lang::buildArgs('', ''),
                    Lang::buildArgs('Link: %s', get_absolute_root_url()),
                    Lang::buildArgs('Username: %s', $this->adminName),
                    Lang::buildArgs('Password: ********** (no copy by email)', ''),
                    Lang::buildArgs('Email: %s', $this->adminMail),
                    Lang::buildArgs('', ''),
                    Lang::buildArgs('Don\'t hesitate to consult our forums for any help: %s', PHPWG_URL),
                ];

                new MailService()
                    ->mail(
                        $this->adminMail,
                        [
                            'subject' => l10n('Just another Piwigo gallery'),
                            'content' => Lang::args($keyargs_content),
                            'content_format' => 'text/plain',
                        ]
                    );
            }
        }
        if (count($this->errors) != 0) {
            $template->assign('errors', $this->errors);
        }

        if (count($this->infos) != 0) {
            $template->assign('infos', $this->infos);
        }

        // ------------------------------------------------- html code display
        $template->pparse('install');
    }
}
