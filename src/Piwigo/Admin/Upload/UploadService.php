<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

/**
 * Ported from admin/include/functions_upload.inc.php (22 free functions).
 * Behavior-preserving port -- $user reads retargeted onto
 * Piwigo\Users\CurrentUser in Legacy Coupling Retirement Track A batch A3;
 * $conf reads retargeted onto Piwigo\Config\Config in Legacy Coupling
 * Retirement Track A gap-fill batch G2; $logger reads retargeted onto
 * Piwigo\Core\CurrentLogger in Legacy Coupling Retirement Track A gap-fill
 * batch G5), except two real fixes made during the port:
 *
 * [SEC-21] addUploadedFile()'s SVG branch validated that the sniffed MIME
 * type matched the ".svg" extension, but never sanitized the SVG's own
 * XML content -- a genuinely-named "photo.svg" containing an embedded
 * <script> passed straight through to storage and is later served
 * inline by the web server. sanitizeSvgIfNeeded() (new) strips <script>
 * elements and on*= event-handler attributes via DOMDocument (LIBXML_NONET,
 * DOCTYPE stripped first -- same safe-parsing shape as
 * MetadataService::parseSvgDimensions(), P19's own SEC-20 fix), run right
 * after the MIME/extension check, before the file is written to permanent
 * storage. Content-Disposition: attachment for uploaded SVG/HTML (the
 * other SEC-21 half) is a web-server-level fix, see upload/.htaccess.
 *
 * [SEC-16] all 8 real exec() calls (PDF/HEIC/TIFF/video/PSD/EPS
 * representative generation) built their command string via unescaped
 * `'"' . $path . '"'` concatenation -- P19 only fixed PwgImage.php's and
 * ImageExtImagick.php's 4 call sites (the doc's own SEC-16 text already
 * scoped "UploadService (10 calls)" separately, i.e. this file was always
 * the remaining half). Every exec() call now uses escapeshellarg(), same
 * `escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command()`
 * dir-prefix pattern P19 established in PwgImage.php/ImageExtImagick.php.
 *
 * The 6 upload_file_* representative-generation handlers are `public
 * static` (not instance methods, unlike the rest of this class) because
 * they're registered as PluginConfig event handlers (in
 * include/common.inc.php's "default event handlers" block since P23
 * sub-batch 8b-3, formerly admin/include/functions_upload.inc.php's thin
 * delegate file) -- EventDispatcher::addEventHandler() dedupes by
 * `$a === $b` on the callable, which for an array callable compares the
 * bound object by identity; an instance-method callable
 * ([$this, 'method']) would silently re-register (and double-fire) a new
 * "distinct" handler on every `new UploadService()`. A `[self::class,
 * 'method']` static callable compares equal across any number of
 * registrations, matching the original free function's true
 * once-per-process registration semantics.
 */
