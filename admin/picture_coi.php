<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var Template $template
 */
global $conf, $template;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(AccessLevel::Administrator);

check_input_parameter('image_id', $_GET, false, ValidationPattern::ID);

// check_input_parameter() only validates the raw $_GET value against
// ValidationPattern::ID (or dies); it does not narrow $_GET's type for PHPStan, so
// re-derive a real int here for every later use.
$image_id = 0;
if (isset($_GET['image_id']) && is_numeric($_GET['image_id'])) {
    $image_id = (int) $_GET['image_id'];
}

if (isset($_POST['submit'])) {
    $coi_l = $_POST['l'] ?? null;
    $coi_l_str = is_scalar($coi_l) ? (string) $coi_l : '';

    if (strlen($coi_l_str) == 0) {
        $coi = null;
    } else {
        $to_fraction = (static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0);

        $coi = fraction_to_char($to_fraction($coi_l))
          . fraction_to_char($to_fraction($_POST['t'] ?? null))
          . fraction_to_char($to_fraction($_POST['r'] ?? null))
          . fraction_to_char($to_fraction($_POST['b'] ?? null));
    }
    new ImageRepository(DbConnection::build())->updateCoi($image_id, $coi);
}

$query = 'SELECT * FROM ' . Tables::images() . ' WHERE id=' . $image_id;
$row = pwg_db_fetch_assoc(pwg_query($query));
if (! is_array($row)) {
    page_not_found('Requested photo does not exist');
}

if (isset($_POST['submit'])) {
    $derivative_infos = [
        'path' => (string) $row['path'],
    ];
    if (! empty($row['representative_ext'])) {
        $derivative_infos['representative_ext'] = $row['representative_ext'];
    }

    foreach (ImageStdParams::get_defined_type_map() as $params) {
        if ($params->sizing->max_crop != 0) {
            delete_element_derivatives($derivative_infos, $params->type);
        }
    }
    delete_element_derivatives($derivative_infos, IMG_CUSTOM);
    $uid = '&b=' . time();
    $conf['question_mark_in_urls'] = $conf['php_extension_in_urls'] = true;
    if ($conf['derivative_url_style'] == 1) {
        $conf['derivative_url_style'] = 0; // auto
    }
} else {
    $uid = '';
}

$tpl_var = [
    'TITLE' => render_element_name($row),
    'ALT' => $row['file'],
    'U_IMG' => DerivativeImage::url(IMG_LARGE, $row),
];

if (! empty($row['coi'])) {
    $tpl_var['coi'] = [
        'l' => char_to_fraction($row['coi'][0]),
        't' => char_to_fraction($row['coi'][1]),
        'r' => char_to_fraction($row['coi'][2]),
        'b' => char_to_fraction($row['coi'][3]),
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

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_coi');
