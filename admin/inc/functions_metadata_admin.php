<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Piwigo\inc\functions;
use Piwigo\inc\functions_metadata;

final class functions_metadata_admin
{
    /**
     * Returns IPTC metadata to sync from a file, depending on IPTC mapping.
     * @todo : clean code (factorize foreach)
     */
    public static function get_sync_iptc_data(
        string $file,
        ?array $imginfo = null
    ): array {
        global $conf;

        $map = $conf->use_iptc_mapping;

        $iptc = functions_metadata::get_iptc_data($file, $map, ',', $imginfo);

        foreach ($iptc as $pwg_key => $value) {
            if (in_array($pwg_key, ['date_creation', 'date_available']) && preg_match('/(\d{4})(\d{2})(\d{2})/', $value, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                if (! checkdate((int) $month, (int) $day, (int) $year)) {
                    // we suppose the year is correct
                    $month = 1;
                    $day = 1;
                }

                $iptc[$pwg_key] = $year . '-' . $month . '-' . $day;
            }
        }

        if (isset($iptc['keywords'])) {
            $iptc['keywords'] = self::metadata_normalize_keywords_string($iptc['keywords']);
        }

        foreach (array_keys($iptc) as $pwg_key) {
            $iptc[$pwg_key] = addslashes($iptc[$pwg_key]);
        }

        return $iptc;
    }

    /**
     * Returns EXIF metadata to sync from a file, depending on EXIF mapping.
     */
    public static function get_sync_exif_data(
        string $file,
        ?array $imginfo = null
    ): array {
        global $conf;

        $exif = functions_metadata::get_exif_data($file, $conf->use_exif_mapping, $imginfo);

        foreach ($exif as $pwg_key => $value) {
            if (in_array($pwg_key, ['date_creation', 'date_available'])) {
                if (is_numeric($value) &&
                    (int) $value == $value
                ) {
                    // UNIX timestamp
                    $exif[$pwg_key] = \DateTime::createFromFormat('U', (string) $value)->format('Y-m-d H:i:s');

                    if ($exif[$pwg_key] === '0000-00-00' ||
                        ! self::validateDate($exif[$pwg_key])
                    ) {
                        $exif[$pwg_key] = null;
                    }
                } elseif (preg_match('/^(\d{4}).(\d{2}).(\d{2}).(\d{2}).(\d{2}).(\d{2})/', (string) $value, $matches)) {
                    // YYYY-MM-DDTHH:MM:SS
                    $exif[$pwg_key] = $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];

                    if ($exif[$pwg_key] === '0000-00-00 00:00:00' ||
                        ! self::validateDate($exif[$pwg_key])
                    ) {
                        $exif[$pwg_key] = null;
                    }
                } elseif (preg_match('/^(\d{4}).(\d{2}).(\d{2})/', $value, $matches)) {
                    // YYYY-MM-DD
                    $exif[$pwg_key] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];

                    if ($exif[$pwg_key] === '0000-00-00' ||
                        ! self::validateDate($exif[$pwg_key], 'Y-m-d')
                    ) {
                        $exif[$pwg_key] = null;
                    }
                } else {
                    unset($exif[$pwg_key]);
                    continue;
                }
            }

            if (in_array($pwg_key, ['keywords', 'tags'])) {
                $exif[$pwg_key] = self::metadata_normalize_keywords_string($exif[$pwg_key]);
            }

            if ($exif[$pwg_key] !== null &&
                ! is_numeric($exif[$pwg_key])
            ) {
                $exif[$pwg_key] = addslashes($exif[$pwg_key]);
            }
        }

