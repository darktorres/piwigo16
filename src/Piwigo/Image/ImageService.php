<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionService;

/**
 * Pure computation ported from `include/functions_picture.inc.php` --
 * slideshow param encode/decode/correct and PDF page counting, none of
 * which touch the DB (that's {@see ImageRepository}'s job). P23 batch 8d's
 * Elements/photos sub-batch added the constructor (ImageRepository +
 * ActivityLoggerInterface, the latter since Piwigo\Activity\ActivityService
 * itself is L2bExtendedDomain and this class is L2aCoreDomain -- same fix
 * as Piwigo\Tag\TagService's own deleteTags(), see
 * ActivityLoggerInterface's own docblock) and the 14 real element/lounge/
 * orphan methods below -- every existing no-arg `new ImageService()` call
 * site needed updating for the new required constructor.
 *
 * Legacy Coupling Retirement Phase 8, 8d: emptyLounge()'s real writes
 * (empty_lounge_running/count_orphans) go through CurrentConfigService::get()
 * (Tier 2) -- constructed throwaway at ~33 real sites, including
 * Bootstrap\RequestBootstrap.php's own `new ImageService(...)->emptyLounge()`
 * inline call (gated on LoungeMaintenance::needsEmptying()), which runs
 * after connect() has already resolved and set a real ConfigService.
 * Every other real construction site (BatchManagerGlobalPageRenderer,
 * UploadService, TagService, Ws\PwgImages, etc.) is normal post-container
 * application code, covered the same way.
 */
