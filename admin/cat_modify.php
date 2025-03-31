<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

include_once(PHPWG_ROOT_PATH.'inc/functions_mail.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions_plugins::trigger_notify('loc_begin_cat_modify');

//---------------------------------------------------------------- verification
if ( !isset( $_GET['cat_id'] ) || !is_numeric( $_GET['cat_id'] ) )
{
  trigger_error( 'missing cat_id param', E_USER_ERROR);
}

//--------------------------------------------------------- form criteria check

if (isset($redirect))
{
  functions::redirect($admin_album_base_url.'-properties');
}

// nullable fields
foreach (array('comment','dir','site_id', 'id_uppercat') as $nullable)
{
  if (!isset($category[$nullable]))
  {
    $category[$nullable] = '';
  }
}

$category['is_virtual'] = empty($category['dir']) ? true : false;

$query = 'SELECT DISTINCT category_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.$_GET['cat_id'].'
  LIMIT 1';
$result = functions_mysqli::pwg_query($query);
$category['has_images'] = functions_mysqli::pwg_db_num_rows($result)>0 ? true : false;

// number of sub-categories
$subcat_ids = functions_category::get_subcat_ids(array($category['id']));

$category['nb_subcats'] = count($subcat_ids) - 1;

// Navigation path
$navigation = functions_html::get_cat_display_name_cache(
  $category['uppercats'],
  functions_url::get_root_url().'admin.php?page=album-'
);

// Parent navigation path
$uppercats_array = explode(',', $category['uppercats']);
if (count($uppercats_array) > 1)
{
  array_pop($uppercats_array);
  $parent_navigation = functions_html::get_cat_display_name_cache(
    implode(',', $uppercats_array),
    functions_url::get_root_url().'admin.php?page=album-'
  );
}
else
{
  $parent_navigation = functions::l10n('Root');
}

//----------------------------------------------------- template initialization
$template->set_filename( 'album_properties', 'cat_modify.tpl');

$base_url = functions_url::get_root_url().'admin.php?page=';
$cat_list_url = $base_url.'albums';

$self_url = $cat_list_url;
if (!empty($category['id_uppercat']))
{
  $self_url.= '&amp;parent_id='.$category['id_uppercat'];
}

// We show or hide this warning in JS
$page['warnings'][] = functions::l10n('This album is currently locked, visible only to administrators.').'<span class="icon-cone unlock-album">'.functions::l10n('Unlock it').'</span>';

$template->assign(
  array(
    'CATEGORIES_NAV'     => preg_replace("# {2,}#"," ",preg_replace("#(\r\n|\n\r|\n|\r)#"," ",$navigation)),
    'CATEGORIES_PARENT_NAV' => preg_replace("# {2,}#"," ",preg_replace("#(\r\n|\n\r|\n|\r)#"," ",$parent_navigation)),
    'PARENT_CAT_ID'      => !empty($category['id_uppercat']) ? $category['id_uppercat'] : 0,
    'CAT_ID'             => $category['id'],
    'CAT_NAME'           => @htmlspecialchars($category['name']),
    'CAT_COMMENT'        => @htmlspecialchars($category['comment']),
    'IS_VISIBLE'          => functions_mysqli::boolean_to_string($category['visible']),

    'U_DELETE' => $base_url.'albums',

    'U_JUMPTO' => functions_url::make_index_url(
      array(
        'category' => $category
        )
      ),

    'U_ADD_PHOTOS_ALBUM' => $base_url.'photos_add&amp;album='.$category['id'],
    'U_CHILDREN' => $cat_list_url.'&amp;parent_id='.$category['id'],
    'U_MOVE' => $base_url.'albums&amp;parent_id='.$category['id'],
    )
  );
 
if ($conf['activate_comments'])
{
  $template->assign('CAT_COMMENTABLE', functions_mysqli::boolean_to_string($category['commentable']));
}

// manage album elements link
$image_count = 0;
$info_title = "";
if ($category['has_images'])
{
  $template->assign(
    'U_MANAGE_ELEMENTS',
    $base_url.'batch_manager&amp;filter=album-'.$category['id']
    );

  $query = '
SELECT
    COUNT(image_id),
    MIN(DATE(date_available)),
    MAX(DATE(date_available))
  FROM '.IMAGES_TABLE.'
    JOIN '.IMAGE_CATEGORY_TABLE.' ON image_id = id
  WHERE category_id = '.$category['id'].'
;';
  list($image_count, $min_date, $max_date) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

  if ($min_date == $max_date)
  {
    $info_title = functions::l10n(
      'This album contains %d photos, added on %s.',
      $image_count,
      functions::format_date($min_date)
      );
  }
  else
  {
    $info_title = functions::l10n(
      'This album contains %d photos, added between %s and %s.',
      $image_count,
      functions::format_date($min_date),
      functions::format_date($max_date)
      );
  }
  
}
$info_photos = functions::l10n('%d photos', $image_count);

$template->assign(
  array(
    'INFO_PHOTO' => $info_photos,
    'INFO_TITLE' => $info_title
    )
  );

// total number of images under this category (including sub-categories)
  $query = '
SELECT DISTINCT
    (image_id)
  FROM 
    '.IMAGE_CATEGORY_TABLE.'
  WHERE 
    category_id IN ('.implode(',', $subcat_ids).')
  ;';
  $image_ids_recursive = functions_mysqli::query2array($query, null, 'image_id');

  $category['nb_images_recursive'] = count($image_ids_recursive);

// date creation
$query = '
SELECT occurred_on
  FROM `'.ACTIVITY_TABLE.'`
  WHERE object_id = '.$category['id'].' 
    AND object = "album"
    AND action = "add"
';
$result = functions_mysqli::query2array($query);

if (count($result) > 0) {
  $template->assign(
    array(
      'INFO_CREATION_SINCE' => functions::time_since($result[0]['occurred_on'], 'day', $format=null, $with_text=true, $with_week=true, $only_last_unit=true),
      'INFO_CREATION' => functions::format_date($result[0]['occurred_on'], array('day', 'month','year'))
      )
    );
}

// Sub Albums
$query = '
SELECT COUNT(*)
  FROM `'.CATEGORIES_TABLE.'`
  WHERE id_uppercat = '.$category['id'].'
';
$result = functions_mysqli::query2array($query);


$template->assign(
  array(
    'INFO_DIRECT_SUB' => functions::l10n(
      '%d sub-albums',
      $result[0]['COUNT(*)']
    ), 
    )
  );

$template->assign(array(
  'INFO_ID' => functions::l10n('Numeric identifier : %d',$category['id']),
  'INFO_LAST_MODIFIED_SINCE' => functions::time_since($category['lastmodified'], 'minute', $format=null, $with_text=true, $with_week=true, $only_last_unit=true),
  'INFO_LAST_MODIFIED'=> functions::format_date($category['lastmodified'], array('day', 'month','year')),
  'INFO_IMAGES_RECURSIVE' => functions::l10n(
    '%d including sub-albums',
    $category['nb_images_recursive']
  ),
  'INFO_SUBCATS' => functions::l10n(
    '%d in whole branch',
    $category['nb_subcats']
  ),

  'NB_SUBCATS' => $category['nb_subcats'],
  )
);

$template->assign(array(
  'U_MANAGE_RANKS' => $base_url.'element_set_ranks&amp;cat_id='.$category['id'],
  'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(array('categories')),
  ));

if (!$category['is_virtual'])
{
  $category['cat_full_dir'] = functions_admin::get_complete_dir($_GET['cat_id']);
  $category_full_dir = preg_replace('/\/$/', '', $category['cat_full_dir']);
  $template->assign(
    array(
      'CAT_FULL_DIR' => $category_full_dir
      )
    );
  $template->assign('CAT_DIR_NAME', basename($category_full_dir));
  $template->assign('CAT_MIN_DIR', functions_admin::get_min_local_dir($category_full_dir));

  if ($conf['enable_synchronization'])
  {
    $template->assign(
      'U_SYNC',
      $base_url.'site_update&amp;site='.$category['site_id'].'&amp;cat_id='.$category['id']
      );
  }

}

// representative management
if ($category['has_images'] or !empty($category['representative_picture_id']))
{
  $tpl_representative = array();

  // picture to display : the identified representative or the generic random
  // representative ?
  if (!empty($category['representative_picture_id']))
  {
    $tpl_representative['picture'] = functions_admin::get_category_representative_properties($category['representative_picture_id'], derivative_std_params::IMG_MEDIUM);
  }

  // can the admin choose to set a new random representative ?
  $tpl_representative['ALLOW_SET_RANDOM'] = ($category['has_images'] ? true : false);

  // can the admin delete the current representative ?
  if (
    ($category['has_images']
     and $conf['allow_random_representative'])
    or
    (!$category['has_images']
     and !empty($category['representative_picture_id'])))
  {
    $tpl_representative['ALLOW_DELETE'] = true;
  }
  $template->assign('representative', $tpl_representative);
}

if ($category['is_virtual'])
{
  $template->assign('parent_category', empty($category['id_uppercat']) ? array() : array($category['id_uppercat']));
}

$template->assign('PWG_TOKEN', functions::get_pwg_token());

functions_plugins::trigger_notify('loc_end_cat_modify');

//----------------------------------------------------------- sending html code
$template->assign_var_from_handle('ADMIN_CONTENT', 'album_properties');
?>
