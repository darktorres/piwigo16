<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_upgrade;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\PersistentFileCache;
use Piwigo\inc\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    throw new \RuntimeException('Hacking attempt!');
}

// determine the initial instant to indicate the generation time of this page
$t2 = microtime(true);

// this is a security precaution to prevent someone trying to break out of a SQL statement.
function sanitize_mysql_kv(
    string &$v
): void {
    $v = addslashes($v);
}

if (is_array($_GET)) {
    array_walk_recursive($_GET, sanitize_mysql_kv(...));
}

if (is_array($_POST)) {
    array_walk_recursive($_POST, sanitize_mysql_kv(...));
}

if (is_array($_COOKIE)) {
    array_walk_recursive($_COOKIE, sanitize_mysql_kv(...));
}

if (! empty($_SERVER['PATH_INFO'])) {
    $_SERVER['PATH_INFO'] = addslashes($_SERVER['PATH_INFO']);
}

//
// Define some basic configuration arrays this also prevents malicious
// rewriting of language and other array values via URI params
//
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

require __DIR__ . '/../inc/config_default.php';

if (file_exists(__DIR__ . '/../local/config/config.php')) {
    require __DIR__ . '/../local/config/config.php';
}

if (! defined('PWG_LOCAL_DIR')) {
    define('PWG_LOCAL_DIR', 'local/');
}

if (file_exists(__DIR__ . '/../local/config/database.php')) {
    require __DIR__ . '/../local/config/database.php';
}

if (! defined('PHPWG_INSTALLED')) {
    header('Location: install.php');
    exit;
}

if (isset($conf->show_php_errors) &&
    ! empty($conf->show_php_errors)
) {
    ini_set('error_reporting', $conf->show_php_errors);

    if ($conf->show_php_errors_on_frontend) {
        ini_set('display_errors', true);
    }
}

if ($conf->session_gc_probability > 0) {
    ini_set('session.gc_divisor', 100);
    ini_set('session.gc_probability', min($conf->session_gc_probability, 100));
}

require __DIR__ . '/../inc/constants.php';
require __DIR__ . '/../inc/functions.php';
require __DIR__ . '/../inc/Template.php';

$persistent_cache = new PersistentFileCache();

// Database connection
try {
    $conf->sql_backend::pwg_db_connect(
        $conf->db_host,
        $conf->db_user,
        $conf->db_password,
        $conf->db_base
    );
} catch (Exception $exception) {
    $conf->sql_backend::my_error(functions::l10n($exception->getMessage()), true);
}

functions::load_conf_from_db();

$logger = new Katzgrau\KLogger\Logger('./' . $conf->data_location . $conf->log_dir, $conf->log_level, [
    // we use an hashed filename to prevent direct file access, and we salt with
    // the db_password instead of secret_key because the log must be usable in i.php
    // (secret_key is in the database)
    'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $conf->db_password) . '.txt',
]);

if (! $conf->check_upgrade_feed && (! isset($conf->piwigo_db_version) || $conf->piwigo_db_version != functions::get_branch_from_version(PHPWG_VERSION))) {
    functions::redirect(functions_url::get_root_url() . 'upgrade.php');
}

ImageStdParams::load_from_db();

session_start();
functions_plugins::load_plugins();

if (! isset($conf->piwigo_installed_version)) {
    functions::conf_update_param('piwigo_installed_version', PHPWG_VERSION);
} elseif ($conf->piwigo_installed_version != PHPWG_VERSION) {
    functions::pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'autoupdate', [
        'from_version' => $conf->piwigo_installed_version,
        'to_version' => PHPWG_VERSION,
    ]);
    functions::conf_update_param('piwigo_installed_version', PHPWG_VERSION);
}

// users can have defined a custom order pattern, incompatible with GUI form
if (isset($conf->order_by_custom)) {
    $conf->order_by = $conf->order_by_custom;
}

if (isset($conf->order_by_inside_category_custom)) {
    $conf->order_by_inside_category = $conf->order_by_inside_category_custom;
}

functions::check_lounge();

require __DIR__ . '/../inc/user.php';

if (in_array(substr($user['language'], 0, 2), ['fr', 'it', 'de', 'es', 'pl', 'ru', 'nl', 'tr', 'da'])) {
    define('PHPWG_DOMAIN', substr($user['language'], 0, 2) . '.piwigo.org');
} elseif ($user['language'] == 'zh_CN') {
    define('PHPWG_DOMAIN', 'cn.piwigo.org');
} elseif ($user['language'] == 'pt_BR') {
    define('PHPWG_DOMAIN', 'br.piwigo.org');
} else {
    define('PHPWG_DOMAIN', 'piwigo.org');
}

