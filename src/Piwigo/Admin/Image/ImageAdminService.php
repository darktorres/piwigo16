<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeEncoding;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Tag\TagRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\UserRepository;

final class ImageAdminService
{
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
        $repo      = ServiceLocator::get(ImageRepository::class);
        foreach ($repo->findFormatsByImageIds($ids) as $row) {
            $fmtImageId = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
            if (!isset($formatsOf[$fmtImageId])) {
                $formatsOf[$fmtImageId] = [];
            }
            $formatsOf[$fmtImageId][] = is_scalar($row['ext']) ? (string) $row['ext'] : '';
        }
        foreach ($repo->findPathsByIds($ids) as $row) {
            $rowPath = is_scalar($row['path']) ? (string) $row['path'] : '';
            if (UrlService::urlIsRemote($rowPath)) {
                continue;
            }
            $files   = [];
            $files[] = get_element_path($row);
            if (!empty($row['representative_ext'])) {
                $files[] = original_to_representative($files[0], is_scalar($row['representative_ext']) ? (string) $row['representative_ext'] : '');
            }
            $rowIdInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
            if (isset($formatsOf[$rowIdInt])) {
                foreach ($formatsOf[$rowIdInt] as $fmtExt) {
                    $files[] = original_to_format($files[0], $fmtExt);
                }
            }
            $ok = true;
            if (!Config::has('never_delete_originals')) {
                foreach ($files as $path) {
                    if (is_file($path) && !unlink($path)) {
                        $ok = false;
                        trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                        break;
                    }
                }
            }
            if ($ok) {
                $this->deleteElementDerivatives($row);
                $newIds[] = is_numeric($row['id']) ? (int) $row['id'] : 0;
            } else {
                break;
            }
        }
        return $newIds;
    }

    /** @param int[] $ids */
    public function deleteElements(array $ids, bool $physicalDeletion = false): int
    {
        if (count($ids) === 0) {
            return 0;
        }
        EventDispatcher::notify('begin_delete_elements', $ids);
        if ($physicalDeletion) {
            $ids = $this->deleteElementFiles($ids);
            if (count($ids) === 0) {
                return 0;
            }
        }
        $imgRepo  = ServiceLocator::get(ImageRepository::class);
        $catRepo  = ServiceLocator::get(CategoryRepository::class);
        $comRepo  = ServiceLocator::get(CommentRepository::class);
        $userRepo = ServiceLocator::get(UserRepository::class);
        $tagRepo  = ServiceLocator::get(TagRepository::class);
        $comRepo->deleteByImageIds($ids);
        $catRepo->deleteImageCategoryByImageIds($ids);
        $imgRepo->deleteFormatsByImageIds($ids);
        $tagRepo->deleteImageTagsByImageIds($ids);
        $userRepo->deleteFavoritesByImageIds($ids);
        $imgRepo->deleteRatingsByImageIds($ids);
        $imgRepo->deleteCaddieByImageIds($ids);
        $imgRepo->deleteByIds($ids);
        $categoryIds = $catRepo->findIdsByRepresentativePicture($ids);
        if (count($categoryIds) > 0) {
            ServiceLocator::get(CategoryAdminService::class)->updateCategory($categoryIds);
        }
        EventDispatcher::notify('delete_elements', $ids);
        pwg_activity('photo', $ids, 'delete');
        return count($ids);
    }

    /** @return array<mixed> */
    public function getCategoryRepresentantProperties(string $imageId, ?string $size = null): array
    {
        $row = ServiceLocator::get(ImageRepository::class)->findById((int) $imageId);
        if ($row === null) {
            return [];
        }
        $src = $size === null ? DerivativeImage::thumbUrl($row) : DerivativeImage::url($size, $row);
        return ['src' => $src, 'url' => ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $imageId)];
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

    #[\Deprecated(message: '2.4')]
    public function getFs(string $path, bool $recursive = true): mixed
    {
        if (!Config::has('flip_picture_ext')) {
            Config::override('flip_picture_ext', array_flip(Config::pictureExtensions()));
        }
        if (!Config::has('flip_file_ext')) {
            Config::override('flip_file_ext', array_flip(Config::fileExtensions()));
        }
        $fs = ['elements' => [], 'thumbnails' => [], 'representatives' => []];
        $subdirs = [];
        if (is_dir($path)) {
            $contents = opendir($path);
            if ($contents !== false) {
                while (($node = readdir($contents)) !== false) {
                    if ($node === '.' || $node === '..') {
                        continue;
                    }
                    if (is_file($path . '/' . $node)) {
                        $ext        = get_extension($node);
                        $flipPicExt = Config::flipPictureExt();
                        $flipFileExt = Config::flipFileExt();
                        if (isset($flipPicExt[$ext])) {
                            $base = basename($path);
                            if ($base === 'thumbnail') {
                                $fs['thumbnails'][] = $path . '/' . $node;
                            } elseif ($base === 'pwg_representative') {
                                $fs['representatives'][] = $path . '/' . $node;
                            } else {
                                $fs['elements'][] = $path . '/' . $node;
                            }
                        } elseif (isset($flipFileExt[$ext])) {
                            $fs['elements'][] = $path . '/' . $node;
                        }
                    } elseif (is_dir($path . '/' . $node) && $node !== 'pwg_high' && $recursive) {
                        $subdirs[] = $node;
                    }
                }
                closedir($contents);
            }
            foreach ($subdirs as $subdir) {
                $tmpFs = $this->getFs($path . '/' . $subdir);
                $tmpFsArr = is_array($tmpFs) ? $tmpFs : [];
                $fs['elements']        = array_merge($fs['elements'], is_array($tmpFsArr['elements'] ?? null) ? $tmpFsArr['elements'] : []);
                $fs['thumbnails']      = array_merge($fs['thumbnails'], is_array($tmpFsArr['thumbnails'] ?? null) ? $tmpFsArr['thumbnails'] : []);
                $fs['representatives'] = array_merge($fs['representatives'], is_array($tmpFsArr['representatives'] ?? null) ? $tmpFsArr['representatives'] : []);
            }
        }
        return $fs;
    }

    /** @param array<mixed> $infos */
    public function deleteElementDerivatives(array $infos, string|int $type = 'all'): void
    {
        $path = is_scalar($infos['path']) ? (string) $infos['path'] : '';
        if (!empty($infos['representative_ext'])) {
            $path = original_to_representative($path, is_scalar($infos['representative_ext']) ? (string) $infos['representative_ext'] : '');
        }
        if (substr_compare($path, '../', 0, 3) === 0) {
            $path = substr($path, 3);
        }
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return;
        }
        $pattern = $type === 'all' ? '-*' : '-' . DerivativeEncoding::derivativeToUrl((string) $type) . '*';
        $path    = substr_replace($path, $pattern, $dot, 0);
        if (($glob = glob(PHPWG_ROOT_PATH . Config::derivativeDir() . $path)) !== false) {
            foreach ($glob as $file) {
                Filesystem::tryUnlink($file);
            }
        }
    }

    public function clearDerivativeCache(mixed $types = 'all'): void
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
        $derivDir = PHPWG_ROOT_PATH . Config::derivativeDir();
        if (is_dir($derivDir) && ($contents = opendir($derivDir)) !== false) {
            while (($node = readdir($contents)) !== false) {
                if ($node !== '.' && $node !== '..' && is_dir(PHPWG_ROOT_PATH . Config::derivativeDir() . $node)) {
                    $this->clearDerivativeCacheRec(PHPWG_ROOT_PATH . Config::derivativeDir() . $node, $pattern);
                }
            }
            closedir($contents);
        }
    }

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
                        $msizes[$sizeCode] = (is_numeric($msizes[$sizeCode] ?? null) ? (float) $msizes[$sizeCode] : 0.0) + ($fsize !== false ? $fsize : 0);
                    } elseif (is_dir($path . '/' . $node)) {
                        foreach ($this->getCacheSizeDerivatives($path . '/' . $node) as $k => $v) {
                            $msizes[$k] = (is_numeric($msizes[$k] ?? null) ? (float) $msizes[$k] : 0.0) + $v;
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
        ServiceLocator::get(ImageRepository::class)->touchLastModified($imageIds);
    }

    /** @return array<string,mixed>|null */
    public function getImageInfos(int|string $imageId, bool $dieOnMissing = false): ?array
    {
        if (!is_numeric($imageId)) {
            fatal_error('[getImageInfos] invalid image identifier ' . htmlentities((string) $imageId));
        }
        $images = DbConnection::get()->executeQuery(
            'SELECT * FROM ' . Tables::images() . ' WHERE id = ' . $imageId
        )->fetchAllAssociative();
        if (count($images) === 0) {
            if ($dieOnMissing) {
                fatal_error('photo ' . $imageId . ' does not exist');
            }
            return null;
        }
        return $images[0];
    }

    /** @return int[] */
    public function getPhotosNoMd5sum(): array
    {
        $raw = array_column(DbConnection::get()->executeQuery(
            'SELECT id FROM ' . Tables::images() . ' WHERE md5sum is null'
        )->fetchAllAssociative(), 'id');
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $raw);
    }

    /** @param int[]|string $ids */
    public function addMd5sum(array|string $ids): int
    {
        $idsArray  = is_array($ids) ? $ids : explode(',', $ids);
        $pathForId = array_column(DbConnection::get()->executeQuery(
            'SELECT id, path FROM ' . Tables::images() . ' WHERE id IN (' . implode(', ', $idsArray) . ')'
        )->fetchAllAssociative(), 'path', 'id');
        $updates = [];
        foreach ($pathForId as $id => $path) {
            $updates[] = ['id' => $id, 'md5sum' => md5_file(PHPWG_ROOT_PATH . (is_scalar($path) ? (string) $path : ''))];
        }
        Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => ['md5sum']], $updates);
        return count($pathForId);
    }

    public function countOrphans(): int
    {
        if (is_null(conf_get_param('count_orphans'))) {
            $allCount = ServiceLocator::get(ImageRepository::class)->countAll();
            $catCount = ServiceLocator::get(CategoryRepository::class)->countLinkedImages();
            $counter  = $allCount - $catCount;
            conf_update_param('count_orphans', $counter, true);
        }
        $count = conf_get_param('count_orphans');
        return is_numeric($count) ? (int) $count : 0;
    }

    /** @return int[] */
    public function getOrphans(): array
    {
        $loungedIds = array_column(DbConnection::get()->executeQuery('SELECT image_id FROM ' . Tables::lounge())->fetchAllAssociative(), 'image_id');
        $query = 'SELECT id FROM ' . Tables::images() . ' LEFT JOIN ' . Tables::imageCategory() . ' ON id = image_id WHERE category_id IS NULL';
        if (count($loungedIds) > 0) {
            $query .= ' AND id NOT IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $loungedIds)) . ')';
        }
        $query .= ' ORDER BY id ASC';
        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id'));
    }

    public function fsQuickCheck(): void
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        if (Config::fsQuickCheckPeriod() === 0) {
            return;
        }
        if (isset($page['fs_quick_check_already_called'])) {
            return;
        }
        $page['fs_quick_check_already_called'] = true;
        conf_update_param('fs_quick_check_last_check', date('c'));

        $issue1827Ids = array_column(DbConnection::get()->executeQuery(
            'SELECT id FROM ' . Tables::images() . " WHERE date_available < '2022-12-08 00:00:00' AND path LIKE './upload/%' LIMIT 5000"
        )->fetchAllAssociative(), 'id');
        shuffle($issue1827Ids);
        $issue1827Ids = array_slice($issue1827Ids, 0, 50);

        $randomImageIds = array_map(
            fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
            array_column(DbConnection::get()->executeQuery('SELECT id FROM ' . Tables::images() . ' LIMIT 5000')->fetchAllAssociative(), 'id')
        );
        shuffle($randomImageIds);
        $randomImageIds = array_slice($randomImageIds, 0, 50);

        $checkIds = array_unique(array_merge(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $issue1827Ids), $randomImageIds));
        if (count($checkIds) < 1) {
            return;
        }

        $paths = array_column(DbConnection::get()->executeQuery(
            'SELECT id, path FROM ' . Tables::images() . ' WHERE id IN (' . implode(',', $checkIds) . ')'
        )->fetchAllAssociative(), 'path', 'id');

        $template = TemplateRegistry::current();
        foreach ($paths as $path) {
            if (!file_exists(is_scalar($path) ? (string) $path : '')) {
                $template->assign('header_msgs', [l10n('Some photos are missing from your file system. Details provided by plugin Check Uploads')]);
                return;
            }
        }

        $duplicatePaths = DbConnection::get()->executeQuery('SELECT path FROM ' . Tables::images() . ' GROUP BY path HAVING COUNT(*) > 1')->fetchAllAssociative();
        if (count($duplicatePaths) > 0) {
            $template->assign('header_msgs', [l10n('We have found %d duplicate paths. Details provided by plugin Check Uploads', count($duplicatePaths))]);
        }
    }
}
