<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $page */
global $page;
/** @var Template $template */
global $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

if (! empty($_POST)) {
    check_pwg_token();
    check_input_parameter('cat_true', $_POST, true, PATTERN_ID);
    check_input_parameter('cat_false', $_POST, true, PATTERN_ID);
    check_input_parameter('section', $_GET, false, '/^[a-z0-9_-]+$/i');
}

// +-----------------------------------------------------------------------+
// |                       modification registration                       |
// +-----------------------------------------------------------------------+

if (isset($_POST['falsify'])
    and isset($_POST['cat_true'])
    and is_array($_POST['cat_true'])
    and count($_POST['cat_true']) > 0) {
    $cat_true = [];
    foreach ($_POST['cat_true'] as $raw_cat_id) {
        if (is_numeric($raw_cat_id)) {
            $cat_true[] = (int) $raw_cat_id;
        }
    }

    switch ($_GET['section']) {
        case 'comments':

            $query = '
UPDATE ' . CATEGORIES_TABLE . '
  SET commentable = \'false\'
  WHERE id IN (' . implode(',', $cat_true) . ')
;';
            pwg_query($query);
            break;

        case 'visible':

            set_cat_visible($cat_true, 'false');
            break;

        case 'status':

            set_cat_status($cat_true, 'private');
            break;

        case 'representative':

            $query = '
UPDATE ' . CATEGORIES_TABLE . '
  SET representative_picture_id = NULL
  WHERE id IN (' . implode(',', $cat_true) . ')
;';
            pwg_query($query);
            break;

    }

    pwg_activity('album', $cat_true, 'edit', [
        'section' => $_GET['section'],
        'action' => 'falsify',
    ]);
} elseif (isset($_POST['trueify'])
         and isset($_POST['cat_false'])
         and is_array($_POST['cat_false'])
         and count($_POST['cat_false']) > 0) {
    $cat_false = [];
    foreach ($_POST['cat_false'] as $raw_cat_id) {
        if (is_numeric($raw_cat_id)) {
            $cat_false[] = (int) $raw_cat_id;
        }
    }

    switch ($_GET['section']) {
        case 'comments':

            $query = '
UPDATE ' . CATEGORIES_TABLE . '
  SET commentable = \'true\'
  WHERE id IN (' . implode(',', $cat_false) . ')
;';
            pwg_query($query);
            break;

        case 'visible':

            set_cat_visible($cat_false, 'true');
            break;

        case 'status':

            set_cat_status($cat_false, 'public');
            break;

        case 'representative':

            // theoretically, all categories in $cat_false contain at
            // least one element, so Piwigo can find a representant.
            set_random_representant($cat_false);
            break;

    }

    pwg_activity('album', $cat_false, 'edit', [
        'section' => $_GET['section'],
        'action' => 'trueify',
    ]);
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    [
        'cat_options' => 'cat_options.tpl',
        'double_select' => 'double_select.tpl',
    ]
);

$section = $_GET['section'] ?? 'status';
if (! is_string($section) or ! in_array($section, ['comments', 'visible', 'status', 'representative'], true)) {
    $section = 'status';
}
$page['section'] = $section;
$base_url = PHPWG_ROOT_PATH . 'admin.php?page=cat_options&amp;section=';

$template->assign(
    [
        'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=cat_options',
        'F_ACTION' => $base_url . $section,
    ]
);

// TabSheet
$tabsheet = new tabsheet();
$tabsheet->set_id('cat_options');
$tabsheet->select($section);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                              form display                             |
// +-----------------------------------------------------------------------+

// for each section, categories in the multiselect field can be :
//
// - true : commentable for comment section
// - false : un-commentable for comment section
// - NA : (not applicable) for virtual categories
//
// for true and false status, we associates an array of category ids,
// function display_select_categories will use the given CSS class for each
// option
$cats_true = [];
$cats_false = [];
[$query_true, $query_false, $l_section, $l_true, $l_false] = match ($section) {
    'comments' => [
        '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE commentable = \'true\'
;',
        '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE commentable = \'false\'
;',
        l10n('Authorize users to add comments on selected albums'),
        l10n('Authorized'),
        l10n('Forbidden'),
    ],
    'visible' => [
        '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE visible = \'true\'
;',
        '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE visible = \'false\'
;',
        l10n('Lock albums'),
        l10n('Unlocked'),
        l10n('Locked'),
    ],
    'status' => [
        '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE status = \'public\'
;',
        '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE status = \'private\'
;',
        l10n('Manage authorizations for selected albums'),
        l10n('Public'),
        l10n('Private'),
    ],
    // 'representative' is the only value that can still reach here: $section
    // is already restricted to comments/visible/status/representative by
    // the in_array() guard above.
    default => [
        '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE representative_picture_id IS NOT NULL
;',
        '
SELECT DISTINCT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id=category_id
  WHERE representative_picture_id IS NULL
;',
        l10n('Representative'),
        l10n('singly represented'),
        l10n('randomly represented'),
    ],
};
$template->assign(
    [
        'L_SECTION' => $l_section,
        'L_CAT_OPTIONS_TRUE' => $l_true,
        'L_CAT_OPTIONS_FALSE' => $l_false,
    ]
);
display_select_cat_wrapper($query_true, [], 'category_option_true');
display_select_cat_wrapper($query_false, [], 'category_option_false');
$template->assign('PWG_TOKEN', get_pwg_token());
$template->assign('ADMIN_PAGE_TITLE', l10n('Properties of abums'));

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
$template->assign_var_from_handle('ADMIN_CONTENT', 'cat_options');
