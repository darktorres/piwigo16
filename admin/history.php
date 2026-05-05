<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;
use Piwigo\Db\SchemaHelper;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Users\UserRepository;
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

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
require_once(PHPWG_ROOT_PATH.'admin/include/functions_history.inc.php');

$types = array_merge(['none'], SchemaHelper::getEnums(HISTORY_TABLE, 'image_type'));

$display_thumbnails = ['no_display_thumbnail' => l10n('No display'),
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
    'F_ACTION' => get_root_url().'admin.php?page=history',
    'API_METHOD' => 'ws.php?format=json&method=pwg.history.search',
    ]
);

// +-----------------------------------------------------------------------+
// |                            navigation bar                             |
// +-----------------------------------------------------------------------+

if (isset($page['search_id'])) {
    $navbar = create_navigation_bar(
        get_root_url().'admin.php'.get_query_string_diff(['start']),
        $page['nb_lines'],
        $page['start'],
        Config::nbLogsPage()
    );

    $template->assign('navbar', $navbar);
}

// +-----------------------------------------------------------------------+
// |                             filter form                               |
// +-----------------------------------------------------------------------+

$form = ['ip' => null, 'image_id' => null, 'filename' => null, 'start' => '', 'end' => '', 'types' => [], 'display_thumbnail' => ''];

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
      pwg_get_cookie_var('display_thumbnail', 'no_display_thumbnail');
}

$raw_filter_ip = $_GET['filter_ip'] ?? null;
$form_param['ip'] = is_scalar($raw_filter_ip) ? (string) $raw_filter_ip : $form['ip'];
$raw_filter_image_id = $_GET['filter_image_id'] ?? null;
$form_param['image_id'] = is_scalar($raw_filter_image_id) ? (string) $raw_filter_image_id : $form['image_id'];
$raw_filter_user_id = $_GET['filter_user_id'] ?? null;
$form_param['user_id'] = is_scalar($raw_filter_user_id) ? (string) $raw_filter_user_id : '-1';

if (isset($_GET['filter_ip']) or isset($_GET['filter_image_id']) or isset($_GET['filter_user_id'])) {
    $form['start'] = '';
}

if ($form_param['user_id'] != '-1') {
    $userFields = Config::userFields();
    $foundUsername = ServiceLocator::get(UserRepository::class)
        ->findUsernameById(
            $userFields['id'],
            $userFields['username'],
            USERS_TABLE,
            (int) $form_param['user_id']
        );
    if ($foundUsername !== null) {
        $form_param['user_name'] = $foundUsername;
    } else {
        $form_param['user_id'] = '-1';
    }
}

$template->assign(
    [
    'USER_ID' => $form_param['user_id'],
    'USER_NAME' => $form_param['user_name'] ?? null,
    'IMAGE_ID' => $form_param['image_id'],
    'FILENAME' => $form['filename'],
    'IP' => $form_param['ip'],
    'START' => $form['start'] ?? null,
    'END' => $form['end'] ?? null,
    ]
);

$template->assign('display_thumbnails', $display_thumbnails);
$template->assign('display_thumbnail_selected', $form['display_thumbnail']);
$template->assign('guest_id', Config::guestId());
$template->assign('ADMIN_PAGE_TITLE', l10n('History'));

$template->assign('page_data_json', json_encode([
    'API_METHOD'                  => 'ws.php?format=json&method=pwg.history.search',
    'filter_user_name'            => $form_param['user_name'] ?? null,
    'guest_id'                    => Config::guestId(),
    'today'                       => date('Y-m-d'),
    'initial_user_id'             => $form_param['user_id'],
    'initial_image_id'            => $form_param['image_id'] ?? '',
    'initial_ip'                  => $form_param['ip'] ?? '',
    'new_user_item'               => null,
    'str_dwld'                    => l10n('Downloaded'),
    'str_most_visited'            => l10n('Most visited'),
    'str_best_rated'              => l10n('Best rated'),
    'str_list'                    => l10n('Random photo'),
    'str_favorites'               => l10n('Your favorites'),
    'str_recent_cats'             => l10n('Recent albums'),
    'str_recent_pics'             => l10n('Recent photos'),
    'str_memories'                => l10n('Memories'),
    'str_no_longer_exist_photo'   => l10n('This photo no longer exists'),
    'str_guest'                   => l10n('guest'),
    'str_contact_form'            => l10n('Contact Form'),
    'str_edit_img'                => l10n('Edit photo'),
    'unit_MB'                     => l10n('%s MB'),
    'str_and_more'                => l10n('and %d more'),
    'str_search_details'          => [
        'allwords'    => l10n('Search for words'),
        'date_posted' => l10n('Post date'),
        'tags'        => l10n('Tags'),
        'cat'         => l10n('Album'),
        'author'      => l10n('Author'),
        'added_by'    => l10n('Added by'),
        'filetypes'   => l10n('File type'),
    ],
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'history');
