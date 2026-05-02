<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Admin\Image\PwgImage;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// | Photo selection                                                       |
// +-----------------------------------------------------------------------+

$template->assign(
    [
      'F_ADD_ACTION' => PHOTOS_ADD_BASE_URL,
      'chunk_size' => \Piwigo\Core\Config::uploadFormChunkSize(),
      'max_file_size' => \Piwigo\Core\Config::uploadFormMaxFileSize(),
      'ADMIN_PAGE_TITLE' => l10n('Upload Photos'),
    ]
);

// what is the maximum number of pixels permitted by the memory_limit?
if (PwgImage::get_library() == 'gd') {
    $fudge_factor = 1.7;
    $available_memory = (int) get_ini_size('memory_limit') - memory_get_usage();
    $max_upload_width = round(sqrt($available_memory / (2 * $fudge_factor)));
    $max_upload_height = round(2 * $max_upload_width / 3);

    // we don't want dimensions like 2995x1992 but 3000x2000
    $max_upload_width = round($max_upload_width / 100) * 100;
    $max_upload_height = round($max_upload_height / 100) * 100;

    $max_upload_resolution = floor($max_upload_width * $max_upload_height / (1000000));

    // no need to display a limitation warning if the limitation is huge like 20MP
    if ($max_upload_resolution < 25) {
        $template->assign(
            [
            'max_upload_width' => $max_upload_width,
            'max_upload_height' => $max_upload_height,
            'max_upload_resolution' => $max_upload_resolution,
            ]
        );
    }
}

//warn the user if the picture will be resized after upload
if (\Piwigo\Core\Config::originalResize()) {
    $template->assign(
        [
          'original_resize_maxwidth' => \Piwigo\Core\Config::originalResizeMaxwidth(),
          'original_resize_maxheight' => \Piwigo\Core\Config::originalResizeMaxheight(),
        ]
    );
}


$template->assign(
    [
      'form_action' => PHOTOS_ADD_BASE_URL,
      'pwg_token' => get_pwg_token(),
    ]
);

$unique_exts = array_unique(
    array_map(
        strtolower(...),
        \Piwigo\Core\Config::uploadFormAllTypes() ? \Piwigo\Core\Config::fileExtensions() : \Piwigo\Core\Config::pictureExtensions()
    )
);

$template->assign(
    [
    'upload_file_types' => implode(', ', $unique_exts),
    'file_exts' => implode(',', $unique_exts),
    ]
);

// +-----------------------------------------------------------------------+
// | Categories                                                            |
// +-----------------------------------------------------------------------+

// we need to know the category in which the last photo was added
$selected_category = [];

if (isset($_GET['album'])) {
    // set the category from get url or ...
    check_input_parameter('album', $_GET, false, PATTERN_ID);

    // test if album really exists
    $album_id_str = is_scalar($_GET['album']) ? (string) $_GET['album'] : '0';
    $query = '
SELECT id, uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$album_id_str.'
;';
    $result = pwg_query($query);
    if (pwg_db_num_rows($result) == 1) {
        $selected_category = [$_GET['album']];

        $cat = pwg_db_fetch_assoc($result);
        if ($cat !== null) {
            $template->assign('ADD_TO_ALBUM', get_cat_display_name_cache(is_scalar($cat['uppercats']) ? (string) $cat['uppercats'] : '', null));
        }
    } else {
        $album_id = is_scalar($_GET['album']) ? (string) $_GET['album'] : '';
        fatal_error('[Hacking attempt] the album id = "'.$album_id.'" is not valid');
    }
} else {
    // we need to know the category in which the last photo was added
    $query = '
SELECT category_id, c.uppercats
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON image_id = i.id
    JOIN '.CATEGORIES_TABLE.' AS c ON category_id = c.id
  ORDER BY i.id DESC
  LIMIT 1
;
';
    $result = pwg_query($query);
    if (pwg_db_num_rows($result) > 0) {
        $row = pwg_db_fetch_assoc($result);
        if ($row !== null) {
            $selected_category = [$row['category_id']];
            $selected_category_name = get_cat_display_name_cache((string)$row['uppercats'], null);
            $template->assign('selected_category_name', $selected_category_name);
        }
    }
}

// existing album
$template->assign('selected_category', $selected_category);

// how many existing albums?
$query = '
SELECT
    COUNT(*)
  FROM '.CATEGORIES_TABLE.'
;';
[$nb_albums] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
// $nb_albums = 0;
$template->assign('NB_ALBUMS', $nb_albums);

// image level options
$selected_level = $_POST['level'] ?? 0;
$template->assign(
    [
      'level_options' => get_privacy_level_options(),
      'level_options_selected' => [$selected_level],
    ]
);

// +-----------------------------------------------------------------------+
// | Setup errors/warnings                                                 |
// +-----------------------------------------------------------------------+

// Errors
$setup_errors = [];

$error_message = ready_for_upload_message();
if (!empty($error_message)) {
    $setup_errors[] = $error_message;
}

if (!function_exists('gd_info')) {
    $setup_errors[] = l10n('GD library is missing');
}

$template->assign([
  'setup_errors' => $setup_errors,
  'CACHE_KEYS' => get_admin_client_cache_keys(['categories']),
  ]);

// Warnings
if (isset($_GET['hide_warnings'])) {
    $_SESSION['upload_hide_warnings'] = true;
}

if (!isset($_SESSION['upload_hide_warnings'])) {
    $setup_warnings = [];

    if (\Piwigo\Core\Config::useExif() and !function_exists('exif_read_data')) {
        $setup_warnings[] = l10n('Exif extension not available, admin should disable exif use');
    }

    if (get_ini_size('upload_max_filesize') > get_ini_size('post_max_size')) {
        $setup_warnings[] = l10n(
            'In your php.ini file, the upload_max_filesize (%sB) is bigger than post_max_size (%sB), you should change this setting',
            get_ini_size('upload_max_filesize', false),
            get_ini_size('post_max_size', false)
        );
    }

    if (get_ini_size('upload_max_filesize') < \Piwigo\Core\Config::uploadFormChunkSize() * 1024) {
        $setup_warnings[] = sprintf(
            'Piwigo setting upload_form_chunk_size (%ukB) should be smaller than PHP configuration setting upload_max_filesize (%ukB)',
            \Piwigo\Core\Config::uploadFormChunkSize(),
            ceil((int) get_ini_size('upload_max_filesize') / 1024)
        );
    }

    $template->assign(
        [
        'setup_warnings' => $setup_warnings,
        'hide_warnings_link' => PHOTOS_ADD_BASE_URL.'&amp;hide_warnings=1',
        ]
    );
}
