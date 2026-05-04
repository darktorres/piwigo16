<?php

declare(strict_types=1);

use Piwigo\Cache\PersistentFileCache;
use Piwigo\Core\Logger;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';

\Piwigo\Bootstrap\ExceptionHandler::register();

// determine the initial instant to indicate the generation time of this page
$t2 = microtime(true);

// @set_magic_quotes_runtime(0); // Disable magic_quotes_runtime

//
// addslashes to vars if magic_quotes_gpc is off this is a security
// precaution to prevent someone trying to break out of a SQL statement.
//
// The magic quote feature was removed in PHP 8.0 (function `get_magic_quotes_gpc`
// no longer exists). We unconditionally sanitize user input variables.
function sanitize_mysql_kv(mixed &$v, string $k): void
{
    $v = addslashes(is_scalar($v) ? (string) $v : '');
}
array_walk_recursive($_GET, sanitize_mysql_kv(...));
array_walk_recursive($_POST, sanitize_mysql_kv(...));
array_walk_recursive($_COOKIE, sanitize_mysql_kv(...));
if (!empty($_SERVER['PATH_INFO'])) {
    $path_info = $_SERVER['PATH_INFO'];
    $_SERVER['PATH_INFO'] = addslashes(is_scalar($path_info) ? (string) $path_info : '');
}

// Skip the bootstrap dance when Kernel::boot() has already run (e.g. a nested
// include or a test that bootstraps via Kernel directly instead of this file).
if (!\Piwigo\Core\Kernel::isBooted()) :

    //
    // Define some basic shared bootstrap arrays. Config no longer lives here
    // — it's owned by Piwigo\Config\Config (seeded by ConfigLoader below).
    //
    $page = [
      'infos' => [],
      'errors' => [],
      'warnings' => [],
      'messages' => [],
      'body_classes' => [],
      'body_data' => [],
      'auth_key_invalid' => false,
      'notify_api_key_expiration' => null,
      ];
    $user = [];
    $lang = [];
    $header_msgs = [];
    $header_notes = [];
    $filter = [];

    \Piwigo\Config\ConfigLoader::applyDefaults();

    defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

    \Piwigo\Config\ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
    \Piwigo\Config\ConfigLoader::applyEnvOverrides();

    $prefixeTable = \Piwigo\Config\Config::dbPrefix();

    if (!\Piwigo\Core\InstallSentinel::isInstalled()) {
        header('Location: install.php');
        exit;
    }
    // Only mysqli is supported. The self-heal for old 'mysql' installs and the
    // dynamic include are gone; functions_mysqli.inc.php is the only dblayer.
    include(PHPWG_ROOT_PATH . 'include/dblayer/functions_mysqli.inc.php');

    if (\Piwigo\Config\Config::has('show_php_errors') && !empty(\Piwigo\Config\Config::showPhpErrors()) && function_exists('ini_set')) {
        ini_set('error_reporting', (string) \Piwigo\Config\Config::showPhpErrors());
        if (\Piwigo\Config\Config::showPhpErrorsOnFrontend()) {
            // Route errors to DevTools (X-PHP-Error-N response headers) instead of
            // inline output, which corrupts JSON/XML/binary responses.
            \Piwigo\Core\ErrorCollector::install();
        }
    }

    if (\Piwigo\Config\Config::sessionGcProbability() > 0 && function_exists('ini_set')) {
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_probability', (string) min((int) \Piwigo\Config\Config::sessionGcProbability(), 100));
    }

    include(PHPWG_ROOT_PATH . 'include/constants.php');
    include(PHPWG_ROOT_PATH . 'include/functions.inc.php');

    $page['execution_uuid'] = generate_key(10);

    $pool             = \Piwigo\Cache\CacheFactory::create();
    $persistent_cache = new PersistentFileCache($pool);
    \Piwigo\Cache\PersistentCacheRegistry::set($persistent_cache);

    // Database connection — DBAL connects lazily on first use.
    // Force it now so a bad config surfaces a clean error before rendering.
    try {
        get_dbal_connection();
    } catch (Exception $e) {
        fatal_error(l10n($e->getMessage()));
    }

    // in Piwigo 15, configuration setting webmaster_id is moved from config files
    // to database. It may be undefined at some point, with Piwigo 15+ scripts and
    // a Piwigo 14 database schema not upgraded yet. Let's avoid any problem.
    if (!\Piwigo\Config\Config::has('webmaster_id')) {
        \Piwigo\Config\Config::override('webmaster_id', 1);
    }

    load_conf_from_db();

    $logger = new Logger([
      'directory' => PHPWG_ROOT_PATH . \Piwigo\Config\Config::dataLocation() . \Piwigo\Config\Config::logDir(),
      'severity' => \Piwigo\Config\Config::logLevel(),
      // we use an hashed filename to prevent direct file access, and we salt with
      // the db_password instead of secret_key because the log must be usable in i.php
      // (secret_key is in the database)
      'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . \Piwigo\Config\Config::dbPassword()) . '.txt',
      'globPattern' => 'log_*.txt',
      'archiveDays' => \Piwigo\Config\Config::logArchiveDays(),
      ]);
    \Piwigo\Core\LoggerRegistry::set($logger);

    if (!\Piwigo\Config\Config::checkUpgradeFeed()) {
        if (!\Piwigo\Config\Config::has('piwigo_db_version') or \Piwigo\Config\Config::piwigoDbVersion() != get_branch_from_version(PHPWG_VERSION)) {
            redirect(get_root_url().'upgrade.php');
        }
    }

    ImageStdParams::load_from_db();

    // Boot the container before session_start() so the session handler callbacks
    // (pwg_session_read, pwg_session_write, etc.) can resolve SessionRepository
    // from ServiceLocator. Entry-point Kernel::boot() calls remain idempotent.
    \Piwigo\Core\Kernel::boot();

    session_start();
    \Piwigo\Plugins\EventDispatcher::init();
    load_plugins();

    if (!\Piwigo\Config\Config::has('piwigo_installed_version')) {
        conf_update_param('piwigo_installed_version', PHPWG_VERSION);
    } elseif (\Piwigo\Config\Config::piwigoInstalledVersion() != PHPWG_VERSION) {
        // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
        pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'autoupdate', ['from_version' => \Piwigo\Config\Config::piwigoInstalledVersion(), 'to_version' => PHPWG_VERSION]);
        conf_update_param('piwigo_installed_version', PHPWG_VERSION);
    }

