<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// vendor/autoload.php must be required directly here (not just reached
// transitively via common.inc.php's own include/env.inc.php) -- Paths::
// fromIndex() below needs the autoloader before common.inc.php has even
// started running. Requiring it twice is safe (PHP's own realpath-keyed
// include cache no-ops the second require). Mirrors index.php's own
// ordering exactly.
require __DIR__ . '/vendor/autoload.php';

use Piwigo\Bootstrap\AdminDispatcher;
use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;
use Piwigo\Http\RequestFactory;
use Piwigo\Template\Template;

// Bootstrap globals, set by include/common.inc.php below.
/**
 * @var array<string, mixed> $conf
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $conf, $template, $user;

// +-----------------------------------------------------------------------+
// | Basic constants and includes                                          |
// +-----------------------------------------------------------------------+

$paths = Paths::fromIndex(__FILE__);
define('PHPWG_ROOT_PATH', './');
define('IN_ADMIN', true);

include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions_plugins.inc.php';

// P21 boots the Kernel/DI container on the admin.php path too -- needed by
// AdminDispatcher (below) to resolve AdminSubControllerInterface services.
// Safe to run after common.inc.php's own legacy config/db/session/user
// bootstrap: every attachGlobals() this calls is additive/idempotent
// (CurrentUser/PageState/Lang all bridge onto or read from the *existing*
// $GLOBALS state rather than overwriting it -- see their own docblocks),
// same ordering index.php already uses.
CommonBootstrap::run($paths);
include_once PHPWG_ROOT_PATH . 'admin/include/add_core_tabs.inc.php';

trigger_notify('loc_begin_admin');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(AccessLevel::Administrator);

check_input_parameter('page', $_GET, false, '/^[a-zA-Z\d_-]+$/');
check_input_parameter('section', $_GET, false, '/^[a-z]+[a-z_\/-]*(\.php)?$/i');

// +-----------------------------------------------------------------------+
// | Filesystem checks                                                     |
// +-----------------------------------------------------------------------+

if ($conf['fs_quick_check_period'] > 0) {
    $perform_fsqc = false;

    // Real invariant: fs_quick_check_last_check is only written (as an ISO
    // 8601 string, see fs_quick_check()) once the quick check has run at
    // least once — on a fresh install the config key genuinely does not
    // exist yet, so this isset() is a real "has it ever run" guard, not
    // just type-narrowing boilerplate.
    $fs_quick_check_last_check = isset($conf['fs_quick_check_last_check']) && is_string($conf['fs_quick_check_last_check'])
        ? $conf['fs_quick_check_last_check']
        : null;

    if ($fs_quick_check_last_check !== null) {
        $fs_quick_check_period = $conf['fs_quick_check_period'];
        if (is_numeric($fs_quick_check_period) and strtotime($fs_quick_check_last_check) < strtotime($fs_quick_check_period . ' seconds ago')) {
            $perform_fsqc = true;
        }
    } else {
        $perform_fsqc = true;
    }

    if ($perform_fsqc) {
        fs_quick_check();
    }
}

// +-----------------------------------------------------------------------+
// | Direct actions                                                        |
// +-----------------------------------------------------------------------+

// save plugins_new display order (AJAX action)
if (isset($_GET['plugins_new_order'])) {
    pwg_set_session_var('plugins_new_order', $_GET['plugins_new_order']);
    exit;
}

// theme changer
if (isset($_GET['change_theme'])) {
    $admin_themes = ['roma', 'clear'];
    $admin_theme_param = userprefs_get_param('admin_theme', 'clear');
    $admin_theme_array = [is_string($admin_theme_param) ? $admin_theme_param : 'clear'];
    $result = array_diff(
        $admin_themes,
        $admin_theme_array
    );

    $new_admin_theme = array_pop(
        $result
    );

    userprefs_update_param('admin_theme', $new_admin_theme);

    $url_params = [];
    foreach (['page', 'tab', 'section'] as $url_param) {
        if (isset($_GET[$url_param]) and is_scalar($_GET[$url_param])) {
            $url_params[] = $url_param . '=' . $_GET[$url_param];
        }
    }

    $redirect_url = 'admin.php';
    if (count($url_params) > 0) {
        $redirect_url .= '?' . implode('&amp;', $url_params);
    }

    redirect($redirect_url);
}

// +-----------------------------------------------------------------------+
// | Synchronize user informations                                         |
// +-----------------------------------------------------------------------+

// sync_user() is only useful when external authentication is activated
if ((bool) $conf['external_authentification']) {
    sync_users();
}

// +-----------------------------------------------------------------------+
// | Variables init                                                        |
// +-----------------------------------------------------------------------+

$change_theme_url = PHPWG_ROOT_PATH . 'admin.php?';
$test_get = $_GET;
unset($test_get['page']);
unset($test_get['section']);
unset($test_get['tag']);
$query_string = $_SERVER['QUERY_STRING'] ?? null;
if (count($test_get) == 0 and is_string($query_string) and ! empty($query_string)) {
    $change_theme_url .= str_replace('&', '&amp;', $query_string) . '&amp;';
}
$change_theme_url .= 'change_theme=1';

// ?page=plugin-community-pendings is an clean alias of
// ?page=plugin&section=community/admin.php&tab=pendings
if (isset($_GET['page']) and is_string($_GET['page']) and (bool) preg_match('/^plugin-([^-]*)(?:-(.*))?$/', $_GET['page'], $matches)) {
    $_GET['page'] = 'plugin';

    if ((bool) preg_match('/^piwigo_(videojs|openstreetmap)$/', $matches[1])) {
        $matches[1] = str_replace('_', '-', $matches[1]);
    }

    $_GET['section'] = $matches[1] . '/admin.php';
    if (isset($matches[2])) {
        $_GET['tab'] = $matches[2];
    }
}

// ?page=album-134-properties is an clean alias of
// ?page=album&cat_id=134&tab=properties
if (isset($_GET['page']) and is_string($_GET['page']) and (bool) preg_match('/^album-(\d+)(?:-(.*))?$/', $_GET['page'], $matches)) {
    $_GET['page'] = 'album';
    $_GET['cat_id'] = $matches[1];
    if (isset($matches[2])) {
        $_GET['tab'] = $matches[2];
    }
}

// ?page=photo-1234-properties is an clean alias of
// ?page=photo&image_id=1234&tab=properties
if (isset($_GET['page']) and is_string($_GET['page']) and (bool) preg_match('/^photo-(\d+)(?:-(.*))?$/', $_GET['page'], $matches)) {
    $_GET['page'] = 'photo';
    $_GET['image_id'] = $matches[1];
    if (isset($matches[2])) {
        $_GET['tab'] = $matches[2];
    }
}

// A valid page slug used to mean exactly "a real admin/<slug>.php file
// exists" -- true when every page was still a raw legacy include. Now
// that AdminDispatcher/config/admin_pages.php can fully absorb a page
// (deleting its legacy file entirely, P23 batch 6a onward), that check
// alone silently rejects every fully-ported slug and falls back to
// 'intro' -- found live: admin.php?page=cat_options rendered the
// dashboard instead of the album-options page once its legacy file was
// deleted, with no error of any kind (a real, previously-undetected
// verification gap: HTTP 200 + no error markers looks identical to this
// silent fallback). A slug is valid when *either* its legacy file still
// exists *or* it's registered in config/admin_pages.php.
/** @var array<string, class-string<\Piwigo\Controller\Admin\AdminSubControllerInterface>> $admin_pages */
$admin_pages = require PHPWG_ROOT_PATH . 'config/admin_pages.php';