final readonly class ImageService
{
    public function __construct(
        private ImageRepository $repo,
        private ActivityLoggerInterface $activityLogger,
    ) {}

    /**
     * `Category` is L2aCoreDomain, same layer as this class -- no
     * deptrac concern, but constructor-injecting `CategoryService` would
     * still ripple across every one of this class's own real call sites
     * for the sake of the 2 methods below, so it's inline-constructed
     * instead, matching {@see \Piwigo\Tag\TagService::newImageService()}'s
     * established precedent.
     */
    private function categoryService(): CategoryService
    {
        $conn = DbConnection::build();

        return new CategoryService(
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
            new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class))
        );
    }

    /**
     * @return array{period: int, repeat: bool, play: bool}
     */
    public function getDefaultSlideshowParams(): array
    {

        return [
            'period' => \Piwigo\Config\CurrentConfig::slideshowPeriod(),
            'repeat' => \Piwigo\Config\CurrentConfig::slideshowRepeat(),
            'play' => true,
        ];
    }

    /**
     * Stays a generic bag by design (unlike getDefaultSlideshowParams()'s
     * own precise 3-key shape): decodeSlideshowParams() can add arbitrary
     * regex-matched keys (any 'name-value'-style token in the encoded
     * string), not just period/repeat/play.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function correctSlideshowParams(array $params = []): array
    {

        $period = $params['period'] ?? 0;
        $min = \Piwigo\Config\CurrentConfig::slideshowPeriodMin();
        $max = \Piwigo\Config\CurrentConfig::slideshowPeriodMax();

        if ($period < $min) {
            $params['period'] = $min;
        } elseif ($period > $max) {
            $params['period'] = $max;
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeSlideshowParams(?string $encodeParams = null): array
    {
        $result = $this->getDefaultSlideshowParams();

        if ($encodeParams !== null && is_numeric($encodeParams)) {
            $result['period'] = $encodeParams;
        } else {
            $matches = [];
            if ((bool) preg_match_all('/([a-z]+)-(\d+)/', (string) $encodeParams, $matches)) {
                $matchCount = count($matches[1]);
                for ($i = 0; $i < $matchCount; $i++) {
                    $result[$matches[1][$i]] = $matches[2][$i];
                }
            }

            if ((bool) preg_match_all('/([a-z]+)-(true|false)/', (string) $encodeParams, $matches)) {
                $matchCount = count($matches[1]);
                for ($i = 0; $i < $matchCount; $i++) {
                    $result[$matches[1][$i]] = \Piwigo\Db\SqlDialect::getBoolean($matches[2][$i]);
                }
            }
        }

        return $this->correctSlideshowParams($result);
    }

    /**
     * @param  array<string, mixed>  $decodeParams
     */
    public function encodeSlideshowParams(array $decodeParams = []): string
    {
        // decodeSlideshowParams()/correctSlideshowParams() only ever
        // populate scalar values (period as int|numeric-string, play and
        // the regex-matched flags as bool|string); filter defensively so
        // array_diff_assoc() only ever compares string-castable values.
        $corrected = array_filter($this->correctSlideshowParams($decodeParams), is_scalar(...));
        $defaults = array_filter($this->getDefaultSlideshowParams(), is_scalar(...));
        $params = array_diff_assoc($corrected, $defaults);
        $result = '';

        // $params' keys are always string: correctSlideshowParams() and
        // getDefaultSlideshowParams() both declare array<string, mixed>.
        foreach ($params as $name => $value) {
            $value = \Piwigo\Db\SqlDialect::booleanToString($value);
            if (! is_scalar($value)) {
                continue;
            }

            $result .= '+' . $name . '-' . (string) $value;
        }

        return $result;
    }

    /**
     * Returns the number of pages of a PDF file, or false if it can't be
     * read.
     */
    public function countPdfPages(string $pdfPath): int|false
    {
        if (! is_readable($pdfPath)) {
            return false;
        }

        $pdfText = file_get_contents($pdfPath);
        if ($pdfText === false) {
            return false;
        }

        return preg_match_all('/\/Page\W/', $pdfText);
    }

    /**
     * Deletes all files (on disk) related to given image ids.
     *
     * @param array<int, int|string> $ids
     * @return array<int, int> image ids where files were successfully deleted (always
     *   int-keyed: $newIds is only ever appended to via [] on a plain array)
     */
    public function deleteElementFiles(array $ids, UrlServiceInterface $urlService): array
    {

        if ($ids === []) {
            return [];
        }

        $formatsOf = [];
        foreach ($this->repo->findFormatsByImageIds($ids) as $row) {
            $formatImageId = $row['image_id'];
            $ext = $row['ext'];

            if (! isset($formatsOf[$formatImageId])) {
                $formatsOf[$formatImageId] = [];
            }

            $formatsOf[$formatImageId][] = $ext;
        }

        $newIds = [];
        foreach ($this->repo->findPathsForFileDeletion($ids) as $row) {
            $rowId = $row['id'];
            $rowPath = $row['path'];

            if ($urlService->urlIsRemote($rowPath)) {
                continue;
            }

            $representativeExt = $row['representative_ext'] ?? null;
            $representativeExt = is_string($representativeExt) && $representativeExt !== '' ? $representativeExt : null;

            $files = [];
            $files[] = ImagePathHelper::getElementPath($row, $urlService);

            if ($representativeExt !== null) {
                $files[] = ImagePathHelper::originalToRepresentative($files[0], $representativeExt);
            }

            if (isset($formatsOf[$rowId])) {
                foreach ($formatsOf[$rowId] as $formatExt) {
                    $files[] = ImagePathHelper::originalToFormat($files[0], $formatExt);
                }
            }

            $ok = true;
            if (! \Piwigo\Config\CurrentConfig::neverDeleteOriginals()) {
                foreach ($files as $path) {
                    if (is_file($path) and ! unlink($path)) {
                        $ok = false;
                        trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                        break;
                    }
                }
            }

            if ($ok) {
                $derivativeInfos = [
                    'path' => $rowPath,
                ];
                if ($representativeExt !== null) {
                    $derivativeInfos['representative_ext'] = $representativeExt;
                }
                new DerivativeCacheService()
                    ->deleteElementDerivatives($derivativeInfos);
                $newIds[] = $rowId;
            } else {
                break;
            }
        }
        return $newIds;
    }

    /**
     * Deletes elements from database.
     * It also deletes :
     *    - all the comments related to elements
     *    - all the links between categories/tags and elements
     *    - all the favorites/rates associated to elements
     *    - removes elements from caddie
     *
     * @param array<int, int|string> $ids
     * @return int number of deleted elements
     */
    public function deleteElements(array $ids, UrlServiceInterface $urlService, bool $physicalDeletion = false): int
    {
        if ($ids === []) {
            return 0;
        }
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('begin_delete_elements', $ids);

        if ($physicalDeletion) {
            $ids = $this->deleteElementFiles($ids, $urlService);
            if ($ids === []) {
                return 0;
            }
        }

        $this->repo->deleteElementReferences($ids);
        $this->repo->deleteImages($ids);

        // are the photos used as category representant?
        $categoryIds = $this->repo->findRepresentedCategoryIds($ids);
        if ($categoryIds !== []) {
            $this->categoryService()
                ->updateCategory($categoryIds);
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('delete_elements', $ids);
        $this->activityLogger->record('photo', $ids, 'delete');
        return count($ids);
    }

    /**
     * Instead of associating images to categories, add them in the lounge, waiting for take-off.
     *
     * @since 12
     * @param array<int, int|string> $images - list of image ids
     * @param array<int, int|string> $categories - list of category ids
     */
    public function fillLounge(array $images, array $categories): void
    {
        $inserts = [];
        foreach ($categories as $categoryId) {
            foreach ($images as $imageId) {
                $inserts[] = [
                    'image_id' => $imageId,
                    'category_id' => $categoryId,
                ];
            }
        }

        $this->repo->massInsertLounge($inserts);
    }

    /**
     * Move images from the lounge to the categories they were intended for.
     *
     * @since 12
     * @return list<array{image_id: int, category_id: int}>|null the moved
     *   image_id/category_id rows, or null if another call is already
     *   emptying the lounge concurrently
     */
    public function emptyLounge(bool $invalidateUserCache = true): ?array
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $emptyLoungeRunning = \Piwigo\Config\CurrentConfig::emptyLoungeRunning();
        if ($emptyLoungeRunning !== null) {
            [$runningExecId, $runningExecStartTime] = explode('-', $emptyLoungeRunning);
            if (time() - (int) $runningExecStartTime > 60) {
                $logger->debug(__FUNCTION__ . ', exec=' . $runningExecId . ', timeout stopped by another call to the function');
                \Piwigo\Config\CurrentConfigService::get()->confDeleteParam('empty_lounge_running');
            }
        }

        $execId = SessionService::get()->generateKey(4);
        $requestMethod = Request\EmptyLoungeRequest::fromGlobals()->requestMethod;
        $apiSuffix = $requestMethod !== null ? ' (API:' . $requestMethod . ')' : '';
        $logger->debug(__FUNCTION__ . $apiSuffix . ', exec=' . $execId . ', begins');

        // if lounge is already being emptied, skip
        $this->repo->tryAcquireLoungeLock($execId . '-' . time());

        $emptyLoungeRunning = $this->repo->findLoungeLockValue();
        assert($emptyLoungeRunning !== null);
        [$runningExecId] = explode('-', $emptyLoungeRunning);

        if ($runningExecId !== $execId) {
            $logger->debug(__FUNCTION__ . ', exec=' . $execId . ', skip');
            return null;
        }
        $logger->debug(__FUNCTION__ . ', exec=' . $execId . ' wins the race and gets the token!');

        $maxImageId = 0;

        $rows = $this->repo->findLoungeRows();

        $images = [];
        foreach ($rows as $idx => $row) {
            $rowImageId = $row['image_id'];
            $rowCategoryId = $row['category_id'];

            if ($rowImageId > $maxImageId) {
                $maxImageId = $rowImageId;
            }

            $images[] = $rowImageId;

            if (! isset($rows[$idx + 1]) or $rows[$idx + 1]['category_id'] !== $row['category_id']) {
                // if we're at the end of the loop OR if category changes
                $this->associateImagesToCategories($images, [$rowCategoryId]);
                $images = [];
            }
        }

        $this->repo->deleteLoungeUpTo($maxImageId);

        if ($invalidateUserCache) {
            PermissionCacheInvalidator::invalidate();
        }

        \Piwigo\Config\CurrentConfigService::get()->confDeleteParam('empty_lounge_running');

        $logger->debug(__FUNCTION__ . ', exec=' . $execId . ', ends');

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('empty_lounge', $rows);

        return $rows;
    }

    /**
     * Associate a list of images to a list of categories.
     * The function will not duplicate links and will preserve ranks.
     *
     * @param array<int|string> $images real callers don't guarantee a list
     *   (e.g. UploadService's own int[]|null $categories) -- key type is
     *   never read below, only values
     * @param array<int|string> $categories
     */
    public function associateImagesToCategories(array $images, array $categories): ?false
    {
        if ($images === [] || $categories === []) {
            return false;
        }

        $existing = $this->repo->findExistingAssociations($images, $categories);
        $currentRankOf = $this->repo->findMaxRanksByCategory($categories);

        // associate only not already associated images
        $inserts = [];
        foreach ($categories as $categoryId) {
            if (! isset($currentRankOf[$categoryId])) {
                $currentRankOf[$categoryId] = 0;
            }
            if (! isset($existing[$categoryId])) {
                $existing[$categoryId] = [];
            }

            foreach ($images as $imageId) {
                // $existing[$categoryId] is a guaranteed int[] (repo casts every
                // value); $imageId may be a numeric string (real callers pass
                // both shapes) -- cast before the strict in_array() so a
                // numeric-string id still matches its equivalent int entry,
                // matching the original's own loose in_array() semantics.
                if (! in_array((int) $imageId, $existing[$categoryId], true)) {
                    $rank = ++$currentRankOf[$categoryId];

                    $inserts[] = [
                        'image_id' => $imageId,
                        'category_id' => $categoryId,
                        'rank' => $rank,
                    ];
                }
            }
        }

        if ($inserts !== []) {
            $this->repo->massInsertImageCategory($inserts);

            $this->categoryService()
                ->updateCategory($categories);
        }

        return null;
    }

    /**
     * Dissociate a list of images from a category.
     *
     * @param array<int, int|string> $images
     */
    public function dissociateImagesFromCategory(array $images, int|string $category): int
    {
        // physical links must not be broken, so we must first retrieve image_id
        // which create virtual links with the category to "dissociate from".
        $dissociables = $this->repo->findDissociableImageIds($images, $category);

        if ($dissociables !== []) {
            $this->repo->deleteImageCategoryLinks($dissociables, $category);
        }

        return count($dissociables);
    }

    /**
     * Dissociate images from all old categories except their storage category and
     * associate to new categories.
     * This function will preserve ranks.
     *
     * @param array<int, int|string> $images
     * @param array<int, int|string>|string $categories Piwigo\Admin\PictureModifyPageRenderer's
     *   $_POST['associate'] can reach here as a non-array scalar (e.g. a
     *   literal '0') since check_input_parameter() only validates array-ness
     *   when the value is non-empty
     */
    public function moveImagesToCategories(array $images, array|string $categories): ?false
    {
        if (! is_array($categories)) {
            $categories = [];
        }

        if ($images === []) {
            return false;
        }

        // let's first break links with all old albums but their "storage album"
        $this->repo->deleteNonStorageCategoryLinks($images, $categories);

        if ($categories !== []) {
            $this->associateImagesToCategories($images, $categories);
        }

        return null;
    }

    /**
     * Return the list of image ids where md5sum is null.
     *
     * @return list<int> image_ids
     */
    public function getPhotosNoMd5sum(): array
    {
        return $this->repo->findImageIdsWithoutMd5sum();
    }

    /**
     * Compute and add the md5sum of image ids (where md5sum is null).
     *
     * @param list<int> $ids list of image ids and there paths
     * @return int number of md5sum added
     */
    public function addMd5sum(array $ids): int
    {
        $pathForId = $this->repo->findPathsForMd5sum($ids);

        $updates = [];

        foreach ($pathForId as $id => $path) {
            $md5sum = md5_file(CurrentPaths::get()->root . $path);
            // md5_file() returns false when the file can't be read -- skip
            // rather than writing a bogus md5sum that would then read as
            // "already computed" on the next addMd5sum() pass.
            if ($md5sum === false) {
                continue;
            }

            $updates[] = [
                'id' => $id,
                'md5sum' => $md5sum,
            ];
        }

        $this->repo->massUpdateMd5sums($updates);

        return count($pathForId);
    }

    public function countOrphans(): int
    {
        if (\Piwigo\Config\CurrentConfig::countOrphans() === null) {
            // we don't care about the list of image_ids, we only care about the number
            // of orphans, so let's use a faster method than calling count(getOrphans())
            $counter = $this->repo->countAllImages() - $this->repo->countImagesInCategories();
            \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('count_orphans', $counter, updateGlobal: true);
        }

        return \Piwigo\Config\CurrentConfig::countOrphans() ?? 0;
    }

    /**
     * Return the list of image ids associated to no album.
     *
     * @return list<int> $imageIds
     */
    public function getOrphans(): array
    {
        // exclude images in the lounge
        $loungedIds = $this->repo->findLoungedImageIds();

        return $this->repo->findOrphanImageIds($loungedIds);
    }

    /**
     * save the rank depending on given images order
     *
     * The list of ordered images id is supposed to be in the same parent
     * category
     *
     * @param array<int, int|string> $images
     */
    public function saveImagesOrder(int|string $categoryId, array $images): void
    {
        $currentRank = 0;
        $datas = [];
        foreach ($images as $id) {
            $datas[] = [
                'category_id' => $categoryId,
                'image_id' => $id,
                'rank' => ++$currentRank,
            ];
        }
        $this->repo->massUpdateImageCategoryRanks($datas);
    }

    /**
     * Force update on images.lastmodified column. Useful when modifying the tag
     * list.
     *
     * @since 2.9
     * @param array<int, int|string> $imageIds
     */
    public function updateImagesLastmodified(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $this->repo->touchLastmodified($imageIds);
    }

    /**
     * Get infos related to an image.
     *
     * Returns a plain array, not the repository's typed Image projection --
     * every real caller (PictureModifyPageRenderer, PhotoSubController,
     * photos_add_direct) stores this straight into a growing Smarty
     * template-variable bag (`$page['image']`), including reassigning
     * individual keys on it after the fact, which a readonly projection
     * can't support. The narrowing win still applies: {@see
     * \Piwigo\Image\Projection\Image::fromRow()} does it once, this just
     * unboxes back to array at this public boundary.
     *
     * @since 2.9
     * @param int|string $imageId several callers (Piwigo\Admin\
     *   PictureModifyPageRenderer, Piwigo\Controller\Admin\PhotoSubController,
     *   admin/photos_add_direct.php) pass a raw, unvalidated $_GET value
     *   directly
     * @return array{id: int, file: string, date_available: ?string, date_creation: ?string,
     *   name: ?string, comment: ?string, author: ?string, hit: int, filesize: ?int,
     *   width: ?int, height: ?int, coi: ?string, representative_ext: ?string,
     *   date_metadata_update: ?string, rating_score: ?float, path: string,
     *   storage_category_id: ?int, level: int, md5sum: ?string, added_by: ?int,
     *   rotation: ?int, latitude: ?float, longitude: ?float, lastmodified: string}|null
     */
    public function getImageInfos(int|string $imageId, HtmlRenderingInterface $htmlRenderer, bool $dieOnMissing = false): ?array
    {
        if (! is_numeric($imageId)) {
            $htmlRenderer->fatalError('[' . __FUNCTION__ . '] invalid image identifier ' . htmlentities($imageId));
        }

        $image = $this->repo->findById($imageId);
        if ($image === null) {
            if ($dieOnMissing) {
                $htmlRenderer->fatalError('photo ' . $imageId . ' does not exist');
            }

            return null;
        }

        return $image->toArray();
    }
}
