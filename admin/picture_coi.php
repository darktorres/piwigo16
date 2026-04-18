<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\derivative_params;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SrcImage;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('image_id', $_GET, false, PATTERN_ID);

if (isset($_POST['submit'])) {
    $query = <<<SQL
        UPDATE images

        SQL;

    if (strlen($_POST['l']) == 0) {
        $query .= " SET coi = NULL\n";
    } else {
        $coi = derivative_params::fraction_to_char($_POST['l'])
          . derivative_params::fraction_to_char($_POST['t'])
          . derivative_params::fraction_to_char($_POST['r'])
          . derivative_params::fraction_to_char($_POST['b']);
        $query .= " SET coi = '{$coi}'\n";
    }

    $query .= " WHERE id = {$_GET['image_id']};";
    $conf->sql_backend::pwg_query($query);
}

$query = <<<SQL
    SELECT * FROM images WHERE id = {$_GET['image_id']};
    SQL;
$row = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));

if (isset($_POST['submit'])) {
    foreach (ImageStdParams::get_defined_type_map() as $params) {
        if ($params->sizing->max_crop != 0) {
            functions_admin::delete_element_derivatives($row, $params->type);
        }
    }

    functions_admin::delete_element_derivatives($row, derivative_std_params::IMG_CUSTOM);
    $uid = '&b=' . time();
    $conf->question_mark_in_urls = true;
    $conf->php_extension_in_urls = true;

    if ($conf->derivative_url_style == 1) {
        $conf->derivative_url_style = 0; //auto
    }
} else {
    $uid = '';
}

$tpl_var = [
    'TITLE' => functions_html::render_element_name($row),
    'ALT' => $row['file'],
    'U_IMG' => DerivativeImage::url(derivative_std_params::IMG_LARGE, $row),
];

if (! empty($row['coi'])) {
    $tpl_var['coi'] = [
        'l' => derivative_params::char_to_fraction($row['coi'][0]),
        't' => derivative_params::char_to_fraction($row['coi'][1]),
        'r' => derivative_params::char_to_fraction($row['coi'][2]),
        'b' => derivative_params::char_to_fraction($row['coi'][3]),
    ];
}

foreach (ImageStdParams::get_defined_type_map() as $params) {
    if ($params->sizing->max_crop != 0) {
        $derivative = new DerivativeImage($params, new SrcImage($row));
        $template->append('cropped_derivatives', [
            'U_IMG' => $derivative->get_url() . $uid,
            'HTM_SIZE' => $derivative->get_size_htm(),
        ]);
    }
}

$template->assign($tpl_var);
$template->set_filename('picture_coi', 'picture_coi.tpl');

require_once 'inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['picture_coi']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_coi');
