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
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (!defined('PHPWG_ROOT_PATH'))
{
  die ("Hacking attempt!");
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

if (!empty($_POST))
{
  functions::check_pwg_token();
  functions::check_input_parameter('cat_true', $_POST, true, PATTERN_ID);
  functions::check_input_parameter('cat_false', $_POST, true, PATTERN_ID);
  functions::check_input_parameter('section', $_GET, false, '/^[a-z0-9_-]+$/i');
}

// +-----------------------------------------------------------------------+
// |                       modification registration                       |
// +-----------------------------------------------------------------------+


if (isset($_POST['falsify'])
    and isset($_POST['cat_true'])
    and count($_POST['cat_true']) > 0)
{
  switch ($_GET['section'])
  {
    case 'comments' :
    {
      $query = '
UPDATE '.CATEGORIES_TABLE.'
  SET commentable = \'false\'
  WHERE id IN ('.implode(',', $_POST['cat_true']).')
;';
      functions_mysqli::pwg_query($query);
      break;
    }
    case 'visible' :
    {
      functions_admin::set_cat_visible($_POST['cat_true'], 'false');
      break;
    }
    case 'status' :
    {
      functions_admin::set_cat_status($_POST['cat_true'], 'private');
      break;
    }
    case 'representative' :
    {
      $query = '
UPDATE '.CATEGORIES_TABLE.'
  SET representative_picture_id = NULL
  WHERE id IN ('.implode(',', $_POST['cat_true']).')
;';
      functions_mysqli::pwg_query($query);
      break;
    }
  }

  functions::pwg_activity('album', $_POST['cat_true'], 'edit', array('section'=>$_GET['section'], 'action'=>'falsify'));
}
else if (isset($_POST['truthify'])
         and isset($_POST['cat_false'])
         and count($_POST['cat_false']) > 0)
{
  switch ($_GET['section'])
  {
    case 'comments' :
    {
      $query = '
UPDATE '.CATEGORIES_TABLE.'
  SET commentable = \'true\'
  WHERE id IN ('.implode(',', $_POST['cat_false']).')
;';
      functions_mysqli::pwg_query($query);
      break;
    }
    case 'visible' :
    {
      functions_admin::set_cat_visible($_POST['cat_false'], 'true');
      break;
    }
    case 'status' :
    {
      functions_admin::set_cat_status($_POST['cat_false'], 'public');
      break;
    }
    case 'representative' :
    {
      // theoretically, all categories in $_POST['cat_false'] contain at
      // least one element, so Piwigo can find a representative.
      functions_admin::set_random_representative($_POST['cat_false']);
      break;
    }
  }

  functions::pwg_activity('album', $_POST['cat_false'], 'edit', array('section'=>$_GET['section'], 'action'=>'truthify'));
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
  array(
    'cat_options' => 'cat_options.tpl',
    'double_select' => 'double_select.tpl'
    )
  );

$page['section'] = isset($_GET['section']) ? $_GET['section'] : 'status';
$base_url = PHPWG_ROOT_PATH.'admin.php?page=cat_options&amp;section=';

$template->assign(
  array(
    'U_HELP' => functions_url::get_root_url().'admin/popuphelp.php?page=cat_options',
    'F_ACTION'=>$base_url.$page['section']
   )
 );

// TabSheet
$tabsheet = new tabsheet();
$tabsheet->set_id('cat_options');
$tabsheet->select($page['section']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                              form display                             |
// +-----------------------------------------------------------------------+

// for each section, categories in the multiselect field can be :
//
// - true : commentable for comment section
// - false : un-commentable for comment section
// - NA : (not applicable) for virtual categories
//
// for true and false status, we associates an array of category ids,
// function display_select_categories will use the given CSS class for each
// option
$cats_true = array();
$cats_false = array();
switch ($page['section'])
{
  case 'comments' :
  {
    $query_true = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE commentable = \'true\'
;';
    $query_false = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE commentable = \'false\'
;';
    $template->assign(
      array(
        'L_SECTION' => functions::l10n('Authorize users to add comments on selected albums'),
        'L_CAT_OPTIONS_TRUE' => functions::l10n('Authorized'),
        'L_CAT_OPTIONS_FALSE' => functions::l10n('Forbidden'),
        )
      );
    break;
  }
  case 'visible' :
  {
    $query_true = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE visible = \'true\'
;';
    $query_false = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE visible = \'false\'
;';
    $template->assign(
      array(
        'L_SECTION' => functions::l10n('Lock albums'),
        'L_CAT_OPTIONS_TRUE' => functions::l10n('Unlocked'),
        'L_CAT_OPTIONS_FALSE' => functions::l10n('Locked'),
        )
      );
    break;
  }
  case 'status' :
  {
    $query_true = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE status = \'public\'
;';
    $query_false = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE status = \'private\'
;';
    $template->assign(
      array(
        'L_SECTION' => functions::l10n('Manage authorizations for selected albums'),
        'L_CAT_OPTIONS_TRUE' => functions::l10n('Public'),
        'L_CAT_OPTIONS_FALSE' => functions::l10n('Private'),
        )
      );
    break;
  }
  case 'representative' :
  {
    $query_true = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE representative_picture_id IS NOT NULL
;';
    $query_false = '
SELECT DISTINCT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.' INNER JOIN '.IMAGE_CATEGORY_TABLE.' ON id=category_id
  WHERE representative_picture_id IS NULL
;';
    $template->assign(
      array(
        'L_SECTION' => functions::l10n('Representative'),
        'L_CAT_OPTIONS_TRUE' => functions::l10n('singly represented'),
        'L_CAT_OPTIONS_FALSE' => functions::l10n('randomly represented')
        )
      );
    break;
  }
}
functions_category::display_select_cat_wrapper($query_true,array(),'category_option_true');
functions_category::display_select_cat_wrapper($query_false,array(),'category_option_false');
$template->assign('PWG_TOKEN',functions::get_pwg_token());
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Properties of albums'));

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
$template->assign_var_from_handle('ADMIN_CONTENT', 'cat_options');
?>