const PHPWG_URL = 'https://' . PHPWG_DOMAIN;

if (isset($conf->alternative_pem_url) &&
    $conf->alternative_pem_url != ''
) {
    define('PEM_URL', $conf->alternative_pem_url);
} else {
    define('PEM_URL', 'https://' . PHPWG_DOMAIN . '/ext');
}

// language files
functions::load_language('common.lang');

if (functions_user::is_admin() ||
   (defined('IN_ADMIN') && IN_ADMIN)
) {
    functions::load_language('admin.lang');
}

functions_plugins::trigger_notify('loading_lang');
functions::load_language('lang', './local/', [
    'no_fallback' => true,
    'local' => true,
]);

// only now we can set the localized username of the guest user (and not in
// inc/user.php)
if (functions_user::is_a_guest()) {
    $user['username'] = functions::l10n('guest');
}

// in case an auth key was provided and is no longer valid, we must wait to
// be here, with language loaded, to prepare the message
if (isset($page['auth_key_invalid']) &&
    $page['auth_key_invalid']
) {
    $page['errors'][] =
      functions::l10n('Your authentication key is no longer valid.')
      . sprintf(' <a href="%s">%s</a>', functions_url::get_root_url() . 'identification.php', functions::l10n('Login'))
    ;
}

// template instance
if (defined('IN_ADMIN') &&
    IN_ADMIN
) { // Admin template
    $template = new Template('./admin/themes', functions_user::userprefs_get_param('admin_theme', 'roma'));
} else { // Classic template
    $theme = $user['theme'];

    if (functions::script_basename() !== 'ws' &&
        functions::mobile_theme()
    ) {
        $theme = $conf->mobile_theme;
    }

    $template = new Template('./themes', $theme);
}

if (! isset($conf->no_photo_yet)) {
    require __DIR__ . '/../inc/no_photo_yet.php';
}

if (isset($user['internal_status']['guest_must_be_guest']) &&
    $user['internal_status']['guest_must_be_guest'] === true
) {
    $header_msgs[] = functions::l10n('Bad status for user "guest", using default status. Please notify the webmaster.');
}

if ($conf->gallery_locked) {
    $header_msgs[] = functions::l10n('The gallery is locked for maintenance. Please, come back later.');

    if (functions::script_basename() !== 'identification' &&
        ! functions_user::is_admin()
    ) {
        functions_html::set_status_header(503, 'Service Unavailable');
        header('Retry-After: 900');
        header('Content-Type: text/html; charset=utf-8');
        echo '<a href="' . functions_url::get_absolute_root_url(false) . 'identification.php">' . functions::l10n('The gallery is locked for maintenance. Please, come back later.') . '</a>';
        echo str_repeat(' ', 512); //IE6 doesn't error output if below a size
        exit();
    }
}

if ($conf->check_upgrade_feed && functions_upgrade::check_upgrade_feed()) {
    $header_msgs[] = 'Some database upgrades are missing, '
      . '<a href="' . functions_url::get_absolute_root_url(false) . 'upgrade_feed.php">upgrade now</a>';
}

if ($header_msgs !== []) {
    $template->assign('header_msgs', $header_msgs);
    $header_msgs = [];
}

if (! empty($conf->filter_pages) &&
    functions::get_filter_page_value('used')
) {
    require __DIR__ . '/../inc/filter.php';
} else {
    $filter['enabled'] = false;
}

if (isset($conf->header_notes)) {
    $header_notes = array_merge($header_notes, $conf->header_notes);
}

// default event handlers
functions_plugins::add_event_handler('render_category_literal_description', functions_html::render_category_literal_description(...));

if (! $conf->allow_html_descriptions) {
    functions_plugins::add_event_handler('render_category_description', nl2br(...));
}

functions_plugins::add_event_handler('render_comment_content', functions_html::render_comment_content(...));
functions_plugins::add_event_handler('render_comment_author', strip_tags(...));
functions_plugins::add_event_handler('render_tag_url', functions::str2url(...));
functions_plugins::add_event_handler('blockmanager_register_blocks', functions_html::register_default_menubar_blocks(...), EVENT_HANDLER_PRIORITY_NEUTRAL - 1);

if (! empty($conf->original_url_protection)) {
    functions_plugins::add_event_handler('get_element_url', functions_html::get_element_url_protection_handler(...));
    functions_plugins::add_event_handler('get_src_image_url', functions_html::get_src_image_url_protection_handler(...));
}

functions_plugins::trigger_notify('init');
