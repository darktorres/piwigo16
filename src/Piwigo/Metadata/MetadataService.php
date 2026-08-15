<?php

declare(strict_types=1);

namespace Piwigo\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityEntity;
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
use Piwigo\Event\Picture\CleanIptcValue;
use Piwigo\Event\Picture\FormatExifData;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Metadata\Projection\MetadataImage;
use Piwigo\Metadata\Projection\SvgDimensions;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use RuntimeException;
use SimpleXMLElement;

/**
 * Pure computation over raw EXIF/IPTC/SVG file data -- extraction, SVG
 * dimension parsing, GPS math, keyword normalization -- plus the 2
 * orchestrator methods (`syncMetadata()`/`getFilelist()`) that call
 * {@see MetadataRepository} for their own DB access.
 *
 * [SEC-20] `getSyncMetadata()`'s SVG dimension parsing strips any
 * `<!DOCTYPE ...>` declaration before calling `simplexml_load_string()`
 * (with `LIBXML_NONET`, never `LIBXML_NOENT`/`LIBXML_DTDLOAD`), an XXE
 * mitigation.
 */
final readonly class MetadataService
{
    public function __construct(
        private Lang $lang,
        private MetadataRepository $repo,
        private CurrentLogger $currentLogger,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private SessionService $sessionService,
        private Paths $paths,
    ) {}

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    public function getIptcData(string $filename, array $map, string $arraySep = ','): array
    {

        $result = [];

        $imginfo = [];
        if (! file_exists($filename) || @getimagesize($filename, $imginfo) === false) {
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

                        if (! $this->currentConfig->allowHtmlInMetadata) {
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
            $value = $this->eventDispatcher->dispatchChange(new CleanIptcValue($value))
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
     * numeric, or a GPS coordinate's own array-of-rationals shape).
     *
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    public function getExifData(string $filename, array $map): array
    {
        $logger = $this->currentLogger->get();

        $result = [];

        if (! function_exists('exif_read_data')) {
            throw new RuntimeException('Exif extension not available, admin should disable exif use');
        }

        // exif_read_data() only ever supports JPEG/TIFF (per its own docs)
        // and warns "File not supported" for anything else -- skip the call
        // entirely for a file extension that can never carry EXIF data
        // (SVG, PNG, ...) instead of relying on @ to hide the resulting
        // warning, which PHPUnit's error handler surfaces regardless.
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $exif = in_array($extension, ['jpg', 'jpeg', 'tif', 'tiff'], true)
            ? exif_read_data($filename)
            : false;
        $exif2 = (bool) $exif ? null : $this->eventDispatcher->dispatchChange(new FormatExifData(null, $filename, $map))
            ->exif;

        if ((bool) $exif || (bool) $exif2) {
            if ((bool) $exif2) {
                $exif = $exif2;
            } else {
                $exif = $this->eventDispatcher->dispatchChange(new FormatExifData($exif, $filename, $map))
                    ->exif;
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
                        $logger->info('[getExifData][filename=' . $filename . '] invalid GPS coordinates, latitude=' . (string) $latitude . ' longitude=' . (string) $longitude);
                    }
                }
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

    public function stripHtmlInMetadata(mixed &$v, int|string $k): void
    {
        $v = strip_tags(is_scalar($v) ? (string) $v : '');
    }

    /**
     * @param  list<string>  $raw  eg: ['41/1', '54/1', '9843/500']
     * @param  string  $ref  'S', 'N', 'E', 'W'
     */
    public function parseExifGpsData(array $raw, string $ref): float
    {
        $parsed = [];
        foreach ($raw as $component) {
            $parts = explode('/', $component);
            $denominator = (float) ($parts[1] ?? '0');
            $parsed[] = $denominator === 0.0 ? 0.0 : (float) $parts[0] / $denominator;
        }

        $v = (float) ($parsed[0] ?? 0) + (float) ($parsed[1] ?? 0) / 60.0 + (float) ($parsed[2] ?? 0) / 3600.0;

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

        $map = $this->stringMap($this->currentConfig->useIptcMapping);

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

        return $iptc;
    }

    /**
     * @return array<string, string>
     */
    public function getSyncExifData(string $file): array
    {

        $map = $this->stringMap($this->currentConfig->useExifMapping);

        $exif = $this->getExifData($file, $map);
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
    public function getSyncMetadata(array $infos): array|false
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
            $infos = array_merge($infos, $this->getSyncExifData($file));
        }

        if ($this->currentConfig->useIptc) {
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
     */
    private function parseSvgDimensions(string $file): ?SvgDimensions
    {
        $xml = file_get_contents($file);
        if ($xml === false) {
            return null;
        }

        $xml = preg_replace('/<!DOCTYPE[^>]*>/i', '', $xml);
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

        // Inline-constructed rather than constructor-injected -- matches
        // SearchService::getElements()'s own established precedent for a
        // one-method-only TagService dependency, avoiding touching every
        // existing `new MetadataService(...)` call site for zero benefit.
        $tagServiceCategoryService = new CategoryService($this->lang, new CategoryRepository($entityManager, $this->currentConfig), $permissionService, $this->currentConfig, $this->eventDispatcher, new Translator($this->currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), new AccessLevelChecker($this->currentUser, $this->currentConfig), new UserRepository($entityManager, $this->eventDispatcher, $this->currentConfig));
        $tagServiceImageService = new ImageService($entityManager->getRepository(ImageEntity::class), new ActivityService($entityManager->getRepository(ActivityEntity::class)), $this->sessionService, $this->eventDispatcher, $this->currentConfig, $this->paths, $tagServiceCategoryService);
        $tagService = new TagService($this->lang, $entityManager->getRepository(TagEntity::class), $permissionService, new ActivityService($entityManager->getRepository(ActivityEntity::class)), $this->eventDispatcher, $this->currentUser, $this->currentConfig, $this->currentLogger, $tagServiceImageService);

        foreach ($this->repo->findImagesByIds($ids) as $row) {
            $data = $this->getSyncMetadata($row->toArray());
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
            $updateFields = array_values(array_diff($updateFields, ['tags', 'keywords']));

            $this->repo->massUpdateImages($updateFields, $datas);
        }

        $tagService->setTagsOf($tagsOf);
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
