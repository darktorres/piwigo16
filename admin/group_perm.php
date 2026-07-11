<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $page is set by admin.php before including this
// panel; $template by include/common.inc.php.
/**
 * @var array<string, mixed> $page
 * @var Template $template
 */
global $page, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(AccessLevel::Administrator);

if (! empty($_POST)) {
    check_pwg_token();
    check_input_parameter('cat_true', $_POST, true, ValidationPattern::ID);
    check_input_parameter('cat_false', $_POST, true, ValidationPattern::ID);
}

// check_input_parameter() above already fatal_error()s out unless these
// are arrays of digit-only strings, but that guarantee isn't visible to
// static analysis across the call; re-derive real array types here.
$cat_true = isset($_POST['cat_true']) && is_array($_POST['cat_true'])
    ? array_filter($_POST['cat_true'], is_string(...))
    : [];
$cat_false = isset($_POST['cat_false']) && is_array($_POST['cat_false'])
    ? array_map(intval(...), array_filter($_POST['cat_false'], is_numeric(...)))
    : [];

// +-----------------------------------------------------------------------+
// |                            variables init                             |
// +-----------------------------------------------------------------------+

if (! isset($_GET['group_id'])) {
    fatal_error('group_id URL parameter is missing');
}

check_input_parameter('group_id', $_GET, false, ValidationPattern::ID);

// check_input_parameter() above already fatal_error()s out unless
// group_id matches ValidationPattern::ID (digits only), but that guarantee isn't
// visible to static analysis across the call; re-check here for a real
// int narrowing.
if (! is_numeric($_GET['group_id'])) {
    fatal_error('group_id URL parameter is missing');
}

$page['group'] = (int) $_GET['group_id'];

// +-----------------------------------------------------------------------+
// |                                updates                                |
// +-----------------------------------------------------------------------+

if (isset($_POST['falsify'])
    and count($cat_true) > 0) {
    // if you forbid access to a category, all sub-categories become
    // automatically forbidden
    $subcats = get_subcat_ids($cat_true);
    $query = '
DELETE
  FROM ' . Tables::groupAccess() . '
  WHERE group_id = ' . $page['group'] . '
  AND cat_id IN (' . implode(',', $subcats) . ')
;';
    pwg_query($query);
} elseif (isset($_POST['trueify'])
         and count($cat_false) > 0) {
    $uppercats = get_uppercat_ids($cat_false);
    $private_uppercats = [];

    $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $uppercats) . ')
  AND status = \'private\'
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $private_uppercats[] = $row['id'];
    }

    // retrying to authorize a category which is already authorized may cause
    // an error (in SQL statement), so we need to know which categories are
    // accesible
    $authorized_ids = [];

    $query = '
SELECT cat_id
  FROM ' . Tables::groupAccess() . '
  WHERE group_id = ' . $page['group'] . '
;';
    $result = pwg_query($query);

    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $authorized_ids[] = $row['cat_id'];
    }

    $inserts = [];
    $to_autorize_ids = array_diff($private_uppercats, $authorized_ids);
    foreach ($to_autorize_ids as $to_autorize_id) {
        $inserts[] = [
            'group_id' => $page['group'],
            'cat_id' => $to_autorize_id,
        ];
    }

    mass_inserts(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts);
    invalidate_user_cache();
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    [
        'group_perm' => 'group_perm.tpl',
        'double_select' => 'double_select.tpl',
    ]
);

$template->assign(
    [
        'TITLE' => l10n(
            'Manage permissions for group "%s"',
            get_groupname($page['group'])
        ),
        'L_CAT_OPTIONS_TRUE' => l10n('Authorized'),
        'L_CAT_OPTIONS_FALSE' => l10n('Forbidden'),

        'F_ACTION' => get_root_url() .
            'admin.php?page=group_perm&amp;group_id=' .
            $page['group'],
    ]
);

// only private categories are listed
$query_true = '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::groupAccess() . ' ON cat_id = id
  WHERE status = \'private\'
    AND group_id = ' . $page['group'] . '
;';
display_select_cat_wrapper($query_true, [], 'category_option_true');

$result = pwg_query($query_true);
$authorized_ids = [];
while ((bool) ($row = pwg_db_fetch_assoc($result))) {
    $authorized_ids[] = $row['id'];
}

$query_false = '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE status = \'private\'';
if (count($authorized_ids) > 0) {
    $query_false .= '
    AND id NOT IN (' . implode(',', $authorized_ids) . ')';
}
$query_false .= '
;';
display_select_cat_wrapper($query_false, [], 'category_option_false');

$template->assign('PWG_TOKEN', get_pwg_token());

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
$template->assign_var_from_handle('ADMIN_CONTENT', 'group_perm');
