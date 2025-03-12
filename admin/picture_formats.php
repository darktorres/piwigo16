<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;

if(!defined("PHPWG_ROOT_PATH"))
{
  die('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('image_id', $_GET, false, PATTERN_ID);

$query='
SELECT *
  FROM '.IMAGES_TABLE.'
  WHERE id = '. $_GET['image_id'] .'
;';
$images = functions_mysqli::query2array($query);
$image = $images[0];

$query = '
SELECT
    *
  FROM '.IMAGE_FORMAT_TABLE.'
  WHERE image_id = '.$_GET['image_id'].'
;';

$formats = functions_mysqli::query2array($query);

foreach ($formats as &$format)
{
  $format['download_url'] = 'action.php?format='.$format['format_id'].'&amp;download';

  
  $format['label'] = strtoupper($format['ext']);
  $lang_key = 'format '.strtoupper($format['ext']);
  if (isset($lang[$lang_key]))
  {
    $format['label'] = $lang[$lang_key];
  }
  
  $format['filesize'] = round($format['filesize']/1024, 2);
}

$template->assign(array(
  'ADD_FORMATS_URL' => functions_url::get_root_url().'admin.php?page=photos_add&formats='.$_GET['image_id'],
  'IMG_SQUARE_SRC' => DerivativeImage::url(ImageStdParams::get_by_type(derivative_std_params::IMG_SQUARE), $image),
  'FORMATS' => $formats,
  'PWG_TOKEN' => functions::get_pwg_token(),
));

$template->set_filename('picture_formats', 'picture_formats.tpl');

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_formats');
?>
