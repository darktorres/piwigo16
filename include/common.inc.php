<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Cache\PersistentFileCache;
use Piwigo\Image\ImageStdParams;

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

// determine the initial instant to indicate the generation time of this page
$t2 = microtime(true);

// accumulates pwg_debug() messages for display in the page footer
$debug = '';

// @set_magic_quotes_runtime(0); // Disable magic_quotes_runtime

//
// addslashes to vars if magic_quotes_gpc is off this is a security
// precaution to prevent someone trying to break out of a SQL statement.
//
// The magic quote feature has been disabled since php 5.4
// but function get_magic_quotes_gpc was always replying false.
// Since php 8 the function get_magic_quotes_gpc is also removed
// but we stil want to sanitize user input variables.
if (! function_exists('get_magic_quotes_gpc') or ! @get_magic_quotes_gpc()) {
    function sanitize_mysql_kv(mixed &$v, int|string $k): void
    {
        // Leaf values recursed into by array_walk_recursive() from $_GET/
        // $_POST/$_COOKIE are always strings in practice (HTTP request data
        // never contains scalars other than strings; arrays are recursed
        // into rather than passed to the callback), but the parameter is
        // typed mixed so we narrow rather than force-cast it.
        if (is_string($v)) {
            $v = addslashes($v);
        }
    }
    array_walk_recursive($_GET, sanitize_mysql_kv(...));
    array_walk_recursive($_POST, sanitize_mysql_kv(...));
    array_walk_recursive($_COOKIE, sanitize_mysql_kv(...));
}
if (! empty($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])) {
    $_SERVER['PATH_INFO'] = addslashes($_SERVER['PATH_INFO']);
}

//
// Define some basic configuration arrays this also prevents malicious
// rewriting of language and otherarray values via URI params
//
$conf = [];
$page = [
    'infos' => [],
    'errors' => [],
    'warnings' => [],
    'messages' => [],
    'body_classes' => [],
    'body_data' => [],
];
$user = [];
$lang = [];
$header_msgs = [];
$header_notes = [];
$filter = [];

include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';

defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

include PHPWG_ROOT_PATH . 'include/env.inc.php';
pwg_load_env_file(PHPWG_ROOT_PATH);
$prefixeTable = '';
pwg_apply_env_to_conf($conf, $prefixeTable);

if (! file_exists(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . pwg_test_mode_installed_stamp())) {
    header('Location: install.php');
    exit;
}
defined('PHPWG_INSTALLED') or define('PHPWG_INSTALLED', true);

// config_default.inc.php/pwg_apply_env_to_conf() always set $conf['dblayer']
// to a string ('mysqli'), but pwg_apply_env_to_conf(array &$conf, ...)'s
// generic `array` by-ref parameter erases per-key type info PHPStan had
// built up for $conf, so we re-narrow at the point of use.
$dblayer = $conf['dblayer'];
if (! is_string($dblayer)) {
    die("Invalid \$conf['dblayer'] configuration: expected a string.");
}
include PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $dblayer . '.inc.php';

if (isset($conf['show_php_errors']) && ! empty($conf['show_php_errors'])) {
    if (is_scalar($conf['show_php_errors'])) {
        @ini_set('error_reporting', $conf['show_php_errors']);
    }
    if ((bool) $conf['show_php_errors_on_frontend']) {
        // Route errors to DevTools (X-PHP-Error-N response headers) instead of
        // inline output, which corrupts JSON/XML/binary responses (see
        // include/error_collector.inc.php).
        include_once PHPWG_ROOT_PATH . 'include/error_collector.inc.php';
        pwg_error_collector_install();
    }
}

if ($conf['session_gc_probability'] > 0) {
    @ini_set('session.gc_divisor', 100);
    $gc_probability = $conf['session_gc_probability'];
    $gc_probability = is_numeric($gc_probability) ? (int) $gc_probability : 1;
    @ini_set('session.gc_probability', min($gc_probability, 100));
}

include PHPWG_ROOT_PATH . 'include/constants.php';
include PHPWG_ROOT_PATH . 'include/functions.inc.php';
include PHPWG_ROOT_PATH . 'include/template.class.php';
include PHPWG_ROOT_PATH . 'include/Logger.class.php';

