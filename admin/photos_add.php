<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_upload;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

include_once(PHPWG_ROOT_PATH.'admin/inc/functions_upload.php');

define(
  'PHOTOS_ADD_BASE_URL',
  functions_url::get_root_url().'admin.php?page=photos_add'
  );

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                          Load configuration                           |
// +-----------------------------------------------------------------------+

$upload_form_config = functions_upload::get_upload_form_config();

// +-----------------------------------------------------------------------+
// |                                 Tabs                                  |
// +-----------------------------------------------------------------------+
if (isset($_GET['section']))
{
  $page['tab'] = $_GET['section'];

  // backward compatibility
  if ('ploader' == $page['tab'])
  {
    $page['tab'] = 'applications';
  }
}
else
{
  $page['tab'] = 'direct';
}

$tabsheet = new tabsheet();
$tabsheet->set_id('photos_add');
$tabsheet->select($page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
  array(
    'photos_add' => 'photos_add_'.$page['tab'].'.tpl'
    )
  );

// +-----------------------------------------------------------------------+
// |                             Load the tab                              |
// +-----------------------------------------------------------------------+

include(PHPWG_ROOT_PATH.'admin/photos_add_'.$page['tab'].'.php');
?>