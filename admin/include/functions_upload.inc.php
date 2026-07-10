<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/image.class.php';

// add default event handler for image and thumbnail resize
add_event_handler('upload_image_resize', 'pwg_image_resize');
add_event_handler('upload_thumbnail_resize', 'pwg_image_resize');

/**
 * @return array<string, array{default: bool|int, min: int|null, max: int|null, pattern: string|null, can_be_null: bool, error_message: string|null}>
 */
function get_upload_form_config(): array
{
    // default configuration for upload
    $upload_form_config = [
        'original_resize' => [
            'default' => false,
            'min' => null,
            'max' => null,
            'pattern' => null,
            'can_be_null' => false,
            'error_message' => null,
        ],

        'original_resize_maxwidth' => [
            'default' => 2000,
            'min' => 500,
            'max' => 20000,
            'pattern' => '/^\d+$/',
            'can_be_null' => false,
            'error_message' => l10n('The original maximum width must be a number between %d and %d'),
        ],

        'original_resize_maxheight' => [
            'default' => 2000,
            'min' => 300,
            'max' => 20000,
            'pattern' => '/^\d+$/',
            'can_be_null' => false,
            'error_message' => l10n('The original maximum height must be a number between %d and %d'),
        ],

        'original_resize_quality' => [
            'default' => 95,
            'min' => 50,
            'max' => 98,
            'pattern' => '/^\d+$/',
            'can_be_null' => false,
            'error_message' => l10n('The original image quality must be a number between %d and %d'),
        ],
    ];

    return $upload_form_config;
}

/**
 * @param array<string, mixed> $data
 * @param array<int, string> $errors
 * @param array<string, string> $form_errors
 */
function save_upload_form_config(array $data, array &$errors = [], array &$form_errors = []): bool
{
    if (empty($data)) {
        return false;
    }

    $upload_form_config = get_upload_form_config();
    $updates = [];

    foreach ($data as $field => $value) {
        if (! isset($upload_form_config[$field])) {
            continue;
        }
        if (is_bool($upload_form_config[$field]['default'])) {
            if (isset($value)) {
                $value = true;
            } else {
                $value = false;
            }

            $updates[] = [
                'param' => $field,
                'value' => boolean_to_string($value),
            ];
        } elseif ($upload_form_config[$field]['can_be_null'] and empty($value)) {
            $updates[] = [
                'param' => $field,
                'value' => 'false',
            ];
        } else {
            $min = $upload_form_config[$field]['min'];
            $max = $upload_form_config[$field]['max'];
            $pattern = $upload_form_config[$field]['pattern'];
            $error_message = $upload_form_config[$field]['error_message'];

            if (! is_int($min) || ! is_int($max) || ! is_string($pattern) || ! is_string($error_message) || ! is_scalar($value)) {
                // every upload_form_config entry that reaches this branch
                // (i.e. isn't the boolean toggle handled above) defines
                // min/max/pattern/error_message as int/int/string/string;
                // this guard only exists to give PHPStan a real narrowing
                // and should never actually skip a field in practice.
                continue;
            }

            if ((bool) preg_match($pattern, (string) $value) and $value >= $min and $value <= $max) {
                $updates[] = [
                    'param' => $field,
                    'value' => $value,
                ];
            } else {
                $errors[] = sprintf(
                    $error_message,
                    $min,
                    $max
                );

                $form_errors[$field] = '[' . $min . ' .. ' . $max . ']';
            }
        }
    }

    if (count($errors) == 0) {
        mass_updates(
            CONFIG_TABLE,
            [
                'primary' => ['param'],
                'update' => ['value'],
            ],
            $updates
        );
        return true;
    }

    return false;
}

/**
 * @param int[]|null $categories
 */
