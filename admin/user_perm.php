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

if (! defined('IN_ADMIN')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
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

if (isset($_GET['user_id']) and is_numeric($_GET['user_id'])) {
    $page['user'] = (int) $_GET['user_id'];
} else {
    die('user_id URL parameter is missing');
}

// +-----------------------------------------------------------------------+
// |                                updates                                |
// +-----------------------------------------------------------------------+

if (isset($_POST['falsify'])
    and count($cat_true) > 0) {
    // if you forbid access to a category, all sub-categories become
    // automatically forbidden
    $subcats = get_subcat_ids($cat_true);
    $query = '
DELETE FROM ' . Tables::userAccess() . '
  WHERE user_id = ' . $page['user'] . '
    AND cat_id IN (' . implode(',', $subcats) . ')
;';
    pwg_query($query);
} elseif (isset($_POST['trueify'])
    and count($cat_false) > 0) {
    add_permission_on_category($cat_false, $page['user']);
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    [
        'user_perm' => 'user_perm.tpl',
        'double_select' => 'double_select.tpl',
    ]
);

$template->assign(
    [
        'TITLE' => l10n(
            'Manage permissions for user "%s"',
            get_username($page['user'])
        ),
        'L_CAT_OPTIONS_TRUE' => l10n('Authorized'),
        'L_CAT_OPTIONS_FALSE' => l10n('Forbidden'),

        'F_ACTION' => PHPWG_ROOT_PATH .
            'admin.php?page=user_perm' .
            '&amp;user_id=' . $page['user'],
    ]
);

// retrieve category ids authorized to the groups the user belongs to
$group_authorized = [];

$query = '
SELECT DISTINCT cat_id, c.uppercats, c.global_rank
  FROM ' . Tables::userGroup() . ' AS ug
    INNER JOIN ' . Tables::groupAccess() . ' AS ga
      ON ug.group_id = ga.group_id
    INNER JOIN ' . Tables::categories() . ' AS c
      ON c.id = ga.cat_id
  WHERE ug.user_id = ' . $page['user'] . '
;';
$result = pwg_query($query);

if (pwg_db_num_rows($result) > 0) {
    $cats = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $cats[] = $row;
        $group_authorized[] = $row['cat_id'];
    }
    usort($cats, global_rank_compare(...));

    foreach ($cats as $category) {
        if ($category['uppercats'] === null) {
            continue;
        }

        $template->append(
            'categories_because_of_groups',
            get_cat_display_name_cache($category['uppercats'], null)
        );
    }
}

// only private categories are listed
$query_true = '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userAccess() . ' ON cat_id = id
  WHERE status = \'private\'
    AND user_id = ' . $page['user'];
if (count($group_authorized) > 0) {
    $query_true .= '
    AND cat_id NOT IN (' . implode(',', $group_authorized) . ')';
}
$query_true .= '
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
if (count($group_authorized) > 0) {
    $query_false .= '
    AND id NOT IN (' . implode(',', $group_authorized) . ')';
}
$query_false .= '
;';
display_select_cat_wrapper($query_false, [], 'category_option_false');

$template->assign('PWG_TOKEN', get_pwg_token());

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
$template->assign_var_from_handle('ADMIN_CONTENT', 'user_perm');