$page['execution_uuid'] = generate_key(10);

$persistent_cache = new PersistentFileCache();

// Database connection
try {
    $db_host = $conf['db_host'];
    $db_user = $conf['db_user'];
    $db_password = $conf['db_password'];
    $db_base = $conf['db_base'];
    if (! is_string($db_host) || ! is_string($db_user) || ! is_string($db_password) || ! is_string($db_base)) {
        throw new Exception("Invalid database configuration: \$conf['db_host'], 'db_user', 'db_password' and 'db_base' must be strings.");
    }
    pwg_db_connect(
        $db_host,
        $db_user,
        $db_password,
        $db_base
    );
} catch (Exception $e) {
    my_error(l10n($e->getMessage()), true);
}

pwg_db_check_charset();

// in Piwigo 15, configuration setting webmaster_id is moved from config files
// to database. It may be undefined at some point, with Piwigo 15+ scripts and
// a Piwigo 14 database schema not upgraded yet. Let's avoid any problem.
$conf['webmaster_id'] ??= 1;

load_conf_from_db();

// $conf['data_location']/'log_dir' lost their specific string types the same
// way $conf['dblayer'] did above (see comment near the dblayer include); we
// already validated 'db_password' is a string above ($db_password), so it is
// reused here rather than re-narrowed.
$log_data_location = $conf['data_location'];
$log_dir = $conf['log_dir'];
if (! is_string($log_data_location) || ! is_string($log_dir)) {
    fatal_error("Invalid \$conf['data_location']/'log_dir' configuration: expected strings.");
}

$logger = new Logger([
    'directory' => PHPWG_ROOT_PATH . $log_data_location . $log_dir,
    'severity' => $conf['log_level'],
    // we use an hashed filename to prevent direct file access, and we salt with
    // the db_password instead of secret_key because the log must be usable in i.php
    // (secret_key is in the database)
    'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $db_password) . '.txt',
    'globPattern' => 'log_*.txt',
    'archiveDays' => $conf['log_archive_days'],
]);

if (! (bool) $conf['check_upgrade_feed']) {
    if (! isset($conf['piwigo_db_version']) or $conf['piwigo_db_version'] != get_branch_from_version(PHPWG_VERSION)) {
        redirect(get_root_url() . 'upgrade.php');
    }
}

ImageStdParams::load_from_db();

session_start();
load_plugins();

if (! isset($conf['piwigo_installed_version'])) {
    conf_update_param('piwigo_installed_version', PHPWG_VERSION);
} elseif ($conf['piwigo_installed_version'] != PHPWG_VERSION) {
    // Piwigo has been updated "from filesystem" and not "from the administration UI". We mark it as an autoupdate in the system activities log
    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'autoupdate', [
        'from_version' => $conf['piwigo_installed_version'],
        'to_version' => PHPWG_VERSION,
    ]);
    conf_update_param('piwigo_installed_version', PHPWG_VERSION);
}

// Check if last major update conf is set if not set it
if (! isset($conf['last_major_update'])) {
    $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
    assert($row !== null);
    [$dbnow] = $row;
    conf_update_param('last_major_update', $dbnow, true);
}

// 2022-02-25 due to escape on "rank" (becoming a mysql keyword in version 8), the $conf['order_by'] might
// use a "rank", even if admin/configuration.php should have removed it. We must remove it.
// TODO remove this data update as soon as 2025 arrives
$conf_order_by = $conf['order_by'];
if (is_string($conf_order_by) && (bool) preg_match('/(, )?`rank` ASC/', $conf_order_by)) {
    $order_by = preg_replace('/(, )?`rank` ASC/', '', $conf_order_by);
    if ($order_by == 'ORDER BY ') {
        $order_by = 'ORDER BY id ASC';
    }
    conf_update_param('order_by', $order_by, true);
}

// users can have defined a custom order pattern, incompatible with GUI form
if (isset($conf['order_by_custom'])) {
    $conf['order_by'] = $conf['order_by_custom'];
}
if (isset($conf['order_by_inside_category_custom'])) {
    $conf['order_by_inside_category'] = $conf['order_by_inside_category_custom'];
}