function add_uploaded_file(string $source_filepath, ?string $original_filename = null, ?array $categories = null, ?int $level = null, ?int $image_id = null, ?string $original_md5sum = null): int|string
{
    // 1) move uploaded file to upload/2010/01/22/20100122003814-449ada00.jpg
    //
    // 2) keep/resize original
    //
    // 3) register in database

    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     * @var \Logger $logger
     */
    global $conf, $user, $logger;

    if ($original_filename !== null) {
        $original_filename = htmlspecialchars($original_filename);
    }

    if (isset($original_md5sum)) {
        $md5sum = $original_md5sum;
    } else {
        $md5sum = md5_file($source_filepath);
    }

    // we only try to detect duplicate on a new image, not when updating an existing image
    if (! isset($image_id) and (bool) $conf['upload_detect_duplicate']) {
        $query = '
SELECT
    id
  FROM ' . IMAGES_TABLE . '
  WHERE md5sum = \'' . $md5sum . '\'
;';
        $images_found = query2array($query);

        if (count($images_found) > 0) {
            $found_id = $images_found[0]['id'];
            if (! is_string($found_id) || ! is_numeric($found_id)) {
                // id is the table's NOT NULL auto-increment primary key,
                // so it is always a numeric string here; this guard only
                // exists to give PHPStan a real narrowing.
                throw new Exception(__FUNCTION__ . '(): unexpected non-numeric image id while checking for duplicates');
            }
            $image_id = (int) $found_id;
            $logger->info('[' . __FUNCTION__ . '] image already exist #' . $image_id . ', we delete the newly uploaded file : ' . $source_filepath);
            unlink($source_filepath);

            // if the destination category is already linked to this photo, no worry,
            // associate_images_to_categories perfectly handles this case
            add_uploaded_file_add_to_categories($image_id, $categories);

            // TODO should we update level? If yes, then we should invalidate_user_cache

            return $image_id;
        }
    }

    $file_path = null;

    if (isset($image_id)) {
        // this photo already exists, we update it
        $query = '
SELECT
    path
  FROM ' . IMAGES_TABLE . '
  WHERE id = ' . $image_id . '
;';
        $result = pwg_query($query);
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $file_path = $row['path'];
        }

        if (! isset($file_path)) {
            die('[' . __FUNCTION__ . '] this photo does not exist in the database');
        }

        // delete all physical files related to the photo (thumbnail, web site, HD)
        delete_element_files([$image_id]);
    } else {
        // this photo is new

        // current date
        $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
        assert($row !== null);
        [$dbnow] = $row;
        $date_parts = preg_split('/[^\d]/', (string) $dbnow, 4);
        if ($date_parts === false) {
            throw new Exception(__FUNCTION__ . '(): preg_split() failed');
        }
        [$year, $month, $day] = $date_parts;

        // upload directory hierarchy
        $conf_upload_dir = $conf['upload_dir'];
        $conf_upload_dir = is_string($conf_upload_dir) ? $conf_upload_dir : '';
        $upload_dir = sprintf(
            PHPWG_ROOT_PATH . $conf_upload_dir . '/%s/%s/%s',
            $year,
            $month,
            $day
        );

        // compute file path
        $date_string = preg_replace('/[^\d]/', '', (string) $dbnow);
        $random_string = substr((string) $md5sum, 0, 4) . '%s';
        $filename_wo_ext = $date_string . '-' . $random_string;
        $file_path = $upload_dir . '/' . $filename_wo_ext . '.';

        $image_size = getimagesize($source_filepath);
        if ($image_size === false) {
            // not a real image (e.g. upload_form_all_types lets through a
            // non-image file); fall through to the same "unrecognized
            // type" handling as any other $type that isn't a known
            // IMAGETYPE_* constant
            $type = false;
        } else {
            [$width, $height, $type] = $image_size;
        }

        if ($type == IMAGETYPE_PNG) {
            $file_path .= 'png';
        } elseif ($type == IMAGETYPE_GIF) {
            $file_path .= 'gif';
        } elseif ($type == IMAGETYPE_JPEG) {
            $file_path .= 'jpg';
        } elseif ($type == IMAGETYPE_WEBP) {
            $file_path .= 'webp';
        } elseif (isset($conf['upload_form_all_types']) and (bool) $conf['upload_form_all_types']) {
            $original_extension = strtolower(get_extension($original_filename));

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                throw new Exception(__FUNCTION__ . '(): finfo_open() failed');
            }
            $finfo_type = finfo_file($finfo, $source_filepath);

            if (in_array($finfo_type, ['image/svg', 'image/svg+xml']) and $original_extension != 'svg') {
                unlink($source_filepath);
                $error_msg = 'File extension "' . $original_extension . '" for file "' . $original_filename . '" does not match file MIME type "' . $finfo_type . '"';
                if (defined('IN_WS')) {
                    /** @var \PwgServer $service */
                    global $service;
                    $service->sendResponse(new PwgError(415, $error_msg));
                    exit;
                }

                die($error_msg);
            }

            $conf_file_ext = $conf['file_ext'];
            $conf_file_ext = is_array($conf_file_ext) ? $conf_file_ext : [];
            if (in_array($original_extension, $conf_file_ext)) {
                $file_path .= $original_extension;
            } else {
                unlink($source_filepath);
                die('unexpected file type');
            }
        } else {
            unlink($source_filepath);
            die('forbidden file type');
        }

        prepare_directory($upload_dir);

        $file_path_pattern = $file_path;
        do {
            // we generate a random string for each upload. If the user uploads
            // the same photo twice at the same time (same timestamp, same md5sum)
            // we still want the path to be unique.
            $file_path = sprintf($file_path_pattern, substr(bin2hex(random_bytes(4)), 0, 4));
        } while (file_exists($file_path));
    }

    if (is_uploaded_file($source_filepath)) {
        move_uploaded_file($source_filepath, $file_path);
    } else {
        rename($source_filepath, $file_path);
    }
    @chmod($file_path, 0644);

    // handle the uploaded file type by potentially making a
    // pwg_representative file.
    $representative_ext = trigger_change('upload_file', null, $file_path);

    // If it is set to either true (the file didn't need a
    // representative generated), false (the generation of the
    // representative failed), or any other non-string value an event
    // handler might return, set it to null because we have no
    // representative file. (All upload_file handlers registered in this
    // file return ?string, but trigger_change() itself is inherently
    // mixed since any plugin can register a handler for this event.)
    if (! is_string($representative_ext)) {
        $representative_ext = null;
    }

    $logger->info('Handling ' . $file_path . ' got ' . ($representative_ext ?? ''));

    if (pwg_image::get_library() != 'gd') {
        if ((bool) $conf['original_resize']) {
            $original_resize_maxwidth = $conf['original_resize_maxwidth'];
            $original_resize_maxwidth = is_numeric($original_resize_maxwidth) ? (int) $original_resize_maxwidth : 2000;

            $original_resize_maxheight = $conf['original_resize_maxheight'];
            $original_resize_maxheight = is_numeric($original_resize_maxheight) ? (int) $original_resize_maxheight : 2000;

            $need_resize = need_resize($file_path, $original_resize_maxwidth, $original_resize_maxheight);

            if ($need_resize) {
                $img = new pwg_image($file_path);

                $original_resize_quality = $conf['original_resize_quality'];
                $original_resize_quality = is_numeric($original_resize_quality) ? (int) $original_resize_quality : 95;

                $img->pwg_resize(
                    $file_path,
                    $original_resize_maxwidth,
                    $original_resize_maxheight,
                    $original_resize_quality,
                    (bool) $conf['upload_form_automatic_rotation'],
                    false
                );

                $img->destroy();
            }
        }
    }

    // we need to save the rotation angle in the database to compute
    // width/height of "multisizes"
    $rotation_angle = pwg_image::get_rotation_angle($file_path);
    $rotation = pwg_image::get_rotation_code_from_angle($rotation_angle);

    $file_infos = pwg_image_infos($file_path);

    if (isset($image_id)) {
        $update = [
            'file' => pwg_db_real_escape_string($original_filename ?? basename($file_path)),
            'filesize' => $file_infos['filesize'],
            'width' => $file_infos['width'],
            'height' => $file_infos['height'],
            'md5sum' => $md5sum,
            'added_by' => $user['id'],
            'rotation' => $rotation,
        ];

        if (isset($level)) {
            $update['level'] = $level;
        }

        single_update(
            IMAGES_TABLE,
            $update,
            [
                'id' => $image_id,
            ]
        );
    } else {
        // database registration
        // pwg_db_real_escape_string() only returns null for a null input,
        // and basename() never returns null, so the ?? fallback rules
        // that out.
        $file = pwg_db_real_escape_string($original_filename ?? basename($file_path));
        assert($file !== null);
        $insert = [
            'file' => $file,
            'name' => get_name_from_file($file),
            'date_available' => $dbnow,
            'path' => preg_replace('#^' . preg_quote(PHPWG_ROOT_PATH) . '#', '', $file_path),
            'filesize' => $file_infos['filesize'],
            'width' => $file_infos['width'],
            'height' => $file_infos['height'],
            'md5sum' => $md5sum,
            'added_by' => $user['id'],
            'rotation' => $rotation,
        ];

        if (isset($level)) {
            $insert['level'] = $level;
        }

        if (isset($representative_ext)) {
            $insert['representative_ext'] = $representative_ext;
        }

        single_insert(IMAGES_TABLE, $insert);

        $image_id = pwg_db_insert_id();
        pwg_activity('photo', $image_id, 'add');
    }

    add_uploaded_file_add_to_categories($image_id, $categories);

    // update metadata from the uploaded file (exif/iptc)
    if ((bool) $conf['use_exif'] and ! function_exists('exif_read_data')) {
        $conf['use_exif'] = false;
    }
    sync_metadata([(int) $image_id]);

    // cache a derivative
    $query = '