        return $exif;
    }

    public static function validateDate(string $date, string $format = 'Y-m-d H:i:s'): bool
    {
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Get all potential file metadata fields, including IPTC and EXIF.
     *
     * @return array<string>
     */
    public static function get_sync_metadata_attributes(): array
    {
        global $conf;

        $update_fields = ['filesize', 'width', 'height'];

        if ($conf->use_exif) {
            $update_fields =
              array_merge(
                  $update_fields,
                  array_keys($conf->use_exif_mapping),
                  ['latitude', 'longitude']
              );
        }

        if ($conf->use_iptc) {
            $update_fields =
              array_merge(
                  $update_fields,
                  array_keys($conf->use_iptc_mapping)
              );
        }

        return array_unique($update_fields);
    }

    /**
     * Get all metadata of a file.
     *
     * @param array<string, int|string|null> $infos - (path[, representative_ext])
     * @return array<string, int|string|null>|bool|null - null means file unchanged (skip)
     */
    public static function get_sync_metadata(
        array $infos
    ): array|bool|null {
        global $conf, $logger;
        $file = './' . $infos['path'];
        $profiling = $conf->sync_profiling;

        if ($profiling) {
            $prof = [];
            $t = microtime(true);
        }

        // Use cached filesize from scan phase if available, otherwise stat the file
        if (isset($infos['fs_filesize'])) {
            $filesize_kb = (int) $infos['fs_filesize'];
        } else {
            $fs = filesize($file);

            if ($fs === false) {
                return false;
            }

            $filesize_kb = (int) floor($fs / 1024);
        }

        if ($profiling) {
            $prof['filesize'] = microtime(true) - $t;
        }

        // Skip full metadata extraction if file size hasn't changed since last sync
        if (isset($infos['filesize']) &&
            (int) $infos['filesize'] === $filesize_kb &&
            $infos['filesize'] > 0
        ) {
            return null; // signal: unchanged, skip this file
        }

        $infos['filesize'] = $filesize_kb;

        $is_tiff = false;
        $imginfo = [];

        if (isset($infos['representative_ext'])) {
            $image_size = getimagesize($file);

            if ($image_size) {
                $type = $image_size[2];

                if ($type == IMAGETYPE_TIFF_MM ||
                    $type == IMAGETYPE_TIFF_II
                ) {
                    $is_tiff = true;
                }
            }

            $file = functions::original_to_representative($file, $infos['representative_ext']);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'svg' || $ext === 'svgz') {
            $xml = file_get_contents($file);

            $xmlget = simplexml_load_string($xml);
            $xmlattributes = $xmlget->attributes();
            $width = (int) $xmlattributes->width;
            $height = (int) $xmlattributes->height;
            $vb = (string) $xmlattributes->viewBox;

            if (isset($width) &&
                $width != 0
            ) {
                $infos['width'] = $width;
            } elseif (isset($vb)) {
                $infos['width'] = explode(' ', $vb)[2];
            }

            if (isset($height) &&
                $height != 0
            ) {
                $infos['height'] = $height;
            } elseif (isset($vb)) {
                $infos['height'] = explode(' ', $vb)[3];
            }
        }

        // Single getimagesize call: dimensions + APP1/APP13 segments for EXIF/IPTC
        if ($profiling) {
            $t = microtime(true);
        }

        $image_size = getimagesize($file, $imginfo);

        if ($profiling) {
            $prof['getimagesize'] = microtime(true) - $t;
        }

        if ($image_size) {
            $infos['width'] = $image_size[0];
            $infos['height'] = $image_size[1];
        }

        // For TIFF: use representative for dimensions (above), original for EXIF/IPTC
        $exif_file = $is_tiff ? './' . $infos['path'] : $file;
        $exif_imginfo = $imginfo;

        if ($is_tiff) {
            getimagesize($exif_file, $exif_imginfo);
        }

        if ($conf->use_exif) {
            if ($profiling) {
                $t = microtime(true);
            }

            $exif = self::get_sync_exif_data($exif_file, $exif_imginfo);

            if ($profiling) {
                $prof['exif'] = microtime(true) - $t;
            }

            $infos = array_merge($infos, $exif);
        }

        if ($conf->use_iptc) {
            if ($profiling) {
                $t = microtime(true);
            }

            $iptc = self::get_sync_iptc_data($exif_file, $exif_imginfo);

            if ($profiling) {
                $prof['iptc'] = microtime(true) - $t;
            }

            $infos = array_merge($infos, $iptc);
        }

        foreach (['name', 'author'] as $single_line_field) {
            if (isset($infos[$single_line_field])) {
                $infos[$single_line_field] = str_replace(
                    ["\r\n", "\n"],
                    ' ',
                    $infos[$single_line_field]
                );
            }
        }

        if ($profiling) {
            $prof_rounded = array_map(fn ($v) => round($v, 5), $prof);
            $logger->debug('[sync][meta][breakdown] ' . $infos['path'], $prof_rounded);

            if (! isset($GLOBALS['sync_meta_prof'])) {
                $GLOBALS['sync_meta_prof'] = [];
            }

            foreach ($prof as $k => $v) {
                if (! isset($GLOBALS['sync_meta_prof'][$k])) {
                    $GLOBALS['sync_meta_prof'][$k] = ['total' => 0, 'count' => 0, 'max' => 0, 'max_file' => ''];
                }

                $GLOBALS['sync_meta_prof'][$k]['total'] += $v;
                $GLOBALS['sync_meta_prof'][$k]['count']++;

                if ($v > $GLOBALS['sync_meta_prof'][$k]['max']) {
                    $GLOBALS['sync_meta_prof'][$k]['max'] = $v;
                    $GLOBALS['sync_meta_prof'][$k]['max_file'] = $infos['path'];
                }
            }
        }

        return $infos;
    }

    /**
     * Sync all metadata of a list of images.
     * Metadata are fetched from original files and saved in database.
     *
     * @param array<int> $ids
     */
    public static function sync_metadata(
        array $ids
    ): void {
        global $conf;

        if (! defined('CURRENT_DATE')) {
            define('CURRENT_DATE', date('Y-m-d'));
        }

        $datas = [];
        $tags_of = [];
        $tag_names_by_image = [];

        $wrapped_ids = wordwrap(implode(', ', $ids), 160);
        $query = <<<SQL
            SELECT id, path, representative_ext, filesize
            FROM images
            WHERE id IN ({$wrapped_ids});
            SQL;

        $result = $conf->sql_backend::pwg_query($query);

        while ($data = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $data = self::get_sync_metadata($data);

            if ($data === false || $data === null) {
                continue;
            }

            $id = $data['id'];

            foreach (['keywords', 'tags'] as $key) {
                if (isset($data[$key])) {
                    if (! isset($tag_names_by_image[$id])) {
                        $tag_names_by_image[$id] = [];
                    }

                    foreach (explode(',', $data[$key]) as $tag_name) {
                        $tag_names_by_image[$id][] = trim($tag_name);
                    }
                }
            }

            $data['date_metadata_update'] = CURRENT_DATE;

            $datas[] = $data;
        }

        // Batch-resolve all tag names
        if ($tag_names_by_image !== []) {
            $all_tag_names = [];

            foreach ($tag_names_by_image as $img_tag_names) {
                foreach ($img_tag_names as $tn) {
                    $all_tag_names[] = $tn;
                }
            }

            $tag_name_to_id = functions_admin::batch_tag_ids_from_tag_names($all_tag_names);

            foreach ($tag_names_by_image as $img_id => $img_tag_names) {
                if (! isset($tags_of[$img_id])) {
                    $tags_of[$img_id] = [];
                }

                foreach ($img_tag_names as $tn) {
                    if (isset($tag_name_to_id[$tn])) {
                        $tags_of[$img_id][] = $tag_name_to_id[$tn];
                    }
                }
            }
        }

        if ($datas !== []) {
            $update_fields = self::get_sync_metadata_attributes();
            $update_fields[] = 'date_metadata_update';

            $update_fields = array_diff(
                $update_fields,
                ['tags', 'keywords']
            );

            $conf->sql_backend::mass_updates(
                'images',
                [
                    'primary' => ['id'],
                    'update' => $update_fields,
                ],
                $datas,
                $conf->sql_backend::MASS_UPDATES_SKIP_EMPTY
            );
        }

        functions_admin::set_tags_of($tags_of);
    }

    /**
     * Returns an array associating element id (images.id) with its complete
     * path in the filesystem
     */
    public static function get_filelist(
        string $category_id = '',
        int|string $site_id = 1,
        bool $recursive = false,
        bool $only_new = false
    ): array {
        global $conf;

        // filling $cat_ids : all categories required
        $cat_ids = [];

        $query = <<<SQL
            SELECT id
            FROM categories
            WHERE site_id = {$site_id}
                AND dir IS NOT NULL

            SQL;

        if (is_numeric($category_id)) {
            if ($recursive) {
                $regex_operator = $conf->sql_backend::DB_REGEX_OPERATOR;
                $query .= <<<SQL
                    AND uppercats {$regex_operator} '(^|,){$category_id}(,|$)'

                    SQL;
            } else {
                $query .= <<<SQL
                    AND id = {$category_id}

                    SQL;
            }
        }

        $query = trim($query) . ';';
        $result = $conf->sql_backend::pwg_query($query);

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $cat_ids[] = $row['id'];
        }

        if (count($cat_ids) == 0) {
            return [];
        }

        $imploded_cat_ids = implode(', ', $cat_ids);
        $query = <<<SQL
            SELECT id, path, representative_ext, filesize
            FROM images
            WHERE storage_category_id IN ({$imploded_cat_ids})

            SQL;

        if ($only_new) {
            $query .= <<<SQL
                AND date_metadata_update IS NULL

                SQL;
        }

        $query = trim($query) . ';';
        return $conf->sql_backend::query2array($query, 'id');
    }

    /**
     * Returns the list of keywords (future tags) correctly separated with
     * commas. Other separators are converted into commas.
     */
    public static function metadata_normalize_keywords_string(
        string $keywords_string
    ): string {
        global $conf;

        $keywords_string = preg_replace($conf->metadata_keyword_separator_regex, ',', $keywords_string);
        // new lines are always considered as keyword separators
        $keywords_string = str_replace(["\r\n", "\n"], ',', $keywords_string);
        $keywords_string = preg_replace('/,+/', ',', $keywords_string);
        $keywords_string = trim($keywords_string, ',');

        return implode(
            ',',
            array_unique(
                explode(
                    ',',
                    $keywords_string
                )
            )
        );
    }
}
