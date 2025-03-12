<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if( !defined("PHPWG_ROOT_PATH") ) die ("Hacking attempt!");

functions::check_input_parameter('user_id', $_GET, false, PATTERN_ID);

$edit_user = functions_user::build_user( $_GET['user_id'], false );

if (!empty($_POST))
{
  functions::check_pwg_token();
}

include_once(PHPWG_ROOT_PATH.'profile.php');

$errors = array();
functions::save_profile_from_post($edit_user, $errors);

functions::load_profile_in_template(
  functions_url::get_root_url().'admin.php?page=profile&amp;user_id='.$edit_user['id'],
  functions_url::get_root_url().'admin.php?page=user_list',
  $edit_user
  );
$page['errors'] = array_merge($page['errors'], $errors);

$template->set_filename('profile', 'profile.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'profile');
?>
