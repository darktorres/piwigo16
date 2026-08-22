<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use ArrayIterator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Upload\Event\UploadedFileAdded;
use Piwigo\Admin\Upload\Event\UploadFile;
use Piwigo\Admin\Upload\Projection\ImageDimensionsInfo;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Env;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\ImageInsertRow;
use Piwigo\Image\SrcImage;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;

/**
 * [SEC-21] The SVG upload branch validates that the sniffed MIME type
 * matches the ".svg" extension, and sanitizeSvgIfNeeded() strips
 * <script> elements, on*= event-handler attributes, javascript:/data:
 * -scheme href/xlink:href values, and SMIL animation elements from the
 * SVG (via DOMDocument, LIBXML_NONET, DOCTYPE stripped first) before the
 * file is written to permanent storage -- a file that can't be parsed
 * and verified is rejected outright, not stored untouched (P44-I).
 * Content-Disposition: attachment for served SVG/HTML originals is
 * enforced by ActionController itself (P44-I) -- upload/ has lived
 * outside the web-servable document root since the "web-root isolation"
 * hardening (docs/PLAN.md), so a web-server-level rule keyed on an
 * `/upload/...` URL shape (the previous approach here) can no longer
 * ever match a real request.
 *
 * [SEC-16] Every exec() call in this file (PDF/HEIC/TIFF/video/PSD/EPS
 * representative generation) builds its command string with
 * escapeshellarg() on every path/dir component, using the same
 * `escapeshellarg($ext_imagick_dir) . ImageBackend::getExtImagickCommand()`
 * dir-prefix pattern as ImageBackend.php/ImageExtImagick.php.
 *
 * The 6 upload_file_* representative-generation handlers are ordinary
 * instance methods, registered once (`RequestBootstrap.php`) against the
 * container-shared instance: one shared instance per request rather than
 * several redundant ones, standard container hygiene, and `RequestBootstrap::
 * finalize()` registers this listener exactly once per request regardless.
 * `__construct()` is 100% `readonly` properties, so sharing one instance
 * carries no mutable-state risk.
 */