/** @var array<string, mixed> $page */
if (isset($_GET['page'])
    and is_string($_GET['page'])
    and (bool) preg_match('/^[a-z_]*$/', $_GET['page'])
    and (is_file(PHPWG_ROOT_PATH . 'admin/' . $_GET['page'] . '.php') or array_key_exists($_GET['page'], $admin_pages))) {
    $page['page'] = $_GET['page'];
} else {
    $page['page'] = 'intro';
}

$link_start = PHPWG_ROOT_PATH . 'admin.php?page=';
$conf_link = $link_start . 'configuration&amp;section=';

// $_GET['tab'] is often used to perform and
// include('admin_page_'.$_GET['tab'].'.php') : we need to protect it to
// avoid any unexpected file inclusion
check_input_parameter('tab', $_GET, false, '/^[a-zA-Z\d_-]+$/');

// +-----------------------------------------------------------------------+
// | Template init                                                         |
// +-----------------------------------------------------------------------+

$title = l10n('Piwigo Administration'); // for include/page_header.php
$page['page_banner'] = '<h1>' . l10n('Piwigo Administration') . '</h1>';
$page['body_id'] = 'theAdminPage';

$template->set_filenames([
    'admin' => 'admin.tpl',
]);

$template->assign(
    [
        'USERNAME' => $user['username'],
        'ENABLE_SYNCHRONIZATION' => $conf['enable_synchronization'],
        'U_SITE_MANAGER' => $link_start . 'site_manager',
        'U_HISTORY_STAT' => $link_start . 'stats&amp;year=' . date('Y') . '&amp;month=' . date('n'),
        'U_FAQ' => $link_start . 'help',
        'U_MAINTENANCE' => $link_start . 'maintenance',
        'U_NOTIFICATION_BY_MAIL' => $link_start . 'notification_by_mail',
        'U_CONFIG_GENERAL' => $link_start . 'configuration',
        'U_CONFIG_DISPLAY' => $conf_link . 'default',
        'U_CONFIG_EXTENTS' => $link_start . 'extend_for_templates',
        'U_CONFIG_MENUBAR' => $link_start . 'menubar',
        'U_CONFIG_LANGUAGES' => $link_start . 'languages',
        'U_CONFIG_THEMES' => $link_start . 'themes',
        'U_CATEGORIES' => $link_start . 'cat_list',
        'U_ALBUMS' => $link_start . 'albums',
        'U_CAT_OPTIONS' => $link_start . 'cat_options',
        'U_CAT_UPDATE' => $link_start . 'site_update&amp;site=1',
        'U_RATING' => $link_start . 'rating',
        'U_RECENT_SET' => $link_start . 'batch_manager&amp;filter=prefilter-last_import',
        'U_BATCH' => $link_start . 'batch_manager',
        'U_TAGS' => $link_start . 'tags',
        'U_USERS' => $link_start . 'user_list',
        'U_GROUPS' => $link_start . 'group_list',
        'U_RETURN' => get_gallery_home_url(),
        'U_ADMIN' => PHPWG_ROOT_PATH . 'admin.php',
        'U_LOGOUT' => PHPWG_ROOT_PATH . 'index.php?act=logout',
        'U_PLUGINS' => $link_start . 'plugins',
        'U_ADD_PHOTOS' => $link_start . 'photos_add',
        'U_CHANGE_THEME' => $change_theme_url,
        'ADMIN_PAGE_TITLE' => 'Piwigo Administration Page',
        'ADMIN_PAGE_OBJECT_ID' => '',
        'U_SHOW_TEMPLATE_TAB' => $conf['show_template_in_side_menu'],
        'SHOW_RATING' => $conf['rate'],
    ]
);