check_lounge();

// include/user.inc.php sets these by calling build_user()/auto_login()/
// auth_key_login(), each mutating the $user/$page globals from its own
// function scope, which static analysis can't trace through the include()
// below. $user's keys are always overwritten before use in every real path;
// $page's are genuinely optional (only auth_key_login() sets them, and only
// on an invalid/expiring auth key), so these defaults are real fallbacks,
// not just analysis scaffolding.
$user['id'] = $conf['guest_id'];
$user['email'] = null;
$user['theme'] = '';
$page['auth_key_invalid'] = false;
$page['notify_api_key_expiration'] = null;

include PHPWG_ROOT_PATH . 'include/user.inc.php';

// include/user.inc.php's own top-level code calls build_user() (which can
// set $user['internal_status']) and auth_key_login() (which can set
// $page['auth_key_invalid']/['notify_api_key_expiration']) — both mutate
// these globals from their own function scope, a function-call-inside-an-
// include hop static analysis can't trace. get_defined_vars() (rather than
// reading $user/$page directly) keeps their real, post-include shape
// visible here instead of appearing to still be exactly the pre-include
// defaults above.
$included_vars = get_defined_vars();
/** @var array<string, mixed> $user */
$user = $included_vars['user'];
/** @var array<string, mixed> $page */
$page = $included_vars['page'];

// This fork does not call back to the real piwigo.org — upstream.example.invalid
// (.invalid TLD per RFC 2606, guaranteed not to resolve) stops it from sending
// telemetry or fetching news/updates/merged-extension lists from the upstream
// server, and makes any accidental outbound call fail fast (DNS failure) rather
// than hang waiting on a real, possibly rate-limiting host.
define('PHPWG_DOMAIN', 'upstream.example.invalid');
define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

if (isset($conf['alternative_pem_url']) and $conf['alternative_pem_url'] != '') {
    define('PEM_URL', $conf['alternative_pem_url']);
} else {
    define('PEM_URL', 'https://' . PHPWG_DOMAIN . '/ext');
}

// language files
load_language('common.lang');
if (is_admin() || (defined('IN_ADMIN') and IN_ADMIN)) {
    load_language('admin.lang');
    // Add language for temporary strings for new popup, from piwigo 15
    load_language('whats_new_' . get_branch_from_version(PHPWG_VERSION) . '.lang');
}
trigger_notify('loading_lang');
load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
    'no_fallback' => true,
    'local' => true,
]);

// only now we can set the localized username of the guest user (and not in
// include/user.inc.php)
if (is_a_guest()) {
    $user['username'] = l10n('guest');
}

// in case an auth key was provided and is no longer valid, we must wait to
// be here, with language loaded, to prepare the message
if ((bool) $page['auth_key_invalid']) {
    // $page itself is only known as array<string, mixed>, so $page['errors']
    // needs its own guard before the nested push -- it's always set to []
    // at the top of this file (line 57), but that specific narrowing is
    // lost by the get_defined_vars()-based re-read above.
    if (! is_array($page['errors'] ?? null)) {
        $page['errors'] = [];
    }
    $page['errors'][] =
      l10n('Your authentication key is no longer valid.')
      . sprintf(' <a href="%s">%s</a>', get_root_url() . 'identification.php', l10n('Login'))
    ;
}

// check if we need to notified user about api_key expiration
if (is_array($page['notify_api_key_expiration'])) {
    // auth_key_login() (include/functions_user.inc.php) always sets
    // 'days_left' to intval($key['days_left']), i.e. an int, but $page is
    // re-derived via get_defined_vars() after the include/user.inc.php
    // include (see comment above), which erases that per-key type info.
    $days_left = $page['notify_api_key_expiration']['days_left'] ?? null;
    $days_left = is_int($days_left) ? $days_left : (is_numeric($days_left) ? (int) $days_left : 0);
    // build_user() always populates 'username'/'email' from the database (see
    // getuserdata()), so these are real strings on every path that reaches
    // here (an auth key was just validated); the is_string() checks are a
    // defensive narrowing, not expected to ever fall back.
    $notify_username = $user['username'];
    $notify_username = is_string($notify_username) ? $notify_username : '';
    $notify_email = $user['email'];
    $notify_email = is_string($notify_email) ? $notify_email : '';
    $is_mail_send = notification_api_key_expiration(
        $notify_username,
        $notify_email,
        $days_left
    );

    if ($is_mail_send) {
        single_update(
            USER_AUTH_KEYS_TABLE,
            [
                'last_notified_on' => $page['notify_api_key_expiration']['dbnow'],
            ],
            [
                'user_id' => $user['id'],
                'auth_key' => $page['notify_api_key_expiration']['auth_key'],
            ],
        );
    }

    unset($page['notify_api_key_expiration']);
}