SELECT
    id,
    path,
    representative_ext
  FROM ' . IMAGES_TABLE . '
  WHERE id = ' . $image_id . '
;';
    $image_infos = pwg_db_fetch_assoc(pwg_query($query));
    if (! is_array($image_infos)) {
        throw new Exception(__FUNCTION__ . '(): image #' . $image_id . ' not found right after being saved');
    }
    $src_image = new SrcImage($image_infos);

    set_make_full_url();
    // in case we are on uploadify.php, we have to replace the false path
    $derivative_url = preg_replace('#admin/include/i#', 'i', DerivativeImage::url(IMG_MEDIUM, $src_image));
    assert($derivative_url !== null);
    unset_make_full_url();

    $logger->info(__FUNCTION__ . ' : force cache generation, derivative_url = ' . $derivative_url);

    fetchRemote($derivative_url, $dest);

    trigger_notify('loc_end_add_uploaded_file', $image_infos);

    return $image_id;
}

/**
 * @param int[]|null $categories
 */
function add_uploaded_file_add_to_categories(int|string $image_id, ?array $categories): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! isset($conf['lounge_active'])) {
        conf_update_param('lounge_active', false, true);
    }

    if (! (bool) $conf['lounge_active']) {
        // check if we need to use the lounge from now
        $row = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM ' . IMAGES_TABLE . ';'));
        assert($row !== null);
        [$nb_photos] = $row;
        if ($nb_photos >= $conf['lounge_activate_threshold']) {
            conf_update_param('lounge_active', true, true);
        }
    }

    if (isset($categories) and count($categories) > 0) {
        if ((bool) $conf['lounge_active']) {
            // fill_lounge() requires int keys for $categories; a WS param
            // forced into an array by makeArrayParam() could theoretically
            // carry non-sequential/string keys, so reindex to guarantee it.
            fill_lounge([$image_id], array_values($categories));
        } else {
            associate_images_to_categories([(int) $image_id], $categories);
        }
    }

    if (! (bool) $conf['lounge_active']) {
        invalidate_user_cache();
    }
}

