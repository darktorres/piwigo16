<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\admin\metadata
 */


include_once(PHPWG_ROOT_PATH.'/include/functions_metadata.inc.php');


/**
 * Returns IPTC metadata to sync from a file, depending on IPTC mapping.
 * @toto : clean code (factorize foreach)
 *
 * @param string $file
 */
/** @return array<mixed> */
function get_sync_iptc_data(string $file): array
{
    $map = \Piwigo\Core\Config::useIptcMapping();

    $iptc = get_iptc_data($file, $map);

    foreach ($iptc as $pwg_key => $value) {
        if (in_array($pwg_key, ['date_creation', 'date_available'])) {
            if (is_scalar($value) && preg_match('/(\d{4})(\d{2})(\d{2})/', (string) $value, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];

                if (!checkdate((int)$month, (int)$day, (int)$year)) {
                    // we suppose the year is correct
                    $month = 1;
                    $day = 1;
                }

                $iptc[$pwg_key] = $year.'-'.$month.'-'.$day;
            }
        }
    }

    if (isset($iptc['keywords'])) {
        $iptc['keywords'] = metadata_normalize_keywords_string(is_scalar($iptc['keywords']) ? (string) $iptc['keywords'] : '');
    }

    foreach ($iptc as $pwg_key => $value) {
        $iptc[$pwg_key] = addslashes(is_scalar($iptc[$pwg_key]) ? (string) $iptc[$pwg_key] : '');
    }

    return $iptc;
}

/**
 * Returns EXIF metadata to sync from a file, depending on EXIF mapping.
 */