final class UploadService
{
    /**
     * @return array<string, array{default: bool|int, min: int|null, max: int|null, pattern: string|null, can_be_null: bool, error_message: string|null}>
     */
    public function getUploadFormConfig(): array
    {
        return [
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
                'error_message' => Lang::t('The original maximum width must be a number between %d and %d'),
            ],

            'original_resize_maxheight' => [
                'default' => 2000,
                'min' => 300,
                'max' => 20000,
                'pattern' => '/^\d+$/',
                'can_be_null' => false,
                'error_message' => Lang::t('The original maximum height must be a number between %d and %d'),
            ],

            'original_resize_quality' => [
                'default' => 95,
                'min' => 50,
                'max' => 98,
                'pattern' => '/^\d+$/',
                'can_be_null' => false,
                'error_message' => Lang::t('The original image quality must be a number between %d and %d'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $errors
     * @param array<string, string> $form_errors
     */
    public function saveUploadFormConfig(array $data, array &$errors = [], array &$form_errors = []): bool
    {
        if ($data === []) {
            return false;
        }

        $upload_form_config = $this->getUploadFormConfig();
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
                    'value' => SqlDialect::booleanToString($value),
                ];
            } elseif ($upload_form_config[$field]['can_be_null'] and self::isFalsy($value)) {
                $updates[] = [
                    'param' => $field,
                    'value' => 'false',
                ];
            } else {
                $min = $upload_form_config[$field]['min'];
                $max = $upload_form_config[$field]['max'];
                $pattern = $upload_form_config[$field]['pattern'];
                $error_message = $upload_form_config[$field]['error_message'];

                if (! is_int($min) || ! is_int($max) || ! is_string($pattern) || $pattern === '' || ! is_string($error_message) || ! is_scalar($value)) {
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

        if (count($errors) === 0) {
            new BatchWriter(DbConnection::build())->massUpdate(
                Tables::config(),
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
     * 1) move uploaded file to upload/2010/01/22/20100122003814-449ada00.jpg
     * 2) keep/resize original
     * 3) register in database
     *
     * @param int[]|null $categories
     * @param PwgServer|null $service Legacy Coupling Retirement Phase 8,
     *   8m: was a `global $service;` read guarded by `defined('IN_WS')`.
     *   Cannot be a required parameter -- the one non-WS real caller,
     *   Job\Handler\BatchUploadHandler::__invoke(BatchUploadJob $job), is a
     *   genuine queued-job handler with no PwgServer in scope at all
     *   (IN_WS is never defined there either, matching the pre-existing
     *   behavior). PwgImages.php's 5 real WS callers (addFile()/add()/
     *   addSimple()/upload()/uploadAsync(), each already carrying its own
     *   PwgServer $service param) pass it through; BatchUploadHandler
     *   passes nothing.
     */
    public function addUploadedFile(string $source_filepath, UrlServiceInterface $urlService, ?string $original_filename = null, ?array $categories = null, ?int $level = null, ?int $image_id = null, ?string $original_md5sum = null, ?PwgServer $service = null): int|string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();
        $conn = DbConnection::build();

        if ($original_filename !== null) {
            $original_filename = htmlspecialchars($original_filename);
        }

        if (isset($original_md5sum)) {
            $md5sum = $original_md5sum;
        } else {
            $md5sum = md5_file($source_filepath);
            if ($md5sum === false) {
                throw new \Exception("upload(): unable to compute md5sum of {$source_filepath}");
            }
        }

        // we only try to detect duplicate on a new image, not when updating an existing image
        if (! isset($image_id) and \Piwigo\Config\CurrentConfig::uploadDetectDuplicate()) {
            $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE md5sum = \'' . $md5sum . '\'
;';
            $images_found = $conn->fetchAllAssociative($query);

            if (count($images_found) > 0) {
                $found_id = $images_found[0]['id'];
                if (! is_numeric($found_id)) {
                    // id is the table's NOT NULL auto-increment primary key,
                    // so it is always numeric here (native int under DBAL,
                    // numeric string under mysqli); this guard only exists to
                    // give PHPStan a real narrowing.
                    throw new \Exception(__METHOD__ . '(): unexpected non-numeric image id while checking for duplicates');
                }
                $image_id = (int) $found_id;
                $logger->info('[' . __METHOD__ . '] image already exist #' . $image_id . ', we delete the newly uploaded file : ' . $source_filepath);
                unlink($source_filepath);

                // if the destination category is already linked to this photo, no worry,
                // associate_images_to_categories perfectly handles this case
                $this->addUploadedFileAddToCategories($image_id, $categories);

                return $image_id;
            }
        }

        $file_path = null;
        // Only ever read in the "new photo" branch below (where it's also
        // assigned) -- declared here so Psalm can see it's always defined
        // by the time it's used, without relying on the two branches'
        // isset($image_id) conditions staying in sync 200 lines apart.
        $dbnow = null;

        if (isset($image_id)) {
            // this photo already exists, we update it
            $query = '
SELECT
    path
  FROM ' . Tables::images() . '
  WHERE id = ' . $image_id . '
;';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                $file_path = is_string($row['path']) ? $row['path'] : null;
            }

            if (! isset($file_path)) {
                throw new ImageProcessingException('[' . __METHOD__ . '] this photo does not exist in the database');
            }

            // delete all physical files related to the photo (thumbnail, web site, HD)
            new ImageService(new ImageRepository($conn), new ActivityService(new ActivityRepository($conn)))
                ->deleteElementFiles([$image_id], $urlService);
        } else {
            // this photo is new

            // current date -- Env::now() rather than a raw "SELECT NOW();",
            // since the latter runs on the MySQL server's real clock,
            // invisible to Env::now()'s PIWIGO_TEST_NOW freeze. This value
            // drives both piwigo_images.date_available and the upload
            // directory/filename's date portion, so a real-clock read here
            // made every fixture regeneration produce a fresh, unstable
            // upload path and a non-reproducible photo sort order.
            $dbnow = Env::now()
                ->format('Y-m-d H:i:s');
            $date_parts = preg_split('/[^\d]/', $dbnow, 4);
            if ($date_parts === false) {
                throw new \Exception(__METHOD__ . '(): preg_split() failed');
            }
            [$year, $month, $day] = $date_parts;

            // upload directory hierarchy
            //
            // Real bug, found via a fixture-regeneration discrepancy:
            // CurrentConfig::uploadDir()'s own default already ends in '/'
            // ('upload/'), so appending another literal '/' before %s below
            // produced a double slash (e.g. 'upload//2026/08/01/...') in
            // every stored images.path -- rtrim() here matches the same
            // defensive normalization this class's own addUploadedFile()
            // already applies a few lines up ($upload_root).
            $conf_upload_dir = rtrim(\Piwigo\Config\CurrentConfig::uploadDir(), '/');
            $upload_dir = sprintf(
                \Piwigo\Core\CurrentPaths::get()->root . $conf_upload_dir . '/%s/%s/%s',
                $year,
                $month,
                $day
            );

            // compute file path
            $date_string = preg_replace('/[^\d]/', '', $dbnow);
            $random_string = substr($md5sum, 0, 4) . '%s';
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

            if ($type === IMAGETYPE_PNG) {
                $file_path .= 'png';
            } elseif ($type === IMAGETYPE_GIF) {
                $file_path .= 'gif';
            } elseif ($type === IMAGETYPE_JPEG) {
                $file_path .= 'jpg';
            } elseif ($type === IMAGETYPE_WEBP) {
                $file_path .= 'webp';
            } elseif (\Piwigo\Config\CurrentConfig::uploadFormAllTypes()) {
                $original_extension = strtolower(\Piwigo\Core\StringHelper::getExtension($original_filename));

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo === false) {
                    throw new \Exception(__METHOD__ . '(): finfo_open() failed');
                }
                $finfo_type = finfo_file($finfo, $source_filepath);

                if (in_array($finfo_type, ['image/svg', 'image/svg+xml'], true) and $original_extension !== 'svg') {
                    unlink($source_filepath);
                    $error_msg = 'File extension "' . $original_extension . '" for file "' . $original_filename . '" does not match file MIME type "' . $finfo_type . '"';
                    if (\Piwigo\Core\WsContext::isActive() && $service !== null) {
                        $service->sendResponse(new PwgError(415, $error_msg));
                        exit;
                    }

                    throw new ImageProcessingException($error_msg);
                }

                // [SEC-21] strip <script>/event-handler content from a
                // genuinely-matching SVG before it ever reaches storage.
                $this->sanitizeSvgIfNeeded($source_filepath, is_string($finfo_type) ? $finfo_type : null);

                $conf_file_ext = \Piwigo\Config\CurrentConfig::fileExtensions();
                if (in_array($original_extension, $conf_file_ext, true)) {
                    $file_path .= $original_extension;
                } else {
                    unlink($source_filepath);
                    throw new ImageProcessingException('unexpected file type');
                }
            } else {
                unlink($source_filepath);
                throw new ImageProcessingException('forbidden file type');
            }

            $this->prepareDirectory($upload_dir);

            $file_path_pattern = $file_path;
            do {
                // we generate a random string for each upload. If the user uploads
                // the same photo twice at the same time (same timestamp, same md5sum)
                // we still want the path to be unique.
                $file_path = sprintf($file_path_pattern, substr(bin2hex(random_bytes(4)), 0, 4));
            } while (file_exists($file_path));
        }

