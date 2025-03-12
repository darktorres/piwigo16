<?php
/**********************************
 * REQUIRED PATH TO THE TPL FILE */

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_session;

$TOUR_PATH = PHPWG_PLUGINS_PATH.'TakeATour/tours/privacy/tour.tpl';

/*********************************/



/**********************
 *    Preparse part   *
 **********************/
  //picture id
  if (isset($_GET['page']) and preg_match('/^photo-(\d+)(?:-(.*))?$/', $_GET['page'], $matches))
  {
    $_GET['image_id'] = $matches[1];
  }
  functions::check_input_parameter('image_id', $_GET, false, PATTERN_ID);
  if (isset($_GET['image_id']) and functions_session::pwg_get_session_var('TAT_image_id')==null)
  {
    $template->assign('TAT_image_id', $_GET['image_id']);
    functions_session::pwg_set_session_var('TAT_image_id', $_GET['image_id']);
  }
  elseif (is_numeric(functions_session::pwg_get_session_var('TAT_image_id')))
  {
    $template->assign('TAT_image_id', functions_session::pwg_get_session_var('TAT_image_id'));
  }
  else
  {
    $query = '
    SELECT id
      FROM '.IMAGES_TABLE.'
      ORDER BY RAND()
      LIMIT 1  
    ;';
    $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
    $template->assign('TAT_image_id', $row['id']);
  }
  //album id
  if (isset($_GET['page']) and preg_match('/^album-(\d+)(?:-(.*))?$/', $_GET['page'], $matches))
  {
    $_GET['cat_id'] = $matches[1];
  }
  functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
  if (isset($_GET['cat_id']) and functions_session::pwg_get_session_var('TAT_cat_id')==null)
  {
    $template->assign('TAT_cat_id', $_GET['cat_id']);
    functions_session::pwg_set_session_var('TAT_cat_id', $_GET['cat_id']);
  }
  elseif (is_numeric(functions_session::pwg_get_session_var('TAT_cat_id')))
  {
    $template->assign('TAT_cat_id', functions_session::pwg_get_session_var('TAT_cat_id'));
  }
  else
  {
    $query = '
    SELECT id
      FROM '.CATEGORIES_TABLE.'
      ORDER BY RAND()
      LIMIT 1  
    ;';
    $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
    $template->assign('TAT_cat_id', $row['id']);
  }
  global $conf;
  if ( isset($conf['enable_synchronization']) )
  {
    $template->assign('TAT_FTP', $conf['enable_synchronization']);
  }
?>