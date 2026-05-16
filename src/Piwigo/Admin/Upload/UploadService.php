<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Event\Location\LocEndAddFormat;
use Piwigo\Event\Location\LocEndAddUploadedFile;
use Piwigo\Event\Picture\UploadFile;
use Piwigo\Exception\ConfigException;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeService;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServerRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class UploadService
{
    public function __construct(
        private Connection $conn,
        private CategoryAdminService $categoryAdminService,
        private ConfigService $configService,
        private DerivativeService $derivativeService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private MetadataAdminService $metadataAdminService,
        private UserAdminService $userAdminService,
        private ActivityLogger $activityLogger,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /** @return array<string, array{default: bool|int|string, can_be_null: bool, min?: int, max?: int, pattern?: string, error_message?: string}> */
    public function getUploadFormConfig(): array
    {
        return [
            'original_resize' => ['default' => false, 'can_be_null' => false],
            'original_resize_maxwidth' => ['default' => 2000, 'min' => 500, 'max' => 20000, 'pattern' => '/^\d+$/', 'can_be_null' => false, 'error_message' => Lang::t('The original maximum width must be a number between %d and %d')],
            'original_resize_maxheight' => ['default' => 2000, 'min' => 300, 'max' => 20000, 'pattern' => '/^\d+$/', 'can_be_null' => false, 'error_message' => Lang::t('The original maximum height must be a number between %d and %d')],
            'original_resize_quality' => ['default' => 95, 'min' => 50, 'max' => 98, 'pattern' => '/^\d+$/', 'can_be_null' => false, 'error_message' => Lang::t('The original image quality must be a number between %d and %d')],
        ];
    }

    /**
     * @param array<mixed> $data
     * @param string[] $errors
     * @param array<array-key, mixed> $formErrors
     */
    public function saveUploadFormConfig(array $data, array &$errors = [], array &$formErrors = []): bool
    {
        if (empty($data)) {
            return false;
        }
        $config  = $this->getUploadFormConfig();
        $updates = [];
        foreach ($data as $field => $value) {
            if (!isset($config[$field])) {
                continue;
            }
            if (is_bool($config[$field]['default'])) {
                $value    = isset($value) ? true : false;
                $updates[] = ['param' => $field, 'value' => BoolUtil::toString($value)];
            } elseif ($config[$field]['can_be_null'] && empty($value)) {
                $updates[] = ['param' => $field, 'value' => 'false'];
            } else {
                $min     = $config[$field]['min'] ?? 0;
                $max     = $config[$field]['max'] ?? PHP_INT_MAX;
                $pattern = $config[$field]['pattern'] ?? '';
                $errMsg  = $config[$field]['error_message'] ?? '%s - %s';
                $effectivePattern = $pattern !== '' ? $pattern : '//';
                if (preg_match($effectivePattern, is_scalar($value) ? (string) $value : '') && $value >= $min && $value <= $max) {
                    $updates[] = ['param' => $field, 'value' => $value];
                } else {
                    $errors[]          = sprintf($errMsg, $min, $max);
                    $formErrors[$field] = '[' . $min . ' .. ' . $max . ']';
                }
            }
        }
        if (count($errors) === 0) {
            Dml::massUpdates(Tables::config(), ['primary' => ['param'], 'update' => ['value']], $updates);
            return true;
        }
        return false;
    }

    /** @param int[]|null $categories */
    public function addUploadedFile(string $sourceFilepath, ?string $originalFilename = null, ?array $categories = null, ?int $level = null, ?int $imageId = null, ?string $originalMd5sum = null): int
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if ($originalFilename !== null) {
            $originalFilename = htmlspecialchars($originalFilename);
        }
        $md5fileResult = md5_file($sourceFilepath);
        $md5sum = $originalMd5sum ?? ($md5fileResult !== false ? $md5fileResult : '');

        if (!isset($imageId) && Config::uploadDetectDuplicate()) {
            $imagesFound = $this->conn->executeQuery(
                'SELECT id FROM ' . Tables::images() . " WHERE md5sum = '$md5sum'"
            )->fetchAllAssociative();
            if (count($imagesFound) > 0) {
                $imageId = is_numeric($imagesFound[0]['id']) ? (int) $imagesFound[0]['id'] : 0;
                $logger->info('[addUploadedFile] image already exists #' . $imageId . ', deleting: ' . $sourceFilepath);
                unlink($sourceFilepath);
                $this->addUploadedFileAddToCategories($imageId, $categories);
                return $imageId;
            }
        }

        $filePath = null;
        $dbnow    = null;

        if (isset($imageId)) {
            $filePath = $this->imageRepository->findPathById($imageId);
            if ($filePath === null) {
                throw new NotFoundException('[addUploadedFile] photo does not exist in database');
            }
            $this->imageAdminService->deleteElementFiles([$imageId]);
        } else {
            $dbnow = new \DateTimeImmutable()->format('Y-m-d H:i:s');
            $splitDate = preg_split('/[^\d]/', $dbnow, 4) ?: ['', '', ''];
            $year  = $splitDate[0];
            $month = $splitDate[1] ?? '';
            $day   = $splitDate[2] ?? '';
            $uploadDir = sprintf(PHPWG_ROOT_PATH . Config::uploadDir() . '/%s/%s/%s', $year, $month, $day);
            $dateString = (string) preg_replace('/[^\d]/', '', $dbnow);
            $randomString  = substr($md5sum, 0, 4) . '%s';
            $filePathPattern = $uploadDir . '/' . $dateString . '-' . $randomString . '.';
            $imgsize = getimagesize($sourceFilepath);
            [$width, $height, $type] = $imgsize ?: [0, 0, 0];

            if (IMAGETYPE_PNG == $type) {
                $filePathPattern .= 'png';
            } elseif (IMAGETYPE_GIF == $type) {
                $filePathPattern .= 'gif';
            } elseif (IMAGETYPE_JPEG == $type) {
                $filePathPattern .= 'jpg';
            } elseif (IMAGETYPE_WEBP == $type) {
                $filePathPattern .= 'webp';
            } elseif (Config::has('upload_form_all_types') && Config::uploadFormAllTypes()) {
                $originalExtension = strtolower(StringUtil::getExtension($originalFilename ?? ''));
                $finfo             = finfo_open(FILEINFO_MIME_TYPE);
                $finfoType         = $finfo !== false ? finfo_file($finfo, $sourceFilepath) : false;
                if (in_array($finfoType, ['image/svg', 'image/svg+xml']) && $originalExtension !== 'svg') {
                    unlink($sourceFilepath);
                    $errorMsg = 'Extension "' . $originalExtension . '" for "' . ($originalFilename ?? '') . '" does not match MIME "' . ($finfoType !== false ? $finfoType : '') . '"';
                    if (RequestContextRegistry::current() === RequestContext::Ws) {
                        PwgServerRegistry::current()->sendResponse(new PwgError(415, $errorMsg));
                        exit;
                    }
                    throw new ValidationException($errorMsg);
                }
                if (in_array($originalExtension, Config::fileExtensions())) {
                    $filePathPattern .= $originalExtension;
                } else {
                    unlink($sourceFilepath);
                    throw new ValidationException('unexpected file type');
                }
            } else {
                unlink($sourceFilepath);
                throw new ValidationException('forbidden file type');
            }

            $this->prepareDirectory($uploadDir);
            do {
                $filePath = sprintf($filePathPattern, substr(bin2hex(random_bytes(4)), 0, 4));
            } while (file_exists($filePath));
        }

        $uploadRoot    = PHPWG_ROOT_PATH . Config::uploadDir();
        $uploadRelPath = StorageRegistry::stripRoot($uploadRoot, $filePath);
        $uploadStream  = fopen($sourceFilepath, 'rb');
        if ($uploadStream !== false) {
            StorageRegistry::disk('uploads')->writeStream($uploadRelPath, $uploadStream);
            fclose($uploadStream);
            if (!is_uploaded_file($sourceFilepath)) {
                Filesystem::tryUnlink($sourceFilepath);
            }
        }
        Filesystem::tryChmod($filePath, Config::chmodValue() & 0o666);

        $uploadEvent = new UploadFile('', $filePath);
        $this->dispatcher->dispatch($uploadEvent);
        $representativeExt = $uploadEvent->representativeExt;
        $logger->info('Handling ' . $filePath . ' got ' . $representativeExt);

        if (PwgImage::getLibrary() !== 'gd' && Config::originalResize()) {
            if ($this->needResize($filePath, Config::originalResizeMaxwidth(), Config::originalResizeMaxheight())) {
                $img = new PwgImage($filePath);
                $img->pwgResize($filePath, Config::originalResizeMaxwidth(), Config::originalResizeMaxheight(), Config::originalResizeQuality(), Config::uploadFormAutomaticRotation(), false);
                $img->destroy();
            }
        }

        $rotationAngle = PwgImage::getRotationAngle($filePath);
        $rotation      = PwgImage::getRotationCodeFromAngle($rotationAngle ?? 0);
        $fileInfos     = $this->pwgImageInfos($filePath);

        if (isset($imageId)) {
            $update = ['file' => $originalFilename ?? basename($filePath), 'filesize' => $fileInfos['filesize'], 'width' => $fileInfos['width'], 'height' => $fileInfos['height'], 'md5sum' => $md5sum, 'added_by' => $userId, 'rotation' => $rotation];
            if (isset($level)) {
                $update['level'] = $level;
            }
            Dml::singleUpdate(Tables::images(), $update, ['id' => $imageId]);
        } else {
            $file   = $originalFilename ?? basename($filePath);
            $insert = ['file' => $file, 'name' => StringUtil::getNameFromFile($file), 'date_available' => $dbnow, 'path' => preg_replace('#^' . preg_quote(PHPWG_ROOT_PATH) . '#', '', $filePath), 'filesize' => $fileInfos['filesize'], 'width' => $fileInfos['width'], 'height' => $fileInfos['height'], 'md5sum' => $md5sum, 'added_by' => $userId, 'rotation' => $rotation];
            if (isset($level)) {
                $insert['level'] = $level;
            }
            if ($representativeExt !== '') {
                $insert['representative_ext'] = $representativeExt;
            }
            Dml::singleInsert(Tables::images(), $insert);
            $imageId = (int) $this->conn->lastInsertId();
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $imageId, 'add'));
        }

        $this->addUploadedFileAddToCategories($imageId, $categories);

        if (Config::useExif() && !function_exists('exif_read_data')) {
            Config::override('use_exif', false);
        }
        $this->metadataAdminService->syncMetadata([$imageId]);

        $imageInfos = $this->imageRepository->findById($imageId);
        if ($imageInfos === null) {
            return $imageId;
        }

        $this->derivativeService->generate($imageInfos, DerivativeSize::Medium->value);
        $logger->info('[addUploadedFile] medium derivative generated', ['id' => $imageId]);

        $this->dispatcher->dispatch(new LocEndAddUploadedFile($imageInfos));
        return $imageId;
    }

    /** @param int[]|null $categories */
    public function addUploadedFileAddToCategories(int $imageId, ?array $categories): void
    {
        if (!Config::has('lounge_active')) {
            $this->configService->confUpdateParam('lounge_active', false, true);
        }
        if (!Config::loungeActive()) {
            $nbPhotos = $this->imageRepository->countAll();
            if ($nbPhotos >= Config::loungeActivateThreshold()) {
                $this->configService->confUpdateParam('lounge_active', true, true);
            }
        }
        if (isset($categories) && count($categories) > 0) {
            if (Config::loungeActive()) {
                $this->categoryAdminService->fillLounge([$imageId], $categories);
            } else {
                $this->categoryAdminService->associateImagesToCategories([$imageId], $categories);
            }
        }
        if (!Config::loungeActive()) {
            $this->userAdminService->invalidateUserCache();
        }
    }

    public function addFormat(string $sourceFilepath, string $formatExt, string $formatOf): string
    {
        if (!$this->configService->confGetParam('enable_formats', false)) {
            throw new ConfigException('[addFormat] formats are disabled');
        }
        $formatExtList = $this->configService->confGetParam('format_ext', ['cr2']);
        if (!is_array($formatExtList)) {
            $formatExtList = ['cr2'];
        }
        if (!in_array($formatExt, $formatExtList)) {
            $extList = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $formatExtList);
            throw new ValidationException('[addFormat] unexpected format extension "' . $formatExt . '" (authorized: ' . implode(', ', $extList) . ')');
        }
        $images = $this->conn->executeQuery('SELECT path FROM ' . Tables::images() . ' WHERE id = ' . $formatOf)->fetchAllAssociative();
        if (!isset($images[0])) {
            throw new NotFoundException('[addFormat] photo does not exist in database');
        }
        $origPath   = is_scalar($images[0]['path']) ? (string) $images[0]['path'] : '';
        $formatPath = dirname($origPath) . '/pwg_format/' . StringUtil::getFilenameWoExtension(basename($origPath)) . '.' . $formatExt;
        $this->prepareDirectory(dirname($formatPath));
        $fmtRoot    = PHPWG_ROOT_PATH . Config::uploadDir();
        $fmtAbsPath = PHPWG_ROOT_PATH . ltrim(str_replace(['\\', '/./'], ['/', '/'], $formatPath), '/');
        $fmtRelPath = StorageRegistry::stripRoot($fmtRoot, $fmtAbsPath);
        $fmtStream  = fopen($sourceFilepath, 'rb');
        if ($fmtStream !== false) {
            StorageRegistry::disk('uploads')->writeStream($fmtRelPath, $fmtStream);
            fclose($fmtStream);
            if (!is_uploaded_file($sourceFilepath)) {
                Filesystem::tryUnlink($sourceFilepath);
            }
        }
        Filesystem::tryChmod($formatPath, Config::chmodValue() & 0o666);
        $fileInfos = $this->pwgImageInfos($formatPath);
        $insert    = ['image_id' => $formatOf, 'ext' => $formatExt, 'filesize' => $fileInfos['filesize']];
        $formats   = $this->conn->executeQuery(
            'SELECT format_id FROM ' . Tables::imageFormat() . ' WHERE image_id = ' . $formatOf . ' AND ext = "' . $formatExt . '"'
        )->fetchAllAssociative();
        if ($formats) {
            Dml::singleUpdate(Tables::imageFormat(), ['filesize' => $fileInfos['filesize']], ['format_id' => $formats[0]['format_id'], 'image_id' => $formatOf, 'ext' => $formatExt]);
            $formatId  = $formats[0]['format_id'];
            $addStatus = 'update';
        } else {
            Dml::singleInsert(Tables::imageFormat(), $insert);
            $formatId  = (int) $this->conn->lastInsertId();
            $addStatus = 'add';
        }
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, (int) $formatOf, 'edit', ['action' => 'add format', 'format_ext' => $formatExt, 'format_id' => $formatId]));
        $formatInfos = array_merge($insert, ['format_id' => $formatId]);
        $this->dispatcher->dispatch(new LocEndAddFormat($formatInfos));
        return $addStatus;
    }

    public function uploadFilePdf(?string $representativeExt, string $filePath): ?string
    {
        $logger = LoggerRegistry::current();
        $logger->info('uploadFilePdf, filePath=' . $filePath);
        if (isset($representativeExt)) {
            return $representativeExt;
        }
        if (PwgImage::getLibrary() !== 'ext_imagick') {
            return $representativeExt;
        }
        if (!in_array(strtolower(StringUtil::getExtension($filePath)), ['pdf'])) {
            return $representativeExt;
        }
        $ext        = is_string($this->configService->confGetParam('pdf_representative_ext', 'jpg')) ? $this->configService->confGetParam('pdf_representative_ext', 'jpg') : 'jpg';
        $jpgQuality = is_int($this->configService->confGetParam('pdf_jpg_quality', 90)) ? $this->configService->confGetParam('pdf_jpg_quality', 90) : 90;
        $repFilePath = StringUtil::originalToRepresentative($filePath, $ext);
        $this->prepareDirectory(dirname($repFilePath));
        $rpFilePath0 = realpath($filePath);
        $exec  = Config::extImagickDir() . PwgImage::getExtImagickCommand() . ' "' . ($rpFilePath0 !== false ? $rpFilePath0 : $filePath) . '"[0]';
        if ($ext === 'jpg') {
            $exec .= ' -quality ' . $jpgQuality;
        }
        $exec .= ' "' . $repFilePath . '" 2>&1';
        exec($exec, $returnarray);
        if (file_exists($repFilePath)) {
            $representativeExt = $ext;
        }
        return $representativeExt;
    }

    public function uploadFileHeic(?string $representativeExt, string $filePath): ?string
    {
        $logger = LoggerRegistry::current();
        $logger->info('uploadFileHeic, filePath=' . $filePath);
        if (isset($representativeExt)) {
            return $representativeExt;
        }
        if (PwgImage::getLibrary() !== 'ext_imagick') {
            return $representativeExt;
        }
        if (!in_array(strtolower(StringUtil::getExtension($filePath)), ['heic'])) {
            return $representativeExt;
        }
        $ext         = 'jpg';
        $repFilePath = StringUtil::originalToRepresentative($filePath, $ext);
        $this->prepareDirectory(dirname($repFilePath));
        [$w, $h] = $this->getOptimalDimensionsForRepresentative();
        $rpHeic = realpath($filePath);
        $exec  = Config::extImagickDir() . PwgImage::getExtImagickCommand() . ' "' . ($rpHeic !== false ? $rpHeic : $filePath) . '"';
        $exec .= ' -sampling-factor 4:2:0 -quality 85 -interlace JPEG -colorspace sRGB -auto-orient +repage -resize "' . (int) $w . 'x' . (int) $h . '>"';
        $exec .= ' "' . $repFilePath . '" 2>&1';
        $logger->info('uploadFileHeic, exec=' . $exec);
        exec($exec, $returnarray);
        if (file_exists($repFilePath)) {
            $representativeExt = $ext;
        }
        return $representativeExt;
    }

    public function uploadFileTiff(?string $representativeExt, string $filePath): ?string
    {
        $logger = LoggerRegistry::current();
        $logger->info('uploadFileTiff, filePath=' . $filePath);
        if (isset($representativeExt)) {
            return $representativeExt;
        }
        if (PwgImage::getLibrary() !== 'ext_imagick') {
            return $representativeExt;
        }
        if (!in_array(strtolower(StringUtil::getExtension($filePath)), ['tif', 'tiff'])) {
            return $representativeExt;
        }
        $representativeExt = Config::tiffRepresentativeExt();
        $repFilePath       = dirname($filePath) . '/pwg_representative/' . StringUtil::getFilenameWoExtension(basename($filePath)) . '.' . $representativeExt;
        $this->prepareDirectory(dirname($repFilePath));
        $rpTiff = realpath($filePath);
        $exec  = Config::extImagickDir() . PwgImage::getExtImagickCommand() . ' "' . ($rpTiff !== false ? $rpTiff : $filePath) . '"';
        if ($representativeExt === 'jpg') {
            $exec .= ' -quality 98';
        }
        $dest  = pathinfo($repFilePath);
        $destDirname = $dest['dirname'];
        $rpDestDirname = realpath($destDirname);
        $exec .= ' "' . ($rpDestDirname !== false ? $rpDestDirname : $destDirname) . '/' . $dest['basename'] . '" 2>&1';
        exec($exec, $returnarray);
        $repAbs = ($rpDestDirname !== false ? $rpDestDirname : $destDirname) . '/' . $dest['basename'];
        if (!file_exists($repAbs)) {
            $first = preg_replace('/\.' . $representativeExt . '$/', '-0.' . $representativeExt, $repAbs) ?? '';
            if (file_exists($first)) {
                rename($first, $repAbs);
            }
        }
        return StringUtil::getExtension($repAbs);
    }

    public function uploadFileVideo(?string $representativeExt, string $filePath): ?string
    {
        $logger = LoggerRegistry::current();
        $logger->info('uploadFileVideo, filePath=' . $filePath);
        if (isset($representativeExt)) {
            return $representativeExt;
        }
        $videoExts = ['wmv','mov','mkv','mp4','mpg','flv','asf','xvid','divx','mpeg','avi','rm','m4v','ogg','ogv','webm','webmv'];
        if (!in_array(strtolower(StringUtil::getExtension($filePath)), $videoExts)) {
            return $representativeExt;
        }
        $representativeExt = 'jpg';
        $repFilePath       = dirname($filePath) . '/pwg_representative/' . StringUtil::getFilenameWoExtension(basename($filePath)) . '.' . $representativeExt;
        $this->prepareDirectory(dirname($repFilePath));
        $O = [];
        exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1' . " '$filePath'", $O, $S);
        $second = !empty($O[0]) ? min(floor((float) $O[0] * 10.0) / 10.0, 2.0) : 0.0;
        $logger->info('uploadFileVideo, poster at ' . number_format($second, 1) . 's');
        $ffmpeg  = Config::ffmpegDir() . 'ffmpeg -ss ' . number_format($second, 1) . ' -i "' . $filePath . '" -frames:v 1 "' . $repFilePath . '"';
        exec($ffmpeg . ' 2>&1', $FO, $FS);
        if (!file_exists($repFilePath)) {
            $avconv = str_replace('ffmpeg', 'avconv', $ffmpeg);
            exec($avconv . ' 2>&1');
        }
        return file_exists($repFilePath) ? $representativeExt : null;
    }

    public function uploadFilePsd(?string $representativeExt, string $filePath): ?string
    {
        $logger = LoggerRegistry::current();
        if (isset($representativeExt) || PwgImage::getLibrary() !== 'ext_imagick' || !in_array(strtolower(StringUtil::getExtension($filePath)), ['psd'])) {
            return $representativeExt;
        }
        $representativeExt = 'png';
        $repFilePath       = dirname($filePath) . '/pwg_representative/' . StringUtil::getFilenameWoExtension(basename($filePath)) . '.png';
        $this->prepareDirectory(dirname($repFilePath));
        $dest  = pathinfo($repFilePath);
        $destDirPsd = $dest['dirname'];
        $rpPsdFilePath = realpath($filePath);
        $rpPsdDestDir  = realpath($destDirPsd);
        $exec  = Config::extImagickDir() . PwgImage::getExtImagickCommand() . ' "' . ($rpPsdFilePath !== false ? $rpPsdFilePath : $filePath) . '" "' . ($rpPsdDestDir !== false ? $rpPsdDestDir : $destDirPsd) . '/' . $dest['basename'] . '" 2>&1';
        $logger->info('uploadFilePsd, exec=' . $exec);
        exec($exec, $returnarray);
        $repAbs = ($rpPsdDestDir !== false ? $rpPsdDestDir : $destDirPsd) . '/' . $dest['basename'];
        if (!file_exists($repAbs)) {
            $first = preg_replace('/\.png$/', '-0.png', $repAbs) ?? '';
            if (file_exists($first)) {
                rename($first, $repAbs);
            }
        }
        return StringUtil::getExtension($repAbs);
    }

    public function uploadFileEps(?string $representativeExt, string $filePath): ?string
    {
        $logger = LoggerRegistry::current();
        if (isset($representativeExt) || PwgImage::getLibrary() !== 'ext_imagick' || !in_array(strtolower(StringUtil::getExtension($filePath)), ['eps'])) {
            return $representativeExt;
        }
        $ext         = 'png';
        $repFilePath = StringUtil::originalToRepresentative($filePath, $ext);
        $this->prepareDirectory(dirname($repFilePath));
        $rpEps = realpath($filePath);
        $exec  = Config::extImagickDir() . PwgImage::getExtImagickCommand() . ' "' . ($rpEps !== false ? $rpEps : $filePath) . '" -density 300 -resize 2048x2048 "' . $repFilePath . '" 2>&1';
        $logger->info('uploadFileEps, exec=' . $exec);
        exec($exec, $returnarray);
        if (file_exists($repFilePath)) {
            $representativeExt = $ext;
        }
        return $representativeExt;
    }

    public function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!Filesystem::mkgetdir($directory, Filesystem::FLAG_RECURSIVE)) {
                throw new ConfigException('[prepareDirectory] cannot create "' . $directory . '"');
            }
        }
        if (!is_writable($directory)) {
            Filesystem::tryChmod($directory, Config::chmodValue());
        }
        if (!is_writable($directory)) {
            throw new ConfigException('[prepareDirectory] directory "' . $directory . '" has no write access');
        }
        StringUtil::secureDirectory($directory);
    }

    public function needResize(string $imageFilepath, int $maxWidth, int $maxHeight): bool
    {
        if (!in_array(strtolower(StringUtil::getExtension($imageFilepath)), Config::pictureExtensions())) {
            return false;
        }
        [$width, $height] = getimagesize($imageFilepath) ?: [0, 0];
        if ($width > $maxWidth || $height > $maxHeight) {
            LoggerRegistry::current()->info('[needResize] ' . $imageFilepath . ' too big (' . $width . 'x' . $height . ' vs ' . $maxWidth . 'x' . $maxHeight . ')');
            return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    public function pwgImageInfos(string $path): array
    {
        [$width, $height] = getimagesize($path) ?: [0, 0];
        $fsize = filesize($path);
        return ['width' => $width, 'height' => $height, 'filesize' => floor(($fsize !== false ? $fsize : 0) / 1024)];
    }

    /** @return string[] */
    public function isValidImageExtension(string $extension): array
    {
        $extensions = (Config::has('upload_form_all_types') && Config::uploadFormAllTypes())
            ? Config::fileExtensions()
            : Config::pictureExtensions();
        return array_unique(array_map(strtolower(...), $extensions));
    }

    public function fileUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE   => sprintf(Lang::t('The uploaded file exceeds the upload_max_filesize directive in php.ini: %sB'), $this->getIniSize('upload_max_filesize', false)),
            UPLOAD_ERR_FORM_SIZE  => Lang::t('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
            UPLOAD_ERR_PARTIAL    => Lang::t('The uploaded file was only partially uploaded'),
            UPLOAD_ERR_NO_FILE    => Lang::t('No file was uploaded'),
            UPLOAD_ERR_NO_TMP_DIR => Lang::t('Missing a temporary folder'),
            UPLOAD_ERR_CANT_WRITE => Lang::t('Failed to write file to disk'),
            UPLOAD_ERR_EXTENSION  => Lang::t('File upload stopped by extension'),
            default               => Lang::t('Unknown upload error'),
        };
    }

    public function getIniSize(string $iniKey, bool $inBytes = true): int|string
    {
        $size = ini_get($iniKey);
        if ($size === false) {
            return 0;
        }
        return $inBytes ? $this->convertShorthandNotationToBytes($size) : $size;
    }

    public function convertShorthandNotationToBytes(string $value): int
    {
        $suffix = substr($value, -1);
        $multiplyBy = match ($suffix) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => null,
        };
        if ($multiplyBy !== null) {
            $value = (int) ((float) substr($value, 0, -1) * (float) $multiplyBy);
        }
        return (int) $value;
    }

    public function addUploadError(string $uploadId, string $errorMessage): void
    {
        $uploadsError                = is_array($_SESSION['uploads_error'] ?? null) ? $_SESSION['uploads_error'] : [];
        $slot                        = is_array($uploadsError[$uploadId] ?? null) ? $uploadsError[$uploadId] : [];
        $slot[]                      = $errorMessage;
        $uploadsError[$uploadId]     = $slot;
        $_SESSION['uploads_error']   = $uploadsError;
    }

    public function readyForUploadMessage(): ?string
    {
        $relativeDir = (string) preg_replace('#^' . PHPWG_ROOT_PATH . '#', '', Config::uploadDir());
        if (!is_dir(Config::uploadDir())) {
            if (!is_writable(dirname(Config::uploadDir()))) {
                return sprintf(Lang::t('Create the "%s" directory at the root of your Piwigo installation'), $relativeDir);
            }
        } else {
            $uploadDir = Config::uploadDir();
            if (!is_writable($uploadDir)) {
                Filesystem::tryChmod($uploadDir, Config::chmodValue());
            }
            if (!is_writable(Config::uploadDir())) {
                return sprintf(Lang::t('Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation'), $relativeDir);
            }
        }
        return null;
    }

    /** @return array<int, int|float> */
    public function getOptimalDimensionsForRepresentative(): array
    {
        $enabled  = ImageStdParams::getDefinedTypeMap();
        $disabled = StringUtil::safeUnserialize(ImageStdParams::getDisabledTypeMap());
        $w = $h   = 2000;
        foreach (ImageStdParams::getAllTypes() as $type) {
            $params = $enabled[$type] ?? ($disabled[$type] ?? null);
            if ($params instanceof DerivativeParams) {
                [$w, $h] = $params->sizing->ideal_size;
            }
        }
        return [(float) $w * 1.5, (float) $h * 1.5];
    }
}