        // move_uploaded_file()/rename() both write $source_filepath's content
        // to $file_path under the uploads tree and consume the source -- routed
        // through StorageRegistry's 'uploads' disk instead so a future non-local
        // adapter (S3/SFTP) needs no call-site change here. PHP's own SAPI-level
        // upload cleanup deletes a real is_uploaded_file() tmp file at request
        // end even without an explicit unlink() (verified via PHP's documented
        // upload garbage-collection guarantee), matching what move_uploaded_file()
        // used to do immediately; the "already local" (rename()) branch still
        // needs an explicit unlink() since nothing else will remove that source.
        $upload_root = rtrim(\Piwigo\Core\CurrentPaths::get()->root . CurrentConfig::uploadDir(), '/');
        $upload_rel_path = StorageRegistry::stripRoot($upload_root, $file_path);
        $upload_stream = fopen($source_filepath, 'rb');
        if ($upload_stream !== false) {
            StorageRegistry::disk('uploads')->writeStream($upload_rel_path, $upload_stream);
            fclose($upload_stream);
            if (! is_uploaded_file($source_filepath)) {
                @unlink($source_filepath);
            }
        }
        @chmod($file_path, 0644);

        // handle the uploaded file type by potentially making a
        // pwg_representative file.
        $representative_ext = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('upload_file', null, $file_path);

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

        $logger->info(__METHOD__ . ' : force cache generation, representative_ext = ' . ($representative_ext ?? ''));

        if (PwgImage::get_library() !== 'gd') {
            if (\Piwigo\Config\CurrentConfig::originalResize()) {
                $original_resize_maxwidth = \Piwigo\Config\CurrentConfig::originalResizeMaxwidth();

                $original_resize_maxheight = \Piwigo\Config\CurrentConfig::originalResizeMaxheight();

                $need_resize = $this->needResize($file_path, $original_resize_maxwidth, $original_resize_maxheight);

                if ($need_resize) {
                    $img = new PwgImage($file_path);

                    $original_resize_quality = \Piwigo\Config\CurrentConfig::originalResizeQuality();

                    $img->pwg_resize(
                        $file_path,
                        $original_resize_maxwidth,
                        $original_resize_maxheight,
                        $original_resize_quality,
                        \Piwigo\Config\CurrentConfig::uploadFormAutomaticRotation(),
                        false
                    );

                    $img->destroy();
                }
            }
        }

        // we need to save the rotation angle in the database to compute
        // width/height of "multisizes"
        $rotation_angle = PwgImage::get_rotation_angle($file_path);
        $rotation = PwgImage::get_rotation_code_from_angle($rotation_angle);

        $file_infos = $this->pwgImageInfos($file_path);

        if (isset($image_id)) {
            $update = [
                'file' => $original_filename ?? basename($file_path),
                'filesize' => $file_infos['filesize'],
                'width' => $file_infos['width'],
                'height' => $file_infos['height'],
                'md5sum' => $md5sum,
                'added_by' => \Piwigo\Users\CurrentUser::get()->id,
                'rotation' => $rotation,
            ];

            if (isset($level)) {
                $update['level'] = $level;
            }

            new BatchWriter($conn)
                ->singleUpdate(
                    Tables::images(),
                    $update,
                    [
                        'id' => $image_id,
                    ]
                );
        } else {
            // database registration
            $file = $original_filename ?? basename($file_path);
            $insert = [
                'file' => $file,
                'name' => \Piwigo\Core\StringHelper::getNameFromFile($file),
                'date_available' => $dbnow,
                // Otherwise relies on the schema's own DEFAULT
                // CURRENT_TIMESTAMP, which reads the real DB-server clock --
                // invisible to Env::now()'s PIWIGO_TEST_NOW freeze, same
                // reasoning as date_available above. Reuses $dbnow rather
                // than a second Env::now() call so both columns agree on the
                // exact same instant, matching what the DB default would
                // have produced for a single INSERT.
                'lastmodified' => $dbnow,
                'path' => preg_replace('#^' . preg_quote(\Piwigo\Core\CurrentPaths::get()->root) . '#', '', $file_path),
                'filesize' => $file_infos['filesize'],
                'width' => $file_infos['width'],
                'height' => $file_infos['height'],
                'md5sum' => $md5sum,
                'added_by' => \Piwigo\Users\CurrentUser::get()->id,
                'rotation' => $rotation,
            ];

            if (isset($level)) {
                $insert['level'] = $level;
            }

            if (isset($representative_ext)) {
                $insert['representative_ext'] = $representative_ext;
            }

            new BatchWriter($conn)
                ->singleInsert(Tables::images(), $insert);

            $image_id = $conn->lastInsertId();
            new ActivityService(new ActivityRepository($conn))
                ->record('photo', $image_id, 'add');
        }

