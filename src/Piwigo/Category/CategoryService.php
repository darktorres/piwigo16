<?php

declare(strict_types=1);

namespace Piwigo\Category;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Exception;
use LogicException;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CachePools;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\Env;
use Piwigo\Core\FilterState;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RecentIconResolver;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Album\CreateVirtualCategory;
use Piwigo\Event\Album\DeleteCategories;
use Piwigo\Event\Album\GetCategoryPreferredImageOrders;
use Piwigo\Event\Site\DeleteSite;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;

/**
 * Category domain business logic.
 *
 * `Activity` is L2bExtendedDomain; {@see deleteSite()}/
 * {@see deleteCategories()}/{@see moveCategories()}/
 * {@see createVirtualCategory()} need it to log activity, but
 * constructor-injecting `ActivityLoggerInterface` (this class's usual
 * cross-layer-dependency fix elsewhere, e.g. {@see imageService()}) would
 * force all ~45 real `new CategoryService(...)` call sites to supply one
 * -- the vast majority pure-read (menu rendering, gallery browsing) and
 * never touch activity logging. Instead, only those 4 write methods take
 * `ActivityLoggerInterface` as an explicit parameter.
 */
final readonly class CategoryService
{
    public function __construct(
        private Lang $lang,
        private CategoryRepository $repo,
        private PermissionService $permissionService,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private Translator $translator,
        private AccessLevelChecker $accessLevelChecker,
    ) {}

    /**
     * Container resolve, not a constructor property -- used only inside
     * getComputedCategories()'s own internal RecentIconResolver::getIcon()
     * call below. A required constructor param here would ripple across
     * this class's own ~21 real construction sites for the sake of this
     * one internal read. Falls back to a fresh, unmemoized instance when
     * Kernel::boot() hasn't run, matching ProcessCache::getStatic()/
     * setStatic()'s own identical pre-boot fallback.
     */
    private function processCache(): ProcessCache
    {
        if (Kernel::isBooted()) {
            $processCache = Kernel::container()->get(ProcessCache::class);
            if (! $processCache instanceof ProcessCache) {
                throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
            }

            return $processCache;
        }

        return new ProcessCache();
    }

    /**
     * `Activity` is L2bExtendedDomain; `CategoryService` is L2aCoreDomain
     * and may not depend on it directly (a private helper constructing
     * `ActivityService` inline, same as {@see \Piwigo\Image\ImageService}'s
     * own `categoryService()` helper does for same-layer `CategoryService`,
     * is a real `deptrac analyse` violation here specifically because
     * Activity crosses layers). Unlike `ImageService`/`TagService` (each
     * constructed fresh per write operation), `CategoryService` has ~45
     * real construction sites, the vast majority pure-read (menu
     * rendering, gallery browsing) that never touch activity logging --
     * constructor-injecting `ActivityLoggerInterface` would force all 45
     * to supply one. Instead, only the 4 methods that actually log
     * activity ({@see deleteSite()}, {@see deleteCategories()},
     * {@see moveCategories()}, {@see createVirtualCategory()}) take it as
     * an explicit parameter, matching this method's own shape.
     */
    private function imageService(ActivityLoggerInterface $activityLogger, SessionService $sessionService, EventDispatcher $eventDispatcher): ImageService
    {
        return new ImageService($this->lang, EntityManagerFactory::build(DbConnection::build())->getRepository(ImageEntity::class), $activityLogger, $sessionService, $eventDispatcher, $this->currentConfig, $this->translator, $this->paths());
    }

    /**
     * Same "avoid a 2nd touch of every manual `new CategoryService(...)`
     * call site" reasoning as this class's own other lazy container-resolve
     * helpers (userRepository() etc.) -- only imageService()'s own callers
     * need this collaborator.
     */
    private function paths(): Paths
    {
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }

    private function userRepository(): UserRepository
    {
        return new UserRepository(EntityManagerFactory::build(DbConnection::build()), $this->eventDispatcher, $this->currentConfig);
    }

    /**
     * Generic cross-domain sort comparator -- 12 real call sites across
     * Category/Ws/Admin/Controller/Picture pass wildly different row
     * shapes (category rows, picture rows, image rows, ...) that merely
     * happen to share a 'global_rank' key; only that one key is read, and
     * defensively (is_scalar()-checked), so $a/$b can't be narrowed to any
     * single domain's row shape without being wrong for the other 11.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareByGlobalRank(array $a, array $b): int
    {
        $aRank = $a['global_rank'];
        $bRank = $b['global_rank'];

        return strnatcasecmp(
            is_scalar($aRank) ? (string) $aRank : '',
            is_scalar($bRank) ? (string) $bRank : ''
        );
    }

    /**
     * Same generic cross-domain comparator rationale as
     * compareByGlobalRank() above -- only reads 'rank', defensively.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareByRank(array $a, array $b): int
    {
        $aRank = $a['rank'];
        $bRank = $b['rank'];

        return (is_numeric($aRank) ? (int) $aRank : 0) - (is_numeric($bRank) ? (int) $bRank : 0);
    }

    /**
     * PHP-side equivalent of the SQL fragment `$dbField >= LEAST(today -
     * $recentPeriod days, $lastPhotoDate - 1 day)`. CategoryCatsRenderer
     * applies this per already-cached tree row (CategoryTreeCache) instead
     * of building a SQL `WHERE`. $lastPhotoDate === null means nothing is
     * ever "recent" -- not merely half of the LEAST() comparison.
     */
    public static function isRecentCategory(?string $dateLast, int $recentPeriod, ?string $lastPhotoDate, DateTimeImmutable $now): bool
    {
        if ($lastPhotoDate === null || $lastPhotoDate === '' || $dateLast === null || $dateLast === '') {
            return false;
        }

        $thresholdFromToday = $now->setTime(0, 0, 0)
            ->modify('-' . $recentPeriod . ' days');
        $thresholdFromLastPhoto = new DateTimeImmutable($lastPhotoDate)
            ->modify('-1 day');
        $threshold = $thresholdFromToday < $thresholdFromLastPhoto ? $thresholdFromToday : $thresholdFromLastPhoto;

        return new DateTimeImmutable($dateLast) >= $threshold;
    }

    /**
     * Menu filter extracted as a pure function for testability,
     * independent of `$page`/`$user`/`$filter`/`$conf` globals. PHP-side
     * equivalent of a SQL `WHERE` filter (a structural `id_uppercat`
     * filter, or `PermissionService::getSqlConditionFandF()`'s
     * `visible_categories` condition), applied to `CategoryTreeCache`'s
     * cached, permission-filtered row set.
     *
     * @param array<int, array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int, user_id: mixed, nb_categories: int, count_categories: int, count_images: int, max_date_last: ?string, name: string, permalink: ?string, id: int}> $allRows keyed by category id,
     *   already permission-filtered (CategoryTreeCache::getForUser())
     * @param array<string, mixed>|null $categoryPage the currently-viewed
     *   category ($page['category']/SectionContext::$category), if any --
     *   only 'uppercats' is read here, defensively, matching that
     *   property's own already-declared array<string,mixed>|null type
     * @return array<int, array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int, user_id: mixed, nb_categories: int, count_categories: int, count_images: int, max_date_last: ?string, name: string, permalink: ?string, id: int}>
     */
    public static function filterMenuRows(
        array $allRows,
        ?array $categoryPage,
        bool $expand,
        bool $filterEnabled,
        string $visibleCategoriesCsv
    ): array {
        // Always expand when a filter is active -- matches the original
        // SQL's own branch condition exactly.
        if (! $expand && ! $filterEnabled) {
            $uppercatsRaw = $categoryPage['uppercats'] ?? null;
            $uppercatIds = $categoryPage !== null && is_scalar($uppercatsRaw) && $uppercatsRaw !== ''
                ? array_map(intval(...), explode(',', (string) $uppercatsRaw))
                : [];

            return array_filter(
                $allRows,
                static fn (array $row): bool => $row['id_uppercat'] === null
                    || in_array($row['id_uppercat'], $uppercatIds, true)
            );
        }

        if ($visibleCategoriesCsv === '') {
            // Matches getSqlConditionFandF()'s own fallthrough: no active
            // filter means "everything visible" (the original's `1 = 1`).
            return $allRows;
        }

        $visibleIds = array_map(intval(...), explode(',', $visibleCategoriesCsv));

        return array_filter(
            $allRows,
            static fn (array $row): bool => in_array($row['id'], $visibleIds, true)
        );
    }

    /**
     * @return array{id: int, name: string, id_uppercat: ?int, comment: ?string,
     *   dir: ?string, rank: ?int, status: string, site_id: ?int, visible: bool,
     *   representative_picture_id: ?int, uppercats: string, commentable: bool,
     *   global_rank: ?string, image_order: ?string, permalink: ?string, lastmodified: string,
     *   upper_names: list<array{id: int, name: string, permalink: ?string}>}|null
     */
    public function getCategoryInfo(int $id): ?array
    {
        $cat = $this->repo->findById($id);
        if ($cat === null) {
            return null;
        }

        // Docs/PLAN.md gap-closure, Stage 1b: commentable/
        // visible are real `bool` on {@see \Piwigo\Category\Projection\Category}
        // itself now -- the repository's own fromRow() does this cast once,
        // so the manual per-key loop that used to live here is retired
        // along with the retype (CategoryServiceTest::
        // test_get_category_info_coerces_true_false_string_columns_to_bool()
        // still covers this, against the projection now).
        $result = $cat->toArray();

        $upperIds = explode(',', $cat->uppercats);
        if (count($upperIds) === 1) {
            $result['upper_names'] = [
                [
                    'id' => $cat->id->value,
                    'name' => $cat->name,
                    'permalink' => $cat->permalink?->value,
                ],
            ];
        } else {
            $names = $this->repo->findNamesByIds(array_map(intval(...), $upperIds));

            $result['upper_names'] = [];
            foreach ($upperIds as $upperId) {
                $upperIdInt = (int) $upperId;
                if (isset($names[$upperIdInt])) {
                    $result['upper_names'][] = $names[$upperIdInt];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public function getPreferredImageOrders(): array
    {

        $orders = $this->eventDispatcher->dispatchChange(new GetCategoryPreferredImageOrders([
            [$this->lang->t('Default'), '', true],
            [$this->lang->t('Photo title, A &rarr; Z'), 'name ASC', true],
            [$this->lang->t('Photo title, Z &rarr; A'), 'name DESC', true],
            [$this->lang->t('Date created, new &rarr; old'), 'date_creation DESC', true],
            [$this->lang->t('Date created, old &rarr; new'), 'date_creation ASC', true],
            [$this->lang->t('Date posted, new &rarr; old'), 'date_available DESC', true],
            [$this->lang->t('Date posted, old &rarr; new'), 'date_available ASC', true],
            [$this->lang->t('Rating score, high &rarr; low'), 'rating_score DESC', $this->currentConfig->rateEnabled()],
            [$this->lang->t('Rating score, low &rarr; high'), 'rating_score ASC', $this->currentConfig->rateEnabled()],
            [$this->lang->t('Visits, high &rarr; low'), 'hit DESC', true],
            [$this->lang->t('Visits, low &rarr; high'), 'hit ASC', true],
            [$this->lang->t('Permissions'), 'level DESC', $this->accessLevelChecker->isAdmin()],
        ]))->orders;

        $result = [];
        foreach ($orders as $order) {
            if (! is_array($order) || ! isset($order[0], $order[1], $order[2]) || ! is_string($order[0]) || ! is_string($order[1])) {
                continue;
            }

            $visible = $order[2];
            $visible = is_scalar($visible) ? (bool) $visible : true;
            $result[] = [$order[0], $order[1], $visible];
        }

        return $result;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function getSubcategoryIds(array $ids): array
    {
        return $this->repo->findSubcategoryIds($ids);
    }

    /**
     * $oldPermalinkRepo is an explicit parameter, not constructor-injected
     * -- Category is L2aCoreDomain, its real implementation
     * (Permalink\PermalinkRepository) is L2bExtendedDomain, and
     * constructor-injecting it would just relocate the deptrac violation to
     * whichever of this method's own many call sites constructs
     * CategoryService (see OldPermalinkLookupInterface's own docblock).
     *
     * @param  list<string>  $permalinks
     */
    public function findCategoryIdFromPermalinks(array $permalinks, ?int &$idx, OldPermalinkLookupInterface $oldPermalinkRepo): ?int
    {
        $permaHash = $oldPermalinkRepo->findPermalinkMatches($permalinks);

        if ($permaHash === []) {
            return null;
        }

        for ($i = count($permalinks) - 1; $i >= 0; $i--) {
            if (! isset($permaHash[$permalinks[$i]])) {
                continue;
            }

            $idx = $i;
            $match = $permaHash[$permalinks[$i]];
            $catId = $match['id'];
            if ((bool) $match['is_old']) {
                $oldPermalinkRepo->touchOldPermalinkHit($permalinks[$i], $catId);
            }

            return $catId;
        }

        return null;
    }

    public static function getDisplayImagesCount(
        Lang $lang,
        int $catNbImages,
        int $catCountImages,
        int $catCountCategories,
        bool $shortMessage = true,
        string $separator = '\n'
    ): string {
        $displayText = '';

        if ($catCountImages > 0) {
            if ($catNbImages > 0 && $catNbImages < $catCountImages) {
                $displayText .= self::getDisplayImagesCount($lang, $catNbImages, $catNbImages, 0, $shortMessage, $separator) . $separator;
                $catCountImages -= $catNbImages;
                $catNbImages = 0;
            }

            $displayText .= $lang->plural('%d photo', '%d photos', $catCountImages);

            if ($catCountCategories === 0 || $catNbImages === $catCountImages) {
                if (! $shortMessage) {
                    $displayText .= ' ' . $lang->t('in this album');
                }
            } else {
                $displayText .= ' ' . $lang->plural('in %d sub-album', 'in %d sub-albums', $catCountCategories);
            }
        }

        return $displayText;
    }

    /**
     * Same cross-domain generic-row-reader rationale as
     * compareByGlobalRank() -- 4 real call sites across CategoryCatsRenderer
     * and Ws\PwgCategories pass differently-sourced category rows (tree-
     * cache rows, WS param arrays); only id/uppercats/count_images are
     * read, defensively.
     *
     * @param  array<string, mixed>  $category  (at least id, uppercats, count_images)
     */
    public function getRandomImageInCategory(array $category, bool $recursive = true): ?int
    {
        $countImages = $category['count_images'] ?? null;
        if (! is_numeric($countImages) || (int) $countImages <= 0) {
            return null;
        }

        $catId = $category['id'];
        $catId = is_numeric($catId) ? (int) $catId : null;
        if ($catId === null) {
            return null;
        }
        $uppercats = $category['uppercats'];
        $uppercats = is_string($uppercats) ? $uppercats : '';

        return $this->repo->findRandomImageId($catId, $uppercats, $recursive, $this->permissionService->getPermissionCriteria());
    }

    /**
     * @param  array<string, mixed>  $userdata
     * @return array{categories: array<int, array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int, user_id: mixed, nb_categories: int, count_categories: int, count_images: int, max_date_last: ?string}>, lastPhotoDate: ?string}
     */
    public function getComputedCategories(array $userdata, ?int $filterDays = null): array
    {
        $level = $userdata['level'];
        $level = is_numeric($level) ? (int) $level : 0;

        $forbiddenCategories = $userdata['forbidden_categories'];
        $forbiddenCategoriesCsv = is_string($forbiddenCategories) ? $forbiddenCategories : '';

        $rows = $this->repo->findComputedCategoriesRollup($level, $filterDays, $forbiddenCategoriesCsv);

        $lastPhotoDate = null;
        $cats = [];
        foreach ($rows as $row) {
            $catId = $row['cat_id'];
            $idUppercat = $row['id_uppercat'];
            $nbImages = $row['nb_images'];
            $dateLast = $row['date_last'];

            $row['cat_id'] = $catId;
            $row['id_uppercat'] = $idUppercat;
            $row['nb_images'] = $nbImages;
            $row['user_id'] = $userdata['id'];
            $row['nb_categories'] = 0;
            $row['count_categories'] = 0;
            $row['count_images'] = $nbImages;
            $row['max_date_last'] = $dateLast;
            if ($dateLast !== null && ($lastPhotoDate === null || $dateLast > $lastPhotoDate)) {
                $lastPhotoDate = $dateLast;
            }

            $cats[$catId] = $row;
        }

        uasort($cats, self::compareByGlobalRank(...));

        foreach ($cats as $cat) {
            $idUppercat = $cat['id_uppercat'];
            if (! is_int($idUppercat)) {
                continue;
            }

            if (! isset($cats[$idUppercat])) {
                continue;
            }

            $parent = &$cats[$idUppercat];
            $parent['nb_categories']++;

            $nbImages = $cat['nb_images'];

            do {
                $parent['count_images'] += $nbImages;
                $parent['count_categories']++;

                $parentMaxDateLast = $parent['max_date_last'];
                if ($parentMaxDateLast === null || $parentMaxDateLast === '' || $parentMaxDateLast < $cat['date_last']) {
                    $parent['max_date_last'] = $cat['date_last'];
                }

                $parentIdUppercat = $parent['id_uppercat'];
                if (! is_int($parentIdUppercat)) {
                    break;
                }

                $parent = &$cats[$parentIdUppercat];
            } while (true);
            unset($parent);
        }

        if ($filterDays !== null) {
            foreach ($cats as $category) {
                $categoryMaxDateLast = $category['max_date_last'];
                if ($categoryMaxDateLast === null || $categoryMaxDateLast === '') {
                    self::removeComputedCategory($cats, $category);
                }
            }
        }

        return [
            'categories' => $cats,
            'lastPhotoDate' => $lastPhotoDate,
        ];
    }

    /**
     * @param  array<int, array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int, user_id: mixed, nb_categories: int, count_categories: int, count_images: int, max_date_last: ?string}>  $cats
     * @param  array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int, user_id: mixed, nb_categories: int, count_categories: int, count_images: int, max_date_last: ?string}  $cat  category to remove
     */
    public static function removeComputedCategory(array &$cats, array $cat): void
    {
        $idUppercat = $cat['id_uppercat'];
        if ($idUppercat !== null && isset($cats[$idUppercat])) {
            $parent = &$cats[$idUppercat];

            $parent['nb_categories']--;

            do {
                $parent['count_images'] -= $cat['nb_images'];
                $parent['count_categories'] -= 1 + $cat['count_categories'];

                $parentIdUppercat = $parent['id_uppercat'];
                if ($parentIdUppercat === null || ! isset($cats[$parentIdUppercat])) {
                    break;
                }

                $parent = &$cats[$parentIdUppercat];
            } while (true);
        }

        unset($cats[$cat['cat_id']]);
    }

    /**
     * @param  list<int>  $catIds
     * @return list<int>
     */
    public function getImageIdsForCategories(
        array $catIds,
        string $mode = 'AND',
        bool $usePermissions = true
    ): array {

        if ($catIds === []) {
            return [];
        }

        $criteria = $usePermissions
            ? $this->permissionService->getPermissionCriteria()
            : new PermissionCriteria(null, null, null, null, null, null);

        return $this->repo->findImageIdsForCategories($catIds, $mode, $criteria);
    }

    /**
     * @param  list<int>  $items
     * @param  list<int>  $excludedCatIds
     * @return array<int, array{id: int, uppercats: string, counter: int}>
     */
    public function getCommonCategories(array $items, ?int $max = null, array $excludedCatIds = [], bool $usePermissions = true): array
    {
        if ($items === []) {
            return [];
        }

        $criteria = $usePermissions
            ? $this->permissionService->getPermissionCriteria()
            : new PermissionCriteria(null, null, null, null, null, null);

        return $this->repo->findCommonCategories($items, $max, $excludedCatIds, $criteria);
    }

    /**
     * Common-categories tree for a list of items, WITHOUT the page-URL
     * decoration (`url` key) -- {@see getRelatedCategoriesMenuWithUrls()}
     * adds that afterward, same split the former free-function wrapper
     * `get_related_categories_menu()` (functions_category.inc.php, deleted)
     * used to have.
     *
     * @param  list<int>  $items
     * @param  list<int>  $excludedCatIds
     * @return list<array<string, mixed>>
     */
    public function getRelatedCategoriesMenu(array $items, array $excludedCatIds = []): array
    {

        $relatedAlbumsDisplayLimit = $this->currentConfig->relatedAlbumsDisplayLimit();
        $relatedAlbumsDisplayLimitInt = $relatedAlbumsDisplayLimit;

        $commonCats = $this->getCommonCategories($items, $relatedAlbumsDisplayLimitInt, $excludedCatIds);

        if ($commonCats === []) {
            return [];
        }

        $catIds = [];
        foreach ($commonCats as $cat) {
            foreach (explode(',', $cat['uppercats']) as $uppercat) {
                $catIds[$uppercat] = ($catIds[$uppercat] ?? 0) + 1;
            }
        }

        $cats = $this->repo->findCategoriesByIds(array_map(intval(...), array_keys($catIds)));
        usort($cats, self::compareByGlobalRank(...));

        $indexOfCat = [];

        foreach ($cats as $idx => $cat) {
            $catId = $cat['id'];
            $indexOfCat[$catId] = $idx;

            $globalRank = $cat['global_rank'];
            $cats[$idx]['LEVEL'] = substr_count(is_string($globalRank) ? $globalRank : '', '.') + 1;
            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName($cat['name'], $cat));
            $cats[$idx]['name'] = $nameEvent->categoryName;

            if (isset($commonCats[$catId])) {
                $cats[$idx]['count_images'] = $commonCats[$catId]['counter'];
            }

            $idUppercat = $cat['id_uppercat'];
            $hasIdUppercat = $idUppercat !== null && $idUppercat !== 0;
            $countImages = $cats[$idx]['count_images'] ?? 0;
            if ($hasIdUppercat && $countImages > 0) {
                foreach (array_slice(explode(',', $cat['uppercats']), 0, -1) as $uppercatId) {
                    $parentIdx = $indexOfCat[$uppercatId] ?? null;
                    if (! is_int($parentIdx)) {
                        continue;
                    }

                    $countCategories = $cats[$parentIdx]['count_categories'] ?? null;
                    $cats[$parentIdx]['count_categories'] = (is_numeric($countCategories) ? $countCategories : 0) + 1;
                }
            }
        }

        return $cats;
    }

    /**
     * Is the category accessible to the connected user? If the user is not
     * authorized to see this category, the script exits.
     */
    public function checkRestrictions(int $categoryId, HtmlRenderingInterface $htmlRenderer, RedirectServiceInterface $redirectService, CurrentUser $currentUser): void
    {
        // $filter['visible_categories'] and $filter['visible_images']
        // are not used because it's not necessary (filter <> restriction)
        $forbiddenCategoriesStr = $currentUser->get()
            ->forbiddenCategories;
        if (in_array((string) $categoryId, explode(',', $forbiddenCategoriesStr), true)) {
            $htmlRenderer->accessDenied($redirectService);
        }
    }

    /**
     * Returns all subcategory identifiers of given category ids.
     *
     * @param array<int|string> $ids several callers (comments.php, admin/rating.php)
     *   wrap a raw, unvalidated $_GET value directly — the is_numeric() check
     *   below is a real guard, not dead code
     * @return list<int> array_values() below always reindexes the result
     */
    public function getSubcatIds(array $ids): array
    {
        // Non-numeric values are only warned about, never embedded into the
        // query -- CategoryRepository::findSubcategoryIds() binds each id as a
        // parameter, so a non-numeric value can never reach raw SQL.
        $validatedIds = [];
        foreach ($ids as $categoryId) {
            if (is_numeric($categoryId)) {
                $validatedIds[] = (int) $categoryId;
                continue;
            }

            trigger_error(
                'getSubcatIds expecting numeric, not ' . gettype($categoryId),
                E_USER_WARNING
            );
        }

        return $this->repo->findSubcategoryIds($validatedIds);
    }

    /**
     * Returns template vars for main categories menu.
     *
     * `FilterUpdaterInterface` is an explicit parameter here, not a
     * constructor dependency, for the same reason as
     * `ActivityLoggerInterface` above (~45 real construction sites, the
     * vast majority pure-read and never touching filtered-category data;
     * this is the one method that does).
     *
     * $category is an explicit param, not a global read. Once the
     * matching menu row is located, its `count_categories` value comes
     * back through the `categoryCountCategories` return field rather
     * than being written back into caller state.
     *
     * 'menu' rows extend CategoryTreeCache::getForUser()'s own row shape
     * with template-display fields (NAME/TITLE/URL/LEVEL/SELECTED/
     * IS_UPPERCAT/icon_ts) built inside this method's own loop; NAME is
     * passed through dispatchChange(new RenderCategoryName(...)).
     *
     * @param array<string, mixed>|null $category
     * @return array{menu: array<int, array<string, mixed>>, categoryCountCategories: ?int}
     */
    public function getCategoriesMenu(?array $category, FilterUpdaterInterface $filterUpdater, UrlServiceInterface $urlService, FilterState $filterState, CurrentUser $currentUser, Lang $lang): array
    {
        $user = $currentUser->get();

        $categoryPage = $category;
        $countCategories = null;

        // findMenuCategories()'s SQL WHERE (structural id_uppercat filter, or
        // PermissionService::getSqlConditionFandF()'s visible_categories
        // condition) is expressed here as an equivalent PHP-side filter
        // applied to CategoryTreeCache's cached, permission-filtered row set
        // -- see that class's own docblock for why this can't be pushed down
        // to SQL (it doesn't read from a DB-backed cache table). No
        // get_categories_menu_sql_where trigger_change() handler exists
        // anywhere in this repo, so there is no PHP-filter equivalent for it.
        $allRows = new CategoryTreeCache(
            $this,
            $this->repo,
            CachePools::categoryTree()
        )->getForUser($user->rawAttributes);

        $rows = self::filterMenuRows(
            $allRows,
            $categoryPage,
            (bool) $user->rawAttributes['expand'],
            $filterState->isEnabled(),
            $filterState->visibleCategories()
        );

        $cats = [];
        $selectedCategory = $categoryPage;
        foreach ($rows as $row) {
            // both sides get coerced to string for comparison: $row['id'] is
            // always a DB-fetch string, but $page['category']['id'] may already
            // be an int depending on how that array was populated -- matches
            // the original's loose ==, which PHPStan disallows outright.
            $rowIdStr = (string) $row['id'];
            $rowGlobalRank = $row['global_rank'];
            $childDateLast = @$row['max_date_last'] > @$row['date_last'];
            $selectedId = $selectedCategory['id'] ?? null;
            $selectedIdStr = is_scalar($selectedId) ? (string) $selectedId : null;
            $selectedIdUppercat = $selectedCategory['id_uppercat'] ?? null;
            $selectedIdUppercatStr = is_scalar($selectedIdUppercat) ? (string) $selectedIdUppercat : null;
            $menuNameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName($row['name'], 'get_categories_menu'));
            $row = array_merge(
                $row,
                [
                    'NAME' => $menuNameEvent->categoryName,
                    'TITLE' => self::getDisplayImagesCount(
                        $lang,
                        $row['nb_images'],
                        $row['count_images'],
                        $row['count_categories'],
                        false,
                        ' / '
                    ),
                    'URL' => $urlService->makeIndexUrl([
                        'category' => $row,
                    ]),
                    'LEVEL' => substr_count(is_string($rowGlobalRank) ? $rowGlobalRank : '', '.') + 1,
                    'SELECTED' => $selectedCategory !== null && $selectedIdStr !== null && $selectedIdStr === $rowIdStr,
                    'IS_UPPERCAT' => $selectedCategory !== null && $selectedIdUppercatStr !== null && $selectedIdUppercatStr === $rowIdStr,
                ]
            );
            if ($this->currentConfig->indexNewIcon()) {
                $maxDateLast = $row['max_date_last'];
                $recentPeriodForIcon = is_numeric($user->rawAttributes['recent_period'] ?? null) ? (int) $user->rawAttributes['recent_period'] : 0;
                $row['icon_ts'] = RecentIconResolver::getIcon(is_string($maxDateLast) ? $maxDateLast : '', $recentPeriodForIcon, $this->processCache(), $this->lang, $childDateLast);
            }
            $cats[] = $row;
            $categoryPageId = $categoryPage['id'] ?? null;
            $categoryPageIdStr = is_scalar($categoryPageId) ? (string) $categoryPageId : null;
            if ($categoryPage !== null && $categoryPageIdStr !== null && $categoryPageIdStr === $rowIdStr) { // save the number of subcats for later optim
                $countCategories = $row['count_categories'];
            }
        }
        usort($cats, self::compareByGlobalRank(...));

        // Update filtered data
        $filterUpdater->updateCatsWithFilteredData($cats);

        return [
            'menu' => $cats,
            'categoryCountCategories' => $countCategories,
        ];
    }

    /**
     * Assign a template var useable with {html_options} from a list of
     * categories.
     *
     * Same cross-domain generic-row-reader rationale as
     * compareByGlobalRank() for $categories; $selecteds is passed straight
     * to Template::assign(), matching that method's own by-design
     * arbitrary-value contract.
     *
     * @param array<int, array<string, mixed>> $categories (at least id,name,global_rank,uppercats for each)
     * @param array<int, mixed> $selecteds
     * @param string $blockname variable name in template
     * @param bool $fullname full breadcrumb or not
     */
    public function displaySelectCategories(
        array $categories,
        array $selecteds,
        string $blockname,
        HtmlRenderingInterface $htmlRenderer,
        TemplateInterface $template,
        bool $fullname = true
    ): void {
        $tplCats = [];
        foreach ($categories as $category) {
            if ($fullname) {
                $uppercats = $category['uppercats'];
                $option = strip_tags(
                    $htmlRenderer->getCatDisplayNameCache(
                        is_string($uppercats) ? $uppercats : '',
                        null
                    )
                );
            } else {
                $globalRank = $category['global_rank'];
                $option = str_repeat(
                    '&nbsp;',
                    (3 * substr_count(is_string($globalRank) ? $globalRank : '', '.'))
                );
                $option .= '- ';
                $selectNameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName(is_string($category['name']) ? $category['name'] : '', 'display_select_categories'));
                $option .= strip_tags($selectNameEvent->categoryName);
            }
            $id = $category['id'];
            if (is_int($id) || is_string($id)) {
                $tplCats[$id] = $option;
            }
        }

        $template->assign($blockname, $tplCats);
        $template->assign($blockname . '_selected', $selecteds);
    }

    /**
     * Further SQL-modernization audit, Item 9: displaySelectCatWrapper()
     * (a caller-built-query wrapper around the now-deleted
     * CategoryRepository::fetchCallerBuiltQuery()) replaced with one
     * typed display method per real query shape below, each just
     * fetching its own typed rows then sharing this same sort+display
     * tail -- same as displaySelectCategories() but categories are
     * ordered by rank first.
     *
     * @param  list<array<string, mixed>>  $categories
     * @param  array<int, mixed>  $selecteds
     */
    private function sortAndDisplaySelectCategories(
        array $categories,
        array $selecteds,
        string $blockname,
        HtmlRenderingInterface $htmlRenderer,
        TemplateInterface $template,
        bool $fullname = true
    ): void {
        usort($categories, self::compareByGlobalRank(...));
        $this->displaySelectCategories($categories, $selecteds, $blockname, $htmlRenderer, $template, $fullname);
    }

    /**
     * Admin\CatOptionsPageRenderer's own "commentable" toggle section.
     */
    public function displaySelectByCommentable(bool $commentable, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findByCommentable($commentable), [], $blockname, $htmlRenderer, $template);
    }

    /**
     * Admin\CatOptionsPageRenderer's own "visible" toggle section.
     */
    public function displaySelectByVisible(bool $visible, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findByVisible($visible), [], $blockname, $htmlRenderer, $template);
    }

    /**
     * Admin\CatOptionsPageRenderer's own "status" (public/private) toggle
     * section.
     */
    public function displaySelectByStatus(string $status, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findByStatus($status), [], $blockname, $htmlRenderer, $template);
    }

    /**
     * Admin\CatOptionsPageRenderer's own "representative" toggle section.
     */
    public function displaySelectByRepresentativePresence(bool $hasRepresentative, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findByRepresentativePresence($hasRepresentative), [], $blockname, $htmlRenderer, $template);
    }

    /**
     * Admin\UserPermPageRenderer's own "category options: authorized" list.
     *
     * @param  list<string>  $groupAuthorizedCatIds
     */
    public function displaySelectPrivateGrantedToUser(int $userId, array $groupAuthorizedCatIds, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findPrivateCategoriesGrantedToUser($userId, $groupAuthorizedCatIds), [], $blockname, $htmlRenderer, $template);
    }

    /**
     * Admin\GroupPermPageRenderer's own "category options: authorized" list.
     */
    public function displaySelectPrivateGrantedToGroup(int $groupId, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findPrivateCategoriesGrantedToGroup($groupId), [], $blockname, $htmlRenderer, $template);
    }

    /**
     * Admin\UserPermPageRenderer/GroupPermPageRenderer's own "category
     * options: not yet authorized" list.
     *
     * @param  list<string>  $excludeCatIds
     */
    public function displaySelectPrivateExcluding(array $excludeCatIds, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findPrivateCategoriesExcluding($excludeCatIds), [], $blockname, $htmlRenderer, $template);
    }

    /**
     * Controller\CommentsController's own "search by album" category list.
     *
     * @param  array<int, mixed>  $selecteds
     */
    public function displaySelectByCondition(PermissionCriteria $criteria, array $selecteds, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findIdNameUppercatsRank($criteria), $selecteds, $blockname, $htmlRenderer, $template);
    }

    /**
     * Controller\Admin\PermalinksSubController's own category list.
     *
     * @param  array<int, mixed>  $selecteds
     */
    public function displaySelectForPermalinks(array $selecteds, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findAllForPermalinksDisplay(), $selecteds, $blockname, $htmlRenderer, $template, false);
    }

    /**
     * Controller\Admin\SiteUpdateSubController's own per-site category list.
     *
     * @param  array<int, mixed>  $selecteds
     */
    public function displaySelectBySite(int $siteId, array $selecteds, string $blockname, HtmlRenderingInterface $htmlRenderer, TemplateInterface $template): void
    {
        $this->sortAndDisplaySelectCategories($this->repo->findIdNameUppercatsRankBySite($siteId), $selecteds, $blockname, $htmlRenderer, $template, false);
    }

    /**
     * Same as getRelatedCategoriesMenu(), plus page-URL decoration (`url`
     * key) built via UrlService.
     *
     * NOTE: 'combined_categories' below carries $cat AFTER
     * getRelatedCategoriesMenu()'s own RenderCategoryName
     * dispatchChange() already ran on 'name', so UrlService::makeIndexUrl()'s
     * id-name style would embed the *rendered* name instead of the raw one
     * if a RenderCategoryName handler is ever registered (none are today --
     * PEM extensions are unwired, and RenderCategoryName is currently
     * `readonly` (no core handler mutates it either) -- so this is
     * currently a no-op difference). Re-verify if a RenderCategoryName
     * handler is ever registered.
     *
     * $category/$combinedCategories are SectionContext::$category-shaped
     * (only used wholesale as UrlService params here, never read by key);
     * the return rows inherit getRelatedCategoriesMenu()'s own 'name'
     * field, mixed via EventDispatcher::triggerChange().
     *
     * @param  array<int, int|string>  $items
     * @param  array<int, int|string>  $excludedCatIds
     * @param  array<string, mixed>|null  $category
     * @param  list<array<string, mixed>>|null  $combinedCategories
     * @return list<array<string, mixed>>
     */
    public function getRelatedCategoriesMenuWithUrls(array $items, UrlServiceInterface $urlService, array $excludedCatIds = [], ?array $category = null, ?array $combinedCategories = null): array
    {
        $cats = $this->getRelatedCategoriesMenu(
            array_values(array_map(intval(...), $items)),
            array_values(array_map(intval(...), $excludedCatIds))
        );

        foreach ($cats as $idx => $cat) {
            if (! isset($cat['count_images'])) {
                continue;
            }

            $urlParams = [];
            if ($category !== null) {
                $urlParams['category'] = $category;

                $urlParams['combined_categories'] = [$cat];
                if ($combinedCategories !== null) {
                    $urlParams['combined_categories'] = array_merge($combinedCategories, [$cat]);
                }
            } else {
                $urlParams['category'] = $cat;
            }

            $cats[$idx]['url'] = $urlService->makeIndexUrl($urlParams);
        }

        return $cats;
    }

    /**
     * Deletes a site and its primary categories.
     *
     * Item 16E: the site's own `sites` row is deleted by a real listener
     * on {@see DeleteSite}, registered in {@see \Piwigo\Bootstrap\RequestBootstrap}
     * -- `Category` (`L2aCoreDomain`) can't depend on `Site`
     * (`L2bExtendedDomain`) directly (`deptrac.yaml` only allows downward
     * dependencies), and the site's own categories must already be gone
     * first regardless, so this stays synchronous rather than a fire-
     * and-forget notification.
     */
    public function deleteSite(int $id, ActivityLoggerInterface $activityLogger, UrlServiceInterface $urlService, SessionService $sessionService, EventDispatcher $eventDispatcher, OldPermalinkLookupInterface $oldPermalinkRepo): void
    {
        $categoryIds = $this->repo->findCategoryIdsBySite($id);
        $this->deleteCategories($categoryIds, $activityLogger, $urlService, $sessionService, $eventDispatcher, oldPermalinkRepo: $oldPermalinkRepo);

        $this->eventDispatcher->dispatchNotify(new DeleteSite($id));
    }

    /**
     * Recursively deletes one or more categories.
     * It also deletes:
     *    - all the elements physically linked to the category (with ImageService::deleteElements())
     *    - all the links between elements and this category
     *    - all the restrictions linked to the category
     *
     * $oldPermalinkRepo is an explicit parameter, not constructor-injected
     * -- same reasoning as findCategoryIdFromPermalinks()'s own docblock.
     *
     * @param array<int, int> $ids
     * @param string $photoDeletionMode
     *    - no_delete: delete no photo, may create orphans
     *    - delete_orphans: delete photos that are no longer linked to any category
     *    - force_delete: delete photos even if they are linked to another category
     */
    public function deleteCategories(array $ids, ActivityLoggerInterface $activityLogger, UrlServiceInterface $urlService, SessionService $sessionService, EventDispatcher $eventDispatcher, OldPermalinkLookupInterface $oldPermalinkRepo, string $photoDeletionMode = 'no_delete'): void
    {
        if (count($ids) === 0) {
            return;
        }

        // add sub-category ids to the given ids : if a category is deleted, all
        // sub-categories must be so
        $ids = $this->getSubcatIds($ids);

        $imageService = $this->imageService($activityLogger, $sessionService, $eventDispatcher);

        // destruction of all photos physically linked to the category
        $elementIds = $this->repo->findStorageLinkedImageIds($ids);
        $imageService->deleteElements($elementIds, $urlService);

        // now, should we delete photos that are virtually linked to the category?
        if ($photoDeletionMode === 'delete_orphans' || $photoDeletionMode === 'force_delete') {
            $imageIdsLinked = $this->repo->findDistinctLinkedImageIds($ids);

            if (count($imageIdsLinked) > 0) {
                if ($photoDeletionMode === 'delete_orphans') {
                    $imageIdsNotOrphans = $this->repo->findNonOrphanImageIds($imageIdsLinked, $ids);
                    $imageIdsToDelete = array_diff($imageIdsLinked, $imageIdsNotOrphans);
                } else {
                    $imageIdsToDelete = $imageIdsLinked;
                }

                $imageService->deleteElements(array_map(intval(...), $imageIdsToDelete), $urlService, true);
            }
        }

        // destruction of the links between images and this category
        $this->repo->deleteImageCategoryLinksForCategories($ids);

        // destruction of the access linked to the category
        $this->repo->deleteUserAccessForCategories($ids);
        $this->repo->deleteGroupAccessForCategories($ids);

        // destruction of the category
        $this->repo->deleteCategoriesByIds($ids);

        $oldPermalinkRepo->deleteOldPermalinksForCategories($ids);

        $eventDispatcher->dispatchNotify(new DeleteCategories($ids));
        $activityLogger->record('album', $ids, 'delete', [
            'photo_deletion_mode' => $photoDeletionMode,
        ]);
    }

    /**
     * Verifies that the representative picture really exists in the db and
     * picks up a random representative if possible and based on config.
     *
     * @param 'all'|int|array<int|string> $ids ws_functions/pwg.images.php passes
     *   preg_match()-validated but never int-cast category id strings; $ids only
     *   ever flows into implode()/SQL contexts below, so numeric strings work
     *   identically
     */
    public function updateCategory(array|int|string $ids = 'all'): ?false
    {

        if ($ids === 'all') {
            $whereCats = '1=1';
            $whereCatsParams = [];
            $whereCatsTypes = [];
        } elseif (! is_array($ids)) {
            $whereCats = '%s = :catId';
            $whereCatsParams = [
                'catId' => $ids,
            ];
            $whereCatsTypes = [];
        } else {
            if (count($ids) === 0) {
                return false;
            }
            $whereCats = '%s IN (:catIds)';
            $whereCatsParams = [
                'catIds' => array_map(intval(...), array_values($ids)),
            ];
            $whereCatsTypes = [
                'catIds' => ArrayParameterType::INTEGER,
            ];
        }

        // find all categories where the setted representative is not possible :
        // the picture does not exist
        $wrongRepresentant = $this->repo->findWrongRepresentativeCategoryIds(sprintf($whereCats, 'c.id'), $whereCatsParams, $whereCatsTypes);

        if (count($wrongRepresentant) > 0) {
            $this->repo->clearRepresentativePictureIds($wrongRepresentant);
        }

        if (! $this->currentConfig->allowRandomRepresentative()) {
            // If the random representant is not allowed, we need to find
            // categories with elements and with no representant. Those categories
            // must be added to the list of categories to set to a random
            // representant.
            $toRand = $this->repo->findCategoriesNeedingRandomRepresentative(sprintf($whereCats, 'category_id'), $whereCatsParams, $whereCatsTypes);
            if (count($toRand) > 0) {
                $this->setRandomRepresentant($toRand);
            }
        }

        return null;
    }

    /**
     * Checks and repairs integrity on categories.
     * Removes all entries from related tables which correspond to a deleted category.
     */
    public function checkCategoriesIntegrity(): void
    {
        $relatedTargets = [
            CategoryOrphanTarget::ImageCategory,
            CategoryOrphanTarget::UserAccess,
            CategoryOrphanTarget::GroupAccess,
            CategoryOrphanTarget::OldPermalinks,
        ];

        foreach ($relatedTargets as $target) {
            $orphans = $this->repo->findOrphanedColumnValues($target);

            if (count($orphans) > 0) {
                $this->repo->deleteRowsWhereColumnIn($target, $orphans);
            }
        }
    }

    /**
     * save the rank depending on given categories order
     *
     * The list of ordered categories id is supposed to be in the same parent
     * category
     *
     * $categories is raw request input (Admin\AlbumsPageRenderer's $_POST-
     * derived array, Ws\PwgCategories' $order_new WS param) -- already
     * defensively is_array()/is_int()/is_string()-checked per element; a
     * real validating shape belongs to a dedicated Request DTO, not a
     * retroactive narrow here.
     *
     * @param array<int, mixed> $categories
     */
    public function saveCategoriesOrder(array $categories): void
    {
        $currentRankForIdUppercat = [];
        $currentRank = 0;

        $datas = [];
        foreach ($categories as $category) {
            if (is_array($category)) {
                $id = $category['id'];
                $idUppercat = $category['id_uppercat'];
                if (! is_int($idUppercat) && ! is_string($idUppercat)) {
                    // id_uppercat is null (or otherwise non-scalar) for top-level
                    // categories; bucket them together like the '' sentinel used
                    // for $currentUppercat in updateGlobalRank() below.
                    $idUppercat = '';
                }

                if (! isset($currentRankForIdUppercat[$idUppercat])) {
                    $currentRankForIdUppercat[$idUppercat] = 0;
                }
                $currentRank = ++$currentRankForIdUppercat[$idUppercat];
            } else {
                $id = $category;
                $currentRank++;
            }

            $datas[] = [
                'id' => $id,
                'rank' => $currentRank,
            ];
        }
        $this->repo->massUpdateRanks($datas);

        $this->updateGlobalRank();
    }

    /**
     * Orders categories (update categories.rank and global_rank database fields)
     * so that rank field are consecutive integers starting at 1 for each child.
     */
    public function updateGlobalRank(): int
    {
        $rows = $this->repo->findCategoriesForRankUpdate();

        $catMap = [];
        $currentRank = 0;
        $currentUppercat = null;

        foreach ($rows as $row) {
            $rowIdUppercat = is_scalar($row['id_uppercat']) ? (string) $row['id_uppercat'] : null;
            if ($rowIdUppercat !== $currentUppercat) {
                $currentRank = 0;
                $currentUppercat = $rowIdUppercat;
            }
            ++$currentRank;

            $rowId = $row['id'];
            $rowUppercats = $row['uppercats'];
            $rowRank = $row['rank'];
            // rank is a NOT NULL column in the categories table.
            assert(is_int($rowRank));

            $cat =
              [
                  'rank' => $currentRank,
                  'rank_changed' => $currentRank !== $rowRank,
                  'global_rank' => $row['global_rank'],
                  'uppercats' => $rowUppercats,
              ];
            $catMap[$rowId] = $cat;
        }

        $datas = [];

        $catMapCallback = function (array $m) use ($catMap): string {
            $matchedId = $m[1] ?? null;
            if (! is_string($matchedId) || ! isset($catMap[$matchedId])) {
                return '';
            }
            return (string) $catMap[$matchedId]['rank'];
        };

        foreach ($catMap as $id => $cat) {
            $newGlobalRank = preg_replace_callback(
                '/(\d+)/',
                $catMapCallback,
                str_replace(',', '.', $cat['uppercats'])
            );

            if ($cat['rank_changed'] || $newGlobalRank !== $cat['global_rank']) {
                $datas[] = [
                    'id' => $id,
                    'rank' => $cat['rank'],
                    'global_rank' => $newGlobalRank,
                ];
            }
        }

        $this->repo->massUpdateRanksAndGlobalRank($datas);

        return count($datas);
    }

    /**
     * Change the **visible** property on a set of categories.
     *
     * @param int[] $categories
     */
    public function setCatVisible(array $categories, bool|string $value, bool $unlockChild = false): ?false
    {
        $filteredValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($filteredValue === null) {
            trigger_error("setCatVisible invalid param {$value}", E_USER_WARNING);
            return false;
        }

        $value = $filteredValue;

        // unlocking a category => all its parent categories become unlocked
        if ($value) {
            $cats = $this->getUppercatIds($categories);
            if ($unlockChild) {
                $cats = array_merge($cats, $this->getSubcatIds($categories));
            }
            $this->repo->updateCategoryVisibility($cats, true);
        } else {
            // locking a category => all its child categories become locked
            $subcats = $this->getSubcatIds($categories);
            $this->repo->updateCategoryVisibility($subcats, false);
        }

        return null;
    }

    /**
     * Change the **commentable** property on a set of categories. Unlike
     * {@see setCatVisible()}, this has no parent/child cascade of its own
     * -- callers wanting to also apply the change to sub-albums pass their
     * own already-expanded id list (e.g. Ws\PwgCategories::setInfo()'s
     * `apply_commentable_to_subalbums`). Same `bool|string` acceptance as
     * setCatVisible() -- Ws\PwgCategories::setInfo()'s own $params still
     * carry the WS API's 'true'/'false' string wire format.
     *
     * @param int[] $categories
     */
    public function setCatCommentable(array $categories, bool|string $value): void
    {
        $this->repo->updateCategoryCommentable($categories, filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateFields(CategoryId $categoryId, array $data): void
    {
        $this->repo->updateFields($categoryId, $data);
    }

    /**
     * @param string[] $dbfields
     * @param array<int, array<string, mixed>> $inserts
     */
    public function massInsertCategories(array $dbfields, array $inserts): void
    {
        $this->repo->massInsertCategories($dbfields, $inserts);
    }

    /**
     * @param array<int, array{group_id: int, cat_id: int}> $inserts
     */
    public function massInsertGroupAccess(array $inserts, bool $ignore = false): void
    {
        $this->repo->massInsertGroupAccess($inserts, $ignore);
    }

    /**
     * Clears the representative picture of a set of categories (they fall
     * back to a random/none representant per
     * CurrentConfig::representativeCacheOnLevel() the next time one is needed).
     *
     * @param int[] $categories
     */
    public function clearRepresentativePictures(array $categories): void
    {
        $this->repo->clearRepresentativePictureIds(array_values($categories));
    }

    /**
     * Change the **status** property on a set of categories : private or public.
     *
     * @param int[] $categories
     */
    public function setCatStatus(array $categories, string $value): ?false
    {
        if (! in_array($value, [CategoryStatus::Public->value, CategoryStatus::Private->value], true)) {
            trigger_error("setCatStatus invalid param {$value}", E_USER_WARNING);
            return false;
        }

        // make public a category => all its parent categories become public
        if ($value === CategoryStatus::Public->value) {
            $uppercats = $this->getUppercatIds($categories);
            $this->repo->updateCategoryStatus($uppercats, CategoryStatus::Public->value);
        }

        // make a category private => all its child categories become private
        if ($value === CategoryStatus::Private->value) {
            $subcats = $this->getSubcatIds($categories);
            $this->repo->updateCategoryStatus($subcats, CategoryStatus::Private->value);

            // We have to keep permissions consistant: a sub-album can't be
            // permitted to a user or group if its parent album is not permitted to
            // the same user or group. Let's remove all permissions on sub-albums if
            // it is not consistant. Let's take the following example:
            //
            // A1        permitted to U1,G1
            // A1/A2     permitted to U1,U2,G1,G2
            // A1/A2/A3  permitted to U3,G1
            // A1/A2/A4  permitted to U2
            // A1/A5     permitted to U4
            // A6        permitted to U4
            // A6/A7     permitted to G1
            //
            // (we consider that it can be possible to start with inconsistant
            // permission, given that public albums can have hidden permissions,
            // revealed once the album returns to private status)
            //
            // The admin selects A2,A3,A4,A5,A6,A7 to become private (all but A1,
            // which is private, which can be true if we're moving A2 into A1). The
            // result must be:
            //
            // A2 permission removed to U2,G2
            // A3 permission removed to U3
            // A4 permission removed to U2
            // A5 permission removed to U2
            // A6 permission removed to U4
            // A7 no permission removed
            //
            // 1) we must extract "top albums": A2, A5 and A6
            // 2) for each top album, decide which album is the reference for permissions
            // 3) remove all inconsistant permissions from sub-albums of each top-album

            // step 1, search top albums
            $topCategories = [];
            $parentIds = [];

            $allCategories = $this->repo->findCategoriesByIds(array_values(array_map(intval(...), $categories)));
            usort($allCategories, self::compareByGlobalRank(...));

            foreach ($allCategories as $cat) {
                $isTop = true;

                $catIdUppercat = $cat['id_uppercat'];
                $catHasParent = $catIdUppercat !== null && $catIdUppercat !== 0;
                $catUppercats = $cat['uppercats'];

                if ($catHasParent) {
                    foreach (explode(',', $catUppercats) as $idUppercat) {
                        if (isset($topCategories[$idUppercat])) {
                            $isTop = false;
                            break;
                        }
                    }
                }

                if ($isTop) {
                    $catId = $cat['id'];
                    $topCategories[$catId] = $cat;

                    if ($catHasParent) {
                        $parentIds[] = $catIdUppercat;
                    }
                }
            }

            // step 2, search the reference album for permissions
            //
            // to find the reference of each top album, we will need the parent albums
            $parentCats = [];

            if (count($parentIds) > 0) {
                $parentCats = $this->repo->findStatusByIds($parentIds);
            }

            foreach ($topCategories as $topCategory) {
                // what is the "reference" for list of permissions? The parent album
                // if it is private, else the album itself
                $topCategoryId = $topCategory['id'];
                $refCatId = $topCategoryId;

                $topCategoryIdUppercat = $topCategory['id_uppercat'];
                $topCategoryHasParent = $topCategoryIdUppercat !== null && $topCategoryIdUppercat !== 0;
                if ($topCategoryHasParent) {
                    $parentCatId = $topCategoryIdUppercat;
                    if (isset($parentCats[$parentCatId]) && $parentCats[$parentCatId]['status'] === CategoryStatus::Private->value) {
                        $refCatId = $parentCatId;
                    }
                }

                $subcats = $this->getSubcatIds([$topCategoryId]);

                foreach (CategoryAccessTarget::cases() as $target) {
                    // what are the permissions user/group of the reference album
                    $refAccess = $target === CategoryAccessTarget::UserAccess
                        ? $this->repo->findAccessUserIds(CategoryId::from($refCatId))
                        : $this->repo->findAccessGroupIds(CategoryId::from($refCatId));

                    if (count($refAccess) === 0) {
                        $refAccess[] = -1;
                    }

                    // step 3, remove the inconsistant permissions from sub-albums
                    $this->repo->deleteInconsistentAccess($target, $refAccess, $subcats);
                }
            }
        }

        return null;
    }

    /**
     * Returns all uppercats category ids of the given category ids.
     *
     * @param int[] $catIds
     * @return int[]
     */
    public function getUppercatIds(array $catIds): array
    {
        return $this->repo->findUppercatIds($catIds);
    }

    /**
     * @return list<int>
     */
    public function getAccessGroupIds(CategoryId $catId): array
    {
        return $this->repo->findAccessGroupIds($catId);
    }

    /**
     * @return list<int>
     */
    public function getAccessUserIds(CategoryId $catId): array
    {
        return $this->repo->findAccessUserIds($catId);
    }

    /**
     * @param  array<int>  $groupIds
     * @param  array<int>  $catIds
     */
    public function denyGroupAccess(array $groupIds, array $catIds): void
    {
        $this->repo->deleteGroupAccessForGroupsAndCategories($groupIds, $catIds);
    }

    /**
     * @param  array<int>  $userIds
     * @param  array<int>  $catIds
     */
    public function denyUserAccess(array $userIds, array $catIds): void
    {
        $this->repo->deleteUserAccessForUsersAndCategories($userIds, $catIds);
    }

    /**
     * @param array<int, array{group_id: int, cat_id: int}> $inserts
     */
    public function grantGroupAccess(array $inserts): void
    {
        $this->repo->massInsertGroupAccess($inserts, ignore: true);
    }

    /**
     * @param  list<int>  $categoryIds
     * @return array<int, mixed> keyed by category_id
     */
    public function getRefDatesByCategoryIds(array $categoryIds, CategoryRefDateField $field, CategoryRefDateAggregate $minmax): array
    {
        return $this->repo->findRefDatesByCategoryIds($categoryIds, $field, $minmax);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> keyed by id
     */
    public function getUppercatsById(array $ids): array
    {
        return $this->repo->findUppercatsById($ids);
    }

    public function updateImageOrder(CategoryId $catId, ?string $imageOrder): void
    {
        $this->repo->updateImageOrder($catId, $imageOrder);
    }

    public function updateImageOrderForDescendants(string $uppercatsPrefix, ?string $imageOrder): void
    {
        $this->repo->updateImageOrderForDescendants($uppercatsPrefix, $imageOrder);
    }

    /**
     * @return array{src: string|array<int|string, mixed>, url: string}
     */
    public function getCategoryRepresentantProperties(int|string $imageId, UrlServiceInterface $urlService, ?string $size = null): array
    {
        $imageIdVo = ImageId::tryFrom($imageId);
        $row = $imageIdVo === null ? null : EntityManagerFactory::build(DbConnection::build())->getRepository(ImageEntity::class)->findById($imageIdVo);
        if ($row === null) {
            throw new Exception("getCategoryRepresentantProperties(): image {$imageId} does not exist (stale representative_picture_id?)");
        }
        // DerivativeImage::thumb_url()/url() take array<string, mixed>|SrcImage
        // by design -- confirmed cross-domain-generic in the Image module's
        // own pass (see SrcImage::__construct()'s docblock), not a gap.
        $rowArray = $row->toArray();
        if ($size === null) {
            $src = DerivativeImage::thumb_url($rowArray);
        } else {
            $src = DerivativeImage::url($size, $rowArray);
        }
        $url = $urlService->getRootUrl() . 'admin.php?page=photo-' . $imageId;

        return [
            'src' => $src,
            'url' => $url,
        ];
    }

    /**
     * Set a new random representant to the categories.
     *
     * @param int[] $categories
     */
    public function setRandomRepresentant(array $categories): void
    {
        $datas = [];
        foreach ($categories as $categoryId) {
            $representative = $this->repo->findRandomImageIdInCategory($categoryId);

            $datas[] = [
                'id' => $categoryId,
                'representative_picture_id' => $representative,
            ];
        }

        $this->repo->massUpdateRepresentativePictures($datas);
    }

    /**
     * Returns the fulldir for each given category id.
     *
     * Item 16F: $siteGalleriesUrlLookup is an explicit parameter, not
     * constructor-injected -- same "only the methods that actually need
     * it take it" reasoning as this class's own ActivityLoggerInterface
     * parameters (see this class's own constructor docblock).
     *
     * @param int[] $catIds
     * @return string[]
     */
    public function getFulldirs(array $catIds, SiteGalleriesUrlLookupInterface $siteGalleriesUrlLookup): array
    {
        if (count($catIds) === 0) {
            return [];
        }

        $catDirs = $this->repo->findCategoryDirsById();
        $galleriesUrl = $siteGalleriesUrlLookup->findAllGalleriesUrls();
        $categories = $this->repo->findCategoriesForFulldirs(array_map(intval(...), $catIds));

        $catDirsCallback = function (array $m) use ($catDirs): string {
            $matchedId = $m[1] ?? null;
            return (is_string($matchedId) && isset($catDirs[(int) $matchedId])) ? $catDirs[(int) $matchedId] : '';
        };

        $catFulldirs = [];
        foreach ($categories as $category) {
            $catId = $category['id'];
            $siteId = $category['site_id'];
            $categoryUppercats = $category['uppercats'];
            // site_id is always populated when a category is created
            // (defaults to the local site).
            assert(is_numeric($siteId));

            $uppercats = str_replace(',', '/', $categoryUppercats);
            $catFulldirs[$catId] = $galleriesUrl[$siteId];
            $catFulldirs[$catId] .= preg_replace_callback(
                '/(\d+)/',
                $catDirsCallback,
                $uppercats
            );
        }

        return $catFulldirs;
    }

    /**
     * Updates categories.uppercats field based on categories.id + categories.id_uppercat
     */
    public function updateUppercats(): void
    {
        $catMap = [];
        foreach ($this->repo->findCategoriesForRankUpdate() as $row) {
            // findCategoriesForRankUpdate() carries the extra rank/global_rank
            // columns this method never reads -- shared with updateGlobalRank()
            // rather than adding a near-duplicate id/id_uppercat/uppercats-only
            // query.
            $id = $row['id'];
            $catMap[$id] = $row;
        }

        $datas = [];
        foreach ($catMap as $id => $cat) {
            $upperList = [];

            $uppercat = $id;
            while ((bool) $uppercat) {
                $upperList[] = $uppercat;
                $nextUppercat = $catMap[$uppercat]['id_uppercat'] ?? null;
                $uppercat = is_int($nextUppercat) ? $nextUppercat : null;
            }

            $newUppercats = implode(',', array_reverse($upperList));
            $catUppercats = $cat['uppercats'];
            if ($newUppercats !== $catUppercats) {
                $datas[] = [
                    'id' => $id,
                    'uppercats' => $newUppercats,
                ];
            }
        }
        $this->repo->massUpdateUppercats($datas);
    }

    /**
     * Update images.path field base on images.file and storage categories fulldirs.
     */
    public function updatePath(SiteGalleriesUrlLookupInterface $siteGalleriesUrlLookup): void
    {
        $catIds = $this->repo->findDistinctStorageCategoryIds();
        $fulldirs = $this->getFulldirs($catIds, $siteGalleriesUrlLookup);

        foreach ($catIds as $catId) {
            $this->repo->updateImagePathsForCategory(CategoryId::from($catId), $fulldirs[$catId]);
        }
    }

    /**
     * Change the parent category of the given categories. The categories are
     * supposed virtual.
     *
     * @param array<int, int> $categoryIds
     * @param int $newParent (-1 for root)
     */
    public function moveCategories(array $categoryIds, ActivityLoggerInterface $activityLogger, PageState $pageState, int $newParent = -1): void
    {
        if (count($categoryIds) === 0) {
            return;
        }

        $newParentSql = $newParent < 1 ? 'NULL' : (string) $newParent;

        $categories = [];
        foreach ($this->repo->findCategoriesForMove($categoryIds) as $row) {
            $rowId = $row['id'];
            $rowUppercats = $row['uppercats'];

            $rowIdUppercat = $row['id_uppercat'];
            $rowHasParent = $rowIdUppercat !== null && $rowIdUppercat !== 0;

            $categories[$rowId] =
              [
                  'parent' => $rowHasParent ? $rowIdUppercat : 'NULL',
                  'status' => $row['status'],
                  'uppercats' => $rowUppercats,
              ];
        }

        // is the movement possible? The movement is impossible if you try to move
        // a category in a sub-category or itself
        if ($newParentSql !== 'NULL') {
            $newParentUppercats = $this->repo->findCategoryUppercatsById((int) $newParentSql);
            assert($newParentUppercats !== null);

            foreach ($categories as $category) {
                // technically, you can't move a category with uppercats 12,125,13,14
                // into a new parent category with uppercats 12,125,13,14,24
                if ((bool) preg_match('/^' . $category['uppercats'] . '(,|$)/', $newParentUppercats)) {
                    $pageState->addError($this->lang->t('You cannot move an album in its own sub album'));
                    return;
                }
            }
        }

        $this->repo->updateCategoryParent($categoryIds, $newParentSql);

        $this->updateUppercats();
        $this->updateGlobalRank();

        // status and related permissions management
        if ($newParentSql === 'NULL') {
            $parentStatus = CategoryStatus::Public->value;
        } else {
            $parentStatus = $this->repo->findCategoryStatus((int) $newParentSql);
        }

        if ($parentStatus === CategoryStatus::Private->value) {
            $this->setCatStatus(array_map(intval(...), array_keys($categories)), CategoryStatus::Private->value);
        }

        $pageState->addInfo($this->translator->plural(
            '%d album moved',
            '%d albums moved',
            count($categories)
        ));

        $activityLogger->record('album', $categoryIds, 'move', [
            'parent' => $newParentSql,
        ]);
    }

    /**
     * @param int|string|null $parentId ws_categories_add() passes null by
     *   default (WsParamType::INT param, unset by the caller), admin/cat_list.php
     *   passes a raw, unvalidated $_GET['parent_id'] string
     * @param array{commentable?: mixed, visible?: mixed, status?: mixed, comment?: mixed, inherit?: mixed} $options
     *   values are validated internally (is_bool()/==), not trusted from callers
     * @return array{error: string}|array{info: string, id: int|string}
     */
    public function createVirtualCategory(string $categoryName, ActivityLoggerInterface $activityLogger, CurrentUser $currentUser, int|string|null $parentId = null, array $options = []): array
    {

        // is the given category name only containing blank spaces ?
        if ((bool) preg_match('/^\s*$/', $categoryName)) {
            return [
                'error' => $this->lang->t('The name of an album must not be empty'),
            ];
        }

        $rank = 0;
        if ($this->currentConfig->newcatDefaultPosition() === 'last') {
            // what is the current higher rank for this parent?
            $maxRank = $this->repo->findMaxRankForParent($parentId);
            if ($maxRank !== null) {
                $rank = $maxRank + 1;
            }
        }

        $insert = [
            'name' => $categoryName,
            'rank' => $rank,
            'global_rank' => 0,
            // Otherwise relies on the schema's own DEFAULT CURRENT_TIMESTAMP,
            // which reads the real DB-server clock -- invisible to Env::now()'s
            // PIWIGO_TEST_NOW freeze.
            'lastmodified' => Env::now()
                ->format('Y-m-d H:i:s'),
        ];

        // is the album commentable?
        if (isset($options['commentable']) && is_bool($options['commentable'])) {
            $insert['commentable'] = $options['commentable'];
        } else {
            $insert['commentable'] = $this->currentConfig->newcatDefaultCommentable();
        }

        // is the album temporarily locked? (only visible by administrators,
        // whatever permissions) (may be overwritten if parent album is not
        // visible)
        if (isset($options['visible']) && is_bool($options['visible'])) {
            $insert['visible'] = $options['visible'];
        } else {
            $insert['visible'] = $this->currentConfig->newcatDefaultVisible();
        }

        // is the album private? (may be overwritten if parent album is private)
        if (isset($options['status']) && $options['status'] === CategoryStatus::Private->value) {
            $insert['status'] = CategoryStatus::Private->value;
        } else {
            $insert['status'] = $this->currentConfig->newcatDefaultStatus();
        }

        // any description for this album?
        if (isset($options['comment'])) {
            $comment = is_scalar($options['comment']) ? (string) $options['comment'] : '';
            $insert['comment'] = ($this->currentConfig->allowHtmlDescriptions()) ? $options['comment'] : strip_tags($comment);
        }

        $parentIdIsEmpty = $parentId === null || $parentId === 0 || $parentId === '0' || $parentId === '';
        if (! $parentIdIsEmpty && is_numeric($parentId)) {
            $parent = $this->repo->findParentCategoryForCreate($parentId);
            if ($parent === null) {
                return [
                    'error' => $this->lang->t('The parent album does not exist'),
                ];
            }

            $insert['id_uppercat'] = $parent['id'];
            $insert['global_rank'] = $parent['global_rank'] . '.' . $insert['rank'];

            // at creation, must a category be visible or not ? Warning : if the
            // parent category is invisible, the category is automatically create
            // invisible. (invisible = locked)
            if (! (bool) $parent['visible']) {
                $insert['visible'] = false;
            }

            // at creation, must a category be public or private ? Warning : if the
            // parent category is private, the category is automatically create
            // private.
            if ($parent['status'] === CategoryStatus::Private->value) {
                $insert['status'] = CategoryStatus::Private->value;
            }

            $uppercatsPrefix = $parent['uppercats'] . ',';
        } else {
            $uppercatsPrefix = '';
        }

        // we have then to add the virtual category
        $insertedId = $this->repo->insertCategory($insert);

        $this->repo->updateCategoryAfterInsert($insertedId, [
            'uppercats' => $uppercatsPrefix . $insertedId,
            // This UPDATE is an unconditional, immediate follow-up to the
            // INSERT above (needs the auto-generated id first) -- part of
            // the same logical "create category" operation, not a later,
            // independent edit. Re-set explicitly, since ON UPDATE
            // CURRENT_TIMESTAMP would otherwise silently overwrite the
            // INSERT's own frozen lastmodified with the real DB-server
            // clock the moment this UPDATE runs.
            'lastmodified' => Env::now()
                ->format('Y-m-d H:i:s'),
        ]);

        $this->updateGlobalRank();

        $insertIdUppercat = $insert['id_uppercat'] ?? null;
        if ($insert['status'] === CategoryStatus::Private->value && $insertIdUppercat !== null && $insertIdUppercat !== 0 && ((isset($options['inherit']) && (bool) $options['inherit']) || $this->currentConfig->inheritanceByDefault())) {
            $grantedGrps = $this->repo->findAccessGroupIds(CategoryId::from($insertIdUppercat));
            $inserts = [];
            foreach ($grantedGrps as $grantedGrp) {
                $inserts[] = [
                    'group_id' => $grantedGrp,
                    'cat_id' => (int) $insertedId,
                ];
            }
            $this->repo->massInsertGroupAccess($inserts);

            $grantedUsers = $this->repo->findAccessUserIds(CategoryId::from($insertIdUppercat));
            $this->permissionService->addPermissionOnCategory((int) $insertedId, $grantedUsers);
        } elseif ($insert['status'] === CategoryStatus::Private->value) {
            $currentUserId = $currentUser->get()
                ->id->value;
            $adminIds = array_map(
                static fn (UserId $id): int => $id->value,
                $this->userRepository()
                    ->findAdminIds()
            );
            $this->permissionService->addPermissionOnCategory((int) $insertedId, array_unique(array_merge($adminIds, [$currentUserId])));
        }

        $this->eventDispatcher->dispatchNotify(new CreateVirtualCategory(array_merge([
            'id' => $insertedId,
        ], $insert)));
        $activityLogger->record('album', $insertedId, 'add');

        return [
            'info' => $this->lang->t('Album added'),
            'id' => $insertedId,
        ];
    }

    /**
     * Is the category accessible to the (Admin) user ?
     * Note : if the user is not authorized to see this category, category jump
     * will be replaced by admin cat_modify page
     */
    public function catAdminAccess(int $categoryId, CurrentUser $currentUser): bool
    {
        // $filter['visible_categories'] and $filter['visible_images']
        // are not used because it's not necessary (filter <> restriction)
        $forbiddenCategories = $currentUser->get()
            ->forbiddenCategories;
        return ! in_array((string) $categoryId, explode(',', $forbiddenCategories), true);
    }

    /**
     * Sets $categoryId's representative image -- Controller\
     * PictureController's own "set_as_representative" action. Bypasses
     * the ORM (see CategoryRepository::setRepresentativeImage()'s own
     * docblock); caller clears the EntityManager afterward.
     */
    public function setRepresentativeImage(int $categoryId, int $imageId): void
    {
        $this->repo->setRepresentativeImage($categoryId, $imageId);
    }

    /**
     * Clears $categoryId's representative image -- Ws\PwgCategories::
     * deleteRepresentative()'s own action; caller clears the
     * EntityManager afterward (same contract as
     * {@see setRepresentativeImage()} above).
     */
    public function clearRepresentativeImage(CategoryId $categoryId): void
    {
        $this->repo->clearRepresentativePictureIds([$categoryId->value]);
    }

    /**
     * @param list<int> $categoryIds
     */
    public function setRepresentativeImageForCategories(array $categoryIds, int $imageId): void
    {
        $this->repo->setRepresentativeImageForCategories($categoryIds, $imageId);
    }

    /**
     * @return list<int>
     */
    public function getCategoryIdsRepresentedByImage(int $imageId): array
    {
        return $this->repo->findCategoryIdsRepresentedByImage($imageId);
    }

    /**
     * @return list<int>
     */
    public function getPrivateCategoryIdsGrantedToGroup(int $groupId): array
    {
        return $this->repo->findPrivateCategoryIdsGrantedToGroup($groupId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCategoriesAuthorizedViaGroupsForUser(int $userId): array
    {
        return $this->repo->findCategoriesAuthorizedViaGroupsForUser($userId);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, permalink: ?string, id_uppercat: ?int, uppercats: string, global_rank: ?string}>
     */
    public function getCategoriesByIds(array $ids): array
    {
        return $this->repo->findCategoriesByIds($ids);
    }

    /**
     * @param list<int> $excludeCategoryIds
     * @return list<int>
     */
    public function getPrivateCategoryIdsGrantedToUser(int $userId, array $excludeCategoryIds): array
    {
        return $this->repo->findPrivateCategoryIdsGrantedToUser($userId, $excludeCategoryIds);
    }

    /**
     * @return list<string>
     */
    public function getActivePermalinks(): array
    {
        return $this->repo->findActivePermalinks();
    }

    public function countAllCategories(): int
    {
        return $this->repo->countAllCategories();
    }

    public function countByDirNull(bool $dirIsNull): int
    {
        return $this->repo->countByDirNull($dirIsNull);
    }

    /**
     * @return list<int>
     */
    public function getIdsByDirNull(bool $dirIsNull): array
    {
        return $this->repo->findIdsByDirNull($dirIsNull);
    }

    public function countByVisible(bool $visible): int
    {
        return $this->repo->countByVisible($visible);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getChildrenOfParent(?int $parentId): array
    {
        return $this->repo->findChildrenOfParent($parentId);
    }

    /**
     * @return array<int, int>
     */
    public function getPhotoCountsByCategory(): array
    {
        return $this->repo->findPhotoCountsByCategory();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getAllCategoryUppercats(): array
    {
        return $this->repo->findAllCategoryUppercats();
    }

    /**
     * @return list<int>
     */
    public function getIdsByParent(?int $parentId): array
    {
        return $this->repo->findIdsByParent($parentId);
    }

    /**
     * @param list<string> $categoryIds
     * @return list<array<string, mixed>>
     */
    public function getIdsNamesUppercatsForIds(array $categoryIds): array
    {
        return $this->repo->findIdsNamesUppercatsForIds($categoryIds);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllForAlbumTree(): array
    {
        return $this->repo->findAllForAlbumTree();
    }

    public function hasImages(int $categoryId): bool
    {
        return $this->repo->hasImages($categoryId);
    }

    /**
     * @return list<mixed>
     */
    public function getPhotoCountAndDateRange(int $categoryId): array
    {
        return $this->repo->findPhotoCountAndDateRange($categoryId);
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function getDistinctImageIdsInCategories(array $categoryIds): array
    {
        return $this->repo->findDistinctImageIdsInCategories($categoryIds);
    }

    /**
     * @param list<int|string> $ids
     * @return array<int|string, mixed>
     */
    public function getDirsByIds(array $ids): array
    {
        return $this->repo->findDirsByIds($ids);
    }

    public function getGalleriesUrlForCategory(int|string $categoryId, SiteGalleriesUrlLookupInterface $siteGalleriesUrlLookup): ?string
    {
        return $siteGalleriesUrlLookup->findGalleriesUrlForCategory($categoryId);
    }

    public function getCategoryUppercatsById(int $id): ?string
    {
        return $this->repo->findCategoryUppercatsById($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getActivePermalinksList(?string $orderByColumn): array
    {
        return $this->repo->findActivePermalinksList($orderByColumn);
    }

    public function existsAndNotForbidden(int $catId, string $forbiddenCategoriesCsv): bool
    {
        return $this->repo->existsAndNotForbidden($catId, $forbiddenCategoriesCsv);
    }

    /**
     * Names/permalinks keyed by id -- Controller\PictureController's own
     * "one query for every related category's display name" lookup.
     *
     * @param list<int> $ids
     * @return array<int, array{id: int, name: string, permalink: ?string}>
     */
    public function getNamesByIds(array $ids): array
    {
        return $this->repo->findNamesByIds($ids);
    }

    public function existsById(int $id): bool
    {
        return $this->repo->existsById($id);
    }

    public function getRandomRepresentativeIdAmongSubcategories(string $uppercats, PermissionCriteria $criteria): ?string
    {
        return $this->repo->findRandomRepresentativeIdAmongSubcategories($uppercats, $criteria);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function getExistingIds(array $ids): array
    {
        return $this->repo->findExistingIds($ids);
    }

    /**
     * @return ?array{id: int, name: string, permalink: ?string}
     */
    public function getIdNamePermalinkById(int $id): ?array
    {
        return $this->repo->findIdNamePermalinkById($id);
    }

    /**
     * @param  list<SqlCondition>  $conditions
     * @return list<array{id: int, image_order: ?string}>
     */
    public function getIdsAndImageOrderWithConditions(array $conditions): array
    {
        return $this->repo->findIdsAndImageOrderWithConditions($conditions);
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function getListForWs(
        CategoryListCriteria $criteria,
        ?string $searchTerm,
        int $searchLimit,
        ?int $limit,
        bool $limitPlusOne
    ): PaginatedResult {
        return $this->repo->findListForWs($criteria, $searchTerm, $searchLimit, $limit, $limitPlusOne);
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function getAdminListForWs(CategoryAdminListCriteria $criteria, ?string $searchTerm, int $searchLimit): PaginatedResult
    {
        return $this->repo->findAdminListForWs($criteria, $searchTerm, $searchLimit);
    }

    /**
     * @param  list<int>  $parentIds
     * @return array<string, int>
     */
    public function getSubcategoryCountsByParent(array $parentIds): array
    {
        return $this->repo->findSubcategoryCountsByParent($parentIds);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, id_uppercat: ?int, rank: ?int}>
     */
    public function getRankInfoByIds(array $ids): array
    {
        return $this->repo->findRankInfoByIds($ids);
    }

    /**
     * @return list<int>
     */
    public function getIdsByParentOrderedById(?int $parentId): array
    {
        return $this->repo->findIdsByParentOrderedById($parentId);
    }

    /**
     * @return list<int>
     */
    public function getSiblingIdsExcludingOrderedByRank(?int $parentId, int $excludeId): array
    {
        return $this->repo->findSiblingIdsExcludingOrderedByRank($parentId, $excludeId);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, dir: ?string, uppercats: string}>
     */
    public function getMoveDetailsByIds(array $ids): array
    {
        return $this->repo->findMoveDetailsByIds($ids);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function getDistinctLinkedImageIds(array $ids): array
    {
        return $this->repo->findDistinctLinkedImageIds($ids);
    }

    /**
     * @return list<int>
     */
    public function getIdsByNameOrCommentLike(string $pattern, bool $matchName, bool $matchComment): array
    {
        return $this->repo->findIdsByNameOrCommentLike($pattern, $matchName, $matchComment);
    }

    /**
     * @param  list<int>  $imageIds
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public function getNonOrphanImageIds(array $imageIds, array $excludeIds): array
    {
        return $this->repo->findNonOrphanImageIds($imageIds, $excludeIds);
    }

    /**
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public function getImageIdsOutsideCategories(array $excludeIds): array
    {
        return $this->repo->findImageIdsOutsideCategories($excludeIds);
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function getUppercatsColumns(array $ids): array
    {
        return $this->repo->findUppercatsColumns($ids);
    }

    public function getNextId(): int
    {
        return $this->repo->findNextId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSyncCandidatesForSite(int $siteId, ?int $catId, bool $recursive): array
    {
        return $this->repo->findSyncCandidatesForSite($siteId, $catId, $recursive);
    }

    /**
     * @return list<int>
     */
    public function getAllIds(): array
    {
        return $this->repo->findAllIds();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getNextRanksByParent(): array
    {
        return $this->repo->findNextRanksByParent();
    }
}