if ((bool) $conf['enable_core_update']) {
    $template->assign('U_UPDATES', $link_start . 'updates');
}

if ((bool) $conf['activate_comments']) {
    $template->assign('U_COMMENTS', $link_start . 'comments');

    // pending comments
    $query = '
SELECT COUNT(*)
  FROM ' . Tables::comments() . '
  WHERE validated=\'false\'
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$nb_comments] = $row;

    if ($nb_comments > 0) {
        $template->assign('NB_PENDING_COMMENTS', $nb_comments);
        $page['nb_pending_comments'] = $nb_comments;
    }
}

// any photo in the caddie?
// Real invariant: $user['id'] is always the numeric user id set in
// build_user() (include/functions_user.inc.php).
$user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;
$query = '
SELECT COUNT(*)
  FROM ' . Tables::caddie() . '
  WHERE user_id = ' . $user_id . '
;';
$row = pwg_db_fetch_row(pwg_query($query));
assert($row !== null);
[$nb_photos_in_caddie] = $row;

if ($nb_photos_in_caddie > 0) {
    $template->assign(
        [
            'NB_PHOTOS_IN_CADDIE' => $nb_photos_in_caddie,
            'U_CADDIE' => $link_start . 'batch_manager&amp;filter=prefilter-caddie',
        ]
    );
} else {
    $template->assign(
        [
            'NB_PHOTOS_IN_CADDIE' => 0,
            'U_CADDIE' => '',
        ]
    );
}

// any photos with no md5sum ?
if (in_array($page['page'], ['site_update', 'batch_manager'])) {
    $nb_no_md5sum = count(get_photos_no_md5sum());

    if ($nb_no_md5sum > 0) {
        $page['no_md5sum_number'] = $nb_no_md5sum;
    }
}

// only calculate number of orphans on all pages if the number of images is "not huge"
$page['nb_orphans'] = 0;

$row = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM ' . Tables::images()));
assert($row !== null);
[$page['nb_photos_total']] = $row;
if ($page['nb_photos_total'] < 100000) { // 100k is already a big gallery
    $page['nb_orphans'] = count_orphans();
}