//Check if last major update conf is set if not set it
if (!\Piwigo\Config\Config::has('last_major_update')) {
    conf_update_param('last_major_update', (new \DateTimeImmutable())->format('Y-m-d H:i:s'), true);
}


// users can have defined a custom order pattern, incompatible with GUI form
if (\Piwigo\Config\Config::has('order_by_custom')) {
    \Piwigo\Config\Config::override('order_by', \Piwigo\Config\Config::orderByCustom());
}
if (\Piwigo\Config\Config::has('order_by_inside_category_custom')) {
    \Piwigo\Config\Config::override('order_by_inside_category', \Piwigo\Config\Config::orderByInsideCategoryCustom());
}

check_lounge();

include(PHPWG_ROOT_PATH.'include/user.inc.php');

// Use GLOBALS access to bypass type narrowing from $user initialization
$user_globals = $GLOBALS['user'];
$user_language = is_array($user_globals) ? (is_scalar($user_globals['language'] ?? null) ? (string) $user_globals['language'] : '') : '';
if (in_array(substr($user_language, 0, 2), ['fr','it','de','es','pl','ru','nl','tr','da'])) {
    define('PHPWG_DOMAIN', substr($user_language, 0, 2).'.piwigo.org');
} elseif ('zh_CN' == $user_language) {
    define('PHPWG_DOMAIN', 'cn.piwigo.org');
} elseif ('pt_BR' == $user_language) {
    define('PHPWG_DOMAIN', 'br.piwigo.org');
} else {
    define('PHPWG_DOMAIN', 'piwigo.org');
}
define('PHPWG_URL', 'https://'.PHPWG_DOMAIN);

if (\Piwigo\Config\Config::has('alternative_pem_url') and \Piwigo\Config\Config::alternativePemUrl() != '') {
    define('PEM_URL', \Piwigo\Config\Config::alternativePemUrl());
} else {
    // Serve extensions from the local sibling repo instead of piwigo.org/ext.
    $pem_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $pem_host   = is_scalar($_SERVER['HTTP_HOST'] ?? null) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    define('PEM_URL', $pem_scheme . '://' . $pem_host . '/piwigo16-ext');
}

// language files
load_language('common.lang');
if (is_admin() || (defined('IN_ADMIN') ? constant('IN_ADMIN') : false)) {
    load_language('admin.lang');
    // Add language for temporary strings for new popup, from piwigo 15
    load_language('whats_new_'.get_branch_from_version(PHPWG_VERSION).'.lang');
}
trigger_notify('loading_lang');
load_language('lang', PHPWG_ROOT_PATH.PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);

// only now we can set the localized username of the guest user (and not in
// include/user.inc.php)
if (is_a_guest()) {
    $user['username'] = l10n('guest');
}

// in case an auth key was provided and is no longer valid, we must wait to
// be here, with language loaded, to prepare the message
if (\Piwigo\Core\PageState::current()->authKeyInvalid) {
    \Piwigo\Core\PageState::current()->addError(
        l10n('Your authentication key is no longer valid.')
      .sprintf(' <a href="%s">%s</a>', get_root_url().'identification.php', l10n('Login'))
    );
}