function add_format(string $source_filepath, string $format_ext, int|string $format_of): string
{
    // 1) find infos about the extended image
    //
    // 2) move uploaded file to upload/2022/05/16/pwg_format/20100122003814-449ada00.cr2
    //
    // 3) register in database

    if (! (bool) conf_get_param('enable_formats', false)) {
        die('[' . __FUNCTION__ . '] formats are disabled');
    }

    $authorized_format_exts = conf_get_param('format_ext', ['cr2']);
    // conf_get_param() is inherently mixed (config values come straight
    // from the $conf global); only elements that are actually strings can
    // be safely passed to in_array()/implode() below.
    $authorized_format_exts = is_array($authorized_format_exts) ? array_filter($authorized_format_exts, is_string(...)) : ['cr2'];

    if (! in_array($format_ext, $authorized_format_exts)) {
        die('[' . __FUNCTION__ . '] unexpected format extension "' . $format_ext . '" (authorized extensions: ' . implode(', ', $authorized_format_exts) . ')');
    }

    $query = '
SELECT
    path
  FROM ' . IMAGES_TABLE . '
  WHERE id = ' . $format_of . '
;';
    $images = query2array($query);

    if (! isset($images[0])) {
        die('[' . __FUNCTION__ . '] this photo does not exist in the database');
    }

    $format_path = dirname((string) $images[0]['path']) . '/pwg_format/';
    $format_path .= get_filename_wo_extension(basename((string) $images[0]['path']));
    $format_path .= '.' . $format_ext;

    prepare_directory(dirname($format_path));

    if (is_uploaded_file($source_filepath)) {
        move_uploaded_file($source_filepath, $format_path);
    } else {
        rename($source_filepath, $format_path);
    }
    @chmod($format_path, 0644);

    $file_infos = pwg_image_infos($format_path);

    $insert = [
        'image_id' => $format_of,
        'ext' => $format_ext,
        'filesize' => $file_infos['filesize'],
    ];

    $query = '
SELECT
  format_id
  FROM ' . IMAGE_FORMAT_TABLE . '
  WHERE image_id = ' . $format_of . '
  AND ext = "' . $format_ext . '"
;';

    $formats = query2array($query);
    if ((bool) $formats) {
        $set_fields = [
            'filesize' => $file_infos['filesize'],
        ];
        $where_fields = [
            'format_id' => $formats[0]['format_id'],
            'image_id' => $format_of,
            'ext' => $format_ext,
        ];
        single_update(IMAGE_FORMAT_TABLE, $set_fields, $where_fields);
        $format_id = $formats[0]['format_id'];
        $add_status = 'update';
    } else {
        single_insert(IMAGE_FORMAT_TABLE, $insert);
        $format_id = pwg_db_insert_id();
        $add_status = 'add';
    }

    pwg_activity('photo', $format_of, 'edit', [
        'action' => 'add format',
        'format_ext' => $format_ext,
        'format_id' => $format_id,
    ]);

    $format_infos = $insert;
    $format_infos['format_id'] = $format_id;

    trigger_notify('loc_end_add_format', $format_infos);

    return $add_status;
}

