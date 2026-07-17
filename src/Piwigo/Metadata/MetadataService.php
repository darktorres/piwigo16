<?php

declare(strict_types=1);

namespace Piwigo\Metadata;

use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;

/**
 * Pure computation ported from `admin/include/functions_metadata.php`
 * (7 functions) + `include/functions_metadata.inc.php` (5 functions) --
 * both deleted in P23 sub-batch 8b-1, their real callers retargeted
 * directly here -- raw EXIF/IPTC extraction, SVG dimension parsing, GPS
 * math, keyword normalization -- plus the 2 orchestrator methods
 * (`syncMetadata()`/`getFilelist()`) that call {@see MetadataRepository}
 * for their own DB access.
 *
 * [SEC-20] `getSyncMetadata()`'s SVG dimension parsing strips any
 * `<!DOCTYPE ...>` declaration before calling `simplexml_load_string()`
 * (with `LIBXML_NONET`, never `LIBXML_NOENT`/`LIBXML_DTDLOAD`) -- the
 * original called `simplexml_load_string($xml)` on raw, uploaded-file
 * content with zero flags and no DOCTYPE stripping, an XXE vector.
 */
final class MetadataService
{
    public function __construct(
        private readonly MetadataRepository $repo,
    ) {}

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    public function getIptcData(string $filename, array $map, string $arraySep = ','): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $result = [];

        $imginfo = [];
        if (@getimagesize($filename, $imginfo) === false) {
            return $result;
        }

        /** @var array<string, mixed> $imginfo */
        if (isset($imginfo['APP13']) && is_string($imginfo['APP13'])) {
            $iptc = iptcparse($imginfo['APP13']);
            if (is_array($iptc)) {
                $rmap = array_flip($map);
                foreach (array_keys($rmap) as $iptcKey) {
                    if (! isset($iptc[$iptcKey][0])) {
                        continue;
                    }

                    if ($iptcKey === '2#025') {
                        $value = implode($arraySep, array_map($this->cleanIptcValue(...), $iptc[$iptcKey]));
                    } else {
                        $value = $this->cleanIptcValue($iptc[$iptcKey][0]);
                    }

                    foreach (array_keys($map, $iptcKey, true) as $pwgKey) {
                        $result[$pwgKey] = $value;

                        if (! (bool) ($conf['allow_html_in_metadata'] ?? false)) {
                            // photo origin is unsecured (user upload) --
                            // strip HTML to avoid XSS.
                            $result[$pwgKey] = strip_tags($result[$pwgKey]);
                        }
                    }
                }
            }
        }

