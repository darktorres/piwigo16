<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Display filtered history lines
 */

// +-----------------------------------------------------------------------+
// |                              functions                                |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $conf/$template are set by include/common.inc.php;
// $page is set by admin.php before including this panel.
/**
 * @var array<string, mixed> $conf
 * @var \Template $template
 * @var array<string, mixed> $page
 */
global $conf, $template, $page;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions_history.inc.php';

$types = array_merge(['none'], get_enums(HISTORY_TABLE, 'image_type'));

$display_thumbnails = [
    'no_display_thumbnail' => l10n('No display'),
    'display_thumbnail_classic' => l10n('Classic display'),
    'display_thumbnail_hoverbox' => l10n('Hoverbox display'),
];

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('filter_ip', $_GET, false, '/^[0-9.]+$/');
check_input_parameter('filter_image_id', $_GET, false, '/^\d+$/');
check_input_parameter('filter_user_id', $_GET, false, '/^\d+$/');

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filename('history', 'history.tpl');

// TabSheet initialization
history_tabsheet();

$template->assign(
    [
        'F_ACTION' => get_root_url() . 'admin.php?page=history',
        'API_METHOD' => 'ws.php?format=json&method=pwg.history.search',
    ]
);

// +-----------------------------------------------------------------------+
// |                            navigation bar                             |
// +-----------------------------------------------------------------------+

if (isset($page['search_id'])) {
    // $page['nb_lines']/['start'] and $conf['nb_logs_page'] come from
    // loosely-typed global bags; create_navigation_bar() needs a real
    // int|string/int, so narrow each before the call.
    $nb_lines = $page['nb_lines'] ?? null;
    $nb_lines = is_numeric($nb_lines) ? (int) $nb_lines : 0;

    $navbar_start = $page['start'] ?? null;
    $navbar_start = is_numeric($navbar_start) ? (int) $navbar_start : 0;

    $nb_logs_page = $conf['nb_logs_page'] ?? null;
    $nb_logs_page = is_numeric($nb_logs_page) ? (int) $nb_logs_page : 0;

    $navbar = create_navigation_bar(
        get_root_url() . 'admin.php' . get_query_string_diff(['start']),
        $nb_lines,
        $navbar_start,
        $nb_logs_page
    );

    $template->assign('navbar', $navbar);
}

// +-----------------------------------------------------------------------+
// |                             filter form                               |
// +-----------------------------------------------------------------------+

$form = [];

// $page['search'] is an unserialize()'d value (see ws_history_search() in
// include/ws_functions/pwg.php); only provably mixed, so narrow to an
// array (and its 'fields' sub-array) before reading nested offsets.
$page_search = $page['search'] ?? null;
$page_search_fields = is_array($page_search) ? ($page_search['fields'] ?? null) : null;
$page_search_fields = is_array($page_search_fields) ? $page_search_fields : null;

if (is_array($page_search)) {
    if (isset($page_search_fields['date-after'])) {
        $form['start'] = $page_search_fields['date-after'];
    }

    if (isset($page_search_fields['date-before'])) {
        $form['end'] = $page_search_fields['date-before'];
    }
} else {
    // by default, at page load, we want the selected date to be the current
    // date
    $form['start'] = $form['end'] = pwg_now()->format('Y-m-d');
    $form['types'] = $types;
    // Hoverbox by default
    $form['display_thumbnail'] =
      pwg_get_cookie_var('display_thumbnail', 'no_display_thumbnail');
}

$form_param = [];
$form_param['ip'] = $_GET['filter_ip'] ?? null;
$form_param['image_id'] = $_GET['filter_image_id'] ?? null;

// check_input_parameter() above already validated filter_user_id to be
// digits-only (pattern '/^\d+$/') when present; is_numeric() here narrows
// the type for static analysis and falls back to the "no filter" sentinel
// -1 (matching the pre-existing '-1' convention below) for anything else.
$form_param['user_id'] = isset($_GET['filter_user_id']) && is_numeric($_GET['filter_user_id'])
    ? (int) $_GET['filter_user_id']
    : -1;

if (isset($_GET['filter_ip']) or isset($_GET['filter_image_id']) or isset($_GET['filter_user_id'])) {
    $form['start'] = '';
}

if ($form_param['user_id'] !== -1) {
    $query = '
  SELECT
      username
    FROM ' . USERS_TABLE . '
    WHERE id = ' . $form_param['user_id'] . '
  ;';

    $row = pwg_db_fetch_row(pwg_query($query));
    $form_param['user_name'] = $row !== null ? $row[0] : null;
    // $row already holds this exact query's result; re-running the query a
    // second time just to test emptiness was a redundant duplicate DB call.
    $form_param['user_id'] = empty($row) ? -1 : $form_param['user_id'];
}

$template->assign(
    [
        'USER_ID' => $form_param['user_id'],
        'USER_NAME' => $form_param['user_name'] ?? null,
        'IMAGE_ID' => $form_param['image_id'],
        'IP' => $form_param['ip'],
        'START' => $form['start'] ?? null,
        'END' => $form['end'] ?? null,
    ]
);

$template->assign('display_thumbnails', $display_thumbnails);
$template->assign('display_thumbnail_selected', $form['display_thumbnail'] ?? null);
$template->assign('guest_id', $conf['guest_id']);
$template->assign('ADMIN_PAGE_TITLE', l10n('History'));

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'history');
