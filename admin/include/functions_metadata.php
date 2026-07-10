<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

include_once PHPWG_ROOT_PATH . '/include/functions_metadata.inc.php';

/**
 * Returns IPTC metadata to sync from a file, depending on IPTC mapping.
 * @toto : clean code (factorize foreach)
 *
 * @param string $file
 * @return array<string, string>
 */
function get_sync_iptc_data($file): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $map_raw = $conf['use_iptc_mapping'] ?? null;
    /** @var array<string, string> $map */
    $map = [];
    if (is_array($map_raw)) {
        foreach ($map_raw as $map_key => $map_value) {
            if (is_string($map_key) && is_string($map_value)) {
                $map[$map_key] = $map_value;
            }
        }
    }

    $iptc = get_iptc_data($file, $map);

    foreach ($iptc as $pwg_key => $value) {
        if (in_array($pwg_key, ['date_creation', 'date_available'])) {
            if ((bool) preg_match('/(\d{4})(\d{2})(\d{2})/', $value, $matches)) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
                $day = (int) $matches[3];

                if (! checkdate($month, $day, $year)) {
                    // we suppose the year is correct
                    $month = 1;
                    $day = 1;
                }

                $iptc[$pwg_key] = $year . '-' . $month . '-' . $day;
            }
        }
    }

    if (isset($iptc['keywords'])) {
        $iptc['keywords'] = metadata_normalize_keywords_string($iptc['keywords']);
    }

    foreach ($iptc as $pwg_key => $value) {
        $iptc[$pwg_key] = addslashes($iptc[$pwg_key]);
    }

    return $iptc;
}

/**
 * Returns EXIF metadata to sync from a file, depending on EXIF mapping.
 *
 * @param string $file
 * @return array<string, mixed>
 */
function get_sync_exif_data($file): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $map_raw = $conf['use_exif_mapping'] ?? null;
    /** @var array<string, string> $map */
    $map = [];
    if (is_array($map_raw)) {
        foreach ($map_raw as $map_key => $map_value) {
            if (is_string($map_key) && is_string($map_value)) {
                $map[$map_key] = $map_value;
            }
        }
    }

    $exif = get_exif_data($file, $map);

    foreach ($exif as $pwg_key => $value) {
        // get_exif_data() returns array<string, mixed> because raw EXIF/trigger_change()
        // values are heterogeneous (scalars, sometimes nested arrays); narrow to a string
        // representation here for the regex/normalization/addslashes operations below.
        $value_str = is_scalar($value) ? (string) $value : '';

        if (in_array($pwg_key, ['date_creation', 'date_available'])) {
            if ((bool) preg_match('/^(\d{4}).(\d{2}).(\d{2}) (\d{2}).(\d{2}).(\d{2})/', $value_str, $matches)) {
                $exif[$pwg_key] = $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                if ($exif[$pwg_key] == '0000-00-00 00:00:00') {
                    $exif[$pwg_key] = null;
                }
            } elseif ((bool) preg_match('/^(\d{4}).(\d{2}).(\d{2})/', $value_str, $matches)) {
                $exif[$pwg_key] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            } else {
                unset($exif[$pwg_key]);
                continue;
            }
        }

        if (in_array($pwg_key, ['keywords', 'tags'])) {
            $exif[$pwg_key] = metadata_normalize_keywords_string($value_str);
        }

        if (empty($exif[$pwg_key])) {
            unset($exif[$pwg_key]);
            continue;
        }

        $current_value = $exif[$pwg_key];
        $exif[$pwg_key] = addslashes(is_scalar($current_value) ? (string) $current_value : '');
    }

    return $exif;
}

/**
 * Get all potential file metadata fields, including IPTC and EXIF.
 *
 * @return string[]
 */
