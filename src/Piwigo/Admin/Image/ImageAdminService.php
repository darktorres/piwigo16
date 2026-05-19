<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Event\Picture\BeginDeleteElements;
use Piwigo\Event\Picture\DeleteElements;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeEncoding;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ImageAdminService
{
    private bool $fsQuickCheckCalled = false;

    public function __construct(
        private readonly CategoryAdminService $categoryAdminService,
        private readonly CategoryRepository $categoryRepository,
        private readonly ConfigService $configService,
        private readonly ImageRepository $imageRepository,
        private readonly UrlGenerator $urlGenerator,
        private readonly ActivityLogger $activityLogger,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Paths $paths,
    ) {
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    public function deleteElementFiles(array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }
        $newIds    = [];
        $formatsOf = [];
        $repo      = $this->imageRepository;
        foreach ($repo->findFormatsByImageIds($ids) as $row) {
            $fmtImageId = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
            if (!isset($formatsOf[$fmtImageId])) {
                $formatsOf[$fmtImageId] = [];
            }
            $formatsOf[$fmtImageId][] = is_string($row['ext'] ?? null) ? $row['ext'] : '';
        }
        foreach ($repo->findPathsByIds($ids) as $row) {
            $rowPath = is_string($row['path'] ?? null) ? $row['path'] : '';
            if (UrlService::urlIsRemote($rowPath)) {
                continue;
            }
            $files   = [];
            $files[] = StringUtil::getElementPath($row);
            if (!empty($row['representative_ext'])) {
                $files[] = StringUtil::originalToRepresentative($files[0], is_string($row['representative_ext']) ? $row['representative_ext'] : '');
            }
            $rowIdInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
            if (isset($formatsOf[$rowIdInt])) {
                foreach ($formatsOf[$rowIdInt] as $fmtExt) {
                    $files[] = StringUtil::originalToFormat($files[0], $fmtExt);
                }
            }
            if (!Config::has('never_delete_originals')) {
                foreach ($files as $path) {
                    if (is_file($path) && !unlink($path)) {
                        throw new \RuntimeException('"' . $path . '" cannot be removed');
                    }
                }
            }
            $this->deleteElementDerivatives($row);
            $newIds[] = is_numeric($row['id']) ? (int) $row['id'] : 0;
        }
        return $newIds;
    }

    /** @param int[] $ids */
    public function deleteElements(array $ids, bool $physicalDeletion = false): int
    {
        if (count($ids) === 0) {
            return 0;
        }
        $this->dispatcher->dispatch(new BeginDeleteElements($ids));
        if ($physicalDeletion) {
            // Filesystem op — cannot participate in DB transaction. Runs first
            // so a failure aborts before any DB rows are touched.
            $ids = $this->deleteElementFiles($ids);
            if (count($ids) === 0) {
                return 0;
            }
        }
        // Capture categories whose representative picture is in $ids BEFORE
        // deleting — after the parent delete fires the FK SET NULL,
        // categories.representative_picture_id is already NULL for those
        // rows and the lookup would return nothing. updateCategory then
        // picks a fresh representative for each affected category.
        $categoryIds = $this->categoryRepository->findIdsByRepresentativePicture($ids);
        $this->imageRepository->deleteAtomicallyByIds($ids);
        if (count($categoryIds) > 0) {
            $this->categoryAdminService->updateCategory($categoryIds);
        }
        $this->dispatcher->dispatch(new DeleteElements($ids));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $ids, 'delete'));
        return count($ids);
    }

    /** @return array<mixed> */
    public function getCategoryRepresentantProperties(string $imageId, ?string $size = null): array
    {
        $image = $this->imageRepository->findById((int) $imageId);
        if ($image === null) {
            return [];
        }
        $srcImage = SrcImage::fromImage($image);
        $src      = $size === null ? DerivativeImage::thumbUrl($srcImage) : DerivativeImage::url($size, $srcImage);
        return ['src' => $src, 'url' => $this->urlGenerator->admin('photo-' . $imageId)];
    }

    /** @return string[] */
    public function getFsDirectories(string $path, bool $recursive = true): array
    {
        $dirs    = [];
        $path    = rtrim($path, '/');
        $exclude = array_flip(array_merge(Config::syncExcludeFolders(), ['.', '..', '.svn', 'thumbnail', 'pwg_high', 'pwg_representative', 'pwg_format']));
        if (is_dir($path)) {
            $contents = opendir($path);
            if ($contents !== false) {
                while (($node = readdir($contents)) !== false) {
                    if (is_dir($path . '/' . $node) && !isset($exclude[$node])) {
                        $dirs[] = $path . '/' . $node;
                        if ($recursive) {
                            $dirs = array_merge($dirs, $this->getFsDirectories($path . '/' . $node));
                        }
                    }
                }
                closedir($contents);
            }
        }
        return $dirs;
    }

    /** @param array<mixed> $infos */
    public function deleteElementDerivatives(array $infos, string|int $type = 'all'): void
    {
        $path = is_string($infos['path'] ?? null) ? $infos['path'] : '';
        if (!empty($infos['representative_ext'])) {
            $path = StringUtil::originalToRepresentative($path, is_string($infos['representative_ext']) ? $infos['representative_ext'] : '');
        }
        if (substr_compare($path, '../', 0, 3) === 0) {
            $path = substr($path, 3);
        }
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return;
        }
        $pattern = $type === 'all' ? '-*' : '-' . DerivativeEncoding::derivativeToUrl((string) $type) . '*';
        /** @var array<int, string>|string $replaced */
        $replaced = substr_replace($path, $pattern, $dot, 0);
        $pathStr  = is_array($replaced) ? '' : $replaced;
        if (($glob = glob($this->paths->root . Config::derivativeDir() . $pathStr)) !== false) {
            foreach ($glob as $file) {
                Filesystem::tryUnlink($file);
            }
        }
    }

    /** @param array<mixed>|string $types */
    public function clearDerivativeCache(array|string $types = 'all'): void
    {
        if ($types === 'all') {
            $types   = ImageStdParams::getAllTypes();
            $types[] = DerivativeSize::Custom->value;
        } elseif (!is_array($types)) {
            $types = [$types];
        }
        $stringTypes = [];
        foreach ($types as $type) {
            $typeStr = is_scalar($type) ? (string) $type : '';
            if ($type == DerivativeSize::Custom->value) {
                $stringTypes[] = DerivativeEncoding::derivativeToUrl($typeStr) . '_[a-zA-Z0-9]+';
            } elseif (in_array($type, ImageStdParams::getAllTypes())) {
                $stringTypes[] = DerivativeEncoding::derivativeToUrl($typeStr);
            } else {
                $stringTypes[] = DerivativeEncoding::derivativeToUrl(DerivativeSize::Custom->value) . '_' . $typeStr;
            }
        }
        $pattern = '#.*-';
        $pattern .= count($stringTypes) > 1 ? '(' . implode('|', $stringTypes) . ')' : ($stringTypes[0] ?? '');
        $pattern .= '\.[a-zA-Z0-9]{3,4}$#';
        $derivDir = $this->paths->root . Config::derivativeDir();
        if (is_dir($derivDir) && ($contents = opendir($derivDir)) !== false) {
            while (($node = readdir($contents)) !== false) {
                if ($node !== '.' && $node !== '..' && is_dir($this->paths->root . Config::derivativeDir() . $node)) {
                    $this->clearDerivativeCacheRec($this->paths->root . Config::derivativeDir() . $node, $pattern);
                }
            }
            closedir($contents);
        }
    }

    /** @param non-empty-string $pattern */
    public function clearDerivativeCacheRec(string $path, string $pattern): bool
    {
        $rmdir   = true;
        $rmIndex = false;
        $contents = opendir($path);
        if ($contents !== false) {
            while (($node = readdir($contents)) !== false) {
                if ($node === '.' || $node === '..') {
                    continue;
                }
                if (is_dir($path . '/' . $node)) {
                    $rmdir = $rmdir && $this->clearDerivativeCacheRec($path . '/' . $node, $pattern);
                } else {
                    if (preg_match($pattern, $node)) {
                        unlink($path . '/' . $node);
                    } elseif ($node === 'index.htm') {
                        $rmIndex = true;
                    } else {
                        $rmdir = false;
                    }
                }
            }
            closedir($contents);
            if ($rmdir) {
                if ($rmIndex) {
                    unlink($path . '/index.htm');
                }
                clearstatcache();
                Filesystem::tryRmdir($path);
            }
            return $rmdir;
        }
        return false;
    }

    /** @return array<string, int|float> */
    public function getCacheSizeDerivatives(string $path): array
    {
        /** @var array<string, int|float> $msizes */
        $msizes = [];
        if (is_dir($path)) {
            $contents = opendir($path);
            if ($contents !== false) {
                while (($node = readdir($contents)) !== false) {
                    if ($node === '.' || $node === '..') {
                        continue;
                    }
                    if (is_file($path . '/' . $node)) {
                        $split    = explode('-', $node);
                        $sizeCode = substr(end($split), 0, 2);
                        $fsize    = filesize($path . '/' . $node);
                        $msizes[$sizeCode] = (is_numeric($msizes[$sizeCode] ?? null) ? (float) $msizes[$sizeCode] : 0.0) + (float) ($fsize !== false ? $fsize : 0);
                    } elseif (is_dir($path . '/' . $node)) {
                        foreach ($this->getCacheSizeDerivatives($path . '/' . $node) as $k => $v) {
                            $msizes[$k] = (is_numeric($msizes[$k] ?? null) ? (float) $msizes[$k] : 0.0) + (float) $v;
                        }
                    }
                }
                closedir($contents);
            }
        }
        return $msizes;
    }

    /** @param int[] $imageIds */
    public function updateImagesLastmodified(array $imageIds): void
    {
        if (count($imageIds) === 0) {
            return;
        }
        $this->imageRepository->touchLastModified($imageIds);
    }

    /**
     * Return the image entity for the given id, or null/fatal if missing.
     *
     * Callers (`PhotoController`, `BatchManagerController`) still consume
     * the legacy row-shaped array; this wrapper builds that shape from the
     * typed entity. The deeper migration to `Image` properties throughout
     * those controllers is parked under [[F5-d/10]] (mutation refactor).
     *
     * @return array<string, mixed>|null
     */
    public function getImageInfos(int|string $imageId, bool $dieOnMissing = false): ?array
    {
        if (!is_numeric($imageId)) {
            HtmlService::fatalError('[getImageInfos] invalid image identifier ' . htmlentities($imageId));
        }
        $image = $this->imageRepository->findById((int) $imageId);
        if ($image === null) {
            if ($dieOnMissing) {
                HtmlService::fatalError('photo ' . $imageId . ' does not exist');
            }
            return null;
        }
        return [
            'id'                   => $image->id->value,
            'file'                 => $image->file->value,
            'path'                 => $image->path->value,
            'name'                 => $image->name,
            'comment'              => $image->comment,
            'author'               => $image->author,
            'hit'                  => $image->hit,
            'filesize'             => $image->filesize,
            'width'                => $image->width,
            'height'               => $image->height,
            'representative_ext'   => $image->representativeExt,
            'date_available'       => $image->dateAvailable?->value,
            'date_creation'        => $image->dateCreation?->value,
            'date_metadata_update' => $image->dateMetadataUpdate?->value,
            'rating_score'         => $image->ratingScore,
            'storage_category_id'  => $image->storageCategoryId?->value,
            'level'                => $image->level,
            'md5sum'               => $image->md5sum?->value,
            'added_by'             => $image->addedBy?->value,
            'rotation'             => $image->rotation,
            'latitude'             => $image->latitude,
            'longitude'            => $image->longitude,
            'coi'                  => $image->coi,
        ];
    }

    /** @return int[] */
    public function getPhotosNoMd5sum(): array
    {
        return $this->imageRepository->findIdsWithoutMd5sum();
    }

    /** @param int[] $ids */
    public function addMd5sum(array $ids): int
    {
        $pathForId = $this->imageRepository->findIdToPathMapByIds($ids);
        $updates = [];
        foreach ($pathForId as $id => $path) {
            $updates[] = ['id' => $id, 'md5sum' => md5_file($this->paths->root . $path)];
        }
        $this->imageRepository->setMd5sumBatch($updates);
        return count($pathForId);
    }

    public function countOrphans(): int
    {
        if (is_null($this->configService->confGetParam('count_orphans'))) {
            $allCount = $this->imageRepository->countAll();
            $catCount = $this->categoryRepository->countLinkedImages();
            $counter  = $allCount - $catCount;
            $this->configService->confUpdateParam('count_orphans', $counter, true);
        }
        $count = $this->configService->confGetParam('count_orphans');
        return is_numeric($count) ? (int) $count : 0;
    }

    /** @return int[] */
    public function getOrphans(): array
    {
        $loungedIds = $this->imageRepository->findLoungeImageIds();
        return $this->imageRepository->findOrphanIdsExcluding($loungedIds);
    }

    public function fsQuickCheck(): void
    {
        if (Config::fsQuickCheckPeriod() === 0) {
            return;
        }
        if ($this->fsQuickCheckCalled) {
            return;
        }
        $this->fsQuickCheckCalled = true;
        $this->configService->confUpdateParam('fs_quick_check_last_check', date('c'));

        $issue1827Ids = $this->imageRepository->findUploadIdsBefore('2022-12-08 00:00:00', 5000);
        shuffle($issue1827Ids);
        $issue1827Ids = array_slice($issue1827Ids, 0, 50);

        $randomImageIds = $this->imageRepository->findIdsCapped(5000);
        shuffle($randomImageIds);
        $randomImageIds = array_slice($randomImageIds, 0, 50);

        $checkIds = array_values(array_unique(array_merge($issue1827Ids, $randomImageIds)));
        if (count($checkIds) < 1) {
            return;
        }

        $paths = $this->imageRepository->findIdToPathMapByIds($checkIds);

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                PageState::current()->headerMessages[] = Lang::t('Some photos are missing from your file system. Details provided by plugin Check Uploads');
                return;
            }
        }

        $duplicatePaths = $this->imageRepository->findDuplicatePaths();
        if (count($duplicatePaths) > 0) {
            PageState::current()->headerMessages[] = Lang::t('We have found %d duplicate paths. Details provided by plugin Check Uploads', count($duplicatePaths));
        }
    }
}