        $this->addUploadedFileAddToCategories($image_id, $categories);

        // update metadata from the uploaded file (exif/iptc)
        if (\Piwigo\Config\CurrentConfig::useExif() and ! function_exists('exif_read_data')) {
            \Piwigo\Config\CurrentConfig::setUseExif(false);
        }
        new MetadataService(new MetadataRepository($conn))
            ->syncMetadata([(int) $image_id]);

        // cache a derivative
        $query = '
SELECT
    id,
    path,
    representative_ext
  FROM ' . Tables::images() . '
  WHERE id = ' . $image_id . '
;';
        $image_infos = $conn->fetchAssociative($query);
        if ($image_infos === false) {
            throw new \Exception(__METHOD__ . '(): image #' . $image_id . ' not found right after being saved');
        }
        $src_image = new SrcImage($image_infos);

        $urlService->setMakeFullUrl();
        // in case we are on uploadify.php, we have to replace the false path
        $derivative_url = preg_replace('#admin/include/i#', 'i', DerivativeImage::url(ImageStdParams::MEDIUM, $src_image));
        assert($derivative_url !== null);
        $urlService->unsetMakeFullUrl();

        $logger->info(__METHOD__ . ' : force cache generation, derivative_url = ' . $derivative_url);

        // Fire-and-forget: the response content is never read, only the
        // self-request's side effect (forcing derivative-image generation)
        // matters.
        HttpClientService::fetch($derivative_url);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_add_uploaded_file', $image_infos);

