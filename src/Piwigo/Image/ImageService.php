<?php

declare(strict_types=1);

namespace Piwigo\Image;

use LogicException;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\SortRenderer;
use Piwigo\Image\Event\BeginDeleteElements;
use Piwigo\Image\Event\DeleteElements;
use Piwigo\Image\Projection\AddMethodBreakdown;
use Piwigo\Image\Projection\ExtensionBreakdown;
use Piwigo\Image\Projection\FormatCountSum;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\ImageCategoryLink;
use Piwigo\Image\Projection\ImageCategoryPair;
use Piwigo\Image\Projection\ImageIdExt;
use Piwigo\Image\Projection\ImageIdFile;
use Piwigo\Image\Projection\ImageInsertRow;
use Piwigo\Image\Projection\ImageLookupRow;
use Piwigo\Image\Projection\ImageSyncInsertRow;
use Piwigo\Image\Projection\MissingDerivativeRow;
use Piwigo\Image\Projection\NextIdCount;
use Piwigo\Image\Projection\PathRepresentativeExt;
use Piwigo\Image\Projection\PathRepresentativeExtLevel;
use Piwigo\Image\Projection\RelatedCategoryRow;
use Piwigo\Image\Projection\SlideshowParams;
use Piwigo\Image\Projection\VisibleCategoryRow;
use Piwigo\Image\Request\EmptyLoungeRequest;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;

/**
 * Slideshow param encode/decode/correct and PDF page counting -- pure
 * computation, none of which touches the database directly (that's
 * {@see ImageRepository}'s job).
 *
 * `ActivityLoggerInterface` is injected rather than the concrete
 * `Piwigo\Activity\ActivityService` because that service sits in a
 * higher dependency layer (L2bExtendedDomain) than this class
 * (L2aCoreDomain) -- see `ActivityLoggerInterface`'s own docblock.
 *
 * emptyLounge()'s writes (empty_lounge_running/count_orphans) go through
 * CurrentConfigService::get(). emptyLounge()/countOrphans() resolve
 * CurrentConfigService via a private lazy currentConfigService() helper
 * rather than a constructor parameter, to avoid rippling a required
 * dependency across this class's many construction sites for the sake of
 * those two call sites -- see self::logger()'s own docblock for the same
 * reasoning.
 */
