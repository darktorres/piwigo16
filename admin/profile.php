<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $page
 * @var Template $template
 */
global $page, $template;

check_input_parameter('user_id', $_GET, false, PATTERN_ID);

// check_input_parameter() already guarantees that, when present, this
// value matches PATTERN_ID (i.e. is a digits-only string); is_numeric()
// is the real runtime guard for the "absent/empty" case it lets through.
$requested_user_id = $_GET['user_id'] ?? null;
$requested_user_id = is_numeric($requested_user_id) ? (int) $requested_user_id : 0;

$edit_user = build_user($requested_user_id, false);

if (! empty($_POST)) {
    check_pwg_token();
}

include_once PHPWG_ROOT_PATH . 'profile.php';

$errors = [];
save_profile_from_post($edit_user, $errors);

// $edit_user['id'] comes back from build_user()/getuserdata() as a raw DB
// row value (string|null per this driver), not necessarily the int we
// originally requested — narrow it for the URL concatenation below.
$edit_user_id = $edit_user['id'] ?? null;
$edit_user_id = is_scalar($edit_user_id) ? (string) $edit_user_id : '';

load_profile_in_template(
    get_root_url() . 'admin.php?page=profile&amp;user_id=' . $edit_user_id,
    get_root_url() . 'admin.php?page=user_list',
    $edit_user
);
// $page['errors'] is always initialized to [] by include/common.inc.php,
// but that isn't visible across the include() boundary -- narrow before
// merging.
if (! is_array($page['errors'] ?? null)) {
    $page['errors'] = [];
}
$page['errors'] = array_merge($page['errors'], $errors);

$template->set_filename('profile', 'profile.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'profile');
