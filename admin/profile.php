<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\Url\UrlGenerator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


check_input_parameter('user_id', $_GET, false, PATTERN_ID);

$editUserId = is_numeric($_GET['user_id'] ?? null) ? (int) $_GET['user_id'] : 0;
$edit_user = build_user($editUserId, false);

if (!empty($_POST)) {
    check_pwg_token();
}

require_once PHPWG_ROOT_PATH . 'include/profile_functions.php';

$errors = [];
save_profile_from_post($edit_user, $errors);

load_profile_in_template(
    ServiceLocator::get(UrlGenerator::class)->admin('profile') . '&amp;user_id='.(is_scalar($edit_user['id'] ?? null) ? (string) $edit_user['id'] : ''),
    ServiceLocator::get(UrlGenerator::class)->admin('user_list'),
    $edit_user
);
$page['errors'] = array_merge($page['errors'], $errors);

$template->set_filename('profile', 'profile.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'profile');
