<?php

declare(strict_types=1);

use Piwigo\Bootstrap\ExceptionHandler;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageStdParams;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\UserBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';

ExceptionHandler::register();

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
if (!Kernel::isBooted()) :

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

    ConfigLoader::applyDefaults();

    defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

    ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
    ConfigLoader::applyEnvOverrides();

    $prefixeTable = Config::dbPrefix();

    if (!InstallSentinel::isInstalled()) {
        header('Location: install.php');
        exit;
    }
    // Only mysqli is supported. The self-heal for old 'mysql' installs and the
    // dynamic include are gone; functions_mysqli.inc.php is the only dblayer.
    require(PHPWG_ROOT_PATH . 'include/dblayer/functions_mysqli.inc.php');

    // Always route PHP errors to DevTools (X-PHP-Error-N response headers) rather
    // than inline output, which corrupts JSON/XML/binary responses.
    // The DB config show_php_errors controls error_reporting level only.
    ErrorCollector::install();
    if (Config::has('show_php_errors') && !empty(Config::showPhpErrors()) && function_exists('ini_set')) {
        ini_set('error_reporting', (string) Config::showPhpErrors());
    }

    if (Config::sessionGcProbability() > 0 && function_exists('ini_set')) {
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_probability', (string) min((int) Config::sessionGcProbability(), 100));
    }

    require(PHPWG_ROOT_PATH . 'include/constants.php');
    require(PHPWG_ROOT_PATH . 'include/functions.inc.php');

    $page['execution_uuid'] = generate_key(10);

    $pool             = CacheFactory::create();
    $persistent_cache = new PersistentFileCache($pool);
    PersistentCacheRegistry::set($persistent_cache);

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
    if (!Config::has('webmaster_id')) {
        Config::override('webmaster_id', 1);
    }

    load_conf_from_db();

    $logger = new Logger([
      'directory' => PHPWG_ROOT_PATH . Config::dataLocation() . Config::logDir(),
      'severity' => Config::logLevel(),
      // we use an hashed filename to prevent direct file access, and we salt with
      // the db_password instead of secret_key because the log must be usable in i.php
      // (secret_key is in the database)
      'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
      'globPattern' => 'log_*.txt',
      'archiveDays' => Config::logArchiveDays(),
      ]);
    LoggerRegistry::set($logger);

    if (!Config::checkUpgradeFeed()) {
        if (!Config::has('piwigo_db_version') or Config::piwigoDbVersion() != get_branch_from_version(PHPWG_VERSION)) {
            redirect(get_root_url().'upgrade.php');
        }
    }

    // Boot the container before session_start() so the session handler callbacks
    // (pwg_session_read, pwg_session_write, etc.) can resolve SessionRepository
    // from ServiceLocator. Entry-point Kernel::boot() calls remain idempotent.
    Kernel::boot();

    ImageStdParams::load_from_db();

    session_start();
    UserBootstrap::bootstrap();
    EventDispatcher::init();
    load_plugins();

    if (!Config::has('piwigo_installed_version')) {
        conf_update_param('piwigo_installed_version', PHPWG_VERSION);
    } elseif (Config::piwigoInstalledVersion() != PHPWG_VERSION) {
        // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
        pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'autoupdate', ['from_version' => Config::piwigoInstalledVersion(), 'to_version' => PHPWG_VERSION]);
        conf_update_param('piwigo_installed_version', PHPWG_VERSION);
    }

//Check if last major update conf is set if not set it
if (!Config::has('last_major_update')) {
    conf_update_param('last_major_update', new \DateTimeImmutable()->format('Y-m-d H:i:s'), true);
}


// users can have defined a custom order pattern, incompatible with GUI form
if (Config::has('order_by_custom')) {
    Config::override('order_by', Config::orderByCustom());
}
if (Config::has('order_by_inside_category_custom')) {
    Config::override('order_by_inside_category', Config::orderByInsideCategoryCustom());
}

check_lounge();

// User bootstrap runs once here (idempotent guard in UserBootstrap prevents
// AuthMiddleware from re-running it). Replaces the former include/user.inc.php.

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

if (Config::has('alternative_pem_url') and Config::alternativePemUrl() != '') {
    define('PEM_URL', Config::alternativePemUrl());
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
if (PageState::current()->authKeyInvalid) {
    PageState::current()->addError(
        l10n('Your authentication key is no longer valid.')
      .sprintf(' <a href="%s">%s</a>', ServiceLocator::get(UrlGenerator::class)->identification(), l10n('Login'))
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
        $theme = Config::mobilTheme();
    }
    $template = new Template(PHPWG_ROOT_PATH.'themes', $theme);
}
TemplateRegistry::set($template);

if (!Config::has('no_photo_yet')) {
    ServiceLocator::get(NoPhotoYetRenderer::class)->render();
}

$user_arr_gs = $GLOBALS['user'];
$internal_status_gs = is_array($user_arr_gs) ? ($user_arr_gs['internal_status'] ?? null) : null;
if (is_array($internal_status_gs)
    && isset($internal_status_gs['guest_must_be_guest'])
    && $internal_status_gs['guest_must_be_guest'] === true) {
    $header_msgs[] = l10n('Bad status for user "guest", using default status. Please notify the webmaster.');
}

if (Config::galleryLocked()) {
    $header_msgs[] = l10n('The gallery is locked for maintenance. Please, come back later.');

    if (script_basename() != 'identification' and !is_admin()) {
        set_status_header(503, 'Service Unavailable');
        if (!headers_sent()) {
            header('Retry-After: 900');
        }
        header('Content-Type: text/html; charset='.get_pwg_charset());
        echo '<a href="'.ServiceLocator::get(UrlGenerator::class)->identification().'">'.l10n('The gallery is locked for maintenance. Please, come back later.').'</a>';
        echo str_repeat(' ', 512); //IE6 doesn't error output if below a size
        exit();
    }
}

if (Config::checkUpgradeFeed()) {
    require_once(PHPWG_ROOT_PATH.'admin/include/functions_upgrade.php');
    if (check_upgrade_feed()) {
        $header_msgs[] = 'Some database upgrades are missing, '
          .'<a href="'.get_absolute_root_url(false).'upgrade_feed.php">upgrade now</a>';
    }
}

if (count($header_msgs) > 0) {
    $template->assign('header_msgs', $header_msgs);
    $header_msgs = [];
}

// Filter bootstrap is handled by FilterMiddleware in the PSR-15 pipeline.
// For scripts that bypass the pipeline (random.php, etc.) filter stays disabled.
$filter['enabled'] = false;

if (Config::has('header_notes')) {
    $header_notes = array_merge($header_notes, Config::headerNotes());
}

// default event handlers
add_event_handler('render_category_literal_description', 'render_category_literal_description');
if (!Config::allowHtmlDescriptions()) {
    add_event_handler('render_category_description', 'pwg_nl2br');
}
add_event_handler('render_comment_content', 'render_comment_content');
add_event_handler('render_comment_author', 'strip_tags');
add_event_handler('render_tag_url', 'str2url');
add_event_handler('blockmanager_register_blocks', 'register_default_menubar_blocks', EVENT_HANDLER_PRIORITY_NEUTRAL - 1);
if (!empty(Config::originalUrlProtection())) {
    add_event_handler('get_element_url', 'get_element_url_protection_handler');
    add_event_handler('get_src_image_url', 'get_src_image_url_protection_handler');
}
trigger_notify('init');

endif; // !Kernel::isBooted()
