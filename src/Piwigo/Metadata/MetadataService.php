<?php

declare(strict_types=1);

namespace Piwigo\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CharsetHelper;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Metadata\Event\CleanIptcValue;
use Piwigo\Metadata\ExifTool\ExifToolProcess;
use Piwigo\Metadata\Projection\MetadataImage;
use Piwigo\Metadata\Projection\SvgDimensions;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use SimpleXMLElement;

/**
 * Pure computation over raw EXIF/IPTC/SVG file data -- extraction, SVG
 * dimension parsing, keyword normalization -- plus the 2 orchestrator
 * methods (`syncMetadata()`/`getFilelist()`) that call
 * {@see MetadataRepository} for their own DB access.
 *
 * EXIF/IPTC extraction goes through {@see \Piwigo\Metadata\ExifTool\
 * ExifToolProcess} (real ExifTool, batched via its `-stay_open` protocol),
 * not PHP's own `exif_read_data()`/`iptcparse()` -- those have no XMP
 * support at all, an incomplete tag dictionary (confirmed live: a real
 * `LensModel` tag came back as `UndefinedTag:0xA434` from
 * `exif_read_data()`), and JPEG/TIFF-only format coverage. Every caller
 * supplies its own `ExifToolProcess` explicitly (no hidden/mutable state
 * on this `readonly` class) so a whole sync batch, or a single picture
 * page's own one-image lookup, can share one persistent process rather
 * than spawning one per file.
 *
 * [SEC-20] `getSyncMetadata()`'s SVG dimension parsing strips any
 * `<!DOCTYPE ...>` declaration before calling `simplexml_load_string()`
 * (with `LIBXML_NONET`, never `LIBXML_NOENT`/`LIBXML_DTDLOAD`), an XXE
 * mitigation. Confirmed this protection has no ExifTool-side gap either:
 * handing the exact malicious XXE SVG this class's own test suite already
 * exercises to a real ExifTool process returns the literal, unresolved
 * `&xxe;` entity text (not the referenced file's content) and completes
 * immediately -- ExifTool's own XML parsing doesn't resolve external
 * entities by default.
 */
