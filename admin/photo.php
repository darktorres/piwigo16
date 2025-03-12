<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
functions::check_input_parameter('image_id', $_GET, false, PATTERN_ID);

$admin_photo_base_url = functions_url::get_root_url().'admin.php?page=photo-'.$_GET['image_id'];

// retrieving direct information about picture
$page['image'] = functions_admin::get_image_infos($_GET['image_id'], true);

if (isset($_GET['cat_id']))
{
  $query = '
SELECT *
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$_GET['cat_id'].'
;';
  $category = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
}

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'properties';

if (isset($_GET['tab']))
{
  $page['tab'] = $_GET['tab'];
}

$tabsheet = new tabsheet();
$tabsheet->set_id('photo');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$template->assign(
  array(
    'ADMIN_PAGE_TITLE' => functions::l10n('Edit photo').' <span class="image-id">#'.$_GET['image_id'].'</span>',
    )
  );

// +-----------------------------------------------------------------------+
// | Load the tab                                                          |
// +-----------------------------------------------------------------------+

if ('properties' == $page['tab'])
{
  include(PHPWG_ROOT_PATH.'admin/picture_modify.php');
}
elseif ('coi' == $page['tab'])
{
  include(PHPWG_ROOT_PATH.'admin/picture_coi.php');
}
elseif ('formats' == $page['tab'] && $conf['enable_formats'])
{
  include(PHPWG_ROOT_PATH.'admin/picture_formats.php');
}
else
{
  include(PHPWG_ROOT_PATH.'admin/photo_'.$page['tab'].'.php');
}
?>