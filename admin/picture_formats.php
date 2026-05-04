<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('image_id', $_GET, false, PATTERN_ID);
$picFmtId = is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '0';

$query = '
SELECT *
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$picFmtId.'
;';
$images = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
$image = $images[0];

$query = '
SELECT
    *
  FROM '.IMAGE_FORMAT_TABLE.'
  WHERE image_id = '.$picFmtId.'
;';

$formats = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

foreach ($formats as &$format) {
    $format['download_url'] = 'action.php?format='.(is_scalar($format['format_id']) ? (string) $format['format_id'] : '').'&amp;download';


    $format['label'] = strtoupper(is_scalar($format['ext']) ? (string) $format['ext'] : '');
    $lang_key = 'format '.strtoupper(is_scalar($format['ext']) ? (string) $format['ext'] : '');
    if (isset($lang[$lang_key])) {
        $format['label'] = $lang[$lang_key];
    }

    $format['filesize'] = round($format['filesize'] / 1024, 2);
}

$template->assign([
    'ADD_FORMATS_URL' => get_root_url().'admin.php?page=photos_add&formats='.$picFmtId,
    'IMG_SQUARE_SRC'  => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image),
    'FORMATS'         => $formats,
    'PWG_TOKEN'       => get_pwg_token(),
    'page_data_json'  => json_encode([
        'pwg_token'                => get_pwg_token(),
        'nb_formats'               => count($formats),
        'str_confirm_delete_format' => l10n('Delete %s format ?'),
        'str_confirm_msg'          => l10n('Yes, I am sure'),
        'str_cancel_msg'           => l10n('No, I have changed my mind'),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
]);

$template->set_filename('picture_formats', 'picture_formats.tpl');

$template->assign_var_from_handle('ADMIN_CONTENT', 'picture_formats');
