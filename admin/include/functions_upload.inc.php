<?php

declare(strict_types=1);

use Piwigo\Admin\Image\pwg_image;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Ws\PwgError;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// add default event handler for image and thumbnail resize
add_event_handler('upload_image_resize', 'pwg_image_resize');
add_event_handler('upload_thumbnail_resize', 'pwg_image_resize');

/** @return array<string, array{default: bool|int|string, can_be_null: bool, min?: int, max?: int, pattern?: string, error_message?: string}> */
function get_upload_form_config(): array
{
    // default configuration for upload
    $upload_form_config = [
      'original_resize' => [
        'default' => false,
        'can_be_null' => false,
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
 * @param array<mixed> $data
 * @param string[] $errors
 * @param string[] $form_errors
 */
function save_upload_form_config(array $data, array &$errors = [], array &$form_errors = []): bool
{
    if (empty($data)) {
        return false;
    }

    $upload_form_config = get_upload_form_config();
    $updates = [];

    foreach ($data as $field => $value) {
        if (!isset($upload_form_config[$field])) {
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
            $min = $upload_form_config[$field]['min'] ?? 0;
            $max = $upload_form_config[$field]['max'] ?? PHP_INT_MAX;
            $pattern = $upload_form_config[$field]['pattern'] ?? '';
            $errMsg = $upload_form_config[$field]['error_message'] ?? '%s - %s';

            if (preg_match($pattern, is_scalar($value) ? (string) $value : '') and $value >= $min and $value <= $max) {
                $updates[] = [
                 'param' => $field,
                 'value' => $value,
                 ];
            } else {
                $errors[] = sprintf(
                    $errMsg,
                    $min,
                    $max
                );

                $form_errors[$field] = '['.$min.' .. '.$max.']';
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

/** @param int[]|null $categories */
function add_uploaded_file(string $source_filepath, ?string $original_filename = null, ?array $categories = null, ?int $level = null, ?int $image_id = null, ?string $original_md5sum = null): int
{
    // 1) move uploaded file to upload/2010/01/22/20100122003814-449ada00.jpg
    //
    // 2) keep/resize original
    //
    // 3) register in database

    global $user, $logger;

    if (!is_null($original_filename)) {
        $original_filename = htmlspecialchars($original_filename);
    }

    if (isset($original_md5sum)) {
        $md5sum = $original_md5sum;
    } else {
        $md5sum = md5_file($source_filepath);
    }

    // we only try to detect duplicate on a new image, not when updating an existing image
    if (!isset($image_id) and \Piwigo\Core\Config::uploadDetectDuplicate()) {
        $query = '
SELECT
    id
  FROM '. IMAGES_TABLE .'
  WHERE md5sum = \''.$md5sum.'\'
;';
        $images_found = query2array($query);

        if (count($images_found) > 0) {
            // SQL fetches return strings in PHP; cast for the int-typed callees below.
            $image_id = (int) $images_found[0]['id'];
            $logger->info('['.__FUNCTION__.'] image already exist #'.$image_id.', we delete the newly uploaded file : '.$source_filepath);
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
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$image_id.'
;';
        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            $file_path = (string)$row['path'];
        }

        if (!isset($file_path)) {
            die('['.__FUNCTION__.'] this photo does not exist in the database');
        }

        // delete all physical files related to the photo (thumbnail, web site, HD)
        delete_element_files([$image_id]);
    } else {
        // this photo is new

        // current date
        [$dbnow] = pwg_db_fetch_row(pwg_query('SELECT NOW();')) ?? [null];
        [$year, $month, $day] = preg_split('/[^\d]/', (string) $dbnow, 4) ?: ['', '', ''];

        // upload directory hierarchy
        $upload_dir = sprintf(
            PHPWG_ROOT_PATH.\Piwigo\Core\Config::uploadDir().'/%s/%s/%s',
            $year,
            $month,
            $day
        );

        // compute file path
        $date_string = preg_replace('/[^\d]/', '', (string) $dbnow);
        $random_string = substr((string) $md5sum, 0, 4).'%s';
        $filename_wo_ext = $date_string.'-'.$random_string;
        $file_path = $upload_dir.'/'.$filename_wo_ext.'.';

        $imgsize = getimagesize($source_filepath);
        [$width, $height, $type] = $imgsize ?: [0, 0, 0];

        if (IMAGETYPE_PNG == $type) {
            $file_path .= 'png';
        } elseif (IMAGETYPE_GIF == $type) {
            $file_path .= 'gif';
        } elseif (IMAGETYPE_JPEG == $type) {
            $file_path .= 'jpg';
        } elseif (IMAGETYPE_WEBP == $type) {
            $file_path .= 'webp';
        } elseif (\Piwigo\Core\Config::has('upload_form_all_types') and \Piwigo\Core\Config::uploadFormAllTypes()) {
            $original_extension = strtolower(get_extension($original_filename ?? ''));

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $finfo_type = $finfo !== false ? finfo_file($finfo, $source_filepath) : false;

            if (in_array($finfo_type, ['image/svg', 'image/svg+xml']) and $original_extension != 'svg') {
                unlink($source_filepath);
                $error_msg = 'File extension "'.$original_extension.'" for file "'.$original_filename.'" does not match file MIME type "'.$finfo_type.'"';
                if (defined('IN_WS')) {
                    global $service;
                    $service->sendResponse(new PwgError(415, $error_msg));
                    exit;
                }

                die($error_msg);
            }

            if (in_array($original_extension, \Piwigo\Core\Config::fileExtensions())) {
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
    $representative_ext = trigger_change('upload_file', '', $file_path);

    $logger->info('Handling ' . (string)$file_path . ' got ' . (string)$representative_ext);

    if (pwg_image::get_library() != 'gd') {
        if (\Piwigo\Core\Config::originalResize()) {
            $need_resize = need_resize($file_path, \Piwigo\Core\Config::originalResizeMaxwidth(), \Piwigo\Core\Config::originalResizeMaxheight());

            if ($need_resize) {
                $img = new pwg_image($file_path);

                $img->pwg_resize(
                    $file_path,
                    \Piwigo\Core\Config::originalResizeMaxwidth(),
                    \Piwigo\Core\Config::originalResizeMaxheight(),
                    \Piwigo\Core\Config::originalResizeQuality(),
                    \Piwigo\Core\Config::uploadFormAutomaticRotation(),
                    false
                );

                $img->destroy();
            }
        }
    }

    // we need to save the rotation angle in the database to compute
    // width/height of "multisizes"
    $rotation_angle = pwg_image::get_rotation_angle($file_path);
    $rotation = pwg_image::get_rotation_code_from_angle($rotation_angle ?? 0);

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
            ['id' => $image_id]
        );
    } else {
        // database registration
        $file = pwg_db_real_escape_string($original_filename ?? basename($file_path));
        $insert = [
          'file' => $file,
          'name' => get_name_from_file($file),
          'date_available' => $dbnow,
          'path' => preg_replace('#^'.preg_quote(PHPWG_ROOT_PATH).'#', '', $file_path),
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

        if ($representative_ext !== '') {
            $insert['representative_ext'] = $representative_ext;
        }

        single_insert(IMAGES_TABLE, $insert);

        $image_id = pwg_db_insert_id();
        pwg_activity('photo', $image_id, 'add');
    }

    add_uploaded_file_add_to_categories((int) $image_id, $categories);

    // update metadata from the uploaded file (exif/iptc)
    if (\Piwigo\Core\Config::useExif() and !function_exists('exif_read_data')) {
        \Piwigo\Core\Config::override('use_exif', false);
    }
    sync_metadata([(int) $image_id]);

    // cache a derivative
    $query = '
SELECT
    id,
    path,
    representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$image_id.'
;';
    $image_infos = pwg_db_fetch_assoc(pwg_query($query));
    if ($image_infos === null) {
        return (int) $image_id;
    }
    $src_image = new SrcImage($image_infos);

    set_make_full_url();
    // in case we are on uploadify.php, we have to replace the false path
    $img_url = DerivativeImage::url(IMG_MEDIUM, $src_image);
    $derivative_url = is_string($img_url) ? (string) preg_replace('#admin/include/i#', 'i', $img_url) : '';
    unset_make_full_url();

    $logger->info(__FUNCTION__.' : force cache generation, derivative_url = '.$derivative_url);

    fetchRemote($derivative_url, $dest);

    trigger_notify('loc_end_add_uploaded_file', $image_infos);

    return (int) $image_id;
}

/** @param int[]|null $categories */
function add_uploaded_file_add_to_categories(int $image_id, ?array $categories): void
{
    if (!\Piwigo\Core\Config::has('lounge_active')) {
        conf_update_param('lounge_active', false, true);
    }

    if (!\Piwigo\Core\Config::loungeActive()) {
        // check if we need to use the lounge from now
        [$nb_photos] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.IMAGES_TABLE.';')) ?? [null];
        if ($nb_photos >= \Piwigo\Core\Config::loungeActivateThreshold()) {
            conf_update_param('lounge_active', true, true);
        }
    }

    if (isset($categories) and count($categories) > 0) {
        if (\Piwigo\Core\Config::loungeActive()) {
            fill_lounge([$image_id], $categories);
        } else {
            associate_images_to_categories([$image_id], $categories);
        }
    }

    if (!\Piwigo\Core\Config::loungeActive()) {
        invalidate_user_cache();
    }
}

function add_format(string $source_filepath, string $format_ext, string $format_of): string
{
    // 1) find infos about the extended image
    //
    // 2) move uploaded file to upload/2022/05/16/pwg_format/20100122003814-449ada00.cr2
    //
    // 3) register in database

    if (!conf_get_param('enable_formats', false)) {
        die('['.__FUNCTION__.'] formats are disabled');
    }

    $format_ext_list = conf_get_param('format_ext', ['cr2']);
    if (!is_array($format_ext_list)) {
        $format_ext_list = ['cr2'];
    }
    if (!in_array($format_ext, $format_ext_list)) {
        $extList = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $format_ext_list);
        die('['.__FUNCTION__.'] unexpected format extension "'.$format_ext.'" (authorized extensions: '.implode(', ', $extList).')');
    }

    $query = '
SELECT
    path
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$format_of.'
;';
    $images = query2array($query);

    if (!isset($images[0])) {
        die('['.__FUNCTION__.'] this photo does not exist in the database');
    }

    $format_path = dirname((string) $images[0]['path']).'/pwg_format/';
    $format_path .= get_filename_wo_extension(basename((string) $images[0]['path']));
    $format_path .= '.'.$format_ext;

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
  FROM '.IMAGE_FORMAT_TABLE.'
  WHERE image_id = '.$format_of.'
  AND ext = "'.$format_ext.'"
;';

    $formats = query2array($query);
    if ($formats) {
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

    pwg_activity('photo', $format_of, 'edit', ['action' => 'add format', 'format_ext' => $format_ext, 'format_id' => $format_id]);

    $format_infos = $insert;
    $format_infos['format_id'] = $format_id;

    trigger_notify('loc_end_add_format', $format_infos);

    return $add_status;
}

add_event_handler('upload_file', 'upload_file_pdf');
function upload_file_pdf(?string $representative_ext, string $file_path): ?string
{
    global $logger;

    $logger->info(__FUNCTION__.', $file_path = '.$file_path.', $representative_ext = '.$representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (!in_array(strtolower(get_extension($file_path)), ['pdf'])) {
        return $representative_ext;
    }

    $extRaw = conf_get_param('pdf_representative_ext', 'jpg');
    $ext = is_string($extRaw) ? $extRaw : 'jpg';
    $qualityRaw = conf_get_param('pdf_jpg_quality', 90);
    $jpg_quality = is_int($qualityRaw) ? $qualityRaw : 90;

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = original_to_representative($file_path, $ext);
    prepare_directory(dirname($representative_file_path));

    $exec = \Piwigo\Core\Config::extImagickDir().pwg_image::get_ext_imagick_command();
    $exec .= ' "'.realpath($file_path).'"[0]';
    if ('jpg' == $ext) {
        $exec .= ' -quality '.$jpg_quality;
    }
    $exec .= ' "'.$representative_file_path.'"';
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
    global $logger;

    $logger->info(__FUNCTION__.', $file_path = '.$file_path.', $representative_ext = '.$representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (!in_array(strtolower(get_extension($file_path)), ['heic'])) {
        return $representative_ext;
    }

    $ext = 'jpg';

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = original_to_representative($file_path, $ext);
    prepare_directory(dirname($representative_file_path));

    [$w, $h] = get_optimal_dimensions_for_representative();

    $exec = \Piwigo\Core\Config::extImagickDir().pwg_image::get_ext_imagick_command();
    $exec .= ' "'.realpath($file_path).'"';
    $exec .= ' -sampling-factor 4:2:0 -quality 85 -interlace JPEG -colorspace sRGB -auto-orient +repage -resize "'.$w.'x'.$h.'>"';
    $exec .= ' "'.$representative_file_path.'"';
    $exec .= ' 2>&1';

    $logger->info(__FUNCTION__.', exec = '.$exec);

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
    global $logger;

    $logger->info(__FUNCTION__.', $file_path = '.$file_path.', $representative_ext = '.$representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (!in_array(strtolower(get_extension($file_path)), ['tif', 'tiff'])) {
        return $representative_ext;
    }

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = dirname((string) $file_path).'/pwg_representative/';
    $representative_file_path .= get_filename_wo_extension(basename((string) $file_path)).'.';

    $representative_ext = \Piwigo\Core\Config::tiffRepresentativeExt();
    $representative_file_path .= $representative_ext;

    prepare_directory(dirname($representative_file_path));

    $exec = \Piwigo\Core\Config::extImagickDir().pwg_image::get_ext_imagick_command();
    $exec .= ' "'.realpath($file_path).'"';

    if ('jpg' == \Piwigo\Core\Config::tiffRepresentativeExt()) {
        $exec .= ' -quality 98';
    }

    $dest = pathinfo($representative_file_path);
    $exec .= ' "'.realpath($dest['dirname']).'/'.$dest['basename'].'"';

    $exec .= ' 2>&1';
    @exec($exec, $returnarray);

    // sometimes ImageMagick creates file-0.jpg (full size) + file-1.jpg
    // (thumbnail). I don't know how to avoid it.
    $representative_file_abspath = realpath($dest['dirname']).'/'.$dest['basename'];
    if (!file_exists($representative_file_abspath)) {
        $first_file_abspath = preg_replace(
            '/\.'.$representative_ext.'$/',
            '-0.'.$representative_ext,
            $representative_file_abspath
        ) ?? '';

        if (file_exists($first_file_abspath)) {
            rename($first_file_abspath, $representative_file_abspath);
        }
    }

    return get_extension($representative_file_abspath);
}

add_event_handler('upload_file', 'upload_file_video');
function upload_file_video(?string $representative_ext, string $file_path): ?string
{
    global $logger;

    $logger->info(__FUNCTION__.', $file_path = '.$file_path.', $representative_ext = '.$representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    $ffmpeg_video_exts = [ // extensions tested with FFmpeg
      'wmv','mov','mkv','mp4','mpg','flv','asf','xvid','divx','mpeg',
      'avi','rm', 'm4v', 'ogg', 'ogv', 'webm', 'webmv',
      ];

    if (!in_array(strtolower(get_extension($file_path)), $ffmpeg_video_exts)) {
        return $representative_ext;
    }

    $representative_file_path = dirname((string) $file_path).'/pwg_representative/';
    $representative_file_path .= get_filename_wo_extension(basename((string) $file_path)).'.';

    $representative_ext = 'jpg';
    $representative_file_path .= $representative_ext;

    prepare_directory(dirname($representative_file_path));

    // Get duration of video and determine time of poster
    exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1'." '$file_path'", $O, $S);

    if (!empty($O[0])) {
        $second = min(floor((float)$O[0] * 10) / 10, 2);
    } else {
        $second = 0; // Safest position of the poster
    }

    $logger->info(__FUNCTION__.', Poster at '.$second.'s');

    // Generate poster, see https://trac.ffmpeg.org/wiki/Seeking
    $ffmpeg = \Piwigo\Core\Config::ffmpegDir().'ffmpeg';
    $ffmpeg .= ' -ss '.$second;  // Fast seeking
    $ffmpeg .= ' -i "'.$file_path.'"'; // Video file
    $ffmpeg .= ' -frames:v 1';  // Extract one frame
    $ffmpeg .= ' "'.$representative_file_path.'"'; // Output file

    @exec($ffmpeg.' 2>&1', $FO, $FS);
    if (!empty($FO[0])) {
        $logger->debug(__FUNCTION__.', Tried '.$ffmpeg);
        $logger->debug($FO[0]);
    }

    // Did we generate the file ?
    if (!file_exists($representative_file_path)) {
        // Let's try with avconv if ffmpeg unavailable
        $avconv = str_replace('ffmpeg', 'avconv', $ffmpeg);
        @exec($avconv.' 2>&1', $AO, $AS);

        if (!empty($AO[0])) {
            $logger->debug(__FUNCTION__.', Tried '.$avconv);
            $logger->debug($AO[0]);
        }
    }

    // Did we finally generate the file ?
    if (!file_exists($representative_file_path)) {
        return null;
    }

    return $representative_ext;
}

add_event_handler('upload_file', 'upload_file_psd');
function upload_file_psd(?string $representative_ext, string $file_path): ?string
{
    global $logger;

    $logger->info(__FUNCTION__.', $file_path = '.$file_path.', $representative_ext = '.$representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (!in_array(strtolower(get_extension($file_path)), ['psd'])) {
        return $representative_ext;
    }

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = dirname((string) $file_path).'/pwg_representative/';
    $representative_file_path .= get_filename_wo_extension(basename((string) $file_path)).'.';

    $representative_ext = 'png';
    $representative_file_path .= $representative_ext;

    prepare_directory(dirname($representative_file_path));

    $exec = \Piwigo\Core\Config::extImagickDir().pwg_image::get_ext_imagick_command();

    $exec .= ' "'.realpath($file_path).'"';

    $dest = pathinfo($representative_file_path);
    $exec .= ' "'.realpath($dest['dirname']).'/'.$dest['basename'].'"';

    $exec .= ' 2>&1';
    $logger->info(__FUNCTION__.', exec = '.$exec);
    @exec($exec, $returnarray);

    // sometimes ImageMagick creates file-0.png + file-1.png + file-2.png...
    // It seems we can't avoid it.
    $representative_file_abspath = realpath($dest['dirname']).'/'.$dest['basename'];
    if (!file_exists($representative_file_abspath)) {
        $first_file_abspath = preg_replace(
            '/\.'.$representative_ext.'$/',
            '-0.'.$representative_ext,
            $representative_file_abspath
        ) ?? '';

        if (file_exists($first_file_abspath)) {
            rename($first_file_abspath, $representative_file_abspath);
        }
    }

    return get_extension($representative_file_abspath);
}

add_event_handler('upload_file', 'upload_file_eps');
function upload_file_eps(?string $representative_ext, string $file_path): ?string
{
    global $logger;

    $logger->info(__FUNCTION__.', $file_path = '.$file_path.', $representative_ext = '.$representative_ext);

    if (isset($representative_ext)) {
        return $representative_ext;
    }

    if (pwg_image::get_library() != 'ext_imagick') {
        return $representative_ext;
    }

    if (!in_array(strtolower(get_extension($file_path)), ['eps'])) {
        return $representative_ext;
    }

    // if the representative is "jpg", the derivatives are ugly. With "png" it's fine.
    $ext = 'png';

    // move the uploaded file to pwg_representative sub-directory
    $representative_file_path = original_to_representative($file_path, $ext);
    prepare_directory(dirname($representative_file_path));

    // convert -density 300 image.eps -resize 2048x2048 image.png

    $exec = \Piwigo\Core\Config::extImagickDir().pwg_image::get_ext_imagick_command();
    $exec .= ' "'.realpath($file_path).'"';
    $exec .= ' -density 300';
    $exec .= ' -resize 2048x2048';
    $exec .= ' "'.$representative_file_path.'"';
    $exec .= ' 2>&1';
    $logger->info(__FUNCTION__.', $exec = '.$exec);
    @exec($exec, $returnarray);

    // Return the extension (if successful) or false (if failed)
    if (file_exists($representative_file_path)) {
        $representative_ext = $ext;
    }

    return $representative_ext;
}

function prepare_directory(string $directory): void
{
    if (!is_dir($directory)) {
        if (str_starts_with(PHP_OS, 'WIN')) {
            $directory = str_replace('/', DIRECTORY_SEPARATOR, $directory);
        }
        umask(0000);
        $recursive = true;
        if (!@mkdir($directory, 0777, $recursive)) {
            die('[prepare_directory] cannot create directory "'.$directory.'"');
        }
    }

    if (!is_writable($directory)) {
        // last chance to make the directory writable
        @chmod($directory, 0777);
    }
    if (!is_writable($directory)) {
        die('[prepare_directory] directory "'.$directory.'" has no write access');
    }

    secure_directory($directory);
}

function need_resize(string $image_filepath, int|string $max_width, int|string $max_height): bool
{
    global $logger;

    if (!in_array(strtolower(get_extension($image_filepath)), \Piwigo\Core\Config::pictureExtensions())) {
        return false;
    }

    // TODO : the resize check should take the orientation into account. If a
    // rotation must be applied to the resized photo, then we should test
    // invert width and height.
    [$width, $height] = getimagesize($image_filepath) ?: [0, 0];

    if ($width > $max_width or $height > $max_height) {
        $logger->info(__FUNCTION__.' '.(string)$image_filepath.' is too big (current='.$width.'x'.$height.'px Vs max='.$max_width.'x'.$max_height.'px)');
        return true;
    }

    return false;
}

/** @return array<string,mixed> */
function pwg_image_infos(string $path): array
{
    [$width, $height] = getimagesize($path) ?: [0, 0];
    $filesize = floor(filesize($path) / 1024);

    return [
      'width'  => $width,
      'height' => $height,
      'filesize' => $filesize,
      ];
}

/** @return string[] */
function is_valid_image_extension(string $extension): array
{
    if (\Piwigo\Core\Config::has('upload_form_all_types') and \Piwigo\Core\Config::uploadFormAllTypes()) {
        $extensions = \Piwigo\Core\Config::fileExtensions();
    } else {
        $extensions = \Piwigo\Core\Config::pictureExtensions();
    }

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

function get_ini_size(string $ini_key, bool $in_bytes = true): int|string
{
    $size = ini_get($ini_key);
    if ($size === false) {
        return 0;
    }

    if ($in_bytes) {
        $size = convert_shorthand_notation_to_bytes($size);
    }

    return $size;
}

function convert_shorthand_notation_to_bytes(int|string $value): int
{
    $suffix = substr((string) $value, -1);
    $multiply_by = null;

    if ('K' == $suffix) {
        $multiply_by = 1024;
    } elseif ('M' == $suffix) {
        $multiply_by = 1024 * 1024;
    } elseif ('G' == $suffix) {
        $multiply_by = 1024 * 1024 * 1024;
    }

    if (isset($multiply_by)) {
        $value = substr((string) $value, 0, -1);
        $value = (int) ((float) $value * $multiply_by);
    }

    return (int) $value;
}

function add_upload_error(string $upload_id, string $error_message): void
{
    $uploadsError = is_array($_SESSION['uploads_error'] ?? null) ? $_SESSION['uploads_error'] : [];
    $slot = is_array($uploadsError[$upload_id] ?? null) ? $uploadsError[$upload_id] : [];
    $slot[] = $error_message;
    $uploadsError[$upload_id] = $slot;
    $_SESSION['uploads_error'] = $uploadsError;
}

function ready_for_upload_message(): ?string
{
    $relative_dir = preg_replace('#^'.PHPWG_ROOT_PATH.'#', '', (string) \Piwigo\Core\Config::uploadDir());

    if (!is_dir(\Piwigo\Core\Config::uploadDir())) {
        if (!is_writable(dirname((string) \Piwigo\Core\Config::uploadDir()))) {
            return sprintf(
                l10n('Create the "%s" directory at the root of your Piwigo installation'),
                $relative_dir
            );
        }
    } else {
        $upload_dir = \Piwigo\Core\Config::uploadDir();
        if (!is_writable($upload_dir)) {
            @chmod($upload_dir, 0777);
        }
        if (!is_writable(\Piwigo\Core\Config::uploadDir())) {
            return sprintf(
                l10n('Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation'),
                $relative_dir
            );
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
 * @return array<int, int|float>
 */
function get_optimal_dimensions_for_representative(): array
{
    $enabled = ImageStdParams::get_defined_type_map();
    $disabled = safe_unserialize(ImageStdParams::get_disabled_type_map());

    $w = $h = 2000; // safe default values

    foreach (ImageStdParams::get_all_types() as $type) {
        $params = $enabled[$type] ?? ($disabled[$type] ?? null);

        if ($params instanceof \Piwigo\Image\DerivativeParams) {
            [$w, $h] = $params->sizing->ideal_size;
        }
    }

    $margin_coef = 1.5;

    return [$w * $margin_coef, $h * $margin_coef];
}
