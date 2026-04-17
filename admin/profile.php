<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

functions::check_input_parameter('user_id', $_GET, false, PATTERN_ID);

$edit_user = functions_user::build_user(isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $user['id'], false);

if (! empty($_POST)) {
    functions::check_pwg_token();
}

require_once __DIR__ . '/../profile.php';

$errors = [];
functions::save_profile_from_post($edit_user, $errors);

functions::load_profile_in_template(
    functions_url::get_root_url() . 'admin.php?page=profile&amp;user_id=' . $edit_user['id'],
    functions_url::get_root_url() . 'admin.php?page=user_list',
    $edit_user
);
$page['errors'] = array_merge($page['errors'], $errors);

// profile.tpl lives in the public theme — add the user theme and its parent chain to the search path
$template->set_template_dir(__DIR__ . '/../themes/' . $user['theme'] . '/template');
$template->set_template_dir(__DIR__ . '/../themes/default/template');
$template->set_filename('profile', 'profile.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'profile');