function get_sync_metadata_attributes(): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $update_fields = ['filesize', 'width', 'height'];

    if ((bool) $conf['use_exif']) {
        $exif_mapping = $conf['use_exif_mapping'] ?? null;
        $exif_mapping = is_array($exif_mapping) ? $exif_mapping : [];
        $update_fields =
          array_merge(
              $update_fields,
              array_map(strval(...), array_keys($exif_mapping)),
              ['latitude', 'longitude']
          );
    }

    if ((bool) $conf['use_iptc']) {
        $iptc_mapping = $conf['use_iptc_mapping'] ?? null;
        $iptc_mapping = is_array($iptc_mapping) ? $iptc_mapping : [];
        $update_fields =
          array_merge(
              $update_fields,
              array_map(strval(...), array_keys($iptc_mapping))
          );
    }

    return array_unique($update_fields);
}

/**
 * Get all metadata of a file.
 *
 * @param array<string, mixed> $infos - (path[, representative_ext])
 * @return array<string, mixed>|false includes data provided in $infos, or false if the
 *   file's size can't be read
 */
function get_sync_metadata($infos)
{
    /** @var array<string, mixed> $conf */
    global $conf;
    $path = $infos['path'] ?? null;
    $path = is_string($path) ? $path : '';
    $file = PHPWG_ROOT_PATH . $path;
    $fs = @filesize($file);

    if ($fs === false) {
        return false;
    }

    $infos['filesize'] = floor($fs / 1024);

    $is_tiff = false;

    if (isset($infos['representative_ext'])) {
        if ((bool) ($image_size = @getimagesize($file))) {
            $type = $image_size[2];

            if ($type == IMAGETYPE_TIFF_MM or $type == IMAGETYPE_TIFF_II) {
                // in case of TIFF files, we want to use the original file and not
                // the representative for EXIF/IPTC, but we need the representative
                // for width/height (to compute the multiple size dimensions)
                $is_tiff = true;
            }
        }

        $representative_ext = $infos['representative_ext'];
        $representative_ext = is_string($representative_ext) ? $representative_ext : '';
        $file = original_to_representative($file, $representative_ext);
    }

    if (function_exists('mime_content_type')) {
        $mime_type = mime_content_type($file);

        if ($mime_type !== false && str_starts_with($mime_type, 'image/')) {
            if (in_array($mime_type, ['image/svg+xml', 'image/svg'])) {
                $xml = file_get_contents($file);

                $xmlget = $xml === false ? false : simplexml_load_string($xml);
                if ($xmlget !== false) {
                    $xmlattributes = $xmlget->attributes();
                    $width = $xmlattributes->width;
                    $height = $xmlattributes->height;
                    $vb = (string) $xmlattributes->viewBox;

                    if (isset($width) and $width != '') {
                        $infos['width'] = (int) $width;
                    } else {
                        $infos['width'] = round((float) explode(' ', $vb)[2]);
                    }

                    if (isset($height) and $height != '') {
                        $infos['height'] = (int) $height;
                    } else {
                        $infos['height'] = round((float) explode(' ', $vb)[3]);
                    }
                }
            }
            if ((bool) ($image_size = @getimagesize($file))) {
                $infos['width'] = $image_size[0];
                $infos['height'] = $image_size[1];
            }
        }
    }

    if ($is_tiff) {
        // back to original file
        $file = PHPWG_ROOT_PATH . $path;
    }

    if ((bool) $conf['use_exif']) {
        $exif = get_sync_exif_data($file);
        $infos = array_merge($infos, $exif);
    }

    if ((bool) $conf['use_iptc']) {
        $iptc = get_sync_iptc_data($file);
        $infos = array_merge($infos, $iptc);
    }

    foreach (['name', 'author'] as $single_line_field) {
        if (isset($infos[$single_line_field])) {
            $field_value = $infos[$single_line_field];
            $field_value = is_string($field_value) ? $field_value : '';
            foreach (["\r\n", "\n", "\r"] as $to_replace_string) {
                $field_value = str_replace($to_replace_string, ' ', $field_value);
            }
            $infos[$single_line_field] = $field_value;
        }
    }

    return $infos;
}

/**
 * Sync all metadata of a list of images.
 * Metadata are fetched from original files and saved in database.
 *
 * @param int[] $ids
 */
