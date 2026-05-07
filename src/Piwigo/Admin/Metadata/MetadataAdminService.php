<?php

declare(strict_types=1);

namespace Piwigo\Admin\Metadata;

use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Image\ImageRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Db\Tables;

final class MetadataAdminService
{
    /**
     * @param array<string, string> $map
     * @return array<mixed>
     */
    public function getSyncIptcData(string $file, array $map): array
    {
        $iptc = ServiceLocator::get(MetadataService::class)->getIptcData($file, $map);

        foreach ($iptc as $pwg_key => $value) {
            if (in_array($pwg_key, ['date_creation', 'date_available'])) {
                if (is_scalar($value) && preg_match('/(\d{4})(\d{2})(\d{2})/', (string) $value, $matches)) {
                    $year = $matches[1];
                    $month = $matches[2];
                    $day = $matches[3];
                    if (!checkdate((int) $month, (int) $day, (int) $year)) {
                        $month = 1;
                        $day = 1;
                    }
                    $iptc[$pwg_key] = $year . '-' . $month . '-' . $day;
                }
            }
        }

        if (isset($iptc['keywords'])) {
            $iptc['keywords'] = $this->normalizeKeywordsString(is_scalar($iptc['keywords']) ? (string) $iptc['keywords'] : '');
        }

        foreach ($iptc as $pwg_key => $value) {
            $iptc[$pwg_key] = addslashes(is_scalar($iptc[$pwg_key]) ? (string) $iptc[$pwg_key] : '');
        }

        return $iptc;
    }