final readonly class ImageService
{
    public function __construct(
        private ImageRepository $repo,
        private ActivityLoggerInterface $activityLogger,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private CategoryService $categoryService,
    ) {}

    /**
     * Container resolve, not a constructor property -- used only inside
     * emptyLounge()'s own internal read below. A required constructor
     * param here would ripple across this class's own real construction
     * sites for the sake of this one internal read, same low-blast-radius
     * reasoning as {@see self::currentConfigService()}. Falls back to an
     * OFF-severity Logger when Kernel::boot() hasn't run.
     */
    private function logger(): Logger
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
     * Same "container resolve, not a constructor param" reasoning as
     * self::logger() above -- constructor-injecting CurrentConfigService
     * would ripple across every one of this class's own real construction
     * sites for the sake of emptyLounge()'s 2 call sites alone.
     */
    private function currentConfigService(): CurrentConfigService
    {
        if (Kernel::isBooted()) {
            $currentConfigService = Kernel::container()->get(CurrentConfigService::class);
            if (! $currentConfigService instanceof CurrentConfigService) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
            }

            return $currentConfigService;
        }

        return new CurrentConfigService();
    }

    /**
     * Same "container resolve, not a constructor param" reasoning as
     * self::logger()/self::currentConfigService() above -- constructor-
     * injecting SortRenderer would ripple across every one of this class's
     * own real construction sites for the sake of
     * getWithConditionsPaginated()'s one call.
     */
    private function sortRenderer(): SortRenderer
    {
        $sortRenderer = Kernel::container()->get(SortRenderer::class);
        if (! $sortRenderer instanceof SortRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . SortRenderer::class);
        }

        return $sortRenderer;
    }

    public function getDefaultSlideshowParams(): SlideshowParams
    {
        return new SlideshowParams(
            period: $this->currentConfig->slideshowPeriod,
            repeat: $this->currentConfig->slideshowRepeat,
            play: true,
        );
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
        $min = $this->currentConfig->slideshowPeriodMin;
        $max = $this->currentConfig->slideshowPeriodMax;

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
        $result = $this->getDefaultSlideshowParams()
            ->toArray();

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
                    $result[$matches[1][$i]] = $matches[2][$i] === 'true';
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
        $defaults = array_filter($this->getDefaultSlideshowParams()->toArray(), is_scalar(...));
        $params = array_diff_assoc($corrected, $defaults);
        $result = '';

        // $params' keys are always string: correctSlideshowParams() and
        // getDefaultSlideshowParams() both declare array<string, mixed>.
        foreach ($params as $name => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
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
        if (! is_file($pdfPath) || ! is_readable($pdfPath)) {
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
            $formatImageId = $row->imageId;
            $ext = $row->ext;

            if (! isset($formatsOf[$formatImageId])) {
                $formatsOf[$formatImageId] = [];
            }

            $formatsOf[$formatImageId][] = $ext;
        }

        $newIds = [];
        foreach ($this->repo->findPathsForFileDeletion($ids) as $row) {
            $rowId = $row->id;
            $rowPath = $row->path;

            if ($urlService->urlIsRemote($rowPath)) {
                continue;
            }

            $representativeExt = $row->representativeExt;
            $representativeExt = is_string($representativeExt) && $representativeExt !== '' ? $representativeExt : null;

            $files = [];
            $files[] = ImagePathHelper::getElementPath($row->toArray(), $urlService, $this->paths);

            if ($representativeExt !== null) {
                $files[] = ImagePathHelper::originalToRepresentative($files[0], $representativeExt);
            }

            if (isset($formatsOf[$rowId])) {
                foreach ($formatsOf[$rowId] as $formatExt) {
                    $files[] = ImagePathHelper::originalToFormat($files[0], $formatExt);
                }
            }

            $ok = true;
            if (! $this->currentConfig->neverDeleteOriginals) {
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
                new DerivativeCacheService($this->currentConfig, $this->paths)
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
        $this->eventDispatcher->dispatch(new BeginDeleteElements($ids));

        if ($physicalDeletion) {
            $ids = $this->deleteElementFiles($ids, $urlService);
            if ($ids === []) {
                return 0;
            }
        }

        // Every table referencing images.id carries a real ON DELETE
        // CASCADE FK (tests/Fixtures/piwigo-17.0.sql) -- deleteImages()
        // below removes all of those rows itself, via cascade.
        $this->repo->deleteImages($ids);

        // are the photos used as category representant?
        $categoryIds = $this->repo->findRepresentedCategoryIds($ids);
        if ($categoryIds !== []) {
            $this->categoryService
                ->updateCategory($categoryIds);
        }

        $this->eventDispatcher->dispatch(new DeleteElements($ids));
        $this->activityLogger->record('photo', $ids, 'delete');
        return count($ids);
    }

    /**
     * Instead of associating images to categories, add them in the lounge, waiting for take-off.
     *
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
     * @param ?int $now the current Unix timestamp, used for both the
     *   staleness check below and the new lock's own timestamp -- defaults
     *   to a real `time()` read; the explicit parameter exists so a test
     *   can pin the exact instant this method treats as "now" instead of
     *   racing its own separately-captured `time()` call against this
     *   method's internal one (real risk only at the exact 60-second
     *   staleness boundary, confirmed live)
     * @return list<array{image_id: int, category_id: int}>|null the moved
     *   image_id/category_id rows, or null if another call is already
     *   emptying the lounge concurrently
     */
    public function emptyLounge(bool $invalidateUserCache = true, ?int $now = null): ?array
    {
        $now ??= time();
        $logger = $this->logger();

        $emptyLoungeRunning = $this->currentConfig->emptyLoungeRunning;
        if ($emptyLoungeRunning !== null) {
            [$runningExecId, $runningExecStartTime] = explode('-', $emptyLoungeRunning);
            if ($now - (int) $runningExecStartTime > 60) {
                $logger->debug(__FUNCTION__ . ', exec=' . $runningExecId . ', timeout stopped by another call to the function');
                $this->currentConfigService()
                    ->get()
                    ->confDeleteParam('empty_lounge_running');
            }
        }

        $execId = $this->sessionService->generateKey(4);
        $requestMethod = EmptyLoungeRequest::fromGlobals()->requestMethod;
        $apiSuffix = $requestMethod !== null ? ' (API:' . $requestMethod . ')' : '';
        $logger->debug(__FUNCTION__ . $apiSuffix . ', exec=' . $execId . ', begins');

        // if lounge is already being emptied, skip
        $this->repo->tryAcquireLoungeLock($execId . '-' . $now);

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
            $rowImageId = $row->imageId;
            $rowCategoryId = $row->categoryId;

            if ($rowImageId > $maxImageId) {
                $maxImageId = $rowImageId;
            }

            $images[] = $rowImageId;

            if (! isset($rows[$idx + 1]) or $rows[$idx + 1]->categoryId !== $row->categoryId) {
                // if we're at the end of the loop OR if category changes
                $this->associateImagesToCategories($images, [$rowCategoryId]);
                $images = [];
            }
        }

        $this->repo->deleteLoungeUpTo($maxImageId);

        if ($invalidateUserCache) {
            PermissionCacheInvalidator::invalidate();
        }

        $this->currentConfigService()
            ->get()
            ->confDeleteParam('empty_lounge_running');

        $logger->debug(__FUNCTION__ . ', exec=' . $execId . ', ends');

        $rowArrays = array_map(static fn (ImageCategoryLink $row): array => $row->toArray(), $rows);

        return $rowArrays;
    }

    /**
     * Associate a list of images to a list of categories.
     * The function will not duplicate links and will preserve ranks.
     *
     * @param array<int> $images real callers don't guarantee a list -- key
     *   type is never read below, only values
     * @param array<int> $categories real callers don't guarantee a list
     *   (e.g. UploadService's own int[]|null $categories) -- key type is
     *   never read below, only values
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
                if (! in_array($imageId, $existing[$categoryId], true)) {
                    $rank = ++$currentRankOf[$categoryId];

                    $inserts[] = new ImageCategoryPair(
                        imageId: $imageId,
                        categoryId: $categoryId,
                        rank: $rank,
                    );
                }
            }
        }

        if ($inserts !== []) {
            $this->repo->massInsertImageCategory($inserts);

            $this->categoryService
                ->updateCategory($categories);
        }

        return null;
    }

    /**
     * Dissociate a list of images from a category.
     *
     * @param array<int> $images
     */
    public function dissociateImagesFromCategory(array $images, int $category): int
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
     * @param array<int> $images
     * @param array<int> $categories
     */
    public function moveImagesToCategories(array $images, array $categories): ?false
    {
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
            $absPath = $this->paths->root . $path;
            $md5sum = is_readable($absPath) ? md5_file($absPath) : false;
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
        if ($this->currentConfig->countOrphans === null) {
            // we don't care about the list of image_ids, we only care about the number
            // of orphans, so let's use a faster method than calling count(getOrphans())
            $counter = $this->repo->countAllImages() - $this->repo->countImagesInCategories();
            $this->currentConfigService()
                ->get()
                ->confUpdateParam('count_orphans', $counter, updateGlobal: true);
        }

        return $this->currentConfig->countOrphans ?? 0;
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
     * @return list<Dimensions>
     */
    public function getDistinctDimensions(): array
    {
        return $this->repo->findDistinctDimensions();
    }

    /**
     * @return list<int>
     */
    public function getDistinctFilesizes(): array
    {
        return $this->repo->findDistinctFilesizes();
    }

    /**
     * @param array<int, int|string> $ids
     * @return list<PathRepresentativeExt>
     */
    public function getPathsForFileDeletion(array $ids): array
    {
        return $this->repo->findPathsForFileDeletion($ids);
    }

    /**
     * @param  list<int>  $ids
     * @return list<PathRepresentativeExtLevel>
     */
    public function getPathsAndLevelForIds(array $ids): array
    {
        return $this->repo->findPathsAndLevelForIds($ids);
    }

    /**
     * @param array<int, int> $imageIds
     * @return int affected row count
     */
    public function updateLevelForImages(array $imageIds, int $level): int
    {
        return $this->repo->updateLevelForImages($imageIds, $level);
    }

    /**
     * @param array<int, int> $imageIds
     */
    public function updateTextFieldForImages(array $imageIds, ImageTextField $field, ?string $value): void
    {
        $this->repo->updateTextFieldForImages($imageIds, $field, $value);
    }

    /**
     * @param array{primary: string[], update: string[]} $dbfields
     * @param array<int, array<string, mixed>> $datas
     */
    public function massUpdateFields(array $dbfields, array $datas, int $flags = 0): void
    {
        $this->repo->massUpdateFields($dbfields, $datas, $flags);
    }

    /**
     * A null parameter means "not supplied" -- see
     * ImageRepository::updateDescriptiveFields()'s own docblock.
     */
    public function updateDescriptiveFields(
        ImageId $imageId,
        ?string $name = null,
        ?string $author = null,
        ?string $comment = null,
        ?string $dateCreation = null,
    ): void {
        $this->repo->updateDescriptiveFields($imageId, $name, $author, $comment, $dateCreation);
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function updateFields(ImageId $imageId, array $updates): void
    {
        $this->repo->updateFields($imageId, $updates);
    }

    public function insertImage(ImageInsertRow $insert): int
    {
        return $this->repo->insertImage($insert);
    }

    /**
     * @param list<ImageSyncInsertRow> $inserts
     */
    public function massInsertImages(array $inserts): void
    {
        $this->repo->massInsertImages($inserts);
    }

    public function updateFormatFilesize(int $formatId, ?int $filesize): void
    {
        $this->repo->updateFormatFilesize($formatId, $filesize);
    }

    public function insertFormat(ImageId $imageId, string $ext, ?int $filesize): int
    {
        return $this->repo->insertFormat($imageId, $ext, $filesize);
    }

    /**
     * @param  list<array{image_id: int, ext: string, filesize: ?int}>  $inserts
     */
    public function massInsertFormats(array $inserts): void
    {
        $this->repo->massInsertFormats($inserts);
    }

    /**
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function getBatchManagerThumbnails(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        return $this->repo->findBatchManagerThumbnails($imageIds, $categoryId, $orderBySql, $limit, $offset);
    }

    /**
     * @param array<array-key, int|string> $imageIds
     * @return list<array{id: int, date_creation: ?string}>
     */
    public function getIdsAndDatesForBatchUnitSave(array $imageIds): array
    {
        return $this->repo->findIdsAndDatesForBatchUnitSave($imageIds);
    }

    /**
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function getBatchManagerUnitRows(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        return $this->repo->findBatchManagerUnitRows($imageIds, $categoryId, $orderBySql, $limit, $offset);
    }

    /**
     * @return list<array{category_id: int, uppercats: string, dir: ?string}>
     */
    public function getCategoryLinksForImage(ImageId $imageId): array
    {
        return $this->repo->findCategoryLinksForImage($imageId);
    }

    /**
     * @return list<int>
     */
    public function getCategoryIdsForImage(ImageId $imageId): array
    {
        return $this->repo->findCategoryIdsForImage($imageId);
    }

    /**
     * @return list<int>
     */
    public function getIssue1827CandidateImageIds(int $limit): array
    {
        return $this->repo->findIssue1827CandidateImageIds($limit);
    }

    /**
     * @return list<int>
     */
    public function getImageIdsSample(int $limit): array
    {
        return $this->repo->findImageIdsSample($limit);
    }

    /**
     * @return list<string>
     */
    public function getDuplicatePaths(): array
    {
        return $this->repo->findDuplicatePaths();
    }

    /**
     * @return list<int>
     */
    public function getOrphanImageCategoryLinkIds(): array
    {
        return $this->repo->findOrphanImageCategoryLinkIds();
    }

    /**
     * @param list<int> $imageIds
     */
    public function deleteImageCategoryRowsForImageIds(array $imageIds): void
    {
        $this->repo->deleteImageCategoryRowsForImageIds($imageIds);
    }

    /**
     * @param array<int|string> $categories real callers don't guarantee a list
     * @return array<int|string, int> category_id => max rank
     */
    public function getMaxRanksByCategory(array $categories): array
    {
        return $this->repo->findMaxRanksByCategory($categories);
    }

    /**
     * @param list<int> $categoryIds
     */
    public function deleteImageCategoryLinksForCategoryIds(ImageId $imageId, array $categoryIds): void
    {
        $this->repo->deleteImageCategoryLinksForCategoryIds($imageId, $categoryIds);
    }

    /**
     * Raw `image_category` link insert, rows already built by the caller --
     * the upload pipeline's own "set category relations for one image,
     * honoring caller-supplied per-category ranks" step, unlike
     * associateImagesToCategories() above which always auto-assigns the
     * next rank. $rank is optional per row -- see ImageRepository::
     * massInsertImageCategory()'s own docblock.
     *
     * @param  list<ImageCategoryPair>  $inserts
     */
    public function insertImageCategoryLinks(array $inserts): void
    {
        $this->repo->massInsertImageCategory($inserts);
    }

    /**
     * save the rank depending on given images order
     *
     * The list of ordered images id is supposed to be in the same parent
     * category
     *
     * @param array<int, int|string> $images
     */
    public function saveImagesOrder(int $categoryId, array $images): void
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
     * photos_add_direct, BatchManagerUnitPageRenderer) stores this straight
     * into a growing Latte template-variable bag (`$page['image']` or
     * `$media['image']`), including reassigning individual keys on it after
     * the fact, which a readonly projection can't support. The narrowing
     * win still applies: {@see
     * \Piwigo\Image\Projection\Image::fromRow()} does it once, this just
     * unboxes back to array at this public boundary.
     *
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

        // A numeric-but-non-positive value (e.g. 0) is format-valid but
        // never a real image id -- treated the same as "not found" below,
        // not as the format-invalid case above (ImageId::tryFrom() would
        // reject it for the same reason findById() will never find it).
        $imageIdVo = ImageId::tryFrom($imageId);
        $image = $imageIdVo instanceof ImageId ? $this->repo->findById($imageIdVo) : null;
        if (! $image instanceof Image) {
            if ($dieOnMissing) {
                $htmlRenderer->fatalError('photo ' . $imageId . ' does not exist');
            }

            return null;
        }

        return $image->toArray();
    }

    public function findByIdOrFilePattern(int $imageId, ?string $imageFile): ImageLookupRow|false
    {
        return $this->repo->findByIdOrFilePattern($imageId, $imageFile);
    }

    public function isImageAccessibleWithCondition(ImageId $imageId, PermissionCriteria $criteria): bool
    {
        return $this->repo->isImageAccessibleWithCondition($imageId, $criteria);
    }

    public function isImageAccessibleViaCategoryWithCondition(ImageId $imageId, PermissionCriteria $criteria): bool
    {
        return $this->repo->isImageAccessibleViaCategoryWithCondition($imageId, $criteria);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function getRowWithCondition(ImageId $imageId, PermissionCriteria $criteria): ?array
    {
        return $this->repo->findRowWithCondition($imageId, $criteria);
    }

    /**
     * @return list<RelatedCategoryRow>
     */
    public function getRelatedCategoriesForImage(ImageId $imageId, PermissionCriteria $criteria): array
    {
        return $this->repo->findRelatedCategoriesForImage($imageId, $criteria);
    }

    public function isImageCommentableWithCondition(ImageId $imageId, PermissionCriteria $criteria): bool
    {
        return $this->repo->isImageCommentableWithCondition($imageId, $criteria);
    }

    /**
     * @return list<VisibleCategoryRow>
     */
    public function getVisibleCategoriesForImage(ImageId $imageId, PermissionCriteria $criteria): array
    {
        return $this->repo->findVisibleCategoriesForImage($imageId, $criteria);
    }

    /**
     * @return list<int>
     */
    public function getIdsByMd5sum(string $md5sum): array
    {
        return $this->repo->findIdsByMd5sum($md5sum);
    }

    /**
     * @param list<string> $md5sums
     * @return array<string, int>
     */
    public function getIdsByMd5sums(array $md5sums): array
    {
        return $this->repo->findIdsByMd5sums($md5sums);
    }

    /**
     * @param list<string> $filenames
     * @return array<string, int>
     */
    public function getIdsByFilenames(array $filenames): array
    {
        return $this->repo->findIdsByFilenames($filenames);
    }

    public function getFormatIdByImageAndExt(ImageId $imageId, string $ext): ?int
    {
        return $this->repo->findFormatIdByImageAndExt($imageId, $ext);
    }

    /**
     * @param array<int, int|string> $ids
     * @return list<PathRepresentativeExt>
     */
    public function getPathsForIds(array $ids): array
    {
        return $this->repo->findPathsForFileDeletion($ids);
    }

    /**
     * Full image row, or null if $imageId doesn't exist -- Admin\Upload\
     * UploadService's own post-save re-read (throws its own plain
     * \Exception on null, unlike getImageInfos()'s HtmlRenderingInterface
     * fatal-error path above, so this stays a separate, simpler method
     * rather than reusing that one with a different error-handling shape).
     * Stays an array, not the real Image Projection: one real caller
     * (AlbumNotificationPageRenderer) feeds the whole row straight into
     * DerivativeImage::url(array<string, mixed>|SrcImage $infos) -- an
     * object there must already be a real SrcImage (is_object($infos) is
     * used as-is, never wrapped), so a different typed object would break
     * silently, same "array form stays generic" contract SrcImage::
     * __construct() already documents. The shape itself is exactly
     * Image::toArray()'s own already-precise return type, copied here.
     *
     * @return array{id: int, file: string, date_available: ?string, date_creation: ?string,
     *   name: ?string, comment: ?string, author: ?string, hit: int, filesize: ?int,
     *   width: ?int, height: ?int, coi: ?string, representative_ext: ?string,
     *   date_metadata_update: ?string, rating_score: ?float, path: string,
     *   storage_category_id: ?int, level: int, md5sum: ?string, added_by: ?int,
     *   rotation: ?int, latitude: ?float, longitude: ?float, lastmodified: string}|null
     */
    public function getImageRow(int $imageId): ?array
    {
        $imageIdVo = ImageId::tryFrom($imageId);

        return $imageIdVo instanceof ImageId ? $this->repo->findById($imageIdVo)?->toArray() : null;
    }

    public function getTotalImageCount(): int
    {
        return $this->repo->countAllImages();
    }

    public function getTotalFilesize(): int
    {
        return $this->repo->sumFilesize();
    }

    public function getFormatCountAndSize(): FormatCountSum
    {
        return $this->repo->countAndSumFormats();
    }

    public function getEarliestDateAvailable(): ?string
    {
        return $this->repo->findEarliestDateAvailable();
    }

    /**
     * @return list<ImageIdFile>
     */
    public function getAllIdsAndFiles(): array
    {
        return $this->repo->findAllIdsAndFiles();
    }

    /**
     * @return list<ImageIdExt>
     */
    public function getAllImageIdsAndExts(): array
    {
        return $this->repo->findAllImageIdsAndExts();
    }

    public function getImageCategoryLinkCount(): int
    {
        return $this->repo->countImageCategoryLinks();
    }

    public function getMinDateAvailable(): ?string
    {
        return $this->repo->findMinDateAvailable();
    }

    public function getNextIdAndCount(): NextIdCount
    {
        return $this->repo->findNextIdAndCount();
    }

    /**
     * @return list<MissingDerivativeRow>
     */
    public function getForMissingDerivatives(MissingDerivativesCriteria $criteria, int $startId, int $limit): array
    {
        return $this->repo->findForMissingDerivatives($criteria, $startId, $limit);
    }

    public function hasAccessibleImageWithAuthor(PermissionCriteria $criteria): bool
    {
        return $this->repo->hasAccessibleImageWithAuthor($criteria);
    }

    public function existsById(ImageId $id): bool
    {
        return $this->repo->existsById($id);
    }

    /**
     * @param array<int|string> $ids
     * @return list<int>
     */
    public function getExistingIds(array $ids): array
    {
        return $this->repo->findExistingIds($ids);
    }

    public function getPathById(ImageId $imageId): ?string
    {
        return $this->repo->findPathById($imageId);
    }

    /**
     * @return list<int>
     */
    public function getIdsByFilenameInCategory(string $filename, CategoryId $categoryId): array
    {
        return $this->repo->findIdsByFilenameInCategory($filename, $categoryId);
    }

    public function countImagesInCategory(CategoryId $categoryId): int
    {
        return $this->repo->countImagesInCategory($categoryId);
    }

    /**
     * @return list<int|string>
     */
    public function getImageIdsOrderedByRankForCategory(CategoryId $categoryId): array
    {
        return $this->repo->findImageIdsOrderedByRankForCategory($categoryId);
    }

    public function isImageInCategory(ImageId $imageId, CategoryId $categoryId): bool
    {
        return $this->repo->isImageInCategory($imageId, $categoryId);
    }

    public function getMaxRankForCategory(CategoryId $categoryId): ?int
    {
        return $this->repo->findMaxRankForCategory($categoryId);
    }

    public function incrementRanksFromForCategory(CategoryId $categoryId, int $rank): void
    {
        $this->repo->incrementRanksFromForCategory($categoryId, $rank);
    }

    public function updateRankForImageInCategory(ImageId $imageId, CategoryId $categoryId, int $rank): void
    {
        $this->repo->updateRankForImageInCategory($imageId, $categoryId, $rank);
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function getWithConditionsPaginated(CategoryImagesCriteria $criteria, ?PhotoSortOrder $orderBy, int $limit, int $offset): PaginatedResult
    {
        $order = $orderBy ?? $this->currentConfig->orderBy;

        return $this->repo->findWithConditionsPaginated($criteria, $this->sortRenderer()->toSql($order, 'i'), $limit, $offset);
    }

    /**
     * @param  list<int>  $imageIds
     * @return list<ImageCategoryLink>
     */
    public function getCategoryLinksForImageIdsWithCondition(array $imageIds, PermissionCriteria $criteria): array
    {
        return $this->repo->findCategoryLinksForImageIdsWithCondition($imageIds, $criteria);
    }

    public function getNextId(): int
    {
        return $this->repo->findNextId();
    }

    /**
     * @param  list<int|string>  $categoryIds
     * @return array<int, string>
     */
    public function getIdsAndPathsByStorageCategoryIds(array $categoryIds): array
    {
        return $this->repo->findIdsAndPathsByStorageCategoryIds($categoryIds);
    }

    /**
     * @param  list<int>  $formatIds
     */
    public function deleteFormatsByIds(array $formatIds): void
    {
        $this->repo->deleteFormatsByIds($formatIds);
    }

    /**
     * @param list<int> $formatIds
     * @return list<ImageIdExt>
     */
    public function getImageIdsAndExtsByFormatIds(array $formatIds): array
    {
        return $this->repo->findImageIdsAndExtsByFormatIds($formatIds);
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function getIdsInCategories(array $categoryIds): array
    {
        return $this->repo->findIdsInCategories($categoryIds);
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function getIdsNotInCategories(array $categoryIds): array
    {
        return $this->repo->findIdsNotInCategories($categoryIds);
    }

    /**
     * @return list<int>
     */
    public function getIdsAddedSameDayAsLatest(): array
    {
        return $this->repo->findIdsAddedSameDayAsLatest();
    }

    /**
     * @return list<int>
     */
    public function getIdsWithNoTag(): array
    {
        return $this->repo->findIdsWithNoTag();
    }

    /**
     * @return list<int>
     */
    public function getAllCategorizedIds(): array
    {
        return $this->repo->findAllCategorizedIds();
    }

    /**
     * @return list<int>
     */
    public function getIdsWithNoCategory(): array
    {
        return $this->repo->findIdsWithNoCategory();
    }

    /**
     * @param list<ImageDuplicateField> $fields
     * @return list<int>
     */
    public function getIdsGroupedByDuplicateFields(array $fields): array
    {
        return $this->repo->findIdsGroupedByDuplicateFields($fields);
    }

    /**
     * @param list<string> $whereClauses
     * @param array<string, int|float|string> $params
     * @return list<int>
     */
    public function getIdsWithConditions(array $whereClauses, array $params, string $orderBySql): array
    {
        return $this->repo->findIdsWithConditions($whereClauses, $params, $orderBySql);
    }

    /**
     * @return list<int>
     */
    public function getIdsVisibleInCategoriesRecentlyAvailable(string $categoryIdsCsv, string $recentPeriodExpr): array
    {
        return $this->repo->findIdsVisibleInCategoriesRecentlyAvailable($categoryIdsCsv, $recentPeriodExpr);
    }

    public function getMostRecentDateAvailable(): ?string
    {
        return $this->repo->findMostRecentDateAvailable();
    }

    public function countWithStorageCategory(): int
    {
        return $this->repo->countWithStorageCategory();
    }

    /**
     * @return list<AddMethodBreakdown>
     */
    public function getAddMethodBreakdown(): array
    {
        return $this->repo->findAddMethodBreakdown();
    }

    /**
     * @return list<ExtensionBreakdown>
     */
    public function getExtensionBreakdown(): array
    {
        return $this->repo->findExtensionBreakdown();
    }

    /**
     * @return list<ExtensionBreakdown>
     */
    public function getFormatExtensionBreakdown(): array
    {
        return $this->repo->findFormatExtensionBreakdown();
    }
}
