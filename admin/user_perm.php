<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_user;

if (! defined('IN_ADMIN')) {
    die('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

if (! empty($_POST)) {
    functions::check_pwg_token();
    functions::check_input_parameter('cat_true', $_POST, true, PATTERN_ID);
    functions::check_input_parameter('cat_false', $_POST, true, PATTERN_ID);
}

// +-----------------------------------------------------------------------+
// |                            variables init                             |
// +-----------------------------------------------------------------------+

if (isset($_GET['user_id']) and
    is_numeric($_GET['user_id'])
) {
    $page['user'] = $_GET['user_id'];
} else {
    die('user_id URL parameter is missing');
}

// +-----------------------------------------------------------------------+
// |                                updates                                |
// +-----------------------------------------------------------------------+

if (isset($_POST['falsify']) and
    isset($_POST['cat_true']) and
    count($_POST['cat_true']) > 0
) {
    // if you forbid access to a category, all sub-categories become
    // automatically forbidden
    $subcats = functions_category::get_subcat_ids($_POST['cat_true']);
    $subcat_ids = implode(', ', $subcats);
    $query = <<<SQL
        DELETE FROM user_access
        WHERE user_id = {$page['user']}
            AND cat_id IN ({$subcat_ids});
        SQL;
    functions_mysqli::pwg_query($query);
} elseif (isset($_POST['truthify']) and
          isset($_POST['cat_false']) and
          count($_POST['cat_false']) > 0
) {
    functions_admin::add_permission_on_category($_POST['cat_false'], $page['user']);
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
          functions::l10n(
              'Manage permissions for user "%s"',
              functions_admin::get_username($page['user'])
          ),
        'L_CAT_OPTIONS_TRUE' => functions::l10n('Authorized'),
        'L_CAT_OPTIONS_FALSE' => functions::l10n('Forbidden'),

        'F_ACTION' =>
            PHPWG_ROOT_PATH .
            'admin.php?page=user_perm' .
            '&amp;user_id=' . $page['user'],
    ]
);

// retrieve category ids authorized to the groups the user belongs to
$group_authorized = [];

$query = <<<SQL
    SELECT DISTINCT cat_id, c.uppercats, c.global_rank
    FROM user_group AS ug
    INNER JOIN group_access AS ga ON ug.group_id = ga.group_id
    INNER JOIN categories AS c ON c.id = ga.cat_id
    WHERE ug.user_id = {$page['user']};
    SQL;
$result = functions_mysqli::pwg_query($query);

if (functions_mysqli::pwg_db_num_rows($result) > 0) {
    $cats = [];

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $cats[] = $row;
        $group_authorized[] = $row['cat_id'];
    }

    usort($cats, functions_category::global_rank_compare(...));

    foreach ($cats as $category) {
        $template->append(
            'categories_because_of_groups',
            functions_html::get_cat_display_name_cache($category['uppercats'], null)
        );
    }
}

// only private categories are listed
$query_true = <<<SQL
    SELECT id, name, uppercats, global_rank
    FROM categories
    INNER JOIN user_access ON cat_id = id
    WHERE status = 'private'
        AND user_id = {$page['user']}

    SQL;

if (count($group_authorized) > 0) {
    $groupAuthorizedImplode = implode(', ', $group_authorized);
    $query_true .= " AND cat_id NOT IN ({$groupAuthorizedImplode})\n";
}

$query_true .= ';';
functions_category::display_select_cat_wrapper($query_true, [], 'category_option_true');

$result = functions_mysqli::pwg_query($query_true);
$authorized_ids = [];

while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
    $authorized_ids[] = $row['id'];
}

$query_false = <<<SQL
    SELECT id, name, uppercats, global_rank
    FROM categories
    WHERE status = 'private'

    SQL;

if (count($authorized_ids) > 0) {
    $authorizedIdsImplode = implode(', ', $authorized_ids);
    $query_false .= " AND id NOT IN ({$authorizedIdsImplode})\n";
}

if (count($group_authorized) > 0) {
    $groupAuthorizedImplode = implode(', ', $group_authorized);
    $query_false .= " AND id NOT IN ({$groupAuthorizedImplode})\n";
}

$query_false .= ';';
functions_category::display_select_cat_wrapper($query_false, [], 'category_option_false');

$template->assign('PWG_TOKEN', functions::get_pwg_token());

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
$template->assign_var_from_handle('ADMIN_CONTENT', 'user_perm');
