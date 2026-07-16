<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\languages;
use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Db\Tables;
use Piwigo\Mail\MailService;
use Piwigo\Session\PwgSession;
use Piwigo\Template\Template;

// ----------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');

include PHPWG_ROOT_PATH . 'include/env.inc.php';
pwg_load_env_file(PHPWG_ROOT_PATH);

// @set_magic_quotes_runtime(0); // Disable magic_quotes_runtime
//
// addslashes to vars if magic_quotes_gpc is off this is a security
// precaution to prevent someone trying to break out of a SQL statement.
//
if (function_exists('get_magic_quotes_gpc') && ! @get_magic_quotes_gpc()) {
    // Leaf values recursed into by array_walk_recursive() from $_GET/$_POST/
    // $_COOKIE are always strings in practice (HTTP request data never
    // contains scalars other than strings; arrays are recursed into rather
    // than passed to the callback), but the parameter is typed mixed so we
    // narrow rather than force-cast it. Same pattern as
    // include/common.inc.php's sanitize_mysql_kv().
    function install_sanitize_mysql_kv(mixed &$v, int|string $k): void
    {
        if (is_string($v)) {
            $v = addslashes($v);
        }
    }
    array_walk_recursive($_POST, install_sanitize_mysql_kv(...));
    array_walk_recursive($_GET, install_sanitize_mysql_kv(...));
    array_walk_recursive($_COOKIE, install_sanitize_mysql_kv(...));
}

// ----------------------------------------------------- variable initialization

define('DEFAULT_PREFIX_TABLE', 'piwigo_');

if (isset($_POST['install'])) {
    // Narrow to string (and guard the possibly-missing array key) rather than
    // trusting raw POST data downstream in SQL/file-content concatenation.
    $prefixeTable = is_string($_POST['prefix'] ?? null) ? $_POST['prefix'] : DEFAULT_PREFIX_TABLE;
} else {
    $prefixeTable = DEFAULT_PREFIX_TABLE;
}

// Piwigo\Db\Tables::*() (used below and by admin/include/functions.php's
// own procedural functions once included) reads Config::dbPrefix() --
// this script never goes through Kernel::boot()/ConfigLoader (there's no
// database.inc.php to read from until this wizard writes one), so the
// user-chosen $prefixeTable must be seeded into Config's static state
// directly, or every Tables::*() call downstream silently falls back to
// the 'piwigo_' SCHEMA default instead of the real chosen prefix.
Config::override('db_prefix', $prefixeTable);

include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

// Bootstrap global, set by include/config_default.inc.php.
/** @var array<string, mixed> $conf */
global $conf;

include PHPWG_ROOT_PATH . 'include/functions.inc.php';

// download database config file if exists
(new \Piwigo\Validation\InputValidator())->validate('dl', $_GET, false, '/^[a-f0-9]{32}$/');

// $conf['data_location'] needs narrowing here specifically (used to build
// on-disk paths below); narrowed once and reused for every use in this
// script, same pattern as feed.php/i.php/common.inc.php.
$conf_data_location = $conf['data_location'] ?? null;
if (! is_string($conf_data_location)) {
    die("Invalid \$conf['data_location'] configuration: expected a string.");
}