/** @return array<mixed> */
function get_sync_exif_data(string $file): array
{
    $exif = get_exif_data($file, \Piwigo\Core\Config::useExifMapping());

    foreach ($exif as $pwg_key => $value) {
        if (in_array($pwg_key, ['date_creation', 'date_available'])) {
            $valueStr = is_scalar($value) ? (string) $value : '';
            if (preg_match('/^(\d{4}).(\d{2}).(\d{2}) (\d{2}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                $exif[$pwg_key] = $matches[1].'-'.$matches[2].'-'.$matches[3].' '.$matches[4].':'.$matches[5].':'.$matches[6];
                if ($exif[$pwg_key] == '0000-00-00 00:00:00') {
                    $exif[$pwg_key] = null;
                }
            } elseif (preg_match('/^(\d{4}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                $exif[$pwg_key] = $matches[1].'-'.$matches[2].'-'.$matches[3];
            } else {
                unset($exif[$pwg_key]);
                continue;
            }
        }

        if (in_array($pwg_key, ['keywords', 'tags'])) {
            $exif[$pwg_key] = metadata_normalize_keywords_string(is_scalar($exif[$pwg_key]) ? (string) $exif[$pwg_key] : '');
        }

        if (empty($exif[$pwg_key])) {
            unset($exif[$pwg_key]);
            continue;
        }

        $exif[$pwg_key] = addslashes(is_scalar($exif[$pwg_key]) ? (string) $exif[$pwg_key] : '');
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
    $update_fields = ['filesize', 'width', 'height'];

    if (\Piwigo\Core\Config::useExif()) {
        $update_fields =
          array_merge(
              $update_fields,
              array_keys(\Piwigo\Core\Config::useExifMapping()),
              ['latitude', 'longitude']
          );
    }

    if (\Piwigo\Core\Config::useIptc()) {
        $update_fields =
          array_merge(
              $update_fields,
              array_keys(\Piwigo\Core\Config::useIptcMapping())
          );
    }

    return array_unique($update_fields);
}

/**
 * Get all metadata of a file.
 *
 * @param array $infos - (path[, representative_ext])
 * @return array|false - includes data provided in $infos
 */
/**
 * @param array<mixed> $infos
 * @return array<mixed>|false
 */
function get_sync_metadata(array $infos): array|false
{
    $file = PHPWG_ROOT_PATH.(is_scalar($infos['path'] ?? null) ? (string) $infos['path'] : '');
    $fs = @filesize($file);

    if ($fs === false) {
        return false;
    }

    $infos['filesize'] = floor($fs / 1024);

    $is_tiff = false;

    if (isset($infos['representative_ext'])) {
        if ($image_size = @getimagesize($file)) {
            $type = $image_size[2];

            if (IMAGETYPE_TIFF_MM == $type or IMAGETYPE_TIFF_II == $type) {
                // in case of TIFF files, we want to use the original file and not
                // the representative for EXIF/IPTC, but we need the representative
                // for width/height (to compute the multiple size dimensions)
                $is_tiff = true;
            }
        }

        $file = original_to_representative($file, is_scalar($infos['representative_ext']) ? (string) $infos['representative_ext'] : '');
    }

    if (function_exists('mime_content_type')) {
        $mime_type = mime_content_type($file);

        if (is_string($mime_type) && str_starts_with($mime_type, 'image/')) {
            if (in_array($mime_type, ['image/svg+xml', 'image/svg'])) {
                $xml = file_get_contents($file);
                if ($xml === false) {
                    return false;
                }

                $xmlget = simplexml_load_string($xml);
                if ($xmlget === false) {
                    return false;
                }
                $xmlattributes = $xmlget->attributes();
                $width = $xmlattributes->width;
                $height = $xmlattributes->height;
                $vb = (string) $xmlattributes->viewBox;

                if (isset($width) and $width != '') {
                    $infos['width'] = (int) $width;
                } elseif ($vb !== '') {
                    $infos['width'] = round((float)explode(' ', $vb)[2]);
                }

                if (isset($height) and $height != '') {
                    $infos['height'] = (int) $height;
                } elseif ($vb !== '') {
                    $infos['height'] = round((float)explode(' ', $vb)[3]);
                }
            }
            if ($image_size = @getimagesize($file)) {
                $infos['width'] = $image_size[0];
                $infos['height'] = $image_size[1];
            }
        }
    }

    if ($is_tiff) {
        // back to original file
        $file = PHPWG_ROOT_PATH.(is_scalar($infos['path'] ?? null) ? (string) $infos['path'] : '');
    }

    if (\Piwigo\Core\Config::useExif()) {
        $exif = get_sync_exif_data($file);
        $infos = array_merge($infos, $exif);
    }

    if (\Piwigo\Core\Config::useIptc()) {
        $iptc = get_sync_iptc_data($file);
        $infos = array_merge($infos, $iptc);
    }

    foreach (['name', 'author'] as $single_line_field) {
        if (isset($infos[$single_line_field]) && is_scalar($infos[$single_line_field])) {
            $fieldVal = (string) $infos[$single_line_field];
            foreach (["\r\n", "\n", "\r"] as $to_replace_string) {
                $fieldVal = str_replace($to_replace_string, ' ', $fieldVal);
            }
            $infos[$single_line_field] = $fieldVal;
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
    if (!defined('CURRENT_DATE')) {
        define('CURRENT_DATE', date('Y-m-d'));
    }

    $datas = [];
    $tags_of = [];

    $query = '
SELECT id, path, representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE id IN (
'.wordwrap(implode(', ', $ids), 160, "\n").'
)
;';

    $result = pwg_query($query);
    while ($data = pwg_db_fetch_assoc($result)) {
        $data = get_sync_metadata($data);
        if ($data === false) {
            continue;
        }
        // print_r($data);
        $id = is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0;
        foreach (['keywords', 'tags'] as $key) {
            if (isset($data[$key])) {
                if (!isset($tags_of[$id])) {
                    $tags_of[$id] = [];
                }

                foreach (explode(',', is_scalar($data[$key]) ? (string) $data[$key] : '') as $tag_name) {
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
            'update'  => $update_fields,
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
 *  int|string
 * @param int $site_id
 * @param boolean $recursive
 * @param boolean $only_new
 */
/** @return array<mixed> */
function get_filelist(
    int|string $category_id = '',
    int $site_id = 1,
    bool $recursive = false,
    bool $only_new = false
): array {
    // filling $cat_ids : all categories required
    $cat_ids = [];

    $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE site_id = '.$site_id.'
    AND dir IS NOT NULL';
    if (is_numeric($category_id)) {
        if ($recursive) {
            $query .= '
    AND uppercats '.DB_REGEX_OPERATOR.' \'(^|,)'.$category_id.'(,|$)\'
';
        } else {
            $query .= '
    AND id = '.$category_id.'
';
        }
    }
    $query .= '
;';
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_assoc($result)) {
        $cat_ids[] = $row['id'];
    }

    if (count($cat_ids) == 0) {
        return [];
    }

    $query = '
SELECT id, path, representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE storage_category_id IN ('.implode(',', $cat_ids).')';
    if ($only_new) {
        $query .= '
    AND date_metadata_update IS NULL
';
    }
    $query .= '
;';
    return query2array($query, 'id');
}

/**
 * Returns the list of keywords (future tags) correctly separated with
 * commas. Other separators are converted into commas.
 *
 * @param string $keywords_string
 */
function metadata_normalize_keywords_string($keywords_string): string
{
    $keywords_string = preg_replace(\Piwigo\Core\Config::metadataKeywordSeparatorRegex(), ',', $keywords_string);
    // new lines are always considered as keyword separators
    $keywords_string = str_replace(["\r\n", "\n", "\r"], ',', $keywords_string ?? '');
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
