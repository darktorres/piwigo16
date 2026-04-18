<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\SrcImage;

if (! defined('PHOTOS_ADD_BASE_URL')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// |                        batch management request                       |
// +-----------------------------------------------------------------------+

if (isset($_GET['batch'])) {
    functions::check_input_parameter('batch', $_GET, false, '/^\d+(,\d+)*$/');

    $query = <<<SQL
        DELETE FROM caddie
        WHERE user_id = {$user['id']};
        SQL;
    $conf->sql_backend::pwg_query($query);

    $inserts = [];

    foreach (explode(',', $_GET['batch']) as $image_id) {
        $inserts[] = [
            'user_id' => $user['id'],
            'element_id' => $image_id,
        ];
    }

    $conf->sql_backend::mass_inserts(
        'caddie',
        array_keys($inserts[0]),
        $inserts
    );

    functions::redirect(functions_url::get_root_url() . 'admin.php?page=batch_manager&filter=prefilter-caddie');
}

if (functions_user::userprefs_get_param('promote-mobile-apps', true)) {
    $query = <<<SQL
        SELECT registration_date
        FROM user_infos
        WHERE registration_date IS NOT NULL
        ORDER BY user_id ASC
        LIMIT 1;
        SQL;
    [$register_date] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

    $query = <<<SQL
        SELECT COUNT(*) AS "COUNT(*)"
        FROM categories;
        SQL;
    [$nb_cats] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

    $query = <<<SQL
        SELECT COUNT(*) AS "COUNT(*)"
        FROM images;
        SQL;
    [$nb_images] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

    $uagent_obj = new uagent_info();
    // To see the mobile app promote, the account must have 2 weeks ancient, 3 albums created and 30 photos uploaded
    $template->assign('PROMOTE_MOBILE_APPS', ! $uagent_obj->DetectIos() &&
                                                             strtotime($register_date) < strtotime('2 weeks ago') &&
                                                             $nb_cats >= 3 &&
                                                             $nb_images >= 30);
} else {
    $template->assign('PROMOTE_MOBILE_APPS', false);
}

$template->assign('PHPWG_URL', PHPWG_URL);

// +-----------------------------------------------------------------------+
// |                             Formats Mode                              |
// +-----------------------------------------------------------------------+

$display_formats = $conf->enable_formats && isset($_GET['formats']);

$have_formats_original = false;
$formats_original_info = [];

// If URL parameter isn't empty
if ($display_formats &&
    $_GET['formats']
) {
    functions::check_input_parameter('formats', $_GET, false, PATTERN_ID);

    $formats_original_info = functions_admin::get_image_infos($_GET['formats']);

    if ($formats_original_info) {
        $src_image = new SrcImage($formats_original_info);

        $formats_original_info['src'] = DerivativeImage::url(derivative_std_params::IMG_SQUARE, $src_image);

        // Fetch actual formats
        $query = <<<SQL
            SELECT *
            FROM image_format
            WHERE image_id = {$formats_original_info['id']};
            SQL;
        $formats = $conf->sql_backend::query2array($query);

        if ($formats !== []) {
            $format_strings = [];

            foreach ($formats as $format) {
                $format_strings[] = sprintf('%s (%.2fMB)', $format['ext'], $format['filesize'] / 1024);
            }

            $formats_original_info['formats'] = functions::l10n('Formats: %s', implode(', ', $format_strings));
        }

        $extTab = explode('.', $formats_original_info['file']);

        $formats_original_info['ext'] = functions::l10n('%s file type', strtoupper(end($extTab)));

        $formats_original_info['u_edit'] = functions_url::get_root_url() . 'admin.php?page=photo-' . $formats_original_info['id'];

        $have_formats_original = true;
    } else {
        $page['errors'][] = functions::l10n("The original picture selected doesn't exists.");
    }

}

// +-----------------------------------------------------------------------+
// |                             prepare form                              |
// +-----------------------------------------------------------------------+

require_once __DIR__ . '/../admin/inc/photos_add_direct_prepare.php';

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

functions_plugins::trigger_notify('loc_end_photo_add_direct');

$cache_keys = functions_admin::get_admin_client_cache_keys(['categories']);
$page_data = [
    'formatMode' => (bool) $display_formats,
    'haveFormatsOriginal' => (bool) $have_formats_original,
    'originalImageId' => $have_formats_original && isset($formats_original_info['id']) ? (string) $formats_original_info['id'] : '-1',
    'categoriesServerKey' => $cache_keys['categories'],
    'categoriesServerId' => $cache_keys['_hash'],
    'rootUrl' => functions_url::get_root_url(),
    'pwgToken' => functions::get_pwg_token(),
    'photosUploadedLabel' => functions::l10n('%d photos uploaded'),
    'formatsUploadedLabel' => functions::l10n('%d formats uploaded for %d photos'),
    'batchLabel' => functions::l10n('Manage this set of %d photos'),
    'albumSummaryLabel' => functions::l10n('Album "%s" now contains %d photos'),
    'strFormatWarning' => functions::l10n('Error when trying to detect formats'),
    'strOk' => functions::l10n('Ok'),
    'strFormatWarningMultiple' => functions::l10n('There is multiple image in the database with the following names : %s.'),
    'strFormatWarningNotFound' => functions::l10n('No picture found with the following name : %s.'),
    'strAndXOthers' => functions::l10n('and %d more'),
    'fileExt' => implode(',', array_unique(array_map(strtolower(...), $conf->upload_form_all_types ? $conf->file_ext : $conf->picture_ext))),
    'formatExt' => implode(',', $conf->format_ext),
    'chunkSize' => $conf->upload_form_chunk_size,
    'maxFileSize' => $conf->upload_form_max_file_size,
    'dropzoneMsg' => functions::l10n('Drop files here or click Add Photos'),
    'strUploadInProgress' => functions::l10n('Upload in progress'),
];
$template->assign('page_data_json', json_encode($page_data));

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['photos_add_direct']);

$template->assign([
    'ENABLE_FORMATS' => $conf->enable_formats,
    'DISPLAY_FORMATS' => $display_formats,
    'HAVE_FORMATS_ORIGINAL' => $have_formats_original,
    'FORMATS_ORIGINAL_INFO' => $formats_original_info,
    'SWITCH_MODE_URL' => functions_url::get_root_url() . 'admin.php?page=photos_add' . ($display_formats ? '' : '&formats'),
    'format_ext' => implode(',', $conf->format_ext),
    'str_format_ext' => implode(', ', $conf->format_ext),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