$template->assign(
    [
        'NB_ORPHANS' => $page['nb_orphans'],
        'U_ORPHANS' => $link_start . 'batch_manager&amp;filter=prefilter-no_album',
    ]
);

// +-----------------------------------------------------------------------+
// | Refresh permissions                                                   |
// +-----------------------------------------------------------------------+

// Only for pages witch change permissions
if (
    in_array(
        $page['page'],
        [
            'site_manager', // delete site
            'site_update',  // ?only POST
        ]
    )
    or (! empty($_POST) and in_array(
        $page['page'],
        [
            'album',        // public/private; lock/unlock, permissions
            'albums',
            'cat_options',  // public/private; lock/unlock
            'user_list',    // group assoc; user level
            'user_perm',
        ]
    )
    )
) {
    invalidate_user_cache();
}

$show_whats_new = false;

$whats_new_major_version = get_branch_from_version(AppInfo::VERSION);

if ((bool) userprefs_get_param('show_whats_new_' . $whats_new_major_version, true) and pwg_is_dbconf_writeable()) {
    if ($user['registration_date'] > $conf['last_major_update']) {
        userprefs_update_param('show_whats_new_' . $whats_new_major_version, false);
    } else {
        // purge old whats_new_*
        // Real invariant: build_user()/getuserdata() always populate the
        // 'preferences' key (as [] when there is nothing to unserialize),
        // so this is a type guard against a corrupted unserialize()
        // result, not a "key might be absent" check.
        $user_preferences = $user['preferences'] ?? null;
        if (is_array($user_preferences)) {
            $userprefs_params_to_delete = [];

            foreach (array_keys($user_preferences) as $pref_param) {
                if ((bool) preg_match('/^whats_new_/', (string) $pref_param)) {
                    $userprefs_params_to_delete[] = (string) $pref_param;
                }
            }

            if (count($userprefs_params_to_delete) > 0) {
                userprefs_delete_param($userprefs_params_to_delete);
            }
        }

        $show_whats_new = true;
    }
}

$release_note_url = PHPWG_URL . '/releases/' . $whats_new_major_version . '.0.0';

$whats_new_imgs = [
    '1' => 'https://upstream.example.invalid/whats-new-1.png',
    '2' => 'https://upstream.example.invalid/whats-new-2.png',
    '3' => 'https://upstream.example.invalid/whats-new-3.png',
];

// If last major update conf is less than a month old then display bell for whats new popin
// Real invariant: include/common.inc.php always writes last_major_update as
// a DB NOW() string (see the `! isset($conf['last_major_update'])` block
// there) before admin.php runs.
$display_bell = false;
$last_major_update = $conf['last_major_update'];
if (is_string($last_major_update) and strtotime($last_major_update) > strtotime('1 month ago')) {
    $display_bell = true;
}

$template->assign(
    [
        'SHOW_WHATS_NEW' => $show_whats_new,
        'WHATS_NEW_MAJOR_VERSION' => $whats_new_major_version,
        'RELEASE_NOTE_URL' => $release_note_url,
        'WHATS_NEW_IMGS' => $whats_new_imgs,
        'DISPLAY_BELL' => $display_bell,
    ]
);

// +-----------------------------------------------------------------------+
// | Include specific page                                                 |
// +-----------------------------------------------------------------------+

trigger_notify('loc_begin_admin_page');

// $page['page'] is always a valid admin page slug — set above to either
// the validated $_GET['page'] or the literal 'intro' fallback (both
// string), so this offset is always string here.
$page_slug = $page['page'];

// SEC-19: sub-controllers read input from this PSR-7 request
// (getQueryParams()/getParsedBody()), not $_GET/$_POST directly. Slugs not
// yet migrated to a sub-controller still get the raw superglobals via the
// legacy include, unchanged, until AdminDispatcher's fallback path is
// retired (P23).
AdminDispatcher::dispatch($page_slug, RequestFactory::fromGlobals());

$template->assign('ACTIVE_MENU', get_active_menu($page_slug));

// +-----------------------------------------------------------------------+
// | Sending html code                                                     |
// +-----------------------------------------------------------------------+

// Add the Piwigo Official menu
$template->assign('pwgmenu', pwg_URL());

include PHPWG_ROOT_PATH . 'include/page_header.php';

trigger_notify('loc_end_admin');

flush_page_messages();

$template->pparse('admin');

include PHPWG_ROOT_PATH . 'include/page_tail.php';