final readonly class MetadataService
{
    /**
     * Translates a legacy IPTC IIM Record 2 (Application Record) dataset
     * number (Piwigo's own historic `2#NNN` config convention --
     * `useIptcMapping`/`showIptcMapping`) to ExifTool's own IPTC tag name.
     * Confirmed against real ExifTool 13.50 output for every entry
     * (round-tripped through a real tagged test file), not assumed from
     * the IPTC-NAA IIM standard alone -- ExifTool has no numeric
     * record:dataset addressing of its own (`-IPTC:2:25` returns nothing
     * at all, confirmed live), so a translation table is the only way to
     * keep existing admin-configured mappings working. Covers every
     * standard Record 2 dataset, not just this codebase's own 6 configured
     * defaults, so an admin's own arbitrary `2#NNN` entry keeps working
     * too.
     *
     * @var array<string, string>
     */
    private const array IPTC_TAG_TRANSLATION = [
        '2#005' => 'ObjectName',
        '2#010' => 'Urgency',
        '2#015' => 'Category',
        '2#020' => 'SupplementalCategories',
        '2#025' => 'Keywords',
        '2#040' => 'SpecialInstructions',
        '2#055' => 'DateCreated',
        '2#060' => 'TimeCreated',
        '2#080' => 'By-line',
        '2#085' => 'By-lineTitle',
        '2#090' => 'City',
        '2#092' => 'Sub-location',
        '2#095' => 'Province-State',
        '2#100' => 'Country-PrimaryLocationCode',
        '2#101' => 'Country-PrimaryLocationName',
        '2#103' => 'OriginalTransmissionReference',
        '2#105' => 'Headline',
        '2#110' => 'Credit',
        '2#115' => 'Source',
        '2#116' => 'CopyrightNotice',
        '2#118' => 'Contact',
        '2#120' => 'Caption-Abstract',
        '2#122' => 'Writer-Editor',
    ];

    /**
     * Translates PHP `exif_read_data()`'s own `COMPUTED;X` section-
     * addressed fields (a PHP-extension-specific convention
     * `showExifFields`'s own `'COMPUTED;ApertureFNumber'` default uses) to
     * ExifTool's closest equivalent tag. `null` marks a field with no
     * honest ExifTool equivalent -- kept explicit so a future reader knows
     * this is a deliberate gap, not an oversight (`IsColor`/
     * `ByteOrderMotorola` are PHP-exif-specific computed flags/heuristics
     * with nothing comparable in ExifTool). A field with no entry at all
     * here falls through to being requested as a literal tag name, correct
     * for any real EXIF/IFD tag configured under a PHP section prefix
     * other than `COMPUTED` -- those other sections are just PHP's own
     * organizational labels over the same tags ExifTool already recognizes
     * by their bare name.
     *
     * @var array<string, ?string>
     */
    private const array COMPUTED_TAG_TRANSLATION = [
        'ApertureFNumber' => 'Aperture',
        'Height' => 'ImageHeight',
        'Width' => 'ImageWidth',
        'IsColor' => null,
        'ByteOrderMotorola' => null,
    ];

    public function __construct(
        private Lang $lang,
        private MetadataRepository $repo,
        private CurrentLogger $currentLogger,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private Paths $paths,
    ) {}

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    public function getIptcData(string $filename, array $map, ExifToolProcess $exifTool, string $arraySep = ','): array
    {
        $result = [];

        if ($map === []) {
            return $result;
        }

        $tagNamesByIptcCode = [];
        foreach ($map as $iptcCode) {
            $tagNamesByIptcCode[$iptcCode] = self::IPTC_TAG_TRANSLATION[$iptcCode] ?? $iptcCode;
        }

        $row = $exifTool->read($filename, array_values(array_unique($tagNamesByIptcCode)));
        if ($row === null) {
            return $result;
        }

        foreach ($map as $pwgKey => $iptcCode) {
            $tagName = $tagNamesByIptcCode[$iptcCode];
            if (! isset($row[$tagName])) {
                continue;
            }

            $rawValue = $row[$tagName];
            if (is_array($rawValue)) {
                // Multi-value IPTC field (e.g. Keywords) -- ExifTool's own
                // `-a` flag (always passed by ExifToolProcess) returns
                // every repeated dataset instance as an array, matching
                // the original iptcparse()-based array-join shape exactly.
                $stringValues = array_values(array_filter($rawValue, is_string(...)));
                $value = implode($arraySep, array_map($this->cleanIptcValue(...), $stringValues));
            } elseif (is_string($rawValue)) {
                $value = $this->cleanIptcValue($rawValue);
            } elseif (is_scalar($rawValue)) {
                $value = $this->cleanIptcValue((string) $rawValue);
            } else {
                continue;
            }

            $result[$pwgKey] = $value;

            if (! $this->currentConfig->allowHtmlInMetadata) {
                // photo origin is unsecured (user upload) -- strip HTML to
                // avoid XSS.
                $result[$pwgKey] = strip_tags($result[$pwgKey]);
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
            $value = $this->eventDispatcher->dispatch(new CleanIptcValue($value))
                ->value;

            $qual = StringHelper::qualifyUtf8($value);
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

                $convertedValue = CharsetHelper::convertCharset($value, $inputEncoding, 'utf-8');
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
     * $result's values are genuinely arbitrary by design -- each configured
     * field's real type depends on which EXIF tag $map requests (string,
     * numeric, or a GPS coordinate's own float value).
     *
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    public function getExifData(string $filename, array $map, ExifToolProcess $exifTool): array
    {
        $logger = $this->currentLogger->get();
        $result = [];

        // Resolve every $map value to the real ExifTool tag name to
        // request, keeping track of which of $map's own keys each
        // resolves back onto -- multiple keys may legitimately request
        // the same underlying tag, same as the original per-key
        // independent lookups.
        $resolvedTagNameByKey = [];
        foreach ($map as $key => $field) {
            $resolvedTagNameByKey[$key] = self::resolveExifToolTagName($field);
        }

        // GPS coordinates are always attempted regardless of $map, same
        // as the original's own unconditional GPS block -- ExifTool's `#`
        // (numeric) suffix returns already-signed decimal degrees directly
        // (confirmed live: a real South/West test file came back negative
        // without any extra sign handling needed), so there's no DMS-to-
        // decimal math to port from the original parseExifGpsData().
        $tagNames = array_values(array_unique([
            ...array_values($resolvedTagNameByKey),
            'GPSLatitude#',
            'GPSLongitude#',
        ]));

        $row = $exifTool->read($filename, $tagNames);
        if ($row === null) {
            return $result;
        }

        foreach ($resolvedTagNameByKey as $key => $tagName) {
            if (isset($row[$tagName])) {
                $result[$key] = $row[$tagName];
            }
        }

        // ExifTool's own JSON output keys drop the `#` request-time
        // modifier -- confirmed live: requesting "GPSLatitude#" comes back
        // keyed just "GPSLatitude" (still holding the numeric, not
        // human-formatted, value the `#` asked for).
        $latitude = $row['GPSLatitude'] ?? null;
        $longitude = $row['GPSLongitude'] ?? null;
        if (is_numeric($latitude) && is_numeric($longitude)) {
            $latitude = (float) $latitude;
            $longitude = (float) $longitude;

            if ($latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0) {
                $result['latitude'] = $latitude;
                $result['longitude'] = $longitude;
            } else {
                $logger->info('[getExifData][filename=' . $filename . '] invalid GPS coordinates, latitude=' . (string) $latitude . ' longitude=' . (string) $longitude);
            }
        }

        if (! $this->currentConfig->allowHtmlInMetadata) {
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

    /**
     * One `$map` value (e.g. `'Make'`, `'COMPUTED;ApertureFNumber'`) to the
     * real ExifTool tag name to request for it.
     */
    private static function resolveExifToolTagName(string $field): string
    {
        if (! str_contains($field, ';')) {
            return $field;
        }

        [$section, $name] = explode(';', $field, 2);
        if ($section !== 'COMPUTED') {
            return $name;
        }

        return self::COMPUTED_TAG_TRANSLATION[$name] ?? $name;
    }

    public function stripHtmlInMetadata(mixed &$v, int|string $k): void
    {
        $v = strip_tags(is_scalar($v) ? (string) $v : '');
    }

    /**
     * @return array<string, string>
     */
    public function getSyncIptcData(string $file, ExifToolProcess $exifTool): array
    {

        $map = $this->stringMap($this->currentConfig->useIptcMapping);

        $iptc = $this->getIptcData($file, $map, $exifTool);

        foreach ($iptc as $pwgKey => $value) {
            if (in_array($pwgKey, ['date_creation', 'date_available'], true)) {
                // \D? between each group -- confirmed live that ExifTool
                // always reformats an IPTC date tag (e.g. DateCreated) to
                // "YYYY:MM:DD" rather than passing through the IIM
                // standard's own raw "YYYYMMDD" digit string the way
                // iptcparse() used to. Matches both shapes (and any other
                // single-character separator) rather than depending on
                // exactly which one the underlying extraction mechanism
                // happens to produce.
                if ((bool) preg_match('/(\d{4})\D?(\d{2})\D?(\d{2})/', $value, $matches)) {
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

        return $iptc;
    }

    /**
     * @return array<string, string>
     */
    public function getSyncExifData(string $file, ExifToolProcess $exifTool): array
    {

        $map = $this->stringMap($this->currentConfig->useExifMapping);

        $exif = $this->getExifData($file, $map, $exifTool);
        $result = [];

        foreach ($exif as $pwgKey => $value) {
            $valueStr = is_scalar($value) ? (string) $value : '';
            $current = $value;

            if (in_array($pwgKey, ['date_creation', 'date_available'], true)) {
                if ((bool) preg_match('/^(\d{4}).(\d{2}).(\d{2}) (\d{2}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                    $current = $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                    if ($current === '0000-00-00 00:00:00') {
                        $current = null;
                    }
                } elseif ((bool) preg_match('/^(\d{4}).(\d{2}).(\d{2})/', $valueStr, $matches)) {
                    $current = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                } else {
                    continue;
                }
            }

            if (in_array($pwgKey, ['keywords', 'tags'], true)) {
                $current = $this->metadataNormalizeKeywordsString($valueStr);
            }

            $isEmpty = $current === null || $current === '' || $current === 0 || $current === '0' || $current === false || $current === [];
            if ($isEmpty) {
                continue;
            }

            $result[$pwgKey] = is_scalar($current) ? (string) $current : '';
        }

        return $result;
    }

    /**
     * @return string[]
     */
    public function getSyncMetadataAttributes(): array
    {

        $updateFields = ['filesize', 'width', 'height'];

        if ($this->currentConfig->useExif) {
            $updateFields = array_merge(
                $updateFields,
                array_map(strval(...), array_keys($this->stringMap($this->currentConfig->useExifMapping))),
                ['latitude', 'longitude']
            );
        }

        if ($this->currentConfig->useIptc) {
            $updateFields = array_merge(
                $updateFields,
                array_map(strval(...), array_keys($this->stringMap($this->currentConfig->useIptcMapping)))
            );
        }

        return array_values(array_unique($updateFields));
    }

    /**
     * $infos is a cross-domain generic image row, same rationale as
     * SrcImage::__construct(); the return widens it with computed
     * filesize/width/height plus getExifData()/getIptcData()'s own
     * by-design arbitrary metadata values.
     *
     * @param  array<string, mixed>  $infos  (path[, representative_ext])
     * @return array<string, mixed>|false includes data provided in
     *   $infos, or false if the file's size can't be read
     */
    public function getSyncMetadata(array $infos, ExifToolProcess $exifTool): array|false
    {

        $path = $infos['path'] ?? null;
        $path = is_string($path) ? $path : '';
        // `path` is root-relative for uploaded photos (UploadService's own
        // storage-relative convention) but already absolute for locally
        // site-synced photos (LocalSiteReader/SiteUpdateSubController --
        // `galleries_url` itself is seeded as an absolute path by
        // InstallWizard/install.php, unlike legacy Piwigo's relative
        // PHPWG_ROOT_PATH). Prepending the root a second time onto an
        // already-absolute path produced an unreadable, doubled-up path:
        // metadata sync silently failed with "File/directory
        // read error" for every photo synced via the "Synchronize" tool on a
        // fresh install.
        $originalFile = str_starts_with($path, '/') ? $path : $this->paths->root . $path;
        $file = $originalFile;
        if (! is_readable($file)) {
            return false;
        }

        $fs = filesize($file);
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
            $file = ImagePathHelper::originalToRepresentative($file, $representativeExt);
        }

        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($file);

            if ($mimeType !== false && str_starts_with($mimeType, 'image/')) {
                if (in_array($mimeType, ['image/svg+xml', 'image/svg'], true)) {
                    $svgSize = $this->parseSvgDimensions($file);
                    if ($svgSize instanceof SvgDimensions) {
                        $infos['width'] = $svgSize->width;
                        $infos['height'] = $svgSize->height;
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
            $file = $originalFile;
        }

        if ($this->currentConfig->useExif) {
            $infos = array_merge($infos, $this->getSyncExifData($file, $exifTool));
        }

        if ($this->currentConfig->useIptc) {
            $infos = array_merge($infos, $this->getSyncIptcData($file, $exifTool));
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
     * flags that would re-enable entity substitution). Consumes a
     * bracketed internal subset correctly (P44-I) -- the previous
     * `[^>]*` stopped at the first `>`, which can sit inside that
     * subset, leaving a mangled remnant that failed to parse. Unlike
     * `UploadService::sanitizeSvgIfNeeded()`'s sibling fix, a parse
     * failure here stays a benign "no dimensions extracted" (`null`),
     * not a security decision -- this method never decides whether an
     * upload is safe to store.
     */
    private function parseSvgDimensions(string $file): ?SvgDimensions
    {
        $xml = file_get_contents($file);
        if ($xml === false) {
            return null;
        }

        $xml = preg_replace('/<!DOCTYPE[^>\[]*(\[[^\]]*\])?[^>]*>/is', '', $xml);
        if ($xml === null) {
            return null;
        }

        // libxml_use_internal_errors() (not @) is the correct way to parse
        // possibly-malformed/adversarial XML without a PHP-level warning --
        // malformed input (or a mangled post-DOCTYPE-strip remnant) is an
        // expected outcome here, not a bug to suppress after the fact.
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $svg = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);
        if ($svg === false) {
            return null;
        }

        $attributes = $svg->attributes();
        if ($attributes === null) {
            return null;
        }
        $widthAttr = $attributes->width;
        $heightAttr = $attributes->height;
        $viewBox = explode(' ', (string) $attributes->viewBox);

        $width = isset($widthAttr) && (string) $widthAttr !== ''
            ? (int) $widthAttr
            : (int) round((float) ($viewBox[2] ?? 0));
        $height = isset($heightAttr) && (string) $heightAttr !== ''
            ? (int) $heightAttr
            : (int) round((float) ($viewBox[3] ?? 0));

        return new SvgDimensions($width, $height);
    }

    /**
     * Sync all metadata of a list of images. Metadata are fetched from
     * original files and saved in database.
     *
     * Opens one {@see ExifToolProcess} for the whole batch (closed in
     * `finally`) rather than one per image -- the actual reason this
     * batches: a persistent process avoids paying Perl's own interpreter
     * boot + module load cost per file (benchmarked: 200 files, 37.5s
     * naive per-file spawn vs 1.79s reused across one process).
     *
     * @param  list<int>  $ids
     */
    public function syncMetadata(array $ids, PermissionService $permissionService, EntityManagerInterface $entityManager): void
    {
        // Reuse the DB-consistent CURRENT_DATE when a real top-level definer
        // (install.php/upgrade.php -- not src/Piwigo/, which itself never
        // calls define(), see tests/Arch/StructuralTest.php) already set it
        // earlier in this same request; otherwise fall back to today's date
        // locally. This fallback's real callers are batch_manager_unit.php
        // and picture_modify.php.
        $definedCurrentDate = defined('CURRENT_DATE') ? constant('CURRENT_DATE') : null;
        $currentDate = is_string($definedCurrentDate) ? $definedCurrentDate : date('Y-m-d');

        $datas = [];
        $tagsOf = [];

        // Inline-constructed rather than constructor-injected -- avoids
        // touching every existing `new MetadataService(...)` call site for
        // zero benefit. $tagServiceImageService is passed to setTagsOf()
        // below as an explicit argument, not to TagService's constructor
        // (TagService::$imageService is itself an explicit per-method
        // parameter, not a constructor property, for the same reasoning).
        $tagServiceCategoryService = new CategoryService($this->lang, new CategoryRepository($entityManager, $this->currentConfig), $permissionService, $this->currentConfig, $this->eventDispatcher, new Translator($this->currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), new AccessLevelChecker($this->currentUser, $this->currentConfig));
        $tagServiceImageService = new ImageService(TypedRepository::narrow($entityManager->getRepository(ImageEntity::class), ImageRepository::class), new ActivityService(TypedRepository::narrow($entityManager->getRepository(ActivityEntity::class), ActivityRepository::class)), $this->eventDispatcher, $this->currentConfig, $this->paths, $tagServiceCategoryService);
        $tagService = new TagService($this->lang, TypedRepository::narrow($entityManager->getRepository(TagEntity::class), TagRepository::class), $permissionService, new ActivityService(TypedRepository::narrow($entityManager->getRepository(ActivityEntity::class), ActivityRepository::class)), $this->eventDispatcher, $this->currentUser, $this->currentConfig, $this->currentLogger);

        $exifTool = new ExifToolProcess();

        try {
            foreach ($this->repo->findImagesByIds($ids) as $row) {
                $data = $this->getSyncMetadata($row->toArray(), $exifTool);
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
        } finally {
            $exifTool->close();
        }

        if (count($datas) > 0) {
            $updateFields = $this->getSyncMetadataAttributes();
            $updateFields[] = 'date_metadata_update';
            $updateFields = array_values(array_diff($updateFields, ['tags', 'keywords']));

            $this->repo->massUpdateImages($updateFields, $datas);
        }

        $tagService->setTagsOf($tagsOf, $tagServiceImageService);
    }

    /**
     * Returns an array associating element id (images.id) with its
     * complete path in the filesystem.
     *
     * @return array<int, array{id: int, path: string, representative_ext: ?string}>
     */
    public function getFilelist(int|string $categoryId = '', int $siteId = 1, bool $recursive = false, bool $onlyNew = false): array
    {
        $catIds = $this->repo->findCategoryIds($siteId, $categoryId, $recursive);

        if ($catIds === []) {
            return [];
        }

        // Both real callers (SiteUpdateSubController) treat each row as a
        // growable data bag, merging in more fields before it feeds their
        // own batch update -- converted back to array form here, at this
        // boundary, rather than widening their own dynamic shape to also
        // understand a real MetadataImage object.
        return array_map(
            static fn (MetadataImage $image): array => $image->toArray(),
            $this->repo->findImagesByStorageCategoryIds($catIds, $onlyNew)
        );
    }

    /**
     * Returns the list of keywords (future tags) correctly separated with
     * commas. Other separators are converted into commas.
     */
    public function metadataNormalizeKeywordsString(string $keywordsString): string
    {

        $separatorRegex = $this->currentConfig->metadataKeywordSeparatorRegex;

        $keywordsString = $separatorRegex === '' ? $keywordsString : preg_replace($separatorRegex, ',', $keywordsString);
        assert($keywordsString !== null);
        // new lines are always considered as keyword separators
        $keywordsString = str_replace(["\r\n", "\n", "\r"], ',', $keywordsString);
        $keywordsString = preg_replace('/,+/', ',', $keywordsString);
        $keywordsString = preg_replace('/^,+|,+$/', '', (string) $keywordsString);

        return implode(',', array_unique(explode(',', (string) $keywordsString)));
    }

    /**
     * $raw is an admin-configurable IPTC/EXIF mapping value (arbitrary
     * JSON by design, same rationale as ConfigService's own dynamic-key
     * config values) -- this method's whole job is defensively normalizing
     * it, so it can't itself assume a clean input.
     *
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