        return $result;
    }

    public function cleanIptcValue(string $value): string
    {
        // strip leading zeros (weird Kodak Scanner software)
        while (isset($value[0]) && $value[0] === chr(0)) {
            $value = substr($value, 1);
        }

        // remove binary nulls
        $value = str_replace(chr(0x00), ' ', $value);

        if ((bool) preg_match('/[\x80-\xff]/', $value)) {
            // apparently mac uses some MacRoman crap encoding -- no
            // reliable way to detect it, a plugin should do the trick.
            $changedValue = trigger_change('clean_iptc_value', $value);
            if (is_string($changedValue)) {
                $value = $changedValue;
            }

            $qual = \Piwigo\Core\StringHelper::qualifyUtf8($value);
            if ($qual !== 0) { // has non-ascii chars
                if ($qual > 0) {
                    $inputEncoding = 'utf-8';
                } else {
                    $inputEncoding = 'iso-8859-1';
                    if (function_exists('iconv') || function_exists('mb_convert_encoding')) {
                        // windows-1252 supports additional characters such
                        // as "oe" in a single character (ligature); the
                        // 0x80-0x9F range diverges from real ISO-8859-1
                        // but those are control characters almost never used.
                        $inputEncoding = 'windows-1252';
                    }
                }

                $convertedValue = \Piwigo\Core\CharsetHelper::convertCharset($value, $inputEncoding, \Piwigo\Core\CharsetHelper::getPwgCharset());
                // convert_charset() can fail (iconv()/mb_convert_encoding()
                // returning false on malformed input) -- keep the
                // unconverted value rather than propagating false.
                if (is_string($convertedValue)) {
                    $value = $convertedValue;
                }
            }
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    public function getExifData(string $filename, array $map): array
    {
        /**
         * @var array<string, mixed> $conf
         * @var Logger $logger
         */
        global $conf, $logger;

        $result = [];

        if (! function_exists('exif_read_data')) {
            die('Exif extension not available, admin should disable exif use');
        }

        $exif = @exif_read_data($filename);
        $exif2 = (bool) $exif ? null : trigger_change('format_exif_data', null, $filename, $map);

        if ((bool) $exif || (bool) $exif2) {
            if ((bool) $exif2) {
                $exif = $exif2;
            } else {
                $exif = trigger_change('format_exif_data', $exif, $filename, $map);
            }

            if (! is_array($exif)) {
                $exif = [];
            }

            // configured fields
            foreach ($map as $key => $field) {
                if (! str_contains($field, ';')) {
                    if (isset($exif[$field])) {
                        $result[$key] = $exif[$field];
                    }
                } else {
                    $tokens = explode(';', $field);
                    $subValue = $exif[$tokens[0]] ?? null;
                    if (is_array($subValue) && isset($subValue[$tokens[1]])) {
                        $result[$key] = $subValue[$tokens[1]];
                    }
                }
            }

            // GPS data
            if (isset($exif['GPSLatitudeRef'], $exif['GPSLatitude'], $exif['GPSLongitudeRef'], $exif['GPSLongitude'])) {
                $latRaw = $exif['GPSLatitude'];
                $latRef = $exif['GPSLatitudeRef'];
                $lonRaw = $exif['GPSLongitude'];
                $lonRef = $exif['GPSLongitudeRef'];

                if (
                    is_array($latRaw) && is_string($latRef) && in_array($latRef, ['S', 'N'], true)
                    && is_array($lonRaw) && is_string($lonRef) && in_array($lonRef, ['W', 'E'], true)
                ) {
                    $latRaw = array_values(array_filter($latRaw, is_string(...)));
                    $lonRaw = array_values(array_filter($lonRaw, is_string(...)));

                    $latitude = $this->parseExifGpsData($latRaw, $latRef);
                    $longitude = $this->parseExifGpsData($lonRaw, $lonRef);

                    if ($latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0) {
                        $result['latitude'] = $latitude;
                        $result['longitude'] = $longitude;
                    } else {
                        $logger->info('[getExifData][filename=' . $filename . '] invalid GPS coordinates, latitude=' . $latitude . ' longitude=' . $longitude);
                    }
                }
            }
        }

        if (! (bool) ($conf['allow_html_in_metadata'] ?? false)) {
            foreach ($result as $key => $value) {
                // photo origin is unsecured (user upload) -- strip HTML to
                // avoid XSS.
                if (is_array($value)) {
                    array_walk_recursive($value, $this->stripHtmlInMetadata(...));
                    $result[$key] = $value;
                } else {
                    $result[$key] = strip_tags(is_scalar($value) ? (string) $value : '');
                }
            }
        }

        return $result;
    }

    public function stripHtmlInMetadata(mixed &$v, int|string $k): void
    {
        $v = strip_tags(is_scalar($v) ? (string) $v : '');
    }

    /**
     * @param  list<string>  $raw  eg: ['41/1', '54/1', '9843/500']
     * @param  string  $ref  'S', 'N', 'E', 'W'
     */
    public function parseExifGpsData(array $raw, string $ref): float|int
    {
        $parsed = [];
        foreach ($raw as $component) {
            $parts = explode('/', $component);
            $denominator = (float) ($parts[1] ?? '0');
            $parsed[] = $denominator === 0.0 ? 0.0 : (float) $parts[0] / $denominator;
        }

        $v = ($parsed[0] ?? 0) + ($parsed[1] ?? 0) / 60 + ($parsed[2] ?? 0) / 3600;

        $ref = strtoupper($ref);
        if ($ref === 'S' || $ref === 'W') {
            $v = -$v;
        }

        return $v;
    }

    /**
     * @return array<string, string>
     */
    public function getSyncIptcData(string $file): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $map = $this->stringMap($conf['use_iptc_mapping'] ?? null);

        $iptc = $this->getIptcData($file, $map);

        foreach ($iptc as $pwgKey => $value) {
            if (in_array($pwgKey, ['date_creation', 'date_available'], true)) {
                if ((bool) preg_match('/(\d{4})(\d{2})(\d{2})/', $value, $matches)) {
                    $year = (int) $matches[1];
                    $month = (int) $matches[2];
                    $day = (int) $matches[3];

                    if (! checkdate($month, $day, $year)) {
                        // we suppose the year is correct
                        $month = 1;
                        $day = 1;
                    }

                    $iptc[$pwgKey] = $year . '-' . $month . '-' . $day;
                }
            }
        }

        if (isset($iptc['keywords'])) {
            $iptc['keywords'] = $this->metadataNormalizeKeywordsString($iptc['keywords']);
        }

        foreach ($iptc as $pwgKey => $value) {
            $iptc[$pwgKey] = addslashes($value);
        }

        return $iptc;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSyncExifData(string $file): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $map = $this->stringMap($conf['use_exif_mapping'] ?? null);

        $exif = $this->getExifData($file, $map);

        foreach ($exif as $pwgKey => $value) {
            $valueStr = is_scalar($value) ? (string) $value : '';

            if (in_array($pwgKey, ['date_creation', 'date_available'], true)) {
                if ((bool) preg_match('/^(\d{4}).(\d{2}).(\d{2}) (\d{2}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                    $exif[$pwgKey] = $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                    if ($exif[$pwgKey] === '0000-00-00 00:00:00') {
                        $exif[$pwgKey] = null;
                    }
                } elseif ((bool) preg_match('/^(\d{4}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                    $exif[$pwgKey] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                } else {
                    unset($exif[$pwgKey]);

                    continue;
                }
            }

            if (in_array($pwgKey, ['keywords', 'tags'], true)) {
                $exif[$pwgKey] = $this->metadataNormalizeKeywordsString($valueStr);
            }

            $current = $exif[$pwgKey] ?? null;
            $isEmpty = $current === null || $current === '' || $current === 0 || $current === '0' || $current === false || $current === [];
            if ($isEmpty) {
                unset($exif[$pwgKey]);

                continue;
            }

            $exif[$pwgKey] = addslashes(is_scalar($current) ? (string) $current : '');
        }

        return $exif;
    }

    /**
     * @return string[]
     */
    public function getSyncMetadataAttributes(): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $updateFields = ['filesize', 'width', 'height'];

        if ((bool) ($conf['use_exif'] ?? false)) {
            $updateFields = array_merge(
                $updateFields,
                array_map(strval(...), array_keys($this->stringMap($conf['use_exif_mapping'] ?? null))),
                ['latitude', 'longitude']
            );
        }

        if ((bool) ($conf['use_iptc'] ?? false)) {
            $updateFields = array_merge(
                $updateFields,
                array_map(strval(...), array_keys($this->stringMap($conf['use_iptc_mapping'] ?? null)))
            );
        }

        return array_values(array_unique($updateFields));
    }

    /**
     * @param  array<string, mixed>  $infos  (path[, representative_ext])
     * @return array<string, mixed>|false includes data provided in
     *   $infos, or false if the file's size can't be read
     */
    public function getSyncMetadata(array $infos): array|false
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

        $isTiff = false;

        if (isset($infos['representative_ext'])) {
            $imageSize = @getimagesize($file);
            if ($imageSize !== false) {
                $type = $imageSize[2];
                if ($type === IMAGETYPE_TIFF_MM || $type === IMAGETYPE_TIFF_II) {
                    // for TIFF files, use the original file (not the
                    // representative) for EXIF/IPTC, but still need the
                    // representative for width/height.
                    $isTiff = true;
                }
            }

            $representativeExt = $infos['representative_ext'];
            $representativeExt = is_string($representativeExt) ? $representativeExt : '';
            $file = \Piwigo\Image\ImagePathHelper::originalToRepresentative($file, $representativeExt);
        }

        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($file);

            if ($mimeType !== false && str_starts_with($mimeType, 'image/')) {
                if (in_array($mimeType, ['image/svg+xml', 'image/svg'], true)) {
                    $svgSize = $this->parseSvgDimensions($file);
                    if ($svgSize !== null) {
                        [$infos['width'], $infos['height']] = $svgSize;
                    }
                }

                $imageSize = @getimagesize($file);
                if ($imageSize !== false) {
                    $infos['width'] = $imageSize[0];
                    $infos['height'] = $imageSize[1];
                }
            }
        }

        if ($isTiff) {
            // back to original file
            $file = PHPWG_ROOT_PATH . $path;
        }

        if ((bool) ($conf['use_exif'] ?? false)) {
            $infos = array_merge($infos, $this->getSyncExifData($file));
        }

        if ((bool) ($conf['use_iptc'] ?? false)) {
            $infos = array_merge($infos, $this->getSyncIptcData($file));
        }

        foreach (['name', 'author'] as $singleLineField) {
            if (isset($infos[$singleLineField])) {
                $fieldValue = $infos[$singleLineField];
                $fieldValue = is_string($fieldValue) ? $fieldValue : '';
                foreach (["\r\n", "\n", "\r"] as $toReplace) {
                    $fieldValue = str_replace($toReplace, ' ', $fieldValue);
                }

                $infos[$singleLineField] = $fieldValue;
            }
        }

        return $infos;
    }

    /**
     * [SEC-20] XXE-safe SVG width/height extraction -- strips any
     * `<!DOCTYPE ...>` declaration before parsing (defense in depth,
     * independent of the running libxml2 version's own external-entity
     * defaults) and never passes `LIBXML_NOENT`/`LIBXML_DTDLOAD` (the
     * flags that would re-enable entity substitution).
     *
     * @return array{0: int, 1: int}|null
     */
    private function parseSvgDimensions(string $file): ?array
    {
        $xml = file_get_contents($file);
        if ($xml === false) {
            return null;
        }

        $xml = preg_replace('/<!DOCTYPE[^>]*>/i', '', $xml);
        if ($xml === null) {
            return null;
        }

        $svg = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($svg === false) {
            return null;
        }

        $attributes = $svg->attributes();
        $widthAttr = $attributes->width;
        $heightAttr = $attributes->height;
        $viewBox = explode(' ', (string) $attributes->viewBox);

        $width = isset($widthAttr) && (string) $widthAttr !== ''
            ? (int) $widthAttr
            : (int) round((float) ($viewBox[2] ?? 0));
        $height = isset($heightAttr) && (string) $heightAttr !== ''
            ? (int) $heightAttr
            : (int) round((float) ($viewBox[3] ?? 0));

        return [$width, $height];
    }

    /**
     * Sync all metadata of a list of images. Metadata are fetched from
     * original files and saved in database.
     *
     * @param  list<int>  $ids
     */
    public function syncMetadata(array $ids): void
    {
        // Reuse the DB-consistent CURRENT_DATE when a real top-level definer
        // (install.php/upgrade.php -- not src/Piwigo/, which itself never
        // calls define(), see tests/Arch/StructuralTest.php) already set it
        // earlier in this same request; otherwise fall back to today's date
        // locally. admin/site_update.php (P23 batch 6j-5's own
        // SiteUpdateSubController) used to define this constant too, but
        // never actually called this method itself and, once absorbed into
        // src/Piwigo/, is bound by the same no-define() rule -- it now uses
        // its own local $dbnow directly instead, so this fallback's real
        // callers (batch_manager_unit.php, picture_modify.php) are
        // unaffected either way.
        $definedCurrentDate = defined('CURRENT_DATE') ? constant('CURRENT_DATE') : null;
        $currentDate = is_string($definedCurrentDate) ? $definedCurrentDate : date('Y-m-d');

        $datas = [];
        $tagsOf = [];

        // Inline-constructed rather than constructor-injected -- matches
        // SearchService::getElements()'s own established precedent for a
        // one-method-only TagService dependency, avoiding touching every
        // existing `new MetadataService(...)` call site for zero benefit.
        $tagConn = DbConnection::build();
        $tagService = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())));

        foreach ($this->repo->findImagesByIds($ids) as $row) {
            $data = $this->getSyncMetadata($row);
            if ($data === false) {
                continue;
            }

            $id = $data['id'] ?? null;
            if (! is_int($id) && ! is_string($id)) {
                // no usable primary key to associate tags with, skip
                // tagging for this row
                continue;
            }

            foreach (['keywords', 'tags'] as $key) {
                if (isset($data[$key])) {
                    if (! isset($tagsOf[$id])) {
                        $tagsOf[$id] = [];
                    }

                    $tagList = $data[$key];
                    $tagList = is_scalar($tagList) ? (string) $tagList : '';

                    foreach (explode(',', $tagList) as $tagName) {
                        $tagsOf[$id][] = $tagService->tagIdFromTagName($tagName);
                    }
                }
            }

            $data['date_metadata_update'] = $currentDate;

            $datas[] = $data;
        }

        if (count($datas) > 0) {
            $updateFields = $this->getSyncMetadataAttributes();
            $updateFields[] = 'date_metadata_update';
            $updateFields = array_diff($updateFields, ['tags', 'keywords']);

            \Piwigo\Db\MysqliDb::massUpdates(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => $updateFields,
                ],
                $datas,
                \Piwigo\Db\MysqliDb::MASS_UPDATES_SKIP_EMPTY
            );
        }

        $tagService->setTagsOf($tagsOf);
    }

    /**
     * Returns an array associating element id (images.id) with its
     * complete path in the filesystem.
     *
     * @return array<int, mixed>
     */
    public function getFilelist(int|string $categoryId = '', int $siteId = 1, bool $recursive = false, bool $onlyNew = false): array
    {
        $catIds = $this->repo->findCategoryIds($siteId, $categoryId, $recursive);

        if ($catIds === []) {
            return [];
        }

        return $this->repo->findImagesByStorageCategoryIds($catIds, $onlyNew);
    }

    /**
     * Returns the list of keywords (future tags) correctly separated with
     * commas. Other separators are converted into commas.
     */
    public function metadataNormalizeKeywordsString(string $keywordsString): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $separatorRegex = $conf['metadata_keyword_separator_regex'] ?? null;
        // matches the built-in default (config_default.inc.php) if the
        // config value is somehow missing/mistyped at runtime.
        $separatorRegex = is_string($separatorRegex) ? $separatorRegex : '/[.,;]/';

        $keywordsString = preg_replace($separatorRegex, ',', $keywordsString);
        assert($keywordsString !== null);
        // new lines are always considered as keyword separators
        $keywordsString = str_replace(["\r\n", "\n", "\r"], ',', $keywordsString);
        $keywordsString = preg_replace('/,+/', ',', $keywordsString);
        $keywordsString = preg_replace('/^,+|,+$/', '', (string) $keywordsString);

        return implode(',', array_unique(explode(',', (string) $keywordsString)));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $raw): array
    {
        $map = [];
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $map[$key] = $value;
                }
            }
        }

        return $map;
    }
}
