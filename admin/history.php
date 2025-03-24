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

use Piwigo\admin\inc\functions_history;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_cookie;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

require_once __DIR__ . '/../admin/inc/functions_history.php';

$types = array_merge(['none'], functions_mysqli::get_enums('history', 'image_type'));

$display_thumbnails = [
    'no_display_thumbnail' => functions::l10n('No display'),
    'display_thumbnail_classic' => functions::l10n('Classic display'),
    'display_thumbnail_hoverbox' => functions::l10n('Hoverbox display'),
];

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('filter_ip', $_GET, false, '/^[0-9.]+$/');
functions::check_input_parameter('filter_image_id', $_GET, false, '/^\d+$/');
functions::check_input_parameter('filter_user_id', $_GET, false, '/^\d+$/');

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filename('history', 'history.tpl');

// TabSheet initialization
functions_history::history_tabsheet();

$template->assign(
    [
        'F_ACTION' => functions_url::get_root_url() . 'admin.php?page=history',
        'API_METHOD' => 'ws.php?format=json&method=pwg.history.search',
    ]
);

// +-----------------------------------------------------------------------+
// |                            navigation bar                             |
// +-----------------------------------------------------------------------+

if (isset($page['search_id'])) {
    $navbar = functions::create_navigation_bar(
        functions_url::get_root_url() . 'admin.php' . functions_url::get_query_string_diff(['start']),
        $page['nb_lines'],
        $page['start'],
        $conf['nb_logs_page']
    );

    $template->assign('navbar', $navbar);
}

// +-----------------------------------------------------------------------+
// |                             filter form                               |
// +-----------------------------------------------------------------------+

$form = [];

if (isset($page['search'])) {
    if (isset($page['search']['fields']['date-after'])) {
        $form['start'] = $page['search']['fields']['date-after'];
    }

    if (isset($page['search']['fields']['date-before'])) {
        $form['end'] = $page['search']['fields']['date-before'];
    }
} else {
    // by default, at page load, we want the selected date to be the current
    // date
    $form['start'] = $form['end'] = date('Y-m-d');
    $form['types'] = $types;
    // Hoverbox by default
    $form['display_thumbnail'] =
      functions_cookie::pwg_get_cookie_var('display_thumbnail', 'no_display_thumbnail');
}

$form_param['ip'] = isset($_GET['filter_ip']) ? $_GET['filter_ip'] : ($form['ip'] ?? null);
$form_param['image_id'] = isset($_GET['filter_image_id']) ? $_GET['filter_image_id'] : ($form['image_id'] ?? null);
$form_param['user_id'] = isset($_GET['filter_user_id']) ? $_GET['filter_user_id'] : '-1';

if (isset($_GET['filter_ip']) ||
    isset($_GET['filter_image_id']) ||
    isset($_GET['filter_user_id'])
) {
    $form['start'] = '';
}

if ($form_param['user_id'] != '-1') {
    $query = <<<SQL
        SELECT username
        FROM users
        WHERE id = {$form_param['user_id']};
        SQL;

    list($form_param['user_name']) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
    $form_param['user_id'] = empty(functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query))) ? '-1' : $form_param['user_id'];
}

$template->assign(
    [
        'USER_ID' => $form_param['user_id'],
        'USER_NAME' => ($form_param['user_name'] ?? null),
        'IMAGE_ID' => $form_param['image_id'],
        'FILENAME' => ($form['filename'] ?? null),
        'IP' => $form_param['ip'],
        'START' => $form['start'],
        'END' => $form['end'],
    ]
);

$template->assign('display_thumbnails', $display_thumbnails);
$template->assign('display_thumbnail_selected', $form['display_thumbnail']);
$template->assign('guest_id', $conf['guest_id']);
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('History'));

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'history');
