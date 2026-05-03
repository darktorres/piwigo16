<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Admin\Languages;
use Piwigo\Session\PwgSession;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//----------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');

// magic_quotes_gpc removed in PHP 7.4+; this block is no longer needed

//----------------------------------------------------- variable initialization

define('DEFAULT_PREFIX_TABLE', 'piwigo_');

if (isset($_POST['install'])) {
    $prefixeTable = is_scalar($_POST['prefix'] ?? null) ? (string) $_POST['prefix'] : DEFAULT_PREFIX_TABLE;
} else {
    $prefixeTable = DEFAULT_PREFIX_TABLE;
}

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

include(PHPWG_ROOT_PATH . 'include/functions.inc.php');
require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';

\Piwigo\Config\ConfigLoader::applyDefaults();

// Obtain various vars
$dbhost   = is_scalar($_POST['dbhost'] ?? null) && !empty($_POST['dbhost']) ? (string) $_POST['dbhost'] : 'localhost';
$dbuser   = is_scalar($_POST['dbuser'] ?? null) && !empty($_POST['dbuser']) ? (string) $_POST['dbuser'] : 'root';
$dbpasswd = is_scalar($_POST['dbpasswd'] ?? null) && !empty($_POST['dbpasswd']) ? (string) $_POST['dbpasswd'] : '1234';
$dbname   = is_scalar($_POST['dbname'] ?? null) && !empty($_POST['dbname']) ? (string) $_POST['dbname'] : 'piwigo';

// Only mysqli is supported since PHP 7.
if (!extension_loaded('mysqli')) {
    fatal_error('PHP extension "mysqli" is required but not loaded');
}
$dblayer = 'mysqli';

$admin_name  = is_scalar($_POST['admin_name'] ?? null) && !empty($_POST['admin_name']) ? (string) $_POST['admin_name'] : 'darktorres';
$admin_pass1 = is_scalar($_POST['admin_pass1'] ?? null) && !empty($_POST['admin_pass1']) ? (string) $_POST['admin_pass1'] : '1234';
$admin_pass2 = is_scalar($_POST['admin_pass2'] ?? null) && !empty($_POST['admin_pass2']) ? (string) $_POST['admin_pass2'] : '1234';
$admin_mail  = is_scalar($_POST['admin_mail'] ?? null) && !empty($_POST['admin_mail']) ? (string) $_POST['admin_mail'] : 'torres.dark@gmail.com';

$is_newsletter_subscribe = false;
if (isset($_POST['install'])) {
    $is_newsletter_subscribe = isset($_POST['newsletter_subscribe']);
}

$infos = [];
$errors = [];

if (\Piwigo\Core\InstallSentinel::isInstalled()) {
    die('Piwigo is already installed');
}

include(PHPWG_ROOT_PATH . 'include/constants.php');
include(PHPWG_ROOT_PATH . 'admin/include/functions.php');

$languages = new Languages('utf-8');

