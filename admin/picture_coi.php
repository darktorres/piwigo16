<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('image_id', $_GET, false, PATTERN_ID);

$imageIdCoi = is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '0';
if (isset($_POST['submit'])) {
    $lRaw = $_POST['l'] ?? null;
    $query = 'UPDATE '.IMAGES_TABLE;
    if (strlen(is_scalar($lRaw) ? (string) $lRaw : '') == 0) {
        $query .= ' SET coi=NULL';
    } else {
        $coi = fraction_to_char(is_numeric($lRaw) ? (float) $lRaw : 0)
          .fraction_to_char(is_numeric($_POST['t'] ?? null) ? (float) $_POST['t'] : 0)
          .fraction_to_char(is_numeric($_POST['r'] ?? null) ? (float) $_POST['r'] : 0)
          .fraction_to_char(is_numeric($_POST['b'] ?? null) ? (float) $_POST['b'] : 0);
        $query .= ' SET coi=\''.$coi.'\'';
    }
    $query .= ' WHERE id='.$imageIdCoi;
    pwg_query($query);
}

$query = 'SELECT * FROM '.IMAGES_TABLE.' WHERE id='.$imageIdCoi;
$image_infos = pwg_db_fetch_assoc(pwg_query($query));

if ($image_infos === null) {
    page_not_found('The requested image does not exist');
    return;
}

$row = $image_infos;

if (isset($_POST['submit'])) {
    foreach (ImageStdParams::get_defined_type_map() as $params) {
        if ($params->sizing->max_crop != 0) {
            delete_element_derivatives($row, $params->type);
        }
    }
    delete_element_derivatives($row, IMG_CUSTOM);
    $uid = '&b='.time();
    \Piwigo\Config\Config::override('question_mark_in_urls', true);
    \Piwigo\Config\Config::override('php_extension_in_urls', true);
    if (\Piwigo\Config\Config::derivativeUrlStyle() == 1) {
        \Piwigo\Config\Config::override('derivative_url_style', 0); //auto
    }
} else {
    $uid = '';
}

$tpl_var = [
  'TITLE' => render_element_name($row),
  'ALT' => $row['file'],
  'U_IMG' => DerivativeImage::url(IMG_LARGE, $row),
  ];

if (!empty($row['coi'])) {
    $coi = (string)$row['coi'];
    $tpl_var['coi'] = [
      'l' => char_to_fraction($coi[0]),
      't' => char_to_fraction($coi[1]),
      'r' => char_to_fraction($coi[2]),
      'b' => char_to_fraction($coi[3]),
    ];
}

foreach (ImageStdParams::get_defined_type_map() as $params) {
    if ($params->sizing->max_crop != 0) {
        $derivative = new DerivativeImage($params, new SrcImage($row));
        $template->append('cropped_derivatives', [
          'U_IMG' => (is_string($u = $derivative->get_url()) ? $u : '').$uid,
          'HTM_SIZE' => $derivative->get_size_htm(),
        ]);
    }
}


$template->assign($tpl_var);
$template->set_filename('picture_coi', 'picture_coi.tpl');

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_coi');