final readonly class UploadService
{
    /**
     * Advisory-lock acquisition timeout for addUploadedFile()'s
     * uploadDetectDuplicate() race fix -- generous enough to cover a
     * concurrent upload's own full image-processing pipeline (resize,
     * representative generation), same reasoning as Images::
     * UPLOAD_UNIQUENESS_LOCK_TIMEOUT_SECONDS.
     */
    private const int DUP_DETECT_LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private Lang $lang,
        private CurrentLogger $currentLogger,
        private StorageRegistry $storageRegistry,
        private EventDispatcher $eventDispatcher,
        private ConfigService $configService,
        private EntityManagerInterface $entityManager,
        private ActivityService $activityService,
        private MetadataService $metadataService,
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private Paths $paths,
        private DbCredentials $dbCredentials,
        private ImageStdParams $imageStdParams,
        private PermissionService $permissionService,
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
     * ConfigurationSubController), not narrowed here.
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
                    'value' => $value ? 'true' : 'false',
                ];
            } elseif (
                // Every getUploadFormConfig() entry currently has
                // can_be_null => false ('original_resize' also never
                // reaches here at all -- its bool default routes it
                // through the branch above instead), so this whole elseif
                // is unreachable via any real call today; kept for a
                // future field that sets can_be_null => true.
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
                    // entirely on is_scalar($value).
                    continue;
                }

                // The (bool) cast is redundant: `and` already coerces its
                // left operand to bool, so removing it can't change which
                // branch runs.
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
            $this->entityManager->getRepository(ConfigEntry::class)
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
     */
    public function addUploadedFile(string $source_filepath, UrlServiceInterface $urlService, ?string $original_filename = null, ?array $categories = null, ?int $level = null, ?int $image_id = null, ?string $original_md5sum = null): int
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
        // This is a second, independent duplicate-detection mechanism
        // from add()'s own
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

        if (! isset($image_id) and $this->currentConfig->uploadDetectDuplicate) {
            // Deliberately its own connection, not $this->entityManager's
            // shared one: GET_LOCK()/RELEASE_LOCK() are session-scoped to
            // whichever connection acquired them, and this exact object
            // (not a fresh resolve) is threaded through to every
            // AdvisorySessionLock::release() call below and past the
            // upload_id/finfo() check further down -- reusing the
            // long-lived shared connection would tie this lock's lifetime
            // to however Doctrine happens to manage that connection
            // outside this method's own control, a real correctness risk
            // for no real construction-cost benefit ($this->entityManager
            // is already built regardless).
            $dup_detect_lock_conn = DbConnection::build();
            // GET_LOCK() names are capped at 64 characters -- hashed (with the
            // database name folded into the hashed input for the same
            // collision-avoidance reasoning as add()'s own lock, see
            // Images::add()) rather than concatenated literally.
            $dup_detect_lock_name = 'piwigo_iud_' . sha1($this->dbCredentials->database . ':' . $md5sum);
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
                    $file_path = $row->path;
                }

                if (! isset($file_path)) {
                    throw new ImageProcessingException('[' . __METHOD__ . '] this photo does not exist in the database');
                }

                // images.path is stored root-relative
                // (see the "new photo" branch's own preg_replace() a bit
                // further down, and addFormat()'s own identical "images.path
                // ... is relative, not yet an absolute path" handling just
                // below in this same class) -- but every downstream use of
                // $file_path past this point (StorageRegistry::stripRoot(),
                // chmod(), getRotationAngle()/pwgImageInfos()'s own
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
                // drives both images.date_available and the upload
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
                // CurrentConfig::uploadDir()'s own default already ends in '/'
                // ('upload/'), so appending another literal '/' before %s below
                // produced a double slash (e.g. 'upload//2026/08/01/...') in
                // every stored images.path -- rtrim() here matches the same
                // defensive normalization this class's own addUploadedFile()
                // already applies a few lines up ($upload_root).
                $conf_upload_dir = rtrim($this->currentConfig->uploadDir, '/');
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
                } elseif ($this->currentConfig->uploadFormAllTypes) {
                    $original_extension = strtolower(StringHelper::getExtension($original_filename));

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo === false) {
                        throw new Exception(__METHOD__ . '(): finfo_open() failed');
                    }
                    $finfo_type = finfo_file($finfo, $source_filepath);

                    // [P44-I] Extends the extension-vs-sniffed-MIME cross
                    // -check SVG already had to any upload finfo sniffs as
                    // HTML-ish too, matching it with at least as much
                    // scrutiny as SVG (previously the only extension in
                    // this whole branch that got any cross-check at all).
                    if (in_array($finfo_type, ['image/svg', 'image/svg+xml'], true) and $original_extension !== 'svg') {
                        unlink($source_filepath);
                        $error_msg = 'File extension "' . $original_extension . '" for file "' . $original_filename . '" does not match file MIME type "' . $finfo_type . '"';
                        throw new UnsupportedMediaTypeException($error_msg);
                    }
                    if (in_array($finfo_type, ['text/html', 'text/plain'], true) and ! in_array($original_extension, ['html', 'htm', 'txt'], true)) {
                        unlink($source_filepath);
                        $error_msg = 'File extension "' . $original_extension . '" for file "' . $original_filename . '" does not match file MIME type "' . $finfo_type . '"';
                        throw new UnsupportedMediaTypeException($error_msg);
                    }

                    // [SEC-21] strip <script>/event-handler/dangerous-URI
                    // content from a genuinely-matching SVG before it ever
                    // reaches storage -- fails closed (P44-I): a file that
                    // can't be parsed and verified is rejected outright,
                    // not stored untouched. Every other extension in
                    // $conf_file_ext below (including html/htm, if an
                    // admin has added either) gets no content sanitization
                    // of any kind -- there is no equivalent for a general
                    // HTML file the way there is for SVG.
                    if (! $this->sanitizeSvgIfNeeded($source_filepath, is_string($finfo_type) ? $finfo_type : null)) {
                        unlink($source_filepath);
                        throw new UnsupportedMediaTypeException('unable to verify uploaded SVG is safe to store');
                    }

                    $conf_file_ext = $this->currentConfig->fileExtensions;
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
            // end even without an explicit unlink() (per PHP's documented upload
            // garbage-collection guarantee); the "already local" (rename()) branch
            // still needs an explicit unlink() since nothing else will remove that
            // source.
            $upload_root = rtrim($this->paths->root . $this->currentConfig->uploadDir, '/');
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
            $representative_ext = $this->eventDispatcher->dispatch(new UploadFile(null, $file_path))
                ->representativeExt;

            $logger->info(__METHOD__ . ' : force cache generation, representative_ext = ' . ($representative_ext ?? ''));

            if (ImageBackend::getLibrary() !== 'gd') {
                if ($this->currentConfig->originalResize) {
                    $original_resize_maxwidth = $this->currentConfig->originalResizeMaxwidth;

                    $original_resize_maxheight = $this->currentConfig->originalResizeMaxheight;

                    $need_resize = $this->needResize($file_path, $original_resize_maxwidth, $original_resize_maxheight);

                    if ($need_resize) {
                        $img = new ImageBackend($file_path, $this->currentLogger, $this->currentConfig);

                        $original_resize_quality = $this->currentConfig->originalResizeQuality;

                        $img->pwgResize(
                            $file_path,
                            $original_resize_maxwidth,
                            $original_resize_maxheight,
                            $original_resize_quality,
                            $this->currentConfig->uploadFormAutomaticRotation,
                            false
                        );

                        $img->destroy();
                    }
                }
            }

            // we need to save the rotation angle in the database to compute
            // width/height of "multisizes"
            $rotation_angle = ImageBackend::getRotationAngle($file_path);
            $rotation = ImageBackend::getRotationCodeFromAngle($rotation_angle);

            $file_infos = $this->pwgImageInfos($file_path);

            if (isset($image_id)) {
                $update = [
                    'file' => $original_filename ?? basename($file_path),
                    'filesize' => $file_infos->filesize,
                    'width' => $file_infos->width,
                    'height' => $file_infos->height,
                    'md5sum' => $md5sum,
                    'added_by' => $this->currentUser->get()
                        ->id->value,
                    'rotation' => $rotation,
                ];

                if (isset($level)) {
                    $update['level'] = $level;
                }

                $this->imageService->updateFields(ImageId::from($image_id), $update);
            } else {
                // database registration
                $file = $original_filename ?? basename($file_path);
                // preg_replace() can return null on a PCRE engine error
                // (never in practice for this simple anchored-prefix
                // pattern) -- falls back to the original, unstripped
                // $file_path rather than silently writing a NULL into the
                // images.path NOT NULL column.
                $insert_path = preg_replace('#^' . preg_quote($this->paths->root) . '#', '', $file_path) ?? $file_path;
                $insert = new ImageInsertRow(
                    file: $file,
                    name: StringHelper::getNameFromFile($file),
                    dateAvailable: $dbnow,
                    // Otherwise relies on the schema's own DEFAULT
                    // CURRENT_TIMESTAMP, which reads the real DB-server clock --
                    // invisible to Env::now()'s PIWIGO_TEST_NOW freeze, same
                    // reasoning as dateAvailable above. Reuses $dbnow rather
                    // than a second Env::now() call so both columns agree on the
                    // exact same instant, matching what the DB default would
                    // have produced for a single INSERT.
                    lastmodified: $dbnow,
                    path: $insert_path,
                    filesize: $file_infos->filesize,
                    width: $file_infos->width,
                    height: $file_infos->height,
                    md5sum: $md5sum,
                    addedBy: $this->currentUser->get()
                        ->id->value,
                    rotation: $rotation,
                    level: $level,
                    representativeExt: $representative_ext,
                );

                $image_id = $this->imageService->insertImage($insert);
                $this->activityService
                    ->record('photo', $image_id, 'add');
            }
        } finally {
            // $dup_detect_lock_name is always assigned in the same branch as
            // $dup_detect_lock_conn (see above), so checking the connection
            // alone is sufficient -- PHPStan proves this itself, flagging a
            // separate null-check on the name as redundant.
            if ($dup_detect_lock_conn instanceof Connection) {
                AdvisorySessionLock::release($dup_detect_lock_conn, $dup_detect_lock_name);
            }
        }
        $this->entityManager->clear();

        $this->addUploadedFileAddToCategories($image_id, $categories);

        // update metadata from the uploaded file (exif/iptc)
        if ($this->currentConfig->useExif and ! function_exists('exif_read_data')) {
            $this->currentConfig->useExif = false;
        }
        $this->metadataService
            ->syncMetadata([$image_id], $this->permissionService, $this->entityManager);

        // cache a derivative
        //
        // SrcImage::
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

        $this->eventDispatcher->dispatch(new UploadedFileAdded($image_infos));

        return $image_id;
    }

    /**
     * @param int[]|null $categories
     */
    private function addUploadedFileAddToCategories(int $image_id, ?array $categories): void
    {

        if (! $this->currentConfig->loungeActive) {
            // check if we need to use the lounge from now
            $nb_photos = $this->imageService->getTotalImageCount();
            if ($nb_photos >= $this->currentConfig->loungeActivateThreshold) {
                $this->configService->confUpdateParam('lounge_active', true, true);
            }
        }

        if (isset($categories) and count($categories) > 0) {
            $imageService = $this->imageService;

            if ($this->currentConfig->loungeActive) {
                // fillLounge() requires int keys for $categories; a caller-supplied
                // array could theoretically carry non-sequential/string keys,
                // so reindex to guarantee it.
                $imageService->fillLounge([$image_id], array_values($categories));
            } else {
                $imageService->associateImagesToCategories([$image_id], $categories);
            }
        }

        if (! $this->currentConfig->loungeActive) {
            PermissionCacheInvalidator::invalidate();
        }
    }

    /**
     * [SEC-21] Strips <script> elements, on*= event-handler attributes,
     * javascript:/data:-scheme href/xlink:href attribute values, and SMIL
     * animation elements (<animate>/<set>/<animateTransform>/
     * <animateMotion> -- no legitimate use in an untrusted upload) from a
     * genuinely-sniffed SVG before it's written to permanent storage --
     * the caller already confirmed the extension matches the MIME type;
     * this closes the remaining "genuinely-named .svg with embedded
     * script" stored-XSS gap. Same safe-parsing shape as
     * MetadataService::parseSvgDimensions() (SEC-20): strip <!DOCTYPE>
     * first (a bracketed internal subset is consumed correctly, not just
     * up to its own first '>' -- P44-I), then DOMDocument with
     * LIBXML_NONET so no external entity/network fetch can happen during
     * parsing.
     *
     * Returns false (reject the upload entirely) for a file that fails to
     * parse as XML -- deliberately fail-closed (P44-I), not "leave the
     * file untouched": a file this method can't parse and verify is not a
     * file it can certify sanitized, and finfo already confirmed it's
     * genuinely SVG-typed by this point, so a parse failure here is a
     * malformed/adversarial file, not a legitimate non-SVG one slipping
     * through (that case is filtered by the caller's own MIME check,
     * which never reaches this method at all).
     */
    private function sanitizeSvgIfNeeded(string $source_filepath, ?string $finfo_type): bool
    {
        if (! in_array($finfo_type, ['image/svg', 'image/svg+xml'], true)) {
            return true;
        }

        $xml = file_get_contents($source_filepath);
        if ($xml === false) {
            return false;
        }

        // Consumes an optional bracketed internal subset (the shape
        // needed to declare a custom entity/attlist) before the
        // DOCTYPE's own closing '>' -- the naive `[^>]*` this replaces
        // stopped at the *first* '>', which can sit inside that internal
        // subset, leaving a mangled ']>' remnant that used to make the
        // parse below fail silently (and, before P44-I, store the file
        // untouched instead of rejecting it).
        $xml = preg_replace('/<!DOCTYPE[^>\[]*(\[[^\]]*\])?[^>]*>/is', '', $xml);
        if ($xml === null || $xml === '') {
            return false;
        }

        // libxml_use_internal_errors() (not @) so a malformed upload
        // doesn't surface as a PHP-level warning at all -- parse errors
        // are just discarded, matching the reject-the-upload fallback
        // below.
        $previous_use_errors = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_use_errors);
        if (! $loaded || ! $dom->documentElement instanceof DOMElement) {
            return false;
        }

        $xpath = new DOMXPath($dom);

        // DOMXPath::query() only returns false for a malformed XPath
        // *expression* string -- every expression below is a fixed,
        // always-syntactically-valid literal, never user input, so the
        // `false` branch of each ternary is unreachable in practice: an
        // actual DOMNodeList is the only value either can ever produce
        // here. That makes `!== false` and `!== true` equivalent for both
        // (a DOMNodeList is !== either boolean).
        $scriptNodes = $xpath->query('//*[local-name()="script"]');
        // Every instanceof check in this loop and the next few is a
        // PHPStan-narrowing guard over a case the DOM API itself already
        // guarantees: every node here comes from a live query result, so
        // it's always a real DOMNode, and its parentNode is never null
        // for an attached node (even a document-root element's "parent"
        // is the owning DOMDocument, itself a DOMNode).
        foreach (iterator_to_array($scriptNodes !== false ? $scriptNodes : new ArrayIterator([])) as $scriptNode) {
            if ($scriptNode instanceof DOMNode && $scriptNode->parentNode instanceof DOMNode) {
                $scriptNode->parentNode->removeChild($scriptNode);
            }
        }

        // SMIL animation elements can rewrite a safe attribute (e.g. a
        // benign href) to a dangerous URI at runtime, entirely outside
        // any attribute this sanitizer inspects statically -- removed
        // outright, not just neutralized.
        $smilNodes = $xpath->query('//*[local-name()="animate" or local-name()="set" or local-name()="animateTransform" or local-name()="animateMotion"]');
        foreach (iterator_to_array($smilNodes !== false ? $smilNodes : new ArrayIterator([])) as $smilNode) {
            if ($smilNode instanceof DOMNode && $smilNode->parentNode instanceof DOMNode) {
                $smilNode->parentNode->removeChild($smilNode);
            }
        }

        $attrNodes = $xpath->query('//@*');
        // Same PHPStan-narrowing shape: a '//@*' XPath query only ever
        // yields DOMAttr nodes by definition.
        foreach (iterator_to_array($attrNodes !== false ? $attrNodes : new ArrayIterator([])) as $attrNode) {
            if (! $attrNode instanceof DOMAttr) {
                continue;
            }
            if (stripos($attrNode->nodeName, 'on') === 0) {
                $attrNode->ownerElement?->removeAttributeNode($attrNode);
                continue;
            }
            // 'href'/'xlink:href' specifically (not every attribute
            // containing "href" as a substring) -- DOMAttr::$localName is
            // the unprefixed name, so this matches both the plain SVG2
            // `href` and the legacy `xlink:href` form regardless of
            // namespace prefix.
            if (strtolower($attrNode->localName ?? '') === 'href'
                and (bool) preg_match('/^\s*(javascript|data)\s*:/i', $attrNode->value)) {
                $attrNode->ownerElement?->removeAttributeNode($attrNode);
            }
        }

        $sanitized = $dom->saveXML();
        if ($sanitized === false) {
            return false;
        }
        file_put_contents($source_filepath, $sanitized);
        return true;
    }

    /**
     * 1) find infos about the extended image
     * 2) move uploaded file to upload/2022/05/16/pwg_format/20100122003814-449ada00.cr2
     * 3) register in database
     */
    public function addFormat(string $source_filepath, string $format_ext, int $format_of): string
    {
        if (! $this->currentConfig->isFormatsEnabled) {
            throw new ImageProcessingException('[' . __METHOD__ . '] formats are disabled');
        }

        $authorized_format_exts = $this->currentConfig->formatExtensions;

        if (! in_array($format_ext, $authorized_format_exts, true)) {
            throw new ImageProcessingException('[' . __METHOD__ . '] unexpected format extension "' . $format_ext . '" (authorized extensions: ' . implode(', ', $authorized_format_exts) . ')');
        }

        $images = $this->imageService->getPathsForIds([$format_of]);

        if (! isset($images[0])) {
            throw new ImageProcessingException('[' . __METHOD__ . '] this photo does not exist in the database');
        }

        $image_0_path = $images[0]->path;
        $format_path = dirname($image_0_path) . '/pwg_format/';
        $format_path .= StringHelper::getFilenameWoExtension(basename($image_0_path));
        $format_path .= '.' . $format_ext;

        // $format_path here (built from the DB-stored images.path column) is
        // relative, not yet an absolute path, so it needs normalizing before
        // stripRoot() can compute the disk-relative path. prepareDirectory()
        // below must use that same normalized absolute path too: mkdir() on
        // the bare relative $format_path would resolve against the PHP
        // process's cwd (the document root, public/, on a real request),
        // silently creating a stray "public/upload/..." directory tree
        // instead of the real one.
        $paths = $this->paths;
        $format_root = $paths->root . $this->currentConfig->uploadDir;
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
            'filesize' => $file_infos->filesize,
        ];

        $filesize = (int) $file_infos->filesize;

        $existing_format_id = $this->imageService->getFormatIdByImageAndExt(ImageId::from($format_of), $format_ext);
        if ($existing_format_id !== null) {
            $this->imageService->updateFormatFilesize($existing_format_id, $filesize);
            $format_id = $existing_format_id;
            $add_status = 'update';
        } else {
            $format_id = $this->imageService->insertFormat(ImageId::from($format_of), $format_ext, $filesize);
            $add_status = 'add';
        }

        $this->activityService
            ->record('photo', $format_of, 'edit', [
                'action' => 'add format',
                'format_ext' => $format_ext,
                'format_id' => $format_id,
            ]);

        return $add_status;
    }

    public function uploadFilePdf(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = $this->currentLogger->get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (ImageBackend::getLibrary() !== 'ext_imagick') {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (! in_array(strtolower(StringHelper::getExtension($file_path)), ['pdf'], true)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        $ext = $this->currentConfig->pdfRepresentativeExt;
        $jpg_quality = $this->currentConfig->pdfJpgQuality;

        // move the uploaded file to pwg_representative sub-directory
        $representative_file_path = ImagePathHelper::originalToRepresentative($file_path, $ext);
        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = $this->currentConfig->extImagickDir;
        // [SEC-16] escapeshellarg() on the dir prefix and both real paths
        // below -- same pattern established in ImageBackend.php/
        // ImageExtImagick.php; the original never escaped an embedded
        // '"' or shell metacharacter in either path.
        $exec = escapeshellarg($ext_imagick_dir) . ImageBackend::getExtImagickCommand();
        // Both (string) casts below are redundant under `.` concatenation
        // (which stringifies int|false identically to an explicit cast) --
        // same reasoning as StatsPageRenderer::getDateObject()'s own
        // equivalent casts.
        $exec .= ' ' . escapeshellarg((string) realpath($file_path) . '[0]');
        if ($ext === 'jpg') {
            $exec .= ' -quality ' . (string) $jpg_quality;
        }
        $exec .= ' ' . escapeshellarg($representative_file_path);
        // "< /dev/null" -- same stdin-hang guard as ImageExtImagick's own
        // identify/convert calls (a vanished source realpath() quotes to
        // an empty escapeshellarg(), and the CLI may fall back to reading
        // stdin instead of failing fast).
        $exec .= ' < /dev/null 2>&1';
        @exec($exec, $returnarray);

        // Return the extension (if successful) or false (if failed)
        if (file_exists($representative_file_path)) {
            $representative_ext = $ext;
        }

        $event->representativeExt = $representative_ext;

        return $event;
    }

    public function uploadFileHeic(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = $this->currentLogger->get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (ImageBackend::getLibrary() !== 'ext_imagick') {
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

        [$w, $h] = $this->getOptimalDimensionsForRepresentative();

        $ext_imagick_dir = $this->currentConfig->extImagickDir;
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . ImageBackend::getExtImagickCommand();
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));
        $exec .= ' -sampling-factor 4:2:0 -quality 85 -interlace JPEG -colorspace sRGB -auto-orient +repage -resize "' . $w . 'x' . $h . '>"';
        $exec .= ' ' . escapeshellarg($representative_file_path);
        // "< /dev/null" -- same stdin-hang guard as ImageExtImagick's own
        // identify/convert calls (a vanished source realpath() quotes to
        // an empty escapeshellarg(), and the CLI may fall back to reading
        // stdin instead of failing fast).
        $exec .= ' < /dev/null 2>&1';

        $logger->info(__METHOD__ . ', exec = ' . $exec);

        @exec($exec, $returnarray);

        // Return the extension (if successful) or false (if failed)
        if (file_exists($representative_file_path)) {
            $representative_ext = $ext;
        }

        $event->representativeExt = $representative_ext;

        return $event;
    }

    public function uploadFileTiff(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = $this->currentLogger->get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (ImageBackend::getLibrary() !== 'ext_imagick') {
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

        $conf_tiff_representative_ext = $this->currentConfig->tiffRepresentativeExt;
        $representative_ext = $conf_tiff_representative_ext;
        $representative_file_path .= $representative_ext;

        self::prepareDirectoryStatic(dirname($representative_file_path));

        $ext_imagick_dir = $this->currentConfig->extImagickDir;
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . ImageBackend::getExtImagickCommand();
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
        // realistically triggerable case.
        $dest_dirname_realpath = realpath($dest['dirname']);
        if ($dest_dirname_realpath === false) {
            throw new Exception("unable to resolve directory {$dest['dirname']}");
        }
        $exec .= ' ' . escapeshellarg($dest_dirname_realpath . '/' . $dest['basename']);

        // "< /dev/null" -- same stdin-hang guard as ImageExtImagick's own
        // identify/convert calls (a vanished source realpath() quotes to
        // an empty escapeshellarg(), and the CLI may fall back to reading
        // stdin instead of failing fast).
        $exec .= ' < /dev/null 2>&1';
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

    public function uploadFileVideo(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = $this->currentLogger->get();

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
        exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($file_path) . ' < /dev/null', $O, $S);

        if (isset($O[0]) && $O[0] !== '') {
            $second = min(floor((float) $O[0] * 10.0) / 10.0, 2);
        } else {
            $second = 0; // Safest position of the poster
        }

        $logger->info(__METHOD__ . ', Poster at ' . (string) $second . 's');

        // Generate poster, see https://trac.ffmpeg.org/wiki/Seeking
        $ffmpeg_dir = $this->currentConfig->ffmpegDir;
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above (same
        // dir-prefix pattern applied to the ffmpeg/avconv binaries here).
        $ffmpeg = escapeshellarg($ffmpeg_dir) . 'ffmpeg';
        $ffmpeg .= ' -ss ' . (string) $second;  // Fast seeking
        $ffmpeg .= ' -i ' . escapeshellarg($file_path); // Video file
        $ffmpeg .= ' -frames:v 1';  // Extract one frame
        $ffmpeg .= ' ' . escapeshellarg($representative_file_path); // Output file

        $FO = [];
        @exec($ffmpeg . ' < /dev/null 2>&1', $FO, $FS);
        if (isset($FO[0]) && $FO[0] !== '') {
            $logger->debug(__METHOD__ . ', Tried ' . $ffmpeg);
            $logger->debug($FO[0]);
        }

        // Did we generate the file ?
        if (! file_exists($representative_file_path)) {
            // Let's try with avconv if ffmpeg unavailable
            $avconv = str_replace('ffmpeg', 'avconv', $ffmpeg);
            $AO = [];
            @exec($avconv . ' < /dev/null 2>&1', $AO, $AS);

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

    public function uploadFilePsd(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = $this->currentLogger->get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (ImageBackend::getLibrary() !== 'ext_imagick') {
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

        $ext_imagick_dir = $this->currentConfig->extImagickDir;
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . ImageBackend::getExtImagickCommand();

        // (string) is redundant under `.` concatenation -- see uploadFilePdf()'s
        // own identical note above.
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));

        $dest = pathinfo($representative_file_path);
        // prepareDirectoryStatic() just above already guarantees $dest['dirname']
        // exists (see uploadFileTiff()'s own identical comment for the
        // full reasoning).
        $dest_dirname_realpath = realpath($dest['dirname']);
        if ($dest_dirname_realpath === false) {
            throw new Exception("unable to resolve directory {$dest['dirname']}");
        }
        $exec .= ' ' . escapeshellarg($dest_dirname_realpath . '/' . $dest['basename']);

        // "< /dev/null" -- same stdin-hang guard as ImageExtImagick's own
        // identify/convert calls (a vanished source realpath() quotes to
        // an empty escapeshellarg(), and the CLI may fall back to reading
        // stdin instead of failing fast).
        $exec .= ' < /dev/null 2>&1';
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

    public function uploadFileEps(UploadFile $event): UploadFile
    {
        $representative_ext = $event->representativeExt;
        $file_path = $event->filePath;
        $logger = $this->currentLogger->get();

        $logger->info(__METHOD__ . ', $file_path = ' . $file_path . ', $representative_ext = ' . $representative_ext);

        if (isset($representative_ext)) {
            $event->representativeExt = $representative_ext;

            return $event;
        }

        if (ImageBackend::getLibrary() !== 'ext_imagick') {
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

        $ext_imagick_dir = $this->currentConfig->extImagickDir;
        // [SEC-16] see uploadFilePdf()'s escapeshellarg() note above.
        $exec = escapeshellarg($ext_imagick_dir) . ImageBackend::getExtImagickCommand();
        // (string) is redundant under `.` concatenation -- see uploadFilePdf()'s
        // own identical note above.
        $exec .= ' ' . escapeshellarg((string) realpath($file_path));
        $exec .= ' -density 300';
        $exec .= ' -resize 2048x2048';
        $exec .= ' ' . escapeshellarg($representative_file_path);
        // "< /dev/null" -- same stdin-hang guard as ImageExtImagick's own
        // identify/convert calls (a vanished source realpath() quotes to
        // an empty escapeshellarg(), and the CLI may fall back to reading
        // stdin instead of failing fast).
        $exec .= ' < /dev/null 2>&1';
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
            // This method never restored the process-wide umask afterward,
            // which would permanently corrupt it to 0 for the rest of the
            // request (or, in a shared PHPUnit/Pest worker, for every later
            // test too) -- matches piwigo16-rewrite's own reference UploadService, which
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
            // effect: PHP's own filesystem functions, including chmod(),
            // clear the stat cache for the affected path, so this recheck
            // genuinely can and does observe the chmod() above.
            // @phpstan-ignore booleanNot.alwaysTrue
            if (! is_writable($directory)) {
                throw new ImageProcessingException('[prepare_directory] directory "' . $directory . '" has no write access');
            }
        }

        FilesystemHelper::secureDirectory($directory);
    }

    private function needResize(string $image_filepath, int $max_width, int $max_height): bool
    {
        $logger = $this->currentLogger->get();

        $picture_ext = $this->currentConfig->pictureExtensions;
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
        $rotation_angle = ImageBackend::getRotationAngle($image_filepath);
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

    public function pwgImageInfos(string $path): ImageDimensionsInfo
    {
        $image_size = getimagesize($path);
        // Not decodable as an image at all (e.g. a non-picture file
        // uploaded via CurrentConfig::uploadFormAllTypes()) -- width/height
        // are genuinely unknown, not an error: images.width/height are
        // nullable columns precisely for this case (see the schema),
        // and addFormat()'s own call to this method (the only other real
        // caller) never reads width/height at all.
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

        return new ImageDimensionsInfo($width, $height, $filesize);
    }

    /**
     * @return string[]
     */
    public function isValidImageExtension(string $extension): array
    {
        if ($this->currentConfig->uploadFormAllTypes) {
            $extensions = $this->currentConfig->fileExtensions;
        } else {
            $extensions = $this->currentConfig->pictureExtensions;
        }

        return array_unique(array_map(strtolower(...), $extensions));
    }

    public function fileUploadErrorMessage(int $error_code): string
    {
        // 'upload_max_filesize' is a real, always-registered core PHP
        // directive -- ini_get() only ever returns false for an unknown
        // directive name, so the `=== false` branch below is unreachable
        // in practice, same reasoning as
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
        $relative_dir = $this->currentConfig->uploadDir;
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
    private function getOptimalDimensionsForRepresentative(): array
    {
        $enabled = $this->imageStdParams->getDefinedTypeMap();
        $disabled = $this->imageStdParams->getDisabledTypeMap();

        $w = $h = 2000; // safe default values

        foreach (ImageStdParams::getAllTypes() as $type) {
            // getAllTypes() includes types disabled by default (e.g.
            // ImageStdParams::THREE_XLARGE/FOUR_XLARGE), which getDefinedTypeMap() genuinely
            // omits (getEnabledDefaultSizes() unsets them) -- $enabled can
            // really lack a $type key here, so this isn't PHPStan-provable
            // dead code even though its docblock-only DerivativeParams[]
            // return type makes it look that way; array_key_exists() forces a
            // real control-flow check instead of trusting that docblock as
            // exhaustive.
            $params = array_key_exists($type, $enabled) ? $enabled[$type] : ($disabled[$type] ?? null);

            // The (bool) cast is redundant: if() already coerces its
            // condition to bool, so removing it can't change which branch
            // runs.
            if ((bool) $params) {
                [$w, $h] = $params->sizing->ideal_size;
            }
        }

        $margin_coef = 1.5;

        // Both (float) casts are redundant: $w/$h are always int (the 2000
        // default, or SizingParams::$ideal_size's own int[] contract), and
        // int * float already promotes to float in PHP regardless of an
        // explicit cast on the int operand.
        return [(int) ((float) $w * $margin_coef), (int) ((float) $h * $margin_coef)];
    }
}