add_event_handler('upload_file', 'upload_file_pdf');
function upload_file_pdf(?string $representative_ext, string $file_path): ?string
{
    /**
     * @var array<string, mixed> $conf
     * @var \Logger $logger
     */
    global $logger, $conf;

    $logger->info(__FUNCTION__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (! in_array(strtolower(get_extension($file_path)), ['pdf'])) {
        return $representative_ext;
    }

    $ext = conf_get_param('pdf_representative_ext', 'jpg');
    if (! is_string($ext)) {
        $ext = 'jpg';
    }
    $jpg_quality = conf_get_param('pdf_jpg_quality', 90);
    if (! is_numeric($jpg_quality)) {
        $jpg_quality = 90;
    }

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = original_to_representative($file_path, $ext);
    prepare_directory(dirname($representative_file_path));

    $ext_imagick_dir = $conf['ext_imagick_dir'];
    $ext_imagick_dir = is_string($ext_imagick_dir) ? $ext_imagick_dir : '';
    $exec = $ext_imagick_dir . pwg_image::get_ext_imagick_command();
    $exec .= ' "' . realpath($file_path) . '"[0]';
    if ($ext == 'jpg') {
        $exec .= ' -quality ' . $jpg_quality;
    }
    $exec .= ' "' . $representative_file_path . '"';
    $exec .= ' 2>&1';
    @exec($exec, $returnarray);

    // Return the extension (if successful) or false (if failed)
    if (file_exists($representative_file_path)) {
        $representative_ext = $ext;
    }

    return $representative_ext;
}

add_event_handler('upload_file', 'upload_file_heic');
function upload_file_heic(?string $representative_ext, string $file_path): ?string
{
    /**
     * @var array<string, mixed> $conf
     * @var \Logger $logger
     */
    global $logger, $conf;

    $logger->info(__FUNCTION__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (! in_array(strtolower(get_extension($file_path)), ['heic'])) {
        return $representative_ext;
    }

    $ext = 'jpg';

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = original_to_representative($file_path, $ext);
    prepare_directory(dirname($representative_file_path));

    [$w, $h] = get_optimal_dimensions_for_representative();

    $ext_imagick_dir = $conf['ext_imagick_dir'];
    $ext_imagick_dir = is_string($ext_imagick_dir) ? $ext_imagick_dir : '';
    $exec = $ext_imagick_dir . pwg_image::get_ext_imagick_command();
    $exec .= ' "' . realpath($file_path) . '"';
    $exec .= ' -sampling-factor 4:2:0 -quality 85 -interlace JPEG -colorspace sRGB -auto-orient +repage -resize "' . $w . 'x' . $h . '>"';
    $exec .= ' "' . $representative_file_path . '"';
    $exec .= ' 2>&1';

    $logger->info(__FUNCTION__ . ', exec = ' . $exec);

    @exec($exec, $returnarray);

    // Return the extension (if successful) or false (if failed)
    if (file_exists($representative_file_path)) {
        $representative_ext = $ext;
    }

    return $representative_ext;
}

add_event_handler('upload_file', 'upload_file_tiff');
function upload_file_tiff(?string $representative_ext, string $file_path): ?string
{
    /**
     * @var array<string, mixed> $conf
     * @var \Logger $logger
     */
    global $logger, $conf;

    $logger->info(__FUNCTION__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (! in_array(strtolower(get_extension($file_path)), ['tif', 'tiff'])) {
        return $representative_ext;
    }

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = dirname($file_path) . '/pwg_representative/';
    $representative_file_path .= get_filename_wo_extension(basename($file_path)) . '.';

    $conf_tiff_representative_ext = $conf['tiff_representative_ext'];
    $representative_ext = is_string($conf_tiff_representative_ext) ? $conf_tiff_representative_ext : 'jpg';
    $representative_file_path .= $representative_ext;

    prepare_directory(dirname($representative_file_path));

    $ext_imagick_dir = $conf['ext_imagick_dir'];
    $ext_imagick_dir = is_string($ext_imagick_dir) ? $ext_imagick_dir : '';
    $exec = $ext_imagick_dir . pwg_image::get_ext_imagick_command();
    $exec .= ' "' . realpath($file_path) . '"';

    if ($representative_ext == 'jpg') {
        $exec .= ' -quality 98';
    }

    $dest = pathinfo($representative_file_path);
    $exec .= ' "' . realpath($dest['dirname']) . '/' . $dest['basename'] . '"';

    $exec .= ' 2>&1';
    @exec($exec, $returnarray);

    // sometimes ImageMagick creates file-0.jpg (full size) + file-1.jpg
    // (thumbnail). I don't know how to avoid it.
    $representative_file_abspath = realpath($dest['dirname']) . '/' . $dest['basename'];
    if (! file_exists($representative_file_abspath)) {
        $first_file_abspath = preg_replace(
            '/\.' . $representative_ext . '$/',
            '-0.' . $representative_ext,
            $representative_file_abspath
        );
        assert($first_file_abspath !== null);

        if (file_exists($first_file_abspath)) {
            rename($first_file_abspath, $representative_file_abspath);
        }
    }

    return get_extension($representative_file_abspath);
}

add_event_handler('upload_file', 'upload_file_video');
function upload_file_video(?string $representative_ext, string $file_path): ?string
{
    /**
     * @var array<string, mixed> $conf
     * @var \Logger $logger
     */
    global $logger, $conf;

    $logger->info(__FUNCTION__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    $ffmpeg_video_exts = [ // extensions tested with FFmpeg
        'wmv', 'mov', 'mkv', 'mp4', 'mpg', 'flv', 'asf', 'xvid', 'divx', 'mpeg',
        'avi', 'rm', 'm4v', 'ogg', 'ogv', 'webm', 'webmv',
    ];

    if (! in_array(strtolower(get_extension($file_path)), $ffmpeg_video_exts)) {
        return $representative_ext;
    }

    $representative_file_path = dirname($file_path) . '/pwg_representative/';
    $representative_file_path .= get_filename_wo_extension(basename($file_path)) . '.';

    $representative_ext = 'jpg';
    $representative_file_path .= $representative_ext;

    prepare_directory(dirname($representative_file_path));

    // Get duration of video and determine time of poster
    exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1' . " '{$file_path}'", $O, $S);

    if (! empty($O[0])) {
        $second = min(floor((float) $O[0] * 10) / 10, 2);
    } else {
        $second = 0; // Safest position of the poster
    }

    $logger->info(__FUNCTION__ . ', Poster at ' . $second . 's');

    // Generate poster, see https://trac.ffmpeg.org/wiki/Seeking
    $ffmpeg_dir = $conf['ffmpeg_dir'];
    $ffmpeg_dir = is_string($ffmpeg_dir) ? $ffmpeg_dir : '';
    $ffmpeg = $ffmpeg_dir . 'ffmpeg';
    $ffmpeg .= ' -ss ' . $second;  // Fast seeking
    $ffmpeg .= ' -i "' . $file_path . '"'; // Video file
    $ffmpeg .= ' -frames:v 1';  // Extract one frame
    $ffmpeg .= ' "' . $representative_file_path . '"'; // Output file

    @exec($ffmpeg . ' 2>&1', $FO, $FS);
    if (! empty($FO[0])) {
        $logger->debug(__FUNCTION__ . ', Tried ' . $ffmpeg);
        $logger->debug($FO[0]);
    }

    // Did we generate the file ?
    if (! file_exists($representative_file_path)) {
        // Let's try with avconv if ffmpeg unavailable
        $avconv = str_replace('ffmpeg', 'avconv', $ffmpeg);
        @exec($avconv . ' 2>&1', $AO, $AS);

        if (! empty($AO[0])) {
            $logger->debug(__FUNCTION__ . ', Tried ' . $avconv);
            $logger->debug($AO[0]);
        }
    }

    // Did we finally generate the file ?
    if (! file_exists($representative_file_path)) {
        return null;
    }

    return $representative_ext;
}

add_event_handler('upload_file', 'upload_file_psd');
function upload_file_psd(?string $representative_ext, string $file_path): ?string
{
    /**
     * @var array<string, mixed> $conf
     * @var \Logger $logger
     */
    global $logger, $conf;

    $logger->info(__FUNCTION__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (! in_array(strtolower(get_extension($file_path)), ['psd'])) {
        return $representative_ext;
    }

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = dirname($file_path) . '/pwg_representative/';
    $representative_file_path .= get_filename_wo_extension(basename($file_path)) . '.';

    $representative_ext = 'png';
    $representative_file_path .= $representative_ext;

    prepare_directory(dirname($representative_file_path));

    $ext_imagick_dir = $conf['ext_imagick_dir'];
    $ext_imagick_dir = is_string($ext_imagick_dir) ? $ext_imagick_dir : '';
    $exec = $ext_imagick_dir . pwg_image::get_ext_imagick_command();

    $exec .= ' "' . realpath($file_path) . '"';

    $dest = pathinfo($representative_file_path);
    $exec .= ' "' . realpath($dest['dirname']) . '/' . $dest['basename'] . '"';

    $exec .= ' 2>&1';
    $logger->info(__FUNCTION__ . ', exec = ' . $exec);
    @exec($exec, $returnarray);

    // sometimes ImageMagick creates file-0.png + file-1.png + file-2.png...
    // It seems we can't avoid it.
    $representative_file_abspath = realpath($dest['dirname']) . '/' . $dest['basename'];
    if (! file_exists($representative_file_abspath)) {
        $first_file_abspath = preg_replace(
            '/\.' . $representative_ext . '$/',
            '-0.' . $representative_ext,
            $representative_file_abspath
        );
        assert($first_file_abspath !== null);

        if (file_exists($first_file_abspath)) {
            rename($first_file_abspath, $representative_file_abspath);
        }
    }

    return get_extension($representative_file_abspath);
}

add_event_handler('upload_file', 'upload_file_eps');
function upload_file_eps(?string $representative_ext, string $file_path): ?string
{
    /**
     * @var array<string, mixed> $conf
     * @var \Logger $logger
     */
    global $logger, $conf;

    $logger->info(__FUNCTION__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (! in_array(strtolower(get_extension($file_path)), ['eps'])) {
        return $representative_ext;
    }

    // if the representative is "jpg", the derivatives are ugly. With "png" it's fine.
    $ext = 'png';

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = original_to_representative($file_path, $ext);
    prepare_directory(dirname($representative_file_path));

    // convert -density 300 image.eps -resize 2048x2048 image.png

    $ext_imagick_dir = $conf['ext_imagick_dir'];
    $ext_imagick_dir = is_string($ext_imagick_dir) ? $ext_imagick_dir : '';
    $exec = $ext_imagick_dir . pwg_image::get_ext_imagick_command();
    $exec .= ' "' . realpath($file_path) . '"';
    $exec .= ' -density 300';
    $exec .= ' -resize 2048x2048';
    $exec .= ' "' . $representative_file_path . '"';
    $exec .= ' 2>&1';
    $logger->info(__FUNCTION__ . ', $exec = ' . $exec);
    @exec($exec, $returnarray);

    // Return the extension (if successful) or false (if failed)
    if (file_exists($representative_file_path)) {
        $representative_ext = $ext;
    }

    return $representative_ext;
}

function prepare_directory(string $directory): void
{
    if (! is_dir($directory)) {
        if (str_starts_with(PHP_OS, 'WIN')) {
            $directory = str_replace('/', DIRECTORY_SEPARATOR, $directory);
        }
        umask(0000);
        $recursive = true;
        if (! @mkdir($directory, 0777, $recursive)) {
            die('[prepare_directory] cannot create directory "' . $directory . '"');
        }
    }

    if (! is_writable($directory)) {
        // last chance to make the directory writable
        @chmod($directory, 0777);

        // PHPStan assumes two is_writable() calls on the same path return
        // the same result, since it doesn't model chmod()'s real side
        // effect (confirmed independently: PHP's own filesystem functions,
        // including chmod(), clear the stat cache for the affected path, so
        // this recheck genuinely can and does observe the chmod() above).
        // @phpstan-ignore booleanNot.alwaysTrue
        if (! is_writable($directory)) {
            die('[prepare_directory] directory "' . $directory . '" has no write access');
        }
    }

    secure_directory($directory);
}

function need_resize(string $image_filepath, int $max_width, int $max_height): bool
{
    /**
     * @var array<string, mixed> $conf
     * @var \Logger $logger
     */
    global $conf, $logger;

    $picture_ext = $conf['picture_ext'];
    $picture_ext = is_array($picture_ext) ? $picture_ext : [];
    if (! in_array(strtolower(get_extension($image_filepath)), $picture_ext)) {
        return false;
    }

    // TODO : the resize check should take the orientation into account. If a
    // rotation must be applied to the resized photo, then we should test
    // invert width and height.
    $image_size = getimagesize($image_filepath);
    if ($image_size === false) {
        // can't determine dimensions, so we can't tell whether a resize
        // is needed
        return false;
    }
    [$width, $height] = $image_size;

    if ($width > $max_width or $height > $max_height) {
        $logger->info(__FUNCTION__ . ' ' . $image_filepath . ' is too big (current=' . $width . 'x' . $height . 'px Vs max=' . $max_width . 'x' . $max_height . 'px)');
        return true;
    }

    return false;
}

/**
 * @return array{width: int, height: int, filesize: float}
 */
function pwg_image_infos(string $path): array
{
    $image_size = getimagesize($path);
    if ($image_size === false) {
        // every caller stores width/height straight into the database;
        // there is no sane fallback shape to return here
        throw new Exception(__FUNCTION__ . '(): getimagesize() failed for ' . $path);
    }
    [$width, $height] = $image_size;
    $filesize = floor(filesize($path) / 1024);

    return [
        'width' => $width,
        'height' => $height,
        'filesize' => $filesize,
    ];
}

/**
 * @return string[]
 */
function is_valid_image_extension(string $extension): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (isset($conf['upload_form_all_types']) and (bool) $conf['upload_form_all_types']) {
        $extensions = $conf['file_ext'];
    } else {
        $extensions = $conf['picture_ext'];
    }

    // $conf values are inherently mixed; only string elements can safely
    // be passed to strtolower() below.
    $extensions = is_array($extensions) ? array_filter($extensions, is_string(...)) : [];

    return array_unique(array_map(strtolower(...), $extensions));
}

function file_upload_error_message(int $error_code): string
{
    return match ($error_code) {
        UPLOAD_ERR_INI_SIZE => sprintf(
            l10n('The uploaded file exceeds the upload_max_filesize directive in php.ini: %sB'),
            get_ini_size('upload_max_filesize', false)
        ),
        UPLOAD_ERR_FORM_SIZE => l10n('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
        UPLOAD_ERR_PARTIAL => l10n('The uploaded file was only partially uploaded'),
        UPLOAD_ERR_NO_FILE => l10n('No file was uploaded'),
        UPLOAD_ERR_NO_TMP_DIR => l10n('Missing a temporary folder'),
        UPLOAD_ERR_CANT_WRITE => l10n('Failed to write file to disk'),
        UPLOAD_ERR_EXTENSION => l10n('File upload stopped by extension'),
        default => l10n('Unknown upload error'),
    };
}

function get_ini_size(string $ini_key, bool $in_bytes = true): int|string|false
{
    $size = ini_get($ini_key);

    if ($in_bytes) {
        $size = convert_shorthand_notation_to_bytes($size);
    }

    return $size;
}

function convert_shorthand_notation_to_bytes(string|false $value): int|string|false
{
    $suffix = substr((string) $value, -1);
    $multiply_by = null;

    if ($suffix == 'K') {
        $multiply_by = 1024;
    } elseif ($suffix == 'M') {
        $multiply_by = 1024 * 1024;
    } elseif ($suffix == 'G') {
        $multiply_by = 1024 * 1024 * 1024;
    }

    if (isset($multiply_by)) {
        $value = (int) substr((string) $value, 0, -1) * $multiply_by;
    }

    return $value;
}

function add_upload_error(int|string $upload_id, string $error_message): void
{
    if (! isset($_SESSION['uploads_error']) || ! is_array($_SESSION['uploads_error'])) {
        $_SESSION['uploads_error'] = [];
    }

    if (! isset($_SESSION['uploads_error'][$upload_id]) || ! is_array($_SESSION['uploads_error'][$upload_id])) {
        $_SESSION['uploads_error'][$upload_id] = [];
    }

    $_SESSION['uploads_error'][$upload_id][] = $error_message;
}

function ready_for_upload_message(): ?string
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $upload_dir = $conf['upload_dir'];
    $upload_dir = is_string($upload_dir) ? $upload_dir : '';

    $relative_dir = preg_replace('#^' . PHPWG_ROOT_PATH . '#', '', $upload_dir);

    if (! is_dir($upload_dir)) {
        if (! is_writable(dirname($upload_dir))) {
            return sprintf(
                l10n('Create the "%s" directory at the root of your Piwigo installation'),
                $relative_dir
            );
        }
    } else {
        if (! is_writable($upload_dir)) {
            @chmod($upload_dir, 0777);

            // PHPStan has no model of chmod()'s real filesystem side effect,
            // so it (wrongly) proves this repeat is_writable() call must
            // still return the same false as the enclosing if — this is a
            // genuine re-check of chmod()'s actual outcome, not dead code.
            // @phpstan-ignore booleanNot.alwaysTrue
            if (! is_writable($upload_dir)) {
                return sprintf(
                    l10n('Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation'),
                    $relative_dir
                );
            }
        }
    }

    return null;
}

/**
 * Return the optimized resize dimensions for a representative, based on maximum display size.
 * There is no need to generate a 4000x3000 JPEG from a 4000x3000 HEIC if XXL size is only 1600x1200.
 *
 * @since 14
 *
 * @return int[] [width, height]
 */
function get_optimal_dimensions_for_representative(): array
{
    global $conf;

    $enabled = ImageStdParams::get_defined_type_map();

    $disabled_raw = safe_unserialize(ImageStdParams::get_disabled_type_map());
    // ImageStdParams persists this map as serialize()d DerivativeParams[]
    // (see ImageStdParams::get_disabled_type_map()'s docblock);
    // unserialize() is only typed mixed by PHP itself, so filter out
    // anything that isn't actually a DerivativeParams instance rather
    // than trusting the blob blindly.
    /** @var array<string, DerivativeParams> $disabled */
    $disabled = [];
    if (is_array($disabled_raw)) {
        foreach ($disabled_raw as $disabled_type => $disabled_params) {
            if (is_string($disabled_type) && $disabled_params instanceof DerivativeParams) {
                $disabled[$disabled_type] = $disabled_params;
            }
        }
    }

    $w = $h = 2000; // safe default values

    foreach (ImageStdParams::get_all_types() as $type) {
        // get_all_types() includes types disabled by default (e.g.
        // IMG_3XLARGE/IMG_4XLARGE), which get_defined_type_map() genuinely
        // omits (get_enabled_default_sizes() unsets them) -- $enabled can
        // really lack a $type key here, so this isn't PHPStan-provable
        // dead code even though its docblock-only DerivativeParams[]
        // return type makes it look that way; array_key_exists() forces a
        // real control-flow check instead of trusting that docblock as
        // exhaustive.
        $params = array_key_exists($type, $enabled) ? $enabled[$type] : ($disabled[$type] ?? null);

        if ((bool) $params) {
            [$w, $h] = $params->sizing->ideal_size;
        }
    }

    $margin_coef = 1.5;

    return [(int) ($w * $margin_coef), (int) ($h * $margin_coef)];
}