    /** @return array<mixed> */
    public function getSyncExifData(string $file): array
    {
        $exif = ServiceLocator::get(MetadataService::class)->getExifData($file, Config::useExifMapping());

        foreach ($exif as $pwg_key => $value) {
            if (in_array($pwg_key, ['date_creation', 'date_available'])) {
                $valueStr = is_scalar($value) ? (string) $value : '';
                if (preg_match('/^(\d{4}).(\d{2}).(\d{2}) (\d{2}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                    $exif[$pwg_key] = $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                    if ($exif[$pwg_key] == '0000-00-00 00:00:00') {
                        $exif[$pwg_key] = null;
                    }
                } elseif (preg_match('/^(\d{4}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                    $exif[$pwg_key] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                } else {
                    unset($exif[$pwg_key]);
                    continue;
                }
            }

            if (in_array($pwg_key, ['keywords', 'tags'])) {
                $exif[$pwg_key] = $this->normalizeKeywordsString(is_scalar($exif[$pwg_key]) ? (string) $exif[$pwg_key] : '');
            }

            if (empty($exif[$pwg_key])) {
                unset($exif[$pwg_key]);
                continue;
            }

            $exif[$pwg_key] = addslashes(is_scalar($exif[$pwg_key]) ? (string) $exif[$pwg_key] : '');
        }

        return $exif;
    }

    /** @return string[] */
    public function getSyncMetadataAttributes(): array
    {
        $update_fields = ['filesize', 'width', 'height'];

        if (Config::useExif()) {
            $update_fields = array_merge(
                $update_fields,
                array_keys(Config::useExifMapping()),
                ['latitude', 'longitude']
            );
        }

        if (Config::useIptc()) {
            $update_fields = array_merge(
                $update_fields,
                array_keys(Config::useIptcMapping())
            );
        }

        return array_unique($update_fields);
    }

    /**
     * @param array<mixed> $infos
     * @return array<mixed>|false
     */
    public function getSyncMetadata(array $infos): array|false
    {
        $file = PHPWG_ROOT_PATH . (is_scalar($infos['path'] ?? null) ? (string) $infos['path'] : '');
        $fs = Filesystem::tryFilesize($file);

        if ($fs === false) {
            return false;
        }

        $infos['filesize'] = floor($fs / 1024);
        $is_tiff = false;

        if (isset($infos['representative_ext'])) {
            if (is_readable($file) && ($image_size = pwg_safe_getimagesize($file))) {
                $type = $image_size[2];
                if (IMAGETYPE_TIFF_MM == $type || IMAGETYPE_TIFF_II == $type) {
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
                    $vb = (string) ($xmlattributes->viewBox ?? '');

                    if (isset($width) && $width != '') {
                        $infos['width'] = (int) $width;
                    } elseif ($vb !== '') {
                        $infos['width'] = round((float) explode(' ', $vb)[2]);
                    }

                    if (isset($height) && $height != '') {
                        $infos['height'] = (int) $height;
                    } elseif ($vb !== '') {
                        $infos['height'] = round((float) explode(' ', $vb)[3]);
                    }
                }
                if (is_readable($file) && ($image_size = pwg_safe_getimagesize($file))) {
                    $infos['width'] = $image_size[0];
                    $infos['height'] = $image_size[1];
                }
            }
        }

        if ($is_tiff) {
            $file = PHPWG_ROOT_PATH . (is_scalar($infos['path'] ?? null) ? (string) $infos['path'] : '');
        }

        if (Config::useExif()) {
            $exif = $this->getSyncExifData($file);
            $infos = array_merge($infos, $exif);
        }

        if (Config::useIptc()) {
            $iptc = $this->getSyncIptcData($file, Config::useIptcMapping());
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

    /** @param int[] $ids */
    public function syncMetadata(array $ids): void
    {
        if (!defined('CURRENT_DATE')) {
            define('CURRENT_DATE', date('Y-m-d'));
        }

        $datas = [];
        $tags_of = [];

        foreach (ServiceLocator::get(ImageRepository::class)->findByIds(array_map(intval(...), $ids)) as $data) {
            $data = $this->getSyncMetadata($data);
            if ($data === false) {
                continue;
            }

            $id = is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0;
            foreach (['keywords', 'tags'] as $key) {
                if (isset($data[$key])) {
                    if (!isset($tags_of[$id])) {
                        $tags_of[$id] = [];
                    }
                    foreach (explode(',', is_scalar($data[$key]) ? (string) $data[$key] : '') as $tag_name) {
                        $tags_of[$id][] = ServiceLocator::get(TagAdminService::class)->tagIdFromTagName($tag_name);
                    }
                }
            }

            $data['date_metadata_update'] = CURRENT_DATE;
            $datas[] = $data;
        }

        if (count($datas) > 0) {
            $update_fields = $this->getSyncMetadataAttributes();
            $update_fields[] = 'date_metadata_update';
            $update_fields = array_diff($update_fields, ['tags', 'keywords']);

            Dml::massUpdates(
                Tables::images(),
                ['primary' => ['id'], 'update' => $update_fields],
                $datas,
                Dml::SKIP_EMPTY
            );
        }

        ServiceLocator::get(TagAdminService::class)->setTagsOf($tags_of);
    }

    /** @return array<mixed> */
    public function getFilelist(
        int|string $category_id = '',
        int $site_id = 1,
        bool $recursive = false,
        bool $only_new = false
    ): array {
        $cat_ids = [];

        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE site_id = ' . $site_id . '
    AND dir IS NOT NULL';
        if (is_numeric($category_id)) {
            if ($recursive) {
                $query .= '
    AND uppercats ' . Dml::REGEX_OPERATOR . " '(^|,)" . $category_id . "(,|$)'";
            } else {
                $query .= '
    AND id = ' . $category_id;
            }
        }
        $query .= ';';

        foreach (DbConnection::get()->executeQuery($query)->fetchAllAssociative() as $row) {
            $cat_ids[] = $row['id'];
        }

        if (count($cat_ids) == 0) {
            return [];
        }

        $query = '
SELECT id, path, representative_ext
  FROM ' . Tables::images() . '
  WHERE storage_category_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $cat_ids)) . ')';
        if ($only_new) {
            $query .= '
    AND date_metadata_update IS NULL';
        }
        $query .= ';';

        return array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'id');
    }

    public function normalizeKeywordsString(string $keywordsString): string
    {
        $keywordsString = preg_replace(Config::metadataKeywordSeparatorRegex(), ',', $keywordsString);
        $keywordsString = str_replace(["\r\n", "\n", "\r"], ',', $keywordsString ?? '');
        $keywordsString = preg_replace('/,+/', ',', $keywordsString);
        $keywordsString = preg_replace('/^,+|,+$/', '', (string) $keywordsString);

        return implode(',', array_unique(explode(',', (string) $keywordsString)));
    }
}