if (isset($_GET['language'])) {
    $language = strip_tags(is_scalar($_GET['language']) ? (string) $_GET['language'] : '');

    if (!in_array($language, array_keys($languages->fs_languages))) {
        $language = PHPWG_DEFAULT_LANGUAGE;
    }
} else {
    $language = 'en_UK';
    // Try to get browser language
    foreach ($languages->fs_languages as $language_code => $fs_language) {
        if (substr((string) $language_code, 0, 2) == @substr(is_scalar($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '', 0, 2)) {
            $language = $language_code;
            break;
        }
    }
}

if ('fr_FR' == $language) {
    define('PHPWG_DOMAIN', 'fr.piwigo.org');
} elseif ('it_IT' == $language) {
    define('PHPWG_DOMAIN', 'it.piwigo.org');
} elseif ('de_DE' == $language) {
    define('PHPWG_DOMAIN', 'de.piwigo.org');
} elseif ('es_ES' == $language) {
    define('PHPWG_DOMAIN', 'es.piwigo.org');
} elseif ('pl_PL' == $language) {
    define('PHPWG_DOMAIN', 'pl.piwigo.org');
} elseif ('zh_CN' == $language) {
    define('PHPWG_DOMAIN', 'cn.piwigo.org');
} elseif ('ru_RU' == $language) {
    define('PHPWG_DOMAIN', 'ru.piwigo.org');
} elseif ('nl_NL' == $language) {
    define('PHPWG_DOMAIN', 'nl.piwigo.org');
} elseif ('tr_TR' == $language) {
    define('PHPWG_DOMAIN', 'tr.piwigo.org');
} elseif ('da_DK' == $language) {
    define('PHPWG_DOMAIN', 'da.piwigo.org');
} elseif ('pt_BR' == $language) {
    define('PHPWG_DOMAIN', 'br.piwigo.org');
} else {
    define('PHPWG_DOMAIN', 'piwigo.org');
}
define('PHPWG_URL', 'https://'.PHPWG_DOMAIN);

load_language('common.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);
load_language('admin.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);
load_language('install.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);

header('Content-Type: text/html; charset=UTF-8');
//------------------------------------------------- check php version
if (version_compare(PHP_VERSION, REQUIRED_PHP_VERSION, '<')) {
    $errors[] = l10n('PHP version %s required (you are running on PHP %s)', REQUIRED_PHP_VERSION, PHP_VERSION);
}

//----------------------------------------------------- template initialization
$template = new Template(PHPWG_ROOT_PATH.'admin/themes', 'roma');
$template->set_filenames(['install' => 'install.tpl']);
if (!isset($step)) {
    $step = 1;
}
//---------------------------------------------------------------- form analyze
include(PHPWG_ROOT_PATH .'include/dblayer/functions_'.$dblayer.'.inc.php');
include(PHPWG_ROOT_PATH . 'admin/include/functions_install.inc.php');
include(PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php');

if (isset($_POST['install'])) {
    install_db_connect($infos, $errors);

    if (count($errors) > 0) {
        print_r($errors);
    }

    pwg_db_check_charset();

    if (
        strlen((string) $prefixeTable) > 20
        or preg_match('/^\d/', (string) $prefixeTable)
        or !preg_match('/^[a-zA-Z0-9_$]*$/u', (string) $prefixeTable)
    ) {
        $errors[] = 'invalid table prefix';
    }

    $webmaster = trim((string) preg_replace('/\s{2,}/', ' ', (string) $admin_name));
    if (empty($webmaster)) {
        $errors[] = l10n('enter a login for webmaster');
    } elseif (preg_match('/[\'"]/', $webmaster)) {
        $errors[] = l10n('webmaster login can\'t contain characters \' or "');
    }
    if (empty($_POST['admin_pass1'] ?? '') || empty($_POST['admin_pass2'] ?? '') || $_POST['admin_pass1'] !== $_POST['admin_pass2']) {
        $errors[] = l10n('please enter your password again');
    }
    if (empty($_POST['admin_mail'] ?? '')) {
        $errors[] = l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
    } else {
        $error_mail_address = validate_mail_address(null, $admin_mail);
        if (!empty($error_mail_address)) {
            $errors[] = $error_mail_address;
        }
    }

    if (count($errors) == 0) {
        $step = 2;

        // Persist DB credentials to the env file (`.env` for prod runs,
        // `.env.test` when this install was triggered with X-Piwigo-Env: test).
        // Atomic write (tmp + rename) so a half-written file can never break
        // a subsequent boot. Fail loudly on write errors — silently continuing
        // leaves the install half-broken (DB tables created, no config to
        // point at them).
        $envPath = PHPWG_ROOT_PATH . \Piwigo\Config\TestMode::envFile();
        $envBody = "PIWIGO_DB_HOST={$dbhost}\n"
                 . "PIWIGO_DB_USER={$dbuser}\n"
                 . "PIWIGO_DB_PASSWORD={$dbpasswd}\n"
                 . "PIWIGO_DB_BASE={$dbname}\n"
                 . "PIWIGO_DB_PREFIX={$prefixeTable}\n";
        $envTmp = $envPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($envTmp, $envBody) === false || !rename($envTmp, $envPath)) {
            @unlink($envTmp);
            fatal_error('Could not write ' . $envPath . ' — check filesystem permissions on the repository root.');
        }

        // Mirror the same values into Config::$data so the rest of this
        // request (table creation, admin insert) sees them.
        \Piwigo\Config\Config::override('db_host', $dbhost);
        \Piwigo\Config\Config::override('db_user', $dbuser);
        \Piwigo\Config\Config::override('db_password', $dbpasswd);
        \Piwigo\Config\Config::override('db_base', $dbname);
        \Piwigo\Config\Config::override('db_prefix', $prefixeTable);

        // tables creation, based on piwigo_structure.sql
        execute_sqlfile(
            PHPWG_ROOT_PATH.'install/piwigo_structure-mysql.sql',
            DEFAULT_PREFIX_TABLE,
            $prefixeTable,
            'mysql'
        );
        // We fill the tables with basic informations
        execute_sqlfile(
            PHPWG_ROOT_PATH.'install/config.sql',
            DEFAULT_PREFIX_TABLE,
            $prefixeTable,
            'mysql'
        );

        $query = '
INSERT INTO '.$prefixeTable.'config (param,value,comment) 
   VALUES (\'secret_key\',\''.sha1(random_bytes(1000)).'\',
   \'a secret key specific to the gallery for internal use\');';
        pwg_query($query);

        conf_update_param('piwigo_db_version', get_branch_from_version(PHPWG_VERSION));
        conf_update_param('gallery_title', pwg_db_real_escape_string(l10n('Just another Piwigo gallery')));

        conf_update_param(
            'page_banner',
            '<h1>%gallery_title%</h1>'."\n\n<p>".pwg_db_real_escape_string(l10n('Welcome to my photo gallery')).'</p>'
        );

        // fill languages table, only activate the current language
        $languages->perform_action('activate', $language);

        // load DB config into Config::$data
        load_conf_from_db();

        activate_core_themes();
        activate_core_plugins();

        $insert = [
          'id' => 1,
          'galleries_url' => PHPWG_ROOT_PATH.'galleries/',
          ];
        mass_inserts(SITES_TABLE, array_keys($insert), [$insert]);

        // webmaster admin user
        $inserts = [
          [
            'id'           => 1, // must be the same value as webmaster_id in config.sql
            'username'     => $admin_name,
            'password'     => password_hash((string) $admin_pass1, PASSWORD_BCRYPT),
            'mail_address' => $admin_mail,
            ],
          [
            'id'           => 2,
            'username'     => 'guest',
            ],
          ];
        mass_inserts(USERS_TABLE, array_keys($inserts[0]), $inserts);

        create_user_infos([1,2], ['language' => $language]);

        // Available upgrades must be ignored after a fresh installation. To
        // make PWG avoid upgrading, we must tell it upgrades have already been
        // made.
        [$dbnow] = pwg_db_fetch_row(pwg_query('SELECT NOW();')) ?? [null];
        define('CURRENT_DATE', $dbnow);
        $datas = [];
        foreach (get_available_upgrade_ids() as $upgrade_id) {
            $datas[] = [
              'id'          => $upgrade_id,
              'applied'     => CURRENT_DATE,
              'description' => 'upgrade included in installation',
              ];
        }
        if (!empty($datas)) {
            mass_inserts(
                UPGRADE_TABLE,
                array_keys($datas[0]),
                $datas
            );
        }

        // Install signal: empty stamp file at local/.installed.
        \Piwigo\Core\InstallSentinel::markInstalled();
    }
}

//------------------------------------------------------ start template output
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
    'RELEASE' => PHPWG_VERSION,
    'F_ACTION' => 'install.php?language=' . $language,
    'F_DB_HOST' => $dbhost,
    'F_DB_USER' => $dbuser,
    'F_DB_PASSWD' => $dbpasswd,
    'F_DB_NAME' => $dbname,
    'F_DB_PREFIX' => $prefixeTable,
    'F_ADMIN' => $admin_name,
    'F_ADMIN_PASS' => $admin_pass1,
    'F_ADMIN_EMAIL' => $admin_mail,
    'EMAIL' => '<span class="adminEmail">'.$admin_mail.'</span>',
    'F_NEWSLETTER_SUBSCRIBE' => $is_newsletter_subscribe,
    'L_INSTALL_HELP' => l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL.'/forum'),
    ]
);

//------------------------------------------------------ errors & infos display
if ($step == 1) {
    $template->assign('install', true);
} else {
    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'install', ['version' => PHPWG_VERSION]);
    $infos[] = l10n('Congratulations, Piwigo installation is completed');

    if (isset($error_copy)) {
        $errors[] = $error_copy;
    } else {
        // See include/functions_session.inc.php
        session_set_save_handler(new PwgSession());
        if (function_exists('ini_set')) {
            ini_set('session.use_cookies', \Piwigo\Config\Config::sessionUseCookies());
            ini_set('session.use_only_cookies', \Piwigo\Config\Config::sessionUseOnlyCookies());
            ini_set('session.use_trans_sid', intval(\Piwigo\Config\Config::sessionUseTransSid()));
            ini_set('session.cookie_httponly', 1);
        }
        session_name(\Piwigo\Config\Config::sessionName());
        session_set_cookie_params(0, cookie_path());
        register_shutdown_function(session_write_close(...));

        // we don't load user cache because since Piwigo 15.4.0 the calculation of user
        // cache requires $logger which is not instanciated
        $user = build_user(1, false);
        log_user(is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0, false);
        $_SESSION['connected_with'] = 'pwg_ui';

        if (!is_array($user['preferences'] ?? null)) {
            $user['preferences'] = [];
        }
        $user['preferences']['show_whats_new_'.get_branch_from_version(PHPWG_VERSION)] = false;

        // newsletter subscription
        if ($is_newsletter_subscribe) {
            fetchRemote(
                get_newsletter_subscribe_base_url($language).$admin_mail,
                $result,
                [],
                ['origin' => 'installation']
            );

            $user['preferences']['show_newsletter_subscription'] = false;
        }

        userprefs_save();

        // email notification
        if (isset($_POST['send_credentials_by_mail'])) {
            include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

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

            pwg_mail(
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

//----------------------------------------------------------- html code display
$template->pparse('install');