function sync_metadata($ids): void
{
    global $conf;

    if (! defined('CURRENT_DATE')) {
        define('CURRENT_DATE', date('Y-m-d'));
    }

    $datas = [];
    $tags_of = [];

    $query = '
SELECT id, path, representative_ext
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (
' . wordwrap(implode(', ', $ids), 160, "\n") . '
)
;';

    $result = pwg_query($query);
    while ((bool) ($data = pwg_db_fetch_assoc($result))) {
        $data = get_sync_metadata($data);
        if ($data === false) {
            continue;
        }
        // print_r($data);
        $id = $data['id'];
        if (! is_int($id) && ! is_string($id)) {
            // no usable primary key to associate tags with, skip tagging for this row
            continue;
        }

        foreach (['keywords', 'tags'] as $key) {
            if (isset($data[$key])) {
                if (! isset($tags_of[$id])) {
                    $tags_of[$id] = [];
                }

                $tag_list = $data[$key];
                $tag_list = is_scalar($tag_list) ? (string) $tag_list : '';

                foreach (explode(',', $tag_list) as $tag_name) {
                    $tags_of[$id][] = tag_id_from_tag_name($tag_name);
                }
            }
        }

        $data['date_metadata_update'] = CURRENT_DATE;

        $datas[] = $data;
    }

    if (count($datas) > 0) {
        $update_fields = get_sync_metadata_attributes();
        $update_fields[] = 'date_metadata_update';

        $update_fields = array_diff(
            $update_fields,
            ['tags', 'keywords']
        );

        mass_updates(
            IMAGES_TABLE,
            [
                'primary' => ['id'],
                'update' => $update_fields,
            ],
            $datas,
            MASS_UPDATES_SKIP_EMPTY
        );
    }

    set_tags_of($tags_of);
}

/**
 * Returns an array associating element id (images.id) with its complete
 * path in the filesystem
 *
 * @param int|string $category_id numeric category id, or '' for no filter
 * @param int $site_id
 * @param bool $recursive
 * @param bool $only_new
 * @return array<int|string, mixed>
 */
function get_filelist(
    $category_id = '',
    $site_id = 1,
    $recursive = false,
    $only_new = false
): array {
    // filling $cat_ids : all categories required
    $cat_ids = [];

    $query = '
SELECT id
  FROM ' . CATEGORIES_TABLE . '
  WHERE site_id = ' . $site_id . '
    AND dir IS NOT NULL';
    if (is_numeric($category_id)) {
        if ($recursive) {
            $query .= '
    AND uppercats ' . DB_REGEX_OPERATOR . ' \'(^|,)' . $category_id . '(,|$)\'
';
        } else {
            $query .= '
    AND id = ' . $category_id . '
';
        }
    }
    $query .= '
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $cat_ids[] = $row['id'];
    }

    if (count($cat_ids) == 0) {
        return [];
    }

    $query = '
SELECT id, path, representative_ext
  FROM ' . IMAGES_TABLE . '
  WHERE storage_category_id IN (' . implode(',', $cat_ids) . ')';
    if ($only_new) {
        $query .= '
    AND date_metadata_update IS NULL
';
    }
    $query .= '
;';
    return hash_from_query($query, 'id');
}

/**
 * Returns the list of keywords (future tags) correctly separated with
 * commas. Other separators are converted into commas.
 *
 * @param string $keywords_string
 */
function metadata_normalize_keywords_string($keywords_string): string
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $separator_regex = $conf['metadata_keyword_separator_regex'] ?? null;
    // matches the built-in default (see config_default.inc.php) if the
    // config value is somehow missing/mistyped at runtime
    $separator_regex = is_string($separator_regex) ? $separator_regex : '/[.,;]/';

    $keywords_string = preg_replace($separator_regex, ',', $keywords_string);
    assert($keywords_string !== null);
    // new lines are always considered as keyword separators
    $keywords_string = str_replace(["\r\n", "\n", "\r"], ',', $keywords_string);
    $keywords_string = preg_replace('/,+/', ',', $keywords_string);
    $keywords_string = preg_replace('/^,+|,+$/', '', (string) $keywords_string);

    $keywords_string = implode(
        ',',
        array_unique(
            explode(
                ',',
                (string) $keywords_string
            )
        )
    );

    return $keywords_string;
}