// template instance
if (defined('IN_ADMIN') and IN_ADMIN) {// Admin template
    // userprefs_get_param() has no return type declaration (its own value
    // comes from the equally-untyped global $user['preferences'][$param]),
    // so its return is inferred as mixed; narrow to the same 'clear'
    // fallback already passed as the default value.
    $admin_theme = userprefs_get_param('admin_theme', 'clear');
    $admin_theme = is_string($admin_theme) ? $admin_theme : 'clear';
    $template = new Template(PHPWG_ROOT_PATH . 'admin/themes', $admin_theme);
} else { // Classic template
    $theme = $user['theme'];
    if (script_basename() != 'ws' and mobile_theme()) {
        $theme = $conf['mobile_theme'];
    }
    $theme = is_string($theme) ? $theme : '';
    $template = new Template(PHPWG_ROOT_PATH . 'themes', $theme);
}

if (! isset($conf['no_photo_yet'])) {
    include PHPWG_ROOT_PATH . 'include/no_photo_yet.inc.php';
}

$user_internal_status = $user['internal_status'] ?? null;
if (is_array($user_internal_status) && ($user_internal_status['guest_must_be_guest'] ?? false) === true) {
    $header_msgs[] = l10n('Bad status for user "guest", using default status. Please notify the webmaster.');
}

if ((bool) $conf['gallery_locked']) {
    $header_msgs[] = l10n('The gallery is locked for maintenance. Please, come back later.');

    if (script_basename() != 'identification' and ! is_admin()) {
        set_status_header(503, 'Service Unavailable');
        @header('Retry-After: 900');
        header('Content-Type: text/html; charset=' . get_pwg_charset());
        echo '<a href="' . get_absolute_root_url(false) . 'identification.php">' . l10n('The gallery is locked for maintenance. Please, come back later.') . '</a>';
        echo str_repeat(' ', 512); // IE6 doesn't error output if below a size
        exit();
    }
}

if ((bool) $conf['check_upgrade_feed']) {
    include_once PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php';
    if (check_upgrade_feed()) {
        $header_msgs[] = 'Some database upgrades are missing, '
          . '<a href="' . get_absolute_root_url(false) . 'upgrade_feed.php">upgrade now</a>';
    }
}

if (count($header_msgs) > 0) {
    $template->assign('header_msgs', $header_msgs);
    $header_msgs = [];
}

if (! empty($conf['filter_pages']) and (bool) get_filter_page_value('used')) {
    include PHPWG_ROOT_PATH . 'include/filter.inc.php';
} else {
    $filter['enabled'] = false;
}

if (isset($conf['header_notes']) && is_array($conf['header_notes'])) {
    $header_notes = array_merge($header_notes, $conf['header_notes']);
}

// default event handlers
add_event_handler('render_category_literal_description', 'render_category_literal_description');
if (! (bool) $conf['allow_html_descriptions']) {
    add_event_handler('render_category_description', 'pwg_nl2br');
}
add_event_handler('render_comment_content', 'render_comment_content');
add_event_handler('render_comment_author', 'strip_tags');
add_event_handler('render_tag_url', 'str2url');
add_event_handler('blockmanager_register_blocks', 'register_default_menubar_blocks');
if (! empty($conf['original_url_protection'])) {
    add_event_handler('get_element_url', 'get_element_url_protection_handler');
    add_event_handler('get_src_image_url', 'get_src_image_url_protection_handler');
}
trigger_notify('init');
