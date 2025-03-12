<?php
/**********************************
 * REQUIRED PATH TO THE TPL FILE */

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;

$TOUR_PATH = PHPWG_PLUGINS_PATH.'TakeATour/tours/2_7_0/tour.tpl';

/*********************************/


/**********************
 *    Preparse part   *
 **********************/
  $template->assign('TAT_index', functions_url::make_index_url(array('section' => 'categories')));
  $template->assign('TAT_search', functions_url::get_root_url().'search.php');

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
?>