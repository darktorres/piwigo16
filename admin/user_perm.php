<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('IN_ADMIN')) {
    die('Hacking attempt!');
}

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

global $template, $user, $page, $persistent_cache, $lang;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

if (!empty($_POST)) {
    check_pwg_token();
    check_input_parameter('cat_true', $_POST, true, PATTERN_ID);
    check_input_parameter('cat_false', $_POST, true, PATTERN_ID);
}

// +-----------------------------------------------------------------------+
// |                            variables init                             |
// +-----------------------------------------------------------------------+

if (isset($_GET['user_id']) and is_numeric($_GET['user_id'])) {
    $page['user'] = $_GET['user_id'];
} else {
    die('user_id URL parameter is missing');
}

// +-----------------------------------------------------------------------+
// |                                updates                                |
// +-----------------------------------------------------------------------+

$post_cat_true = is_array($_POST['cat_true'] ?? null) ? $_POST['cat_true'] : [];
$post_cat_false = is_array($_POST['cat_false'] ?? null) ? $_POST['cat_false'] : [];

if (isset($_POST['falsify'])
    and count($post_cat_true) > 0) {
    // if you forbid access to a category, all sub-categories become
    // automatically forbidden
    $post_cat_true_ids = array_map(fn($v) => is_numeric($v) ? (int) $v : 0, $post_cat_true);
    $subcats = get_subcat_ids($post_cat_true_ids);
    $subcats_str = array_map(fn($v) => (string) $v, $subcats);
    $query = '
DELETE FROM '.USER_ACCESS_TABLE.'
  WHERE user_id = '.$page['user'].'
    AND cat_id IN ('.implode(',', $subcats_str).')
;';
    pwg_query($query);
} elseif (isset($_POST['trueify'])
    and count($post_cat_false) > 0) {
    $post_cat_false_ids = array_map(fn($v) => is_numeric($v) ? (int) $v : 0, $post_cat_false);
    add_permission_on_category($post_cat_false_ids, (int) $page['user']);
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
    'TITLE' =>
      l10n(
          'Manage permissions for user "%s"',
          get_username((int) $page['user'])
      ),
    'L_CAT_OPTIONS_TRUE' => l10n('Authorized'),
    'L_CAT_OPTIONS_FALSE' => l10n('Forbidden'),

    'F_ACTION' =>
        PHPWG_ROOT_PATH.
        'admin.php?page=user_perm'.
        '&amp;user_id='.$page['user'],
    ]
);


// retrieve category ids authorized to the groups the user belongs to
$group_authorized = [];

$query = '
SELECT DISTINCT cat_id, c.uppercats, c.global_rank
  FROM '.USER_GROUP_TABLE.' AS ug
    INNER JOIN '.GROUP_ACCESS_TABLE.' AS ga
      ON ug.group_id = ga.group_id
    INNER JOIN '.CATEGORIES_TABLE.' AS c
      ON c.id = ga.cat_id
  WHERE ug.user_id = '.$page['user'].'
;';
$result = pwg_query($query);

if (pwg_db_num_rows($result) > 0) {
    $cats = [];
    while ($row = pwg_db_fetch_assoc($result)) {
        $cats[] = $row;
        $group_authorized[] = $row['cat_id'];
    }
    usort($cats, global_rank_compare(...));

    foreach ($cats as $category) {
        $template->append(
            'categories_because_of_groups',
            get_cat_display_name_cache((string)($category['uppercats'] ?? ''), null)
        );
    }
}

// only private categories are listed
$query_true = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.' INNER JOIN '.USER_ACCESS_TABLE.' ON cat_id = id
  WHERE status = \'private\'
    AND user_id = '.$page['user'];
if (count($group_authorized) > 0) {
    $query_true .= '
    AND cat_id NOT IN ('.implode(',', $group_authorized).')';
}
$query_true .= '
;';
display_select_cat_wrapper($query_true, [], 'category_option_true');

$result = pwg_query($query_true);
$authorized_ids = [];
while ($row = pwg_db_fetch_assoc($result)) {
    $authorized_ids[] = $row['id'];
}

$query_false = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE status = \'private\'';
if (count($authorized_ids) > 0) {
    $query_false .= '
    AND id NOT IN ('.implode(',', $authorized_ids).')';
}
if (count($group_authorized) > 0) {
    $query_false .= '
    AND id NOT IN ('.implode(',', $group_authorized).')';
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