// check if we need to notified user about api_key expiration
$user_arr = $GLOBALS['user'];
$page_arr = $GLOBALS['page'];
$notify_exp = is_array($page_arr) ? $page_arr['notify_api_key_expiration'] : null;
if (is_array($notify_exp)) {
    $notify_username_raw = is_array($user_arr) ? ($user_arr['username'] ?? '') : '';
    $notify_email_raw = is_array($user_arr) ? ($user_arr['email'] ?? '') : '';
    $notify_days_left = $notify_exp['days_left'];
    $is_mail_send = notification_api_key_expiration(
        is_scalar($notify_username_raw) ? (string) $notify_username_raw : '',
        is_scalar($notify_email_raw) ? (string) $notify_email_raw : '',
        is_numeric($notify_days_left) ? (int) $notify_days_left : 0
    );

    if ($is_mail_send) {
        $notify_user_id_raw = is_array($user_arr) ? ($user_arr['id'] ?? 0) : 0;
        single_update(
            USER_AUTH_KEYS_TABLE,
            ['last_notified_on' => $notify_exp['dbnow']],
            [
            'user_id' => is_numeric($notify_user_id_raw) ? (int) $notify_user_id_raw : 0,
            'auth_key' => $notify_exp['auth_key'],
      ],
        );
    }

    unset($page['notify_api_key_expiration']);
}

// template instance
if (defined('IN_ADMIN') ? constant('IN_ADMIN') : false) {// Admin template
    $admin_theme_raw = userprefs_get_param('admin_theme', 'roma');
    $template = new Template(PHPWG_ROOT_PATH.'admin/themes', is_scalar($admin_theme_raw) ? (string) $admin_theme_raw : 'roma');
} else { // Classic template
    $user_arr_theme = $GLOBALS['user'];
    $theme_raw = is_array($user_arr_theme) ? ($user_arr_theme['theme'] ?? '') : '';
    $theme = is_scalar($theme_raw) ? (string) $theme_raw : '';
    if (script_basename() != 'ws' and mobile_theme()) {
        $theme = \Piwigo\Config\Config::mobilTheme();
    }
    $template = new Template(PHPWG_ROOT_PATH.'themes', $theme);
}
\Piwigo\Template\TemplateRegistry::set($template);

if (!\Piwigo\Config\Config::has('no_photo_yet')) {
    include(PHPWG_ROOT_PATH.'include/no_photo_yet.inc.php');
}

$user_arr_gs = $GLOBALS['user'];
$internal_status_gs = is_array($user_arr_gs) ? ($user_arr_gs['internal_status'] ?? null) : null;
if (is_array($internal_status_gs)
    && isset($internal_status_gs['guest_must_be_guest'])
    && $internal_status_gs['guest_must_be_guest'] === true) {
    $header_msgs[] = l10n('Bad status for user "guest", using default status. Please notify the webmaster.');
}

if (\Piwigo\Config\Config::galleryLocked()) {
    $header_msgs[] = l10n('The gallery is locked for maintenance. Please, come back later.');

    if (script_basename() != 'identification' and !is_admin()) {
        set_status_header(503, 'Service Unavailable');
        if (!headers_sent()) {
            header('Retry-After: 900');
        }
        header('Content-Type: text/html; charset='.get_pwg_charset());
        echo '<a href="'.get_absolute_root_url(false).'identification.php">'.l10n('The gallery is locked for maintenance. Please, come back later.').'</a>';
        echo str_repeat(' ', 512); //IE6 doesn't error output if below a size
        exit();
    }
}

if (\Piwigo\Config\Config::checkUpgradeFeed()) {
    include_once(PHPWG_ROOT_PATH.'admin/include/functions_upgrade.php');
    if (check_upgrade_feed()) {
        $header_msgs[] = 'Some database upgrades are missing, '
          .'<a href="'.get_absolute_root_url(false).'upgrade_feed.php">upgrade now</a>';
    }
}

if (count($header_msgs) > 0) {
    $template->assign('header_msgs', $header_msgs);
    $header_msgs = [];
}

if (!empty(\Piwigo\Config\Config::filterPages()) and get_filter_page_value('used')) {
    include(PHPWG_ROOT_PATH.'include/filter.inc.php');
} else {
    $filter['enabled'] = false;
}

if (\Piwigo\Config\Config::has('header_notes')) {
    $header_notes = array_merge($header_notes, \Piwigo\Config\Config::headerNotes());
}

// default event handlers
add_event_handler('render_category_literal_description', 'render_category_literal_description');
if (!\Piwigo\Config\Config::allowHtmlDescriptions()) {
    add_event_handler('render_category_description', 'pwg_nl2br');
}
add_event_handler('render_comment_content', 'render_comment_content');
add_event_handler('render_comment_author', 'strip_tags');
add_event_handler('render_tag_url', 'str2url');
add_event_handler('blockmanager_register_blocks', 'register_default_menubar_blocks', EVENT_HANDLER_PRIORITY_NEUTRAL - 1);
if (!empty(\Piwigo\Config\Config::originalUrlProtection())) {
    add_event_handler('get_element_url', 'get_element_url_protection_handler');
    add_event_handler('get_src_image_url', 'get_src_image_url_protection_handler');
}
trigger_notify('init');

endif; // !Kernel::isBooted()
