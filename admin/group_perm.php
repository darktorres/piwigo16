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
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
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

if (! isset($_GET['group_id'])) {
    functions_html::fatal_error('group_id URL parameter is missing');
}

functions::check_input_parameter('group_id', $_GET, false, PATTERN_ID);

$page['group'] = $_GET['group_id'];

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
    $subcat_list = implode(', ', $subcats);
    $query = <<<SQL
        DELETE FROM group_access
        WHERE group_id = {$page['group']}
            AND cat_id IN ({$subcat_list});
        SQL;
    functions_mysqli::pwg_query($query);
} elseif (isset($_POST['truthify']) and
          isset($_POST['cat_false']) and
          count($_POST['cat_false']) > 0
) {
    $uppercats = functions_admin::get_uppercat_ids($_POST['cat_false']);
    $private_uppercats = [];

    $uppercat_list = implode(', ', $uppercats);
    $query = <<<SQL
        SELECT id
        FROM categories
        WHERE id IN ({$uppercat_list})
            AND status = 'private';
        SQL;
    $result = functions_mysqli::pwg_query($query);

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $private_uppercats[] = $row['id'];
    }

    // retrying to authorize a category which is already authorized may cause
    // an error (in SQL statement), so we need to know which categories are
    // accessible
    $authorized_ids = [];

    $query = <<<SQL
        SELECT cat_id
        FROM group_access
        WHERE group_id = {$page['group']};
        SQL;
    $result = functions_mysqli::pwg_query($query);

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $authorized_ids[] = $row['cat_id'];
    }

    $inserts = [];
    $to_authorize_ids = array_diff($private_uppercats, $authorized_ids);

    foreach ($to_authorize_ids as $to_authorize_id) {
        $inserts[] = [
            'group_id' => $page['group'],
            'cat_id' => $to_authorize_id,
        ];
    }

    functions_mysqli::mass_inserts('group_access', ['group_id', 'cat_id'], $inserts);
    functions_admin::invalidate_user_cache();
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
        'TITLE' =>
          functions::l10n(
              'Manage permissions for group "%s"',
              functions_admin::get_groupname($page['group'])
          ),
        'L_CAT_OPTIONS_TRUE' => functions::l10n('Authorized'),
        'L_CAT_OPTIONS_FALSE' => functions::l10n('Forbidden'),

        'F_ACTION' =>
            functions_url::get_root_url() .
            'admin.php?page=group_perm&amp;group_id=' .
            $page['group'],
    ]
);

// only private categories are listed
$query_true = <<<SQL
    SELECT id, name, uppercats, global_rank
    FROM categories
    INNER JOIN group_access ON cat_id = id
    WHERE status = 'private'
        AND group_id = {$page['group']};
    SQL;
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
    $ids_list = implode(', ', $authorized_ids);
    $query_false .= <<<SQL
        AND id NOT IN ({$ids_list})

        SQL;
}

$query_false .= ';';
functions_category::display_select_cat_wrapper($query_false, [], 'category_option_false');

$template->assign('PWG_TOKEN', functions::get_pwg_token());

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
$template->assign_var_from_handle('ADMIN_CONTENT', 'group_perm');
