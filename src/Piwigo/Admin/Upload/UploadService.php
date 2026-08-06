<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use ArrayIterator;
use Doctrine\ORM\EntityManagerInterface;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use LogicException;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Env;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Location\LocEndAddFormat;
use Piwigo\Event\Location\LocEndAddUploadedFile;
use Piwigo\Event\Picture\UploadFile;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Metadata\MetadataService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;
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
     * Advisory-lock acquisition timeout for addUploadedFile()'s
     * uploadDetectDuplicate() race fix -- generous enough to cover a
     * concurrent upload's own full image-processing pipeline (resize,
     * representative generation), same reasoning as PwgImages::
     * UPLOAD_UNIQUENESS_LOCK_TIMEOUT_SECONDS.
     */
    private const int DUP_DETECT_LOCK_TIMEOUT_SECONDS = 30;

    /**
     * @see UploadService::imageStdParams()
     */
    private static ?ImageStdParams $imageStdParamsFallback = null;

    public function __construct(
        private readonly Lang $lang,
        private readonly CurrentLogger $currentLogger,
        private readonly StorageRegistry $storageRegistry,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ConfigService $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityService $activityService,
        private readonly MetadataService $metadataService,
        private readonly ImageService $imageService,
        private readonly CurrentConfig $currentConfig,
        private readonly WsContext $wsContext,
        private readonly CurrentUser $currentUser,
        private readonly Paths $paths,
        private readonly DbCredentials $dbCredentials,
    ) {}

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
                'error_message' => $this->lang->t('The original maximum width must be a number between %d and %d'),
            ],

            'original_resize_maxheight' => [
                'default' => 2000,
                'min' => 300,
                'max' => 20000,
                'pattern' => '/^\d+$/',
                'can_be_null' => false,
                'error_message' => $this->lang->t('The original maximum height must be a number between %d and %d'),
            ],

            'original_resize_quality' => [
                'default' => 95,
                'min' => 50,
                'max' => 98,
                'pattern' => '/^\d+$/',
                'can_be_null' => false,
                'error_message' => $this->lang->t('The original image quality must be a number between %d and %d'),
            ],
        ];
    }

    /**
     * $data is raw, unvalidated $_POST data (see the only real caller,
     * ConfigurationSubController) -- flagged for Phase 4 (SEC-40/P26
     * Request DTOs), not narrowed now.
     *
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
            } elseif (
                // Every getUploadFormConfig() entry currently has
                // can_be_null => false ('original_resize' also never
                // reaches here at all -- its bool default routes it
                // through the branch above instead), so this whole elseif
                // is unreachable via any real call today; kept for a
                // future field that sets can_be_null => true. Confirmed
                // while investigating a mutation-testing gap: with no
                // reachable input, a mutation here can't be observed
                // either way.
                $upload_form_config[$field]['can_be_null'] and self::isFalsy($value)
            ) {
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
                    // Only the last `|| ! is_scalar($value)` clause can ever
                    // actually be true for a real field -- every clause
                    // before it is therefore always false regardless of
                    // input, making an `||`-to-`&&` swap on any of them (or
                    // the `$pattern === ''` literal) unobservable: `false
                    // [op] false` gives the same result under either
                    // operator, so the guard's outcome still depends
                    // entirely on is_scalar($value). Confirmed while
                    // investigating a mutation-testing gap.
                    continue;
                }

                // The (bool) cast is redundant: `and` already coerces its
                // left operand to bool, so removing it can't change which
                // branch runs. Confirmed while investigating a
                // mutation-testing gap.
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
            EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class)
                ->massUpdateValues($updates);
            $this->entityManager->clear();
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
    public function addUploadedFile(string $source_filepath, UrlServiceInterface $urlService, ?string $original_filename = null, ?array $categories = null, ?int $level = null, ?int $image_id = null, ?string $original_md5sum = null, ?PwgServer $service = null): int
    {
        $logger = $this->currentLogger->get();

        if ($original_filename !== null) {
            $original_filename = htmlspecialchars($original_filename);
        }

        if (isset($original_md5sum)) {
            $md5sum = $original_md5sum;
        } else {
            $md5sum = md5_file($source_filepath);
            if ($md5sum === false) {
                throw new Exception("upload(): unable to compute md5sum of {$source_filepath}");
            }
        }

        // we only try to detect duplicate on a new image, not when updating an existing image
        //
        // Further SQL-modernization audit, Item 1: this is a second,
        // independent duplicate-detection mechanism from add()'s own
        // check_uniqueness (different config flag, different resolution --
        // merge into the existing image rather than reject), with the
        // identical check-then-insert TOCTOU shape. Covered by its own
        // advisory lock, held from this check through the INSERT below --
        // a distinct lock-name namespace ('piwigo_iud_' prefix) from add()'s
        // own ('piwigo_iu_' prefix) so the two mechanisms can never contend
        // on the same lock name and self-deadlock when a
        // single add() call has both active (add() already holds its own
        // lock for the whole duration of this call, including this one).
        $dup_detect_lock_conn = null;
        $dup_detect_lock_name = null;

        if (! isset($image_id) and $this->currentConfig->uploadDetectDuplicate()) {
            $dup_detect_lock_conn = DbConnection::build();
            // GET_LOCK() names are capped at 64 characters -- hashed (with the DB
            // prefix folded into the hashed input for the same collision-avoidance
            // reasoning as add()'s own lock, see PwgImages::add()) rather than
            // concatenated literally.
            $dup_detect_lock_name = 'piwigo_iud_' . sha1($this->dbCredentials->prefix . ':' . $md5sum);
            $dup_detect_lock_acquired = AdvisorySessionLock::acquire(
                $dup_detect_lock_conn,
                $dup_detect_lock_name,
                self::DUP_DETECT_LOCK_TIMEOUT_SECONDS
            );

            if (! $dup_detect_lock_acquired) {
                throw new Exception(__METHOD__ . '(): could not acquire upload duplicate-detection lock for md5sum ' . $md5sum);
            }

            $images_found = $this->imageService->getIdsByMd5sum($md5sum);

            if (count($images_found) > 0) {
                $image_id = $images_found[0];
                $logger->info('[' . __METHOD__ . '] image already exist #' . $image_id . ', we delete the newly uploaded file : ' . $source_filepath);
                unlink($source_filepath);

                // if the destination category is already linked to this photo, no worry,
                // associate_images_to_categories perfectly handles this case
                $this->addUploadedFileAddToCategories($image_id, $categories);

                AdvisorySessionLock::release($dup_detect_lock_conn, $dup_detect_lock_name);
                return $image_id;
            }
        }

        try {
            $file_path = null;
            // Only ever read in the "new photo" branch below (where it's also
            // assigned) -- declared here so Psalm can see it's always defined
            // by the time it's used, without relying on the two branches'
            // isset($image_id) conditions staying in sync 200 lines apart.
            $dbnow = null;

            if (isset($image_id)) {
                // this photo already exists, we update it
                $existing_paths = $this->imageService->getPathsForIds([$image_id]);
                foreach ($existing_paths as $row) {
                    $file_path = $row['path'];
                }

                if (! isset($file_path)) {
                    throw new ImageProcessingException('[' . __METHOD__ . '] this photo does not exist in the database');
                }

                // Real bug, found while writing a real "update an existing
                // photo" integration test (confirmed live, matching what
                // WsImagesUploadGapsTest's own docblock already documented as
                // a pre-existing 500: "getimagesize(upload/2026/08/01/....jpg):
                // Failed to open stream"): images.path is stored root-relative
                // (see the "new photo" branch's own preg_replace() a bit
                // further down, and addFormat()'s own identical "images.path
                // ... is relative, not yet an absolute path" handling just
                // below in this same class) -- but every downstream use of
                // $file_path past this point (StorageRegistry::stripRoot(),
                // chmod(), get_rotation_angle()/pwgImageInfos()'s own
                // getimagesize()/filesize() calls) requires an absolute
                // filesystem path, exactly like the "new photo" branch's own
                // $file_path already is. Prefixing here, once, right after the
                // DB read, keeps both branches producing the same absolute
                // shape for the rest of the method.
                $file_path = $this->paths->root . $file_path;

                // delete all physical files related to the photo (thumbnail, web site, HD)
                $this->imageService
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
                    throw new Exception(__METHOD__ . '(): preg_split() failed');
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
                $conf_upload_dir = rtrim($this->currentConfig->uploadDir(), '/');
                $upload_dir = sprintf(
                    $this->paths->root . $conf_upload_dir . '/%s/%s/%s',
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
                } elseif ($this->currentConfig->uploadFormAllTypes()) {
                    $original_extension = strtolower(StringHelper::getExtension($original_filename));

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo === false) {
                        throw new Exception(__METHOD__ . '(): finfo_open() failed');
                    }
                    $finfo_type = finfo_file($finfo, $source_filepath);

                    if (in_array($finfo_type, ['image/svg', 'image/svg+xml'], true) and $original_extension !== 'svg') {
                        unlink($source_filepath);
                        $error_msg = 'File extension "' . $original_extension . '" for file "' . $original_filename . '" does not match file MIME type "' . $finfo_type . '"';
                        if ($this->wsContext->isActive() && $service !== null) {
                            $service->sendResponse(new PwgError(415, $error_msg));
                            exit;
                        }

                        throw new ImageProcessingException($error_msg);
                    }

                    // [SEC-21] strip <script>/event-handler content from a
                    // genuinely-matching SVG before it ever reaches storage.
                    $this->sanitizeSvgIfNeeded($source_filepath, is_string($finfo_type) ? $finfo_type : null);

                    $conf_file_ext = $this->currentConfig->fileExtensions();
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
            $upload_root = rtrim($this->paths->root . $this->currentConfig->uploadDir(), '/');
            $upload_rel_path = StorageRegistry::stripRoot($upload_root, $file_path);
            $upload_stream = fopen($source_filepath, 'rb');
            if ($upload_stream !== false) {
                $this->storageRegistry->get('uploads')
                    ->writeStream($upload_rel_path, $upload_stream);
                fclose($upload_stream);
                if (! is_uploaded_file($source_filepath)) {
                    @unlink($source_filepath);
                }
            }
            @chmod($file_path, 0644);

            // handle the uploaded file type by potentially making a
            // pwg_representative file.
            $representative_ext = $this->eventDispatcher->dispatchChange(new UploadFile(null, $file_path))
                ->representativeExt;

            $logger->info(__METHOD__ . ' : force cache generation, representative_ext = ' . ($representative_ext ?? ''));

            if (PwgImage::get_library() !== 'gd') {
                if ($this->currentConfig->originalResize()) {
                    $original_resize_maxwidth = $this->currentConfig->originalResizeMaxwidth();

                    $original_resize_maxheight = $this->currentConfig->originalResizeMaxheight();

                    $need_resize = $this->needResize($file_path, $original_resize_maxwidth, $original_resize_maxheight);

                    if ($need_resize) {
                        $img = new PwgImage($file_path, $this->currentLogger, $this->eventDispatcher, $this->currentConfig);

                        $original_resize_quality = $this->currentConfig->originalResizeQuality();

                        $img->pwg_resize(
                            $file_path,
                            $original_resize_maxwidth,
                            $original_resize_maxheight,
                            $original_resize_quality,
                            $this->currentConfig->uploadFormAutomaticRotation(),
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
                    'added_by' => $this->currentUser->get()
                        ->id->value,
                    'rotation' => $rotation,
                ];

                if (isset($level)) {
                    $update['level'] = $level;
                }

                $this->imageService->updateFields($image_id, $update);
            } else {
                // database registration
                $file = $original_filename ?? basename($file_path);
                $insert = [
                    'file' => $file,
                    'name' => StringHelper::getNameFromFile($file),
                    'date_available' => $dbnow,
                    // Otherwise relies on the schema's own DEFAULT
                    // CURRENT_TIMESTAMP, which reads the real DB-server clock --
                    // invisible to Env::now()'s PIWIGO_TEST_NOW freeze, same
                    // reasoning as date_available above. Reuses $dbnow rather
                    // than a second Env::now() call so both columns agree on the
                    // exact same instant, matching what the DB default would
                    // have produced for a single INSERT.
                    'lastmodified' => $dbnow,
                    'path' => preg_replace('#^' . preg_quote($this->paths->root) . '#', '', $file_path),
                    'filesize' => $file_infos['filesize'],
                    'width' => $file_infos['width'],
                    'height' => $file_infos['height'],
                    'md5sum' => $md5sum,
                    'added_by' => $this->currentUser->get()
                        ->id->value,
                    'rotation' => $rotation,
                ];

                if (isset($level)) {
                    $insert['level'] = $level;
                }

                if (isset($representative_ext)) {
                    $insert['representative_ext'] = $representative_ext;
                }

                $image_id = $this->imageService->insertImage($insert);
                $this->activityService
                    ->record('photo', $image_id, 'add');
            }
        } finally {
            // $dup_detect_lock_name is always assigned in the same branch as
            // $dup_detect_lock_conn (see above), so checking the connection
            // alone is sufficient -- PHPStan proves this itself, flagging a
            // separate null-check on the name as redundant.
            if ($dup_detect_lock_conn !== null) {
                AdvisorySessionLock::release($dup_detect_lock_conn, $dup_detect_lock_name);
            }
        }
        $this->entityManager->clear();

        $this->addUploadedFileAddToCategories($image_id, $categories);

        // update metadata from the uploaded file (exif/iptc)
        if ($this->currentConfig->useExif() and ! function_exists('exif_read_data')) {
            $this->currentConfig->setUseExif(false);
        }
        $this->metadataService
            ->syncMetadata([$image_id]);

        // cache a derivative
        //
        // Real bug, found alongside the update-branch $file_path one above
        // (also confirmed live via WsImagesUploadGapsTest's own documented
        // "Undefined array key 'file' in SrcImage.php"): SrcImage::
        // __construct()'s own docblock states id/path/file are all
        // NOT-NULL DB columns it trusts will be present -- unlike
        // representative_ext, which it reads via `?? null` as genuinely
        // optional -- but this query never selected `file`, so every real
        // addUploadedFile() call (new photo or update) hit that warning
        // right here, not just the update branch.
        $image_infos = $this->imageService->getImageRow($image_id);
        if ($image_infos === null) {
            throw new Exception(__METHOD__ . '(): image #' . $image_id . ' not found right after being saved');
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
        HttpClientService::fetch($derivative_url, $this->currentConfig);

        $this->eventDispatcher->dispatchNotify(new LocEndAddUploadedFile($image_infos));

        return $image_id;
    }

    /**
     * @param int[]|null $categories
     */
    private function addUploadedFileAddToCategories(int $image_id, ?array $categories): void
    {

        if (! $this->currentConfig->loungeActive()) {
            // check if we need to use the lounge from now
            $nb_photos = $this->imageService->getTotalImageCount();
            if ($nb_photos >= $this->currentConfig->loungeActivateThreshold()) {
                $this->configService->confUpdateParam('lounge_active', true, true);
            }
        }

        if (isset($categories) and count($categories) > 0) {
            $imageConn = DbConnection::build();
            $imageService = $this->imageService;

            if ($this->currentConfig->loungeActive()) {
                // fillLounge() requires int keys for $categories; a WS param
                // forced into an array by makeArrayParam() could theoretically
                // carry non-sequential/string keys, so reindex to guarantee it.
                $imageService->fillLounge([$image_id], array_values($categories));
            } else {
                $imageService->associateImagesToCategories([$image_id], $categories);
            }
        }

        if (! $this->currentConfig->loungeActive()) {
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
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_use_errors);
        if (! $loaded || ! $dom->documentElement instanceof DOMElement) {
            return;
        }

        $xpath = new DOMXPath($dom);

        // DOMXPath::query() only returns false for a malformed XPath
        // *expression* string -- both expressions below are fixed,
        // always-syntactically-valid literals, never user input, so the
        // `false` branch of each ternary is unreachable in practice: an
        // actual DOMNodeList is the only value either can ever produce
        // here. That makes `!== false` and `!== true` equivalent for both
        // (a DOMNodeList is !== either boolean), confirmed while
        // investigating a mutation-testing gap.
        $scriptNodes = $xpath->query('//*[local-name()="script"]');
        // Every instanceof check in this loop and the next is a PHPStan-
        // narrowing guard over a case the DOM API itself already
        // guarantees: every $scriptNode here comes from a live query
        // result, so it's always a real DOMNode, and its parentNode is
        // never null for an attached node (even a document-root element's
        // "parent" is the owning DOMDocument, itself a DOMNode).
        foreach (iterator_to_array($scriptNodes !== false ? $scriptNodes : new ArrayIterator([])) as $scriptNode) {
            if ($scriptNode instanceof DOMNode && $scriptNode->parentNode instanceof DOMNode) {
                $scriptNode->parentNode->removeChild($scriptNode);
            }
        }

        $attrNodes = $xpath->query('//@*');
        // Same PHPStan-narrowing shape: a '//@*' XPath query only ever
        // yields DOMAttr nodes by definition.
        foreach (iterator_to_array($attrNodes !== false ? $attrNodes : new ArrayIterator([])) as $attrNode) {
            if ($attrNode instanceof DOMAttr && stripos($attrNode->nodeName, 'on') === 0) {
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
        if (! $this->currentConfig->isFormatsEnabled()) {
            throw new ImageProcessingException('[' . __METHOD__ . '] formats are disabled');
        }

        $authorized_format_exts = $this->currentConfig->formatExtensions();

        if (! in_array($format_ext, $authorized_format_exts, true)) {
            throw new ImageProcessingException('[' . __METHOD__ . '] unexpected format extension "' . $format_ext . '" (authorized extensions: ' . implode(', ', $authorized_format_exts) . ')');
        }

        $images = $this->imageService->getPathsForIds([$format_of]);

        if (! isset($images[0])) {
            throw new ImageProcessingException('[' . __METHOD__ . '] this photo does not exist in the database');
        }

        $image_0_path = $images[0]['path'];
        $format_path = dirname($image_0_path) . '/pwg_format/';
        $format_path .= StringHelper::getFilenameWoExtension(basename($image_0_path));
        $format_path .= '.' . $format_ext;

        // Same StorageRegistry-routed migration as addUploadedFile()'s own
        // move_uploaded_file()/rename() pair above -- $format_path here (built
        // from the DB-stored images.path column) is relative, not yet an
        // absolute path, so it needs normalizing before stripRoot() can
        // compute the disk-relative path. prepareDirectory() below must use
        // that same normalized absolute path too: mkdir() on the bare
        // relative $format_path resolves against the PHP process's cwd
        // (the document root, public/, on a real request), silently
        // creating a stray "public/upload/..." directory tree instead of
        // the real one -- a real, previously-shipped bug fixed here.
        $paths = $this->paths;
        $format_root = $paths->root . $this->currentConfig->uploadDir();
        $format_abs_path = $paths->root . ltrim(str_replace(['\\', '/./'], ['/', '/'], $format_path), '/');
        $format_rel_path = StorageRegistry::stripRoot($format_root, $format_abs_path);

        $this->prepareDirectory(dirname($format_abs_path));

        $format_stream = fopen($source_filepath, 'rb');
        if ($format_stream !== false) {
            $this->storageRegistry->get('uploads')
                ->writeStream($format_rel_path, $format_stream);
            fclose($format_stream);
            if (! is_uploaded_file($source_filepath)) {
                @unlink($source_filepath);
            }
        }
        @chmod($format_abs_path, 0644);

        $file_infos = $this->pwgImageInfos($format_abs_path);

        $insert = [
            'image_id' => $format_of,
            'ext' => $format_ext,
            'filesize' => $file_infos['filesize'],
        ];

        $filesize = (int) $file_infos['filesize'];

        $existing_format_id = $this->imageService->getFormatIdByImageAndExt((int) $format_of, $format_ext);
        if ($existing_format_id !== null) {
            $this->imageService->updateFormatFilesize($existing_format_id, $filesize);
            $format_id = $existing_format_id;
            $add_status = 'update';
        } else {
            $format_id = $this->imageService->insertFormat((int) $format_of, $format_ext, $filesize);
            $add_status = 'add';
        }

        $this->activityService
            ->record('photo', $format_of, 'edit', [
                'action' => 'add format',
                'format_ext' => $format_ext,
                'format_id' => $format_id,
            ]);

        $format_infos = $insert;
        $format_infos['format_id'] = $format_id;

        $this->eventDispatcher->dispatchNotify(new LocEndAddFormat($format_infos));

        return $add_status;
    }

    public static function uploadFilePdf(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = self::currentLogger();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (! in_array(strtolower(StringHelper::getExtension($file_path)), ['pdf'], true)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        $ext = CurrentConfig::current()->pdfRepresentativeExt();
        $jpg_quality = CurrentConfig::current()->pdfJpgQuality();

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = ImagePathHelper::originalToRepresentative($file_path, $ext);
        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = CurrentConfig::current()->extImagickDir();
        // [SEC-16] escapeshellarg() on the dir prefix and both real paths
        // below -- same pattern P19 established in PwgImage.php/
        // ImageExtImagick.php; the original never escaped an embedded
        // '"' or shell metacharacter in either path.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();
        // Both (string) casts below are redundant under `.` concatenation
        // (which stringifies int|false identically to an explicit cast) --
        // confirmed while investigating a mutation-testing gap, same
        // reasoning as StatsPageRenderer::getDateObject()'s own equivalent
        // casts.
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

        $event->representativeExt = $representative_ext;

        return $event;
    }

    public static function uploadFileHeic(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = self::currentLogger();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (! in_array(strtolower(StringHelper::getExtension($file_path)), ['heic'], true)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        $ext = 'jpg';

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = ImagePathHelper::originalToRepresentative($file_path, $ext);
        self::prepareDirectoryStatic(dirname($representative_file_path));

        [$w, $h] = self::getOptimalDimensionsForRepresentative();

        $ext_imagick_dir = CurrentConfig::current()->extImagickDir();
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

        $event->representativeExt = $representative_ext;

        return $event;
    }

    public static function uploadFileTiff(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = self::currentLogger();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (! in_array(strtolower(StringHelper::getExtension($file_path)), ['tif', 'tiff'], true)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = dirname($file_path) . '/pwg_representative/';
        $representative_file_path .= StringHelper::getFilenameWoExtension(basename($file_path)) . '.';

        $conf_tiff_representative_ext = CurrentConfig::current()->tiffRepresentativeExt();
        $representative_ext = $conf_tiff_representative_ext;
        $representative_file_path .= $representative_ext;

        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = CurrentConfig::current()->extImagickDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();
        // (string) is redundant under `.` concatenation -- see uploadFilePdf()'s
        // own identical note above.
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));

        if ($representative_ext === 'jpg') {
            $exec .= ' -quality 98';
        }

        $dest = pathinfo($representative_file_path);
        // prepareDirectoryStatic() just above already guarantees $dest['dirname']
        // exists (it either finds an existing dir or throws its own
        // ImageProcessingException trying to create one) -- realpath()
        // returning false here would require the directory to vanish in
        // the brief window between that call and this one, not a
        // realistically triggerable case. Confirmed while investigating a
        // mutation-testing gap.
        $dest_dirname_realpath = realpath($dest['dirname']);
        if ($dest_dirname_realpath === false) {
            throw new Exception("unable to resolve directory {$dest['dirname']}");
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

        $event->representativeExt = StringHelper::getExtension($representative_file_abspath);

        return $event;
    }

    public static function uploadFileVideo(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = self::currentLogger();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        $ffmpeg_video_exts = [ // extensions tested with FFmpeg
            'wmv', 'mov', 'mkv', 'mp4', 'mpg', 'flv', 'asf', 'xvid', 'divx', 'mpeg',
            'avi', 'rm', 'm4v', 'ogg', 'ogv', 'webm', 'webmv',
        ];

        if (! in_array(strtolower(StringHelper::getExtension($file_path)), $ffmpeg_video_exts, true)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        $representative_file_path = dirname($file_path) . '/pwg_representative/';
        $representative_file_path .= StringHelper::getFilenameWoExtension(basename($file_path)) . '.';

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
        $ffmpeg_dir = CurrentConfig::current()->ffmpegDir();
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
            $event->representativeExt = null;

            return $event;
        }

        $event->representativeExt = $representative_ext;

        return $event;
    }

    public static function uploadFilePsd(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = self::currentLogger();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (! in_array(strtolower(StringHelper::getExtension($file_path)), ['psd'], true)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = dirname($file_path) . '/pwg_representative/';
        $representative_file_path .= StringHelper::getFilenameWoExtension(basename($file_path)) . '.';

        $representative_ext = 'png';
        $representative_file_path .= $representative_ext;

        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = CurrentConfig::current()->extImagickDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();

        // (string) is redundant under `.` concatenation -- see uploadFilePdf()'s
        // own identical note above.
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));

        $dest = pathinfo($representative_file_path);
        // prepareDirectoryStatic() just above already guarantees $dest['dirname']
        // exists (see uploadFileTiff()'s own identical comment for the
        // full reasoning). Confirmed while investigating a mutation-testing
        // gap.
        $dest_dirname_realpath = realpath($dest['dirname']);
        if ($dest_dirname_realpath === false) {
            throw new Exception("unable to resolve directory {$dest['dirname']}");
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

        $event->representativeExt = StringHelper::getExtension($representative_file_abspath);

        return $event;
    }

    public static function uploadFileEps(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = self::currentLogger();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (PwgImage::get_library() !== 'ext_imagick') {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (! in_array(strtolower(StringHelper::getExtension($file_path)), ['eps'], true)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        // if the representative is "jpg", the derivatives are ugly. With "png" it's fine.
        $ext = 'png';

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = ImagePathHelper::originalToRepresentative($file_path, $ext);
        self::prepareDirectoryStatic(dirname($representative_file_path));

        // convert -density 300 image.eps -resize 2048x2048 image.png

        $ext_imagick_dir = CurrentConfig::current()->extImagickDir();
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . PwgImage::get_ext_imagick_command();
        // (string) is redundant under `.` concatenation -- see uploadFilePdf()'s
        // own identical note above.
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

        $event->representativeExt = $representative_ext;

        return $event;
    }

    private function prepareDirectory(string $directory): void
    {
        self::prepareDirectoryStatic($directory);
    }

    private static function prepareDirectoryStatic(string $directory): void
    {
        if (! is_dir($directory)) {
            // PHP_OS is a compile-time constant -- always "Linux" in this
            // project's real dev/CI/production environments, so this
            // branch is unreachable here (same "unfakeable compile-time
            // constant" reasoning as Core\ContainerDetectorTest's own
            // documented PHP_OS case).
            if (str_starts_with(PHP_OS, 'WIN')) {
                $directory = str_replace('/', DIRECTORY_SEPARATOR, $directory);
            }
            // Real bug, found live via mutation testing on FilesystemHelper::
            // mkgetdir()'s own sibling umask(0)/restore pair: this method
            // never restored the process-wide umask afterward, permanently
            // corrupting it to 0 for the rest of the request (or, in a
            // shared PHPUnit/Pest worker, for every later test too) --
            // matches piwigo16-rewrite's own reference UploadService, which
            // delegates directory creation to Filesystem::mkgetdir() instead
            // of reimplementing it here without the restore.
            $umask = umask(0000);
            $recursive = true;
            $created = @mkdir($directory, 0777, $recursive);
            umask($umask);
            if (! $created) {
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

        FilesystemHelper::secureDirectory($directory);
    }

    /**
     * Singleton/service-locator elimination campaign, Phase 12 sub-phase
     * 12F-1: the `uploadFileXxx()` event handlers below must stay static
     * (EventDispatcher::callablesEqual()'s closure-identity dedup only
     * correctly no-ops repeat registrations for a static method's closure;
     * an instance method's closure would double-register across this
     * class's own real non-singleton `new UploadService(...)` sites), so
     * they can't read the constructor-injected `$this->currentLogger`
     * `needResize()` uses below -- this resolves the container-shared
     * instance directly instead, same "container resolve, not a
     * constructor property" shape as Core\Logger::pageState(). Falls back
     * to a fresh, no-op `severity => OFF` Logger pre-boot, matching
     * CurrentLogger::getStatic()'s own former fallback exactly.
     */
    private static function currentLogger(): Logger
    {
        if (Kernel::isBooted()) {
            $currentLogger = Kernel::container()->get(CurrentLogger::class);
            if (! $currentLogger instanceof CurrentLogger) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
            }

            return $currentLogger->get();
        }

        return new Logger([
            'severity' => Logger::OFF,
        ]);
    }

    /**
     * Singleton/service-locator elimination campaign, Phase 12 sub-phase
     * 12F-4: same "must stay static, resolves the container-shared
     * instance directly" reasoning as currentLogger() above --
     * getOptimalDimensionsForRepresentative() (the sole caller) is itself
     * a `private static` helper reached only from the static
     * uploadFileXxx() handlers. Unlike currentLogger()'s fresh-per-call
     * fallback, this replicates ImageStdParams::current()'s own former
     * MEMOIZED pre-boot fallback exactly (a `private static` property here,
     * populated via a real load_from_db() read on first not-booted
     * access) -- ImageStdParams is "load once, read/write many times" per
     * request, so a fresh instance per call would silently lose writes
     * between calls.
     */
    private static function imageStdParams(): ImageStdParams
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(ImageStdParams::class);
            if (! $instance instanceof ImageStdParams) {
                throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
            }

            return $instance;
        }

        if (! self::$imageStdParamsFallback instanceof ImageStdParams) {
            self::$imageStdParamsFallback = new ImageStdParams();
            self::$imageStdParamsFallback->load_from_db();
        }

        return self::$imageStdParamsFallback;
    }

    private function needResize(string $image_filepath, int $max_width, int $max_height): bool
    {
        $logger = $this->currentLogger->get();

        $picture_ext = $this->currentConfig->pictureExtensions();
        if (! in_array(strtolower(StringHelper::getExtension($image_filepath)), $picture_ext, true)) {
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
     * @return array{width: ?int, height: ?int, filesize: float}
     */
    public function pwgImageInfos(string $path): array
    {
        $image_size = getimagesize($path);
        // Not decodable as an image at all (e.g. a non-picture file
        // uploaded via CurrentConfig::uploadFormAllTypes()) -- width/height
        // are genuinely unknown, not an error: piwigo_images.width/height
        // are nullable columns precisely for this case (see the schema),
        // and addFormat()'s own call to this method (the only other real
        // caller) never reads width/height at all. This used to throw
        // unconditionally, crashing every non-image upload; same fix
        // family as PwgImage::get_rotation_angle() in this same
        // coverage-gap-closure pass.
        if ($image_size === false) {
            $width = null;
            $height = null;
        } else {
            [$width, $height] = $image_size;
        }
        $filesize_bytes = filesize($path);
        if ($filesize_bytes === false) {
            // same rationale as the getimagesize() guard above: every caller
            // stores this straight into the database, no sane fallback shape.
            throw new Exception(__METHOD__ . '(): filesize() failed for ' . $path);
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
        if ($this->currentConfig->uploadFormAllTypes()) {
            $extensions = $this->currentConfig->fileExtensions();
        } else {
            $extensions = $this->currentConfig->pictureExtensions();
        }

        return array_unique(array_map(strtolower(...), $extensions));
    }

    public function fileUploadErrorMessage(int $error_code): string
    {
        // 'upload_max_filesize' is a real, always-registered core PHP
        // directive -- ini_get() only ever returns false for an unknown
        // directive name, so the `=== false` branch below is unreachable
        // in practice (confirmed live: ini_get('upload_max_filesize')
        // always returns a string). Confirmed while investigating a
        // mutation-testing gap, same reasoning as
        // ServerInfoService::curatedInfo()'s own equivalent guard.
        $ini_size = $this->getIniSize('upload_max_filesize', false);

        return match ($error_code) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                $this->lang->t('The uploaded file exceeds the upload_max_filesize directive in php.ini: %sB'),
                $ini_size === false ? 'unknown' : $ini_size
            ),
            UPLOAD_ERR_FORM_SIZE => $this->lang->t('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
            UPLOAD_ERR_PARTIAL => $this->lang->t('The uploaded file was only partially uploaded'),
            UPLOAD_ERR_NO_FILE => $this->lang->t('No file was uploaded'),
            UPLOAD_ERR_NO_TMP_DIR => $this->lang->t('Missing a temporary folder'),
            UPLOAD_ERR_CANT_WRITE => $this->lang->t('Failed to write file to disk'),
            UPLOAD_ERR_EXTENSION => $this->lang->t('File upload stopped by extension'),
            default => $this->lang->t('Unknown upload error'),
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
        $relative_dir = $this->currentConfig->uploadDir();
        $upload_dir = $this->paths->root . $relative_dir;

        if (! is_dir($upload_dir)) {
            if (! is_writable(dirname($upload_dir))) {
                return sprintf(
                    $this->lang->t('Create the "%s" directory at the root of your Piwigo installation'),
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
                        $this->lang->t('Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation'),
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
        $enabled = self::imageStdParams()->get_defined_type_map();
        $disabled = self::imageStdParams()->get_disabled_type_map();

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

            // The (bool) cast is redundant: if() already coerces its
            // condition to bool, so removing it can't change which branch
            // runs. Confirmed while investigating a mutation-testing gap.
            if ((bool) $params) {
                [$w, $h] = $params->sizing->ideal_size;
            }
        }

        $margin_coef = 1.5;

        // Both (float) casts are redundant: $w/$h are always int (the 2000
        // default, or SizingParams::$ideal_size's own int[] contract), and
        // int * float already promotes to float in PHP regardless of an
        // explicit cast on the int operand. Confirmed while investigating
        // a mutation-testing gap.
        return [(int) ((float) $w * $margin_coef), (int) ((float) $h * $margin_coef)];
    }
}