$dl_param = $_GET['dl'] ?? null;
if (is_string($dl_param) && $dl_param !== '' && file_exists(PHPWG_ROOT_PATH . $conf_data_location . 'pwg_' . $dl_param)) {
    $filename = PHPWG_ROOT_PATH . $conf_data_location . 'pwg_' . $dl_param;
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
$dbhost = (! empty($_POST['dbhost']) && is_string($_POST['dbhost'])) ? $_POST['dbhost'] : 'localhost';
$dbuser = (! empty($_POST['dbuser']) && is_string($_POST['dbuser'])) ? $_POST['dbuser'] : '';
$dbpasswd = (! empty($_POST['dbpasswd']) && is_string($_POST['dbpasswd'])) ? $_POST['dbpasswd'] : '';
$dbname = (! empty($_POST['dbname']) && is_string($_POST['dbname'])) ? $_POST['dbname'] : '';

// Same reasoning as the db_prefix seeding above: this script never goes
// through Kernel::boot()/ConfigLoader, so any code reached later in this
// same request that resolves a DB connection via Piwigo\Db\DbConnection::
// build() (which reads Config::dbHost()/dbUser()/dbPassword()/dbName())
// would otherwise silently see SCHEMA defaults instead of the real
// submitted credentials. Found live: get_default_user_value() ->
// UserService -> UserRepository -> DbConnection::build(), reached from
// activate_core_themes() during step-2 theme activation, fatals with
// "Access denied for user ''@'localhost'" without this.
Config::override('db_host', $dbhost);
Config::override('db_user', $dbuser);
Config::override('db_password', $dbpasswd);
Config::override('db_base', $dbname);

// dblayer
if (! extension_loaded('mysqli')) {
    fatal_error('PHP extension "mysqli" is not loaded');
}
$dblayer = 'mysqli';

$admin_name = (! empty($_POST['admin_name']) && is_string($_POST['admin_name'])) ? $_POST['admin_name'] : '';
$admin_pass1 = (! empty($_POST['admin_pass1']) && is_string($_POST['admin_pass1'])) ? $_POST['admin_pass1'] : '';
$admin_pass2 = (! empty($_POST['admin_pass2']) && is_string($_POST['admin_pass2'])) ? $_POST['admin_pass2'] : '';
$admin_mail = (! empty($_POST['admin_mail']) && is_string($_POST['admin_mail'])) ? $_POST['admin_mail'] : '';

$is_newsletter_subscribe = true;
if (isset($_POST['install'])) {
    $is_newsletter_subscribe = isset($_POST['newsletter_subscribe']);
}

$infos = [];
$errors = [];

$config_file = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php';

// Is Piwigo already installed ?
if (file_exists(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . pwg_test_mode_installed_stamp())) {
    die('Piwigo is already installed');
}

include PHPWG_ROOT_PATH . 'admin/include/functions.php';

$languages = new languages('utf-8');

if (isset($_GET['language']) && is_string($_GET['language'])) {
    $language = strip_tags($_GET['language']);

    if (! in_array($language, array_keys($languages->fs_languages))) {
        $language = AppInfo::DEFAULT_LANGUAGE;
    }
} else {
    $language = 'en_UK';
    // Try to get browser language
    $accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
    $accept_language = is_string($accept_language) ? $accept_language : '';
    foreach ($languages->fs_languages as $language_code => $fs_language) {
        if (substr($language_code, 0, 2) == substr($accept_language, 0, 2)) {
            $language = $language_code;
            break;
        }
    }
}

// See include/common.inc.php for why this fork never points PHPWG_DOMAIN at
// the real piwigo.org.
define('PHPWG_DOMAIN', 'upstream.example.invalid');
define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

load_language('common.lang', '', [
    'language' => $language,
]);
load_language('admin.lang', '', [
    'language' => $language,
]);
load_language('install.lang', '', [
    'language' => $language,
]);

header('Content-Type: text/html; charset=UTF-8');
// ------------------------------------------------- check php version
if (version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION, '<')) {
    $errors[] = l10n('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION);
}

// ----------------------------------------------------- template initialization
$template = new Template(PHPWG_ROOT_PATH . 'admin/themes', 'clear');
$template->set_filenames([
    'install' => 'install.tpl',
]);
if (! isset($step)) {
    $step = 1;
}
// ---------------------------------------------------------------- form analyze
include PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $dblayer . '.inc.php';
include PHPWG_ROOT_PATH . 'admin/include/functions_install.inc.php';
include PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php';

if (isset($_POST['install'])) {
    install_db_connect($infos, $errors);

    if (count($errors) > 0) {
        print_r($errors);
    }

    pwg_db_check_charset();

    if (
        strlen($prefixeTable) > 20
        or (bool) preg_match('/^\d/', $prefixeTable)
        or ! (bool) preg_match('/^[a-zA-Z0-9_$]*$/u', $prefixeTable)
    ) {
        $errors[] = 'invalid table prefix';
    }

    $webmaster = trim((string) preg_replace('/\s{2,}/', ' ', $admin_name));
    if (empty($webmaster)) {
        $errors[] = l10n('enter a login for webmaster');
    } elseif ((bool) preg_match('/[\'"]/', $webmaster)) {
        $errors[] = l10n('webmaster login can\'t contain characters \' or "');
    }
    if ($admin_pass1 != $admin_pass2 || empty($admin_pass1)) {
        $errors[] = l10n('please enter your password again');
    }
    if (empty($admin_mail)) {
        $errors[] = l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
    } else {
        $error_mail_address = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->validateMailAddress(null, $admin_mail);
        if (! empty($error_mail_address)) {
            $errors[] = $error_mail_address;
        }
    }

    if (count($errors) == 0) {
        $step = 2;

        define('PHPWG_INSTALLED', true);
        define('PWG_CHARSET', 'utf-8');
        define('DB_CHARSET', 'utf8');
        define('DB_COLLATE', '');

        // Write .env (or .env.test in test mode) with DB credentials — atomic rename.
        $env_file = PHPWG_ROOT_PATH . pwg_test_mode_env_file();
        // Strip line-breaks to prevent .env injection via crafted POST values.
        $env_vals = array_map(
            fn (string $v): string => str_replace(["\n", "\r", "\0"], '', $v),
            [$dbhost, $dbuser, $dbpasswd, $dbname, $prefixeTable]
        );
        $env_body = 'PIWIGO_DB_HOST=' . $env_vals[0] . "\n" . 'PIWIGO_DB_USER=' . $env_vals[1] . "\n"
                  . 'PIWIGO_DB_PASSWORD=' . $env_vals[2] . "\n" . 'PIWIGO_DB_BASE=' . $env_vals[3] . "\n"
                  . 'PIWIGO_DB_PREFIX=' . $env_vals[4] . "\n";
        // In test mode, also record the base URL so e2e runners know where to connect.
        if (pwg_test_mode_is_active()) {
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
        // (e.g. PIWIGO_TEST_NOW — see include/env.inc.php's pwg_now()). Preserve
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
            $errors[] = 'Could not write ' . $env_file . ' — check filesystem permissions.';
        }

        // Also write legacy database.inc.php in prod mode so upgrade.php and other
        // not-yet-migrated scripts keep working (see docs/PLAN-REPLAY.md P13).
        if (! pwg_test_mode_is_active() && count($errors) == 0) {
            $file_content = '<?php
$conf[\'dblayer\'] = \'' . $dblayer . '\';
$conf[\'db_base\'] = \'' . $dbname . '\';
$conf[\'db_user\'] = \'' . $dbuser . '\';
$conf[\'db_password\'] = \'' . $dbpasswd . '\';
$conf[\'db_host\'] = \'' . $dbhost . '\';

$prefixeTable = \'' . $prefixeTable . '\';

define(\'PHPWG_INSTALLED\', true);
define(\'PWG_CHARSET\', \'utf-8\');
define(\'DB_CHARSET\', \'utf8\');
define(\'DB_COLLATE\', \'\');

?>';

            @umask(0111);
            // writing the configuration file
            if (! (bool) ($fp = @fopen($config_file, 'w'))) {
                // make sure nobody can list files of _data directory
                \Piwigo\Core\FilesystemHelper::secureDirectory(PHPWG_ROOT_PATH . $conf_data_location);

                $tmp_filename = md5(uniqid((string) time()));
                $fh = @fopen(PHPWG_ROOT_PATH . $conf_data_location . 'pwg_' . $tmp_filename, 'w');
                if ($fh !== false) {
                    @fputs($fh, $file_content, strlen($file_content));
                    @fclose($fh);
                }

                $template->assign(
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
        if (count($errors) == 0) {
            touch(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . pwg_test_mode_installed_stamp());
        }

        // tables creation, based on piwigo_structure.sql
        execute_sqlfile(
            PHPWG_ROOT_PATH . 'install/piwigo_structure-mysql.sql',
            DEFAULT_PREFIX_TABLE,
            $prefixeTable,
            'mysql'
        );
        // We fill the tables with basic informations
        execute_sqlfile(
            PHPWG_ROOT_PATH . 'install/config.sql',
            DEFAULT_PREFIX_TABLE,
            $prefixeTable,
            'mysql'
        );

        $query = '
INSERT INTO ' . $prefixeTable . 'config (param,value,comment)
   VALUES (\'secret_key\',\'' . sha1(random_bytes(1000)) . '\',
   \'a secret key specific to the gallery for internal use\');';
        pwg_query($query);

        conf_update_param('piwigo_db_version', get_branch_from_version(AppInfo::VERSION));
        conf_update_param('gallery_title', pwg_db_real_escape_string(l10n('Just another Piwigo gallery')));

        conf_update_param(
            'page_banner',
            '<h1>%gallery_title%</h1>' . "\n\n<p>" . pwg_db_real_escape_string(l10n('Welcome to my photo gallery')) . '</p>'
        );

        // fill languages table, only activate the current language
        $languages->perform_action('activate', $language);

        // fill $conf global array
        load_conf_from_db();

        // PWG_CHARSET is required for building the fs_themes array in the
        // themes class
        if (! defined('PWG_CHARSET')) {
            define('PWG_CHARSET', 'utf-8');
        }
        activate_core_themes();
        activate_core_plugins();

        $insert = [
            'id' => 1,
            'galleries_url' => PHPWG_ROOT_PATH . 'galleries/',
        ];
        mass_inserts(Tables::sites(), array_keys($insert), [$insert]);

        // webmaster admin user
        $inserts = [
            [
                'id' => 1, // must be the same value as webmaster_id in config.sql
                'username' => $admin_name,
                'password' => (new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build())))->hash($admin_pass1),
                'mail_address' => $admin_mail,
            ],
            [
                'id' => 2,
                'username' => 'guest',
            ],
        ];
        mass_inserts(Tables::users(), array_keys($inserts[0]), $inserts);

        (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->createUserInfos([1, 2], [
            'language' => $language,
        ]);

        // Available upgrades must be ignored after a fresh installation. To
        // make PWG avoid upgrading, we must tell it upgrades have already been
        // made.
        $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
        assert($row !== null);
        [$dbnow] = $row;
        define('CURRENT_DATE', $dbnow);
        $datas = [];
        foreach (get_available_upgrade_ids() as $upgrade_id) {
            $datas[] = [
                'id' => $upgrade_id,
                'applied' => CURRENT_DATE,
                'description' => 'upgrade included in installation',
            ];
        }
        mass_inserts(
            Tables::upgrade(),
            array_keys($datas[0]),
            $datas
        );
    }
}

// ------------------------------------------------------ start template output
$languages_options = [];
foreach ($languages->fs_languages as $language_code => $fs_language) {
    if ($language == $language_code) {
        $template->assign('language_selection', $language_code);
    }
    $languages_options[$language_code] = $fs_language['name'];
}
$template->assign('language_options', $languages_options);

$template->assign(
    [
        'T_CONTENT_ENCODING' => 'utf-8',
        'RELEASE' => AppInfo::VERSION,
        'F_ACTION' => 'install.php?language=' . $language,
        'F_DB_HOST' => $dbhost,
        'F_DB_USER' => $dbuser,
        'F_DB_NAME' => $dbname,
        'F_DB_PREFIX' => $prefixeTable,
        'F_ADMIN' => $admin_name,
        'F_ADMIN_EMAIL' => $admin_mail,
        'EMAIL' => '<span class="adminEmail">' . $admin_mail . '</span>',
        'F_NEWSLETTER_SUBSCRIBE' => $is_newsletter_subscribe,
        'L_INSTALL_HELP' => l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
    ]
);

// ------------------------------------------------------ errors & infos display
if ($step == 1) {
    $template->assign('install', true);
} else {
    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('system', ActivitySystem::Core, 'install', [
        'version' => AppInfo::VERSION,
    ]);
    $infos[] = l10n('Congratulations, Piwigo installation is completed');

    if (isset($error_copy)) {
        $errors[] = $error_copy;
    } else {
        // See include/functions_session.inc.php
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
        $user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->buildUser(1, false);
        // build_user() returns array<string, mixed>; the 'id' key we just set
        // to the literal user id 1 doesn't retain that literal type through
        // the return, so narrow to what log_user() actually accepts.
        $login_user_id = $user['id'];
        $login_user_id = is_int($login_user_id) || (is_string($login_user_id) && is_numeric($login_user_id)) ? $login_user_id : false;
        (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->logUser($login_user_id, false);
        $_SESSION['connected_with'] = 'pwg_ui';

        // Same reason: narrow 'preferences' to array without discarding
        // whatever getuserdata() already populated it with.
        $preferences = $user['preferences'] ?? null;
        $preferences = is_array($preferences) ? $preferences : [];
        $preferences['show_whats_new_' . get_branch_from_version(AppInfo::VERSION)] = false;
        $user['preferences'] = $preferences;

        // newsletter subscription
        if ($is_newsletter_subscribe) {
            // $result is never a resource here: no fopen() handle is passed to
            // fetchRemote() above. Seeded as a string so the by-reference $dest
            // out-param satisfies fetchRemote()'s string|resource contract.
            $result = '';
            fetchRemote(
                get_newsletter_subscribe_base_url($language) . $admin_mail,
                $result,
                [],
                [
                    'origin' => 'installation',
                ]
            );

            $preferences['show_newsletter_subscription'] = false;
            $user['preferences'] = $preferences;
        }

        (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->save();

        // email notification
        if (isset($_POST['send_credentials_by_mail'])) {
            $keyargs_content = [
                get_l10n_args('Hello %s,', $admin_name),
                get_l10n_args('Welcome to your new installation of Piwigo!', ''),
                get_l10n_args('', ''),
                get_l10n_args('Here are your connection settings', ''),
                get_l10n_args('', ''),
                get_l10n_args('Link: %s', get_absolute_root_url()),
                get_l10n_args('Username: %s', $admin_name),
                get_l10n_args('Password: ********** (no copy by email)', ''),
                get_l10n_args('Email: %s', $admin_mail),
                get_l10n_args('', ''),
                get_l10n_args('Don\'t hesitate to consult our forums for any help: %s', PHPWG_URL),
            ];

            new MailService()
                ->mail(
                    $admin_mail,
                    [
                        'subject' => l10n('Just another Piwigo gallery'),
                        'content' => l10n_args($keyargs_content),
                        'content_format' => 'text/plain',
                    ]
                );
        }
    }
}
if (count($errors) != 0) {
    $template->assign('errors', $errors);
}

if (count($infos) != 0) {
    $template->assign('infos', $infos);
}

// ----------------------------------------------------------- html code display
$template->pparse('install');