        return $image_id;
    }

    /**
     * @param int[]|null $categories
     */
    private function addUploadedFileAddToCategories(int|string $image_id, ?array $categories): void
    {

        if (! \Piwigo\Config\CurrentConfig::loungeActive()) {
            // check if we need to use the lounge from now
            $row = DbConnection::build()->fetchNumeric('SELECT COUNT(*) FROM ' . Tables::images() . ';');
            $nb_photos = $row !== false ? $row[0] : 0;
            if ($nb_photos >= \Piwigo\Config\CurrentConfig::loungeActivateThreshold()) {
                \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('lounge_active', true, true);
            }
        }

        if (isset($categories) and count($categories) > 0) {
            $imageConn = DbConnection::build();
            $imageService = new ImageService(new ImageRepository($imageConn), new ActivityService(new ActivityRepository($imageConn)));

            if (\Piwigo\Config\CurrentConfig::loungeActive()) {
                // fillLounge() requires int keys for $categories; a WS param
                // forced into an array by makeArrayParam() could theoretically
                // carry non-sequential/string keys, so reindex to guarantee it.
                $imageService->fillLounge([$image_id], array_values($categories));
            } else {
                $imageService->associateImagesToCategories([(int) $image_id], $categories);
            }
        }

        if (! \Piwigo\Config\CurrentConfig::loungeActive()) {
            PermissionCacheInvalidator::invalidate();
        }
    }

    /**
     * [SEC-21] Strips <script> elements and on*= event-handler attributes
     * from a genuinely-sniffed SVG before it's written to permanent
     * storage -- the caller already confirmed the extension matches the
     * MIME type; this closes the remaining "genuinely-named .svg with
     * embedded script" stored-XSS gap. Same safe-parsing shape as
     * MetadataService::parseSvgDimensions() (P19, SEC-20): strip
     * <!DOCTYPE> first, then DOMDocument with LIBXML_NONET so no external
     * entity/network fetch can happen during parsing. A file that fails to
     * parse as XML is left untouched (finfo already confirmed it isn't a
     * real SVG in that case, so nothing to sanitize -- the caller's own
     * mismatch check further up handles genuinely wrong content).
     */
    private function sanitizeSvgIfNeeded(string $source_filepath, ?string $finfo_type): void
    {
        if (! in_array($finfo_type, ['image/svg', 'image/svg+xml'], true)) {
            return;
        }

        $xml = file_get_contents($source_filepath);
        if ($xml === false) {
            return;
        }

        $xml = preg_replace('/<!DOCTYPE[^>]*>/i', '', $xml);
        if ($xml === null) {
            return;
        }

        // libxml_use_internal_errors() (not @) so a malformed upload
        // doesn't surface as a PHP-level warning at all -- parse errors
        // are just discarded, matching the "leave untouched" fallback below.
        $previous_use_errors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_use_errors);
        if (! $loaded || ! $dom->documentElement instanceof \DOMElement) {
            return;
        }

        $xpath = new \DOMXPath($dom);

        $scriptNodes = $xpath->query('//*[local-name()="script"]');
        foreach (iterator_to_array($scriptNodes !== false ? $scriptNodes : new \ArrayIterator([])) as $scriptNode) {
            if ($scriptNode instanceof \DOMNode && $scriptNode->parentNode instanceof \DOMNode) {
                $scriptNode->parentNode->removeChild($scriptNode);
            }
        }

        $attrNodes = $xpath->query('//@*');
        foreach (iterator_to_array($attrNodes !== false ? $attrNodes : new \ArrayIterator([])) as $attrNode) {
            if ($attrNode instanceof \DOMAttr && stripos($attrNode->nodeName, 'on') === 0) {
                $attrNode->ownerElement?->removeAttributeNode($attrNode);
            }
        }

        $sanitized = $dom->saveXML();
        if ($sanitized !== false) {
            file_put_contents($source_filepath, $sanitized);
        }
    }

    /**
     * 1) find infos about the extended image
     * 2) move uploaded file to upload/2022/05/16/pwg_format/20100122003814-449ada00.cr2
     * 3) register in database
     */
    public function addFormat(string $source_filepath, string $format_ext, int|string $format_of): string
    {
        if (! \Piwigo\Config\CurrentConfig::isFormatsEnabled()) {
            throw new ImageProcessingException('[' . __METHOD__ . '] formats are disabled');
        }

        $authorized_format_exts = \Piwigo\Config\CurrentConfig::formatExtensions();

        if (! in_array($format_ext, $authorized_format_exts, true)) {
            throw new ImageProcessingException('[' . __METHOD__ . '] unexpected format extension "' . $format_ext . '" (authorized extensions: ' . implode(', ', $authorized_format_exts) . ')');
        }

        $conn = DbConnection::build();
        $query = '
SELECT
    path
  FROM ' . Tables::images() . '
  WHERE id = ' . $format_of . '
;';
        $images = $conn->fetchAllAssociative($query);

        if (! isset($images[0])) {
            throw new ImageProcessingException('[' . __METHOD__ . '] this photo does not exist in the database');
        }

        $image_0_path = is_scalar($images[0]['path']) ? (string) $images[0]['path'] : '';
        $format_path = dirname($image_0_path) . '/pwg_format/';
        $format_path .= \Piwigo\Core\StringHelper::getFilenameWoExtension(basename($image_0_path));
        $format_path .= '.' . $format_ext;

        $this->prepareDirectory(dirname($format_path));

        // Same StorageRegistry-routed migration as addUploadedFile()'s own
        // move_uploaded_file()/rename() pair above -- $format_path here (built
        // from the DB-stored images.path column) is relative, not yet an
        // absolute path, so it needs normalizing before stripRoot() can
        // compute the disk-relative path.
        $paths = \Piwigo\Core\CurrentPaths::get();
        $format_root = $paths->root . CurrentConfig::uploadDir();
        $format_abs_path = $paths->root . ltrim(str_replace(['\\', '/./'], ['/', '/'], $format_path), '/');
        $format_rel_path = StorageRegistry::stripRoot($format_root, $format_abs_path);
        $format_stream = fopen($source_filepath, 'rb');
        if ($format_stream !== false) {
            StorageRegistry::disk('uploads')->writeStream($format_rel_path, $format_stream);
            fclose($format_stream);
            if (! is_uploaded_file($source_filepath)) {
                @unlink($source_filepath);
            }
        }
        @chmod($format_path, 0644);

        $file_infos = $this->pwgImageInfos($format_path);

        $insert = [
            'image_id' => $format_of,
            'ext' => $format_ext,
            'filesize' => $file_infos['filesize'],
        ];

        $query = '
SELECT
  format_id
  FROM ' . Tables::imageFormat() . '
  WHERE image_id = ' . $format_of . '
  AND ext = "' . $format_ext . '"
;';

        $formats = $conn->fetchAllAssociative($query);
        if ((bool) $formats) {
            $set_fields = [
                'filesize' => $file_infos['filesize'],
            ];
            $where_fields = [
                'format_id' => $formats[0]['format_id'],
                'image_id' => $format_of,
                'ext' => $format_ext,
            ];
            new BatchWriter($conn)
                ->singleUpdate(Tables::imageFormat(), $set_fields, $where_fields);
            $format_id = $formats[0]['format_id'];
            $add_status = 'update';
        } else {
            new BatchWriter($conn)
                ->singleInsert(Tables::imageFormat(), $insert);
            $format_id = $conn->lastInsertId();
            $add_status = 'add';
        }

        new ActivityService(new ActivityRepository($conn))
            ->record('photo', $format_of, 'edit', [
                'action' => 'add format',
                'format_ext' => $format_ext,
                'format_id' => $format_id,
            ]);

        $format_infos = $insert;
        $format_infos['format_id'] = $format_id;

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_add_format', $format_infos);

        return $add_status;
    }

    public static function uploadFilePdf(?string $representative_ext, string $file_path): ?string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            return $representative_ext;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            return $representative_ext;
        }

        if (! in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($file_path)), ['pdf'], true)) {
            return $representative_ext;
        }

        $ext = \Piwigo\Config\CurrentConfig::pdfRepresentativeExt();
        $jpg_quality = \Piwigo\Config\CurrentConfig::pdfJpgQuality();

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = \Piwigo\Image\ImagePathHelper::originalToRepresentative($file_path, $ext);
        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = \Piwigo\Config\CurrentConfig::extImagickDir();
        // [SEC-16] escapeshellarg() on the dir prefix and both real paths
        // below -- same pattern P19 established in PwgImage.php/
        // ImageExtImagick.php; the original never escaped an embedded
        // '"' or shell metacharacter in either path.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();
        $exec .= ' ' . escapeshellarg((string) realpath($file_path) . '[0]');
        if ($ext === 'jpg') {
            $exec .= ' -quality ' . (string) $jpg_quality;
        }
        $exec .= ' ' . escapeshellarg($representative_file_path);
        $exec .= ' 2>&1';
        @exec($exec, $returnarray);

        // Return the extension (if successful) or false (if failed)
        if (file_exists($representative_file_path)) {
            $representative_ext = $ext;
        }

        return $representative_ext;
    }

    public static function uploadFileHeic(?string $representative_ext, string $file_path): ?string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            return $representative_ext;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            return $representative_ext;
        }

        if (! in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($file_path)), ['heic'], true)) {
            return $representative_ext;
        }

        $ext = 'jpg';

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = \Piwigo\Image\ImagePathHelper::originalToRepresentative($file_path, $ext);
        self::prepareDirectoryStatic(dirname($representative_file_path));

        [$w, $h] = self::getOptimalDimensionsForRepresentative();

        $ext_imagick_dir = \Piwigo\Config\CurrentConfig::extImagickDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));
        $exec .= ' -sampling-factor 4:2:0 -quality 85 -interlace JPEG -colorspace sRGB -auto-orient +repage -resize "' . $w . 'x' . $h . '>"';
        $exec .= ' ' . escapeshellarg($representative_file_path);
        $exec .= ' 2>&1';

        $logger->info(__METHOD__ . ', exec = ' . $exec);

        @exec($exec, $returnarray);

        // Return the extension (if successful) or false (if failed)
        if (file_exists($representative_file_path)) {
            $representative_ext = $ext;
        }

        return $representative_ext;
    }

    public static function uploadFileTiff(?string $representative_ext, string $file_path): ?string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            return $representative_ext;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            return $representative_ext;
        }

        if (! in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($file_path)), ['tif', 'tiff'], true)) {
            return $representative_ext;
        }

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = dirname($file_path) . '/pwg_representative/';
        $representative_file_path .= \Piwigo\Core\StringHelper::getFilenameWoExtension(basename($file_path)) . '.';

        $conf_tiff_representative_ext = \Piwigo\Config\CurrentConfig::tiffRepresentativeExt();
        $representative_ext = $conf_tiff_representative_ext;
        $representative_file_path .= $representative_ext;

        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = \Piwigo\Config\CurrentConfig::extImagickDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));

        if ($representative_ext === 'jpg') {
            $exec .= ' -quality 98';
        }

        $dest = pathinfo($representative_file_path);
        $dest_dirname_realpath = realpath($dest['dirname']);
        if ($dest_dirname_realpath === false) {
            throw new \Exception("unable to resolve directory {$dest['dirname']}");
        }
        $exec .= ' ' . escapeshellarg($dest_dirname_realpath . '/' . $dest['basename']);

        $exec .= ' 2>&1';
        @exec($exec, $returnarray);

        // sometimes ImageMagick creates file-0.jpg (full size) + file-1.jpg
        // (thumbnail). I don't know how to avoid it.
        $representative_file_abspath = $dest_dirname_realpath . '/' . $dest['basename'];
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

        return \Piwigo\Core\StringHelper::getExtension($representative_file_abspath);
    }

    public static function uploadFileVideo(?string $representative_ext, string $file_path): ?string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            return $representative_ext;
        }

        $ffmpeg_video_exts = [ // extensions tested with FFmpeg
            'wmv', 'mov', 'mkv', 'mp4', 'mpg', 'flv', 'asf', 'xvid', 'divx', 'mpeg',
            'avi', 'rm', 'm4v', 'ogg', 'ogv', 'webm', 'webmv',
        ];

        if (! in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($file_path)), $ffmpeg_video_exts, true)) {
            return $representative_ext;
        }

        $representative_file_path = dirname($file_path) . '/pwg_representative/';
        $representative_file_path .= \Piwigo\Core\StringHelper::getFilenameWoExtension(basename($file_path)) . '.';

        $representative_ext = 'jpg';
        $representative_file_path .= $representative_ext;

        self::prepareDirectoryStatic(dirname($representative_file_path));

        // Get duration of video and determine time of poster
        // [SEC-16] escapeshellarg() on the video path -- the original
        // single-quoted it manually, which never escapes an embedded `'`.
        $O = [];
        exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($file_path), $O, $S);

        if (isset($O[0]) && $O[0] !== '') {
            $second = min(floor((float) $O[0] * 10.0) / 10.0, 2);
        } else {
            $second = 0; // Safest position of the poster
        }

        $logger->info(__METHOD__ . ', Poster at ' . (string) $second . 's');

        // Generate poster, see https://trac.ffmpeg.org/wiki/Seeking
        $ffmpeg_dir = \Piwigo\Config\CurrentConfig::ffmpegDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above (same
        // dir-prefix pattern applied to the ffmpeg/avconv binaries here).
        $ffmpeg = escapeshellarg($ffmpeg_dir) . 'ffmpeg';
        $ffmpeg .= ' -ss ' . (string) $second;  // Fast seeking
        $ffmpeg .= ' -i ' . escapeshellarg($file_path); // Video file
        $ffmpeg .= ' -frames:v 1';  // Extract one frame
        $ffmpeg .= ' ' . escapeshellarg($representative_file_path); // Output file

        $FO = [];
        @exec($ffmpeg . ' 2>&1', $FO, $FS);
        if (isset($FO[0]) && $FO[0] !== '') {
            $logger->debug(__METHOD__ . ', Tried ' . $ffmpeg);
            $logger->debug($FO[0]);
        }

        // Did we generate the file ?
        if (! file_exists($representative_file_path)) {
            // Let's try with avconv if ffmpeg unavailable
            $avconv = str_replace('ffmpeg', 'avconv', $ffmpeg);
            $AO = [];
            @exec($avconv . ' 2>&1', $AO, $AS);

            if (isset($AO[0]) && $AO[0] !== '') {
                $logger->debug(__METHOD__ . ', Tried ' . $avconv);
                $logger->debug($AO[0]);
            }
        }

        // Did we finally generate the file ?
        if (! file_exists($representative_file_path)) {
            return null;
        }

        return $representative_ext;
    }

    public static function uploadFilePsd(?string $representative_ext, string $file_path): ?string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            return $representative_ext;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            return $representative_ext;
        }

        if (! in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($file_path)), ['psd'], true)) {
            return $representative_ext;
        }

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = dirname($file_path) . '/pwg_representative/';
        $representative_file_path .= \Piwigo\Core\StringHelper::getFilenameWoExtension(basename($file_path)) . '.';

        $representative_ext = 'png';
        $representative_file_path .= $representative_ext;

        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = \Piwigo\Config\CurrentConfig::extImagickDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();

        $exec .= ' ' . escapeshellarg((string) realpath($file_path));

        $dest = pathinfo($representative_file_path);
        $dest_dirname_realpath = realpath($dest['dirname']);
        if ($dest_dirname_realpath === false) {
            throw new \Exception("unable to resolve directory {$dest['dirname']}");
        }
        $exec .= ' ' . escapeshellarg($dest_dirname_realpath . '/' . $dest['basename']);

        $exec .= ' 2>&1';
        $logger->info(__METHOD__ . ', exec = ' . $exec);
        @exec($exec, $returnarray);

        // sometimes ImageMagick creates file-0.png + file-1.png + file-2.png...
        // It seems we can't avoid it.
        $representative_file_abspath = $dest_dirname_realpath . '/' . $dest['basename'];
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

        return \Piwigo\Core\StringHelper::getExtension($representative_file_abspath);
    }

    public static function uploadFileEps(?string $representative_ext, string $file_path): ?string
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            return $representative_ext;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            return $representative_ext;
        }

        if (! in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($file_path)), ['eps'], true)) {
            return $representative_ext;
        }

        // if the representative is "jpg", the derivatives are ugly. With "png" it's fine.
        $ext = 'png';

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = \Piwigo\Image\ImagePathHelper::originalToRepresentative($file_path, $ext);
        self::prepareDirectoryStatic(dirname($representative_file_path));

        // convert -density 300 image.eps -resize 2048x2048 image.png

        $ext_imagick_dir = \Piwigo\Config\CurrentConfig::extImagickDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));
        $exec .= ' -density 300';
        $exec .= ' -resize 2048x2048';
        $exec .= ' ' . escapeshellarg($representative_file_path);
        $exec .= ' 2>&1';
        $logger->info(__METHOD__ . ', $exec = ' . $exec);
        @exec($exec, $returnarray);

        // Return the extension (if successful) or false (if failed)
        if (file_exists($representative_file_path)) {
            $representative_ext = $ext;
        }

        return $representative_ext;
    }

    private function prepareDirectory(string $directory): void
    {
        self::prepareDirectoryStatic($directory);
    }

    private static function prepareDirectoryStatic(string $directory): void
    {
        if (! is_dir($directory)) {
            if (str_starts_with(PHP_OS, 'WIN')) {
                $directory = str_replace('/', DIRECTORY_SEPARATOR, $directory);
            }
            umask(0000);
            $recursive = true;
            if (! @mkdir($directory, 0777, $recursive)) {
                throw new ImageProcessingException('[prepare_directory] cannot create directory "' . $directory . '"');
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
                throw new ImageProcessingException('[prepare_directory] directory "' . $directory . '" has no write access');
            }
        }

        \Piwigo\Core\FilesystemHelper::secureDirectory($directory);
    }

    private function needResize(string $image_filepath, int $max_width, int $max_height): bool
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $picture_ext = \Piwigo\Config\CurrentConfig::pictureExtensions();
        if (! in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($image_filepath)), $picture_ext, true)) {
            return false;
        }

        $image_size = getimagesize($image_filepath);
        if ($image_size === false) {
            // can't determine dimensions, so we can't tell whether a resize
            // is needed
            return false;
        }
        [$width, $height] = $image_size;

        // getimagesize() reports raw pixel dimensions, not the visually
        // correct ones -- a portrait photo stored with landscape raw
        // pixels plus a 90/270 EXIF rotation flag needs width/height
        // swapped before comparing against the max thresholds, or the
        // check (and the resize it gates) runs against the wrong axis.
        $rotation_angle = PwgImage::get_rotation_angle($image_filepath);
        if (in_array($rotation_angle, [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }

        if ($width > $max_width or $height > $max_height) {
            $logger->info(__METHOD__ . ' ' . $image_filepath . ' is too big (current=' . $width . 'x' . $height . 'px Vs max=' . $max_width . 'x' . $max_height . 'px)');
            return true;
        }

        return false;
    }

    /**
     * Strict, PHPStan-friendly equivalent of empty() for a genuinely mixed
     * value (this class's only caller is a form-data loop over
     * array<string, mixed>) -- mirrors empty()'s real falsy set exactly:
     * null, false, 0, 0.0, '0', '', [].
     */
    private static function isFalsy(mixed $value): bool
    {
        return $value === null
            || $value === false
            || $value === 0
            || $value === 0.0
            || $value === '0'
            || $value === ''
            || $value === [];
    }

    /**
     * @return array{width: int, height: int, filesize: float}
     */
    public function pwgImageInfos(string $path): array
    {
        $image_size = getimagesize($path);
        if ($image_size === false) {
            // every caller stores width/height straight into the database;
            // there is no sane fallback shape to return here
            throw new \Exception(__METHOD__ . '(): getimagesize() failed for ' . $path);
        }
        [$width, $height] = $image_size;
        $filesize_bytes = filesize($path);
        if ($filesize_bytes === false) {
            // same rationale as the getimagesize() guard above: every caller
            // stores this straight into the database, no sane fallback shape.
            throw new \Exception(__METHOD__ . '(): filesize() failed for ' . $path);
        }
        $filesize = floor($filesize_bytes / 1024);

        return [
            'width' => $width,
            'height' => $height,
            'filesize' => $filesize,
        ];
    }

    /**
     * @return string[]
     */
    public function isValidImageExtension(string $extension): array
    {
        if (\Piwigo\Config\CurrentConfig::uploadFormAllTypes()) {
            $extensions = \Piwigo\Config\CurrentConfig::fileExtensions();
        } else {
            $extensions = \Piwigo\Config\CurrentConfig::pictureExtensions();
        }

        return array_unique(array_map(strtolower(...), $extensions));
    }

    public function fileUploadErrorMessage(int $error_code): string
    {
        $ini_size = $this->getIniSize('upload_max_filesize', false);

        return match ($error_code) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                Lang::t('The uploaded file exceeds the upload_max_filesize directive in php.ini: %sB'),
                $ini_size === false ? 'unknown' : $ini_size
            ),
            UPLOAD_ERR_FORM_SIZE => Lang::t('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
            UPLOAD_ERR_PARTIAL => Lang::t('The uploaded file was only partially uploaded'),
            UPLOAD_ERR_NO_FILE => Lang::t('No file was uploaded'),
            UPLOAD_ERR_NO_TMP_DIR => Lang::t('Missing a temporary folder'),
            UPLOAD_ERR_CANT_WRITE => Lang::t('Failed to write file to disk'),
            UPLOAD_ERR_EXTENSION => Lang::t('File upload stopped by extension'),
            default => Lang::t('Unknown upload error'),
        };
    }

    public function getIniSize(string $ini_key, bool $in_bytes = true): int|string|false
    {
        $size = ini_get($ini_key);

        if ($in_bytes) {
            $size = $this->convertShorthandNotationToBytes($size);
        }

        return $size;
    }

    private function convertShorthandNotationToBytes(string|false $value): int|string|false
    {
        $suffix = substr((string) $value, -1);
        $multiply_by = null;

        if ($suffix === 'K') {
            $multiply_by = 1024;
        } elseif ($suffix === 'M') {
            $multiply_by = 1024 * 1024;
        } elseif ($suffix === 'G') {
            $multiply_by = 1024 * 1024 * 1024;
        }

        if (isset($multiply_by)) {
            $value = (int) substr((string) $value, 0, -1) * $multiply_by;
        }

        return $value;
    }

    public function addUploadError(int|string $upload_id, string $error_message): void
    {
        if (! isset($_SESSION['uploads_error']) || ! is_array($_SESSION['uploads_error'])) {
            $_SESSION['uploads_error'] = [];
        }

        if (! isset($_SESSION['uploads_error'][$upload_id]) || ! is_array($_SESSION['uploads_error'][$upload_id])) {
            $_SESSION['uploads_error'][$upload_id] = [];
        }

        $_SESSION['uploads_error'][$upload_id][] = $error_message;
    }

    public function readyForUploadMessage(): ?string
    {

        // CurrentConfig::uploadDir()'s own default ('upload/') is root-relative
        // (Part II), not CWD-relative -- the real is_dir()/is_writable()/
        // chmod() calls below need an absolute path (PHP's CWD tracks the
        // executing script's directory, not necessarily the install root),
        // while $relative_dir stays the short, root-relative form for
        // display (replaces the former PHPWG_ROOT_PATH-stripped './' read).
        $relative_dir = \Piwigo\Config\CurrentConfig::uploadDir();
        $upload_dir = \Piwigo\Core\CurrentPaths::get()->root . $relative_dir;

        if (! is_dir($upload_dir)) {
            if (! is_writable(dirname($upload_dir))) {
                return sprintf(
                    Lang::t('Create the "%s" directory at the root of your Piwigo installation'),
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
                        Lang::t('Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation'),
                        $relative_dir
                    );
                }
            }
        }

        return null;
    }

    /**
     * Return the optimized resize dimensions for a representative, based
     * on maximum display size. There is no need to generate a 4000x3000
     * JPEG from a 4000x3000 HEIC if XXL size is only 1600x1200.
     *
     * @return int[] [width, height]
     */
    private static function getOptimalDimensionsForRepresentative(): array
    {
        $enabled = ImageStdParams::get_defined_type_map();

        $disabled_raw = \Piwigo\Core\ArrayHelper::safeUnserialize(ImageStdParams::get_disabled_type_map());
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
            // ImageStdParams::THREE_XLARGE/FOUR_XLARGE), which get_defined_type_map() genuinely
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

        return [(int) ((float) $w * $margin_coef), (int) ((float) $h * $margin_coef)];
    }
}
