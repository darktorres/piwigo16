<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\ExecutionMutex;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\Tables;
use Piwigo\Event\Album\CreateVirtualCategory;
use Piwigo\Event\Album\DeleteCategories;
use Piwigo\Event\Album\EmptyLounge;
use Piwigo\Image\ImageRepository;
use Piwigo\Lang\Translator;
use Piwigo\Users\CurrentUser;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class CategoryAdminService
{
    public function __construct(
        private Connection $conn,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private UserAdminService $userAdminService,
        private ActivityLogger $activityLogger,
        private ExecutionMutex $mutex,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function deleteSite(int $id): void
    {
        $repo        = $this->categoryRepository;
        $intId       = $id;
        $categoryIds = $repo->findIdsBySiteId($intId);
        $this->deleteCategories($categoryIds);
        $repo->deleteSiteById($intId);
    }

    /** @param int[] $ids */
    public function deleteCategories(array $ids, string $photoDeletionMode = 'no_delete'): void
    {
        if (count($ids) === 0) {
            return;
        }
        $ids        = $this->categoryService->getSubcatIds($ids);
        $elementIds = $this->imageRepository->findIdsByStorageCategoryIds($ids);
        $this->imageAdminService->deleteElements($elementIds);

        if ($photoDeletionMode === 'delete_orphans' || $photoDeletionMode === 'force_delete') {
            $catRepo         = $this->categoryRepository;
            $imageIdsLinked  = $catRepo->findLinkedImageIdsByCategoryIds($ids);
            if (count($imageIdsLinked) > 0) {
                $imageIdsToDelete = [];
                if ($photoDeletionMode === 'delete_orphans') {
                    $notOrphans       = $catRepo->findLinkedImageIdsNotIn($imageIdsLinked, $ids);
                    $imageIdsToDelete = array_diff($imageIdsLinked, $notOrphans);
                }
                if ($photoDeletionMode === 'force_delete') {
                    $imageIdsToDelete = $imageIdsLinked;
                }
                $this->imageAdminService->deleteElements($imageIdsToDelete, true);
            }
        }

        $catRepo2 = $this->categoryRepository;
        $catRepo2->deleteByIds($ids);
        // FK CASCADE clears the cat_id side of image_category, user_access,
        // group_access, user_cache_categories. FK SET NULL nulls
        // images.storage_category_id and self-ref categories.id_uppercat
        // (subtree promotion). old_permalinks.cat_id has no FK — keep
        // the manual cleanup.
        $catRepo2->deletePermalinksByCategoryIds($ids);

        $this->dispatcher->dispatch(new DeleteCategories($ids));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $ids, 'delete', ['photo_deletion_mode' => $photoDeletionMode]));
    }

    public function imagesIntegrity(): void
    {
        $catRepo       = $this->categoryRepository;
        $orphanImageIds = $catRepo->findOrphanImageCategoryLinks();
        if (count($orphanImageIds) > 0) {
            $catRepo->deleteOrphanImageCategoryLinks($orphanImageIds);
        }
    }

    /** @param 'all'|int|int[]|string[] $ids */
    public function updateCategory(array|string|int $ids = 'all'): void
    {
        if ($ids == 'all') {
            $whereCats = '1=1';
        } elseif (!is_array($ids)) {
            $whereCats = '%s=' . $ids;
        } else {
            if (count($ids) === 0) {
                return;
            }
            $whereCats = '%s IN(' . wordwrap(implode(', ', $ids), 120, "\n") . ')';
        }
        $query = '
SELECT DISTINCT c.id
  FROM ' . Tables::categories() . ' AS c LEFT JOIN ' . Tables::images() . ' AS i
    ON c.representative_picture_id = i.id
  WHERE representative_picture_id IS NOT NULL
    AND ' . sprintf($whereCats, 'c.id') . '
    AND i.id IS NULL
;';
        $wrongRep = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');
        if (count($wrongRep) > 0) {
            $this->categoryRepository->clearRepresentatives(
                array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $wrongRep)
            );
        }
        if (!Config::allowRandomRepresentative()) {
            $query = '
SELECT DISTINCT id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::imageCategory() . '
    ON id = category_id
  WHERE representative_picture_id IS NULL
    AND ' . sprintf($whereCats, 'category_id') . '
;';
            $toRand = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'));
            if (count($toRand) > 0) {
                $this->setRandomRepresentant($toRand);
            }
        }
    }

    public function categoriesIntegrity(): void
    {
        $relatedColumns = [
            Tables::imageCategory() . '.category_id',
            Tables::userAccess() . '.cat_id',
            Tables::groupAccess() . '.cat_id',
            Tables::oldPermalinks() . '.cat_id',
            Tables::userCacheCategories() . '.cat_id',
        ];
        foreach ($relatedColumns as $fullcol) {
            [$table, $column] = explode('.', $fullcol);
            $query = 'SELECT ' . $column . ' FROM ' . $table . ' LEFT JOIN ' . Tables::categories() . ' ON id = ' . $column . ' WHERE id IS NULL';
            $orphans = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($this->conn->executeQuery($query)->fetchAllAssociative(), $column)));
            if (count($orphans) > 0) {
                $this->conn->executeStatement('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . implode(',', $orphans) . ')');
            }
        }
    }

    /** @param array<mixed> $categories */
    public function saveCategoriesOrder(array $categories): void
    {
        $currentRankForUppercat = [];
        $currentRank            = 0;
        $keyed                  = [];
        foreach ($categories as $category) {
            if (is_array($category)) {
                $id         = $category['id'] ?? null;
                $idUppercat = is_scalar($category['id_uppercat'] ?? null) ? (string) $category['id_uppercat'] : '0';
                if (!isset($currentRankForUppercat[$idUppercat])) {
                    $currentRankForUppercat[$idUppercat] = 0;
                }
                $currentRank = ++$currentRankForUppercat[$idUppercat];
            } else {
                $id = $category;
                $currentRank++;
            }
            if ($id === null) {
                continue;
            }
            $keyed[(string) (is_scalar($id) ? $id : '')] = ['id' => $id, 'rank' => $currentRank];
        }
        $rows = array_values($keyed);
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                // `rank` is a MySQL 8.0 reserved word — backtick the set-array key.
                $this->conn->update(Tables::categories(), ['`rank`' => $row['rank']], ['id' => $row['id']]);
            }
        });
        $this->updateGlobalRank();
    }

    public function updateGlobalRank(): int
    {
        $catMap         = [];
        $currentRank    = 0;
        $currentUppercat = '';
        foreach ($this->categoryRepository->getAllForRankUpdate() as $row) {
            if ($row['id_uppercat'] != $currentUppercat) {
                $currentRank    = 0;
                $currentUppercat = is_scalar($row['id_uppercat'] ?? null) ? (string) $row['id_uppercat'] : '';
            }
            ++$currentRank;
            $rowIdKey          = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0';
            $catMap[$rowIdKey] = [
                'rank'         => $currentRank,
                'rank_changed' => $currentRank != $row['rank'],
                'global_rank'  => $row['global_rank'],
                'uppercats'    => $row['uppercats'],
            ];
        }
        $datas    = [];
        $callback = (fn (array $m): string => is_string($m[1]) ? (string) ($catMap[$m[1]]['rank'] ?? 0) : '0');
        foreach ($catMap as $id => $cat) {
            $uppercatsStr   = is_string($cat['uppercats'] ?? null) ? $cat['uppercats'] : '';
            $newGlobalRank  = preg_replace_callback('/(\d+)/', $callback, str_replace(',', '.', $uppercatsStr));
            if ($cat['rank_changed'] || $newGlobalRank !== $cat['global_rank']) {
                $datas[] = ['id' => $id, 'rank' => $cat['rank'], 'global_rank' => $newGlobalRank];
            }
        }
        $this->conn->transactional(function () use ($datas): void {
            foreach ($datas as $row) {
                // `rank` is a MySQL 8.0 reserved word — backtick the set-array key.
                $this->conn->update(Tables::categories(), ['`rank`' => $row['rank'], 'global_rank' => $row['global_rank']], ['id' => $row['id']]);
            }
        });
        return count($datas);
    }

    /** @param int[]|int|string $categories */
    public function setCatVisible(array|int|string $categories, bool|string $value, bool $unlockChild = false): void
    {
        if (!is_array($categories)) {
            $categories = [$categories];
        }
        if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
            throw new \InvalidArgumentException('set_cat_visible invalid param');
        }
        $catRepo = $this->categoryRepository;
        if ($value) {
            $cats = $this->getUppercatIds($categories);
            if ($unlockChild) {
                $cats = array_merge($cats, $this->categoryService->getSubcatIds($categories));
            }
            $catRepo->setVisible(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $cats), true);
        } else {
            $subcats = $this->categoryService->getSubcatIds($categories);
            $catRepo->setVisible(array_map(fn (int $v): int => $v, $subcats), false);
        }
    }

    /** @param int[]|int|string $categories */
    public function setCatStatus(array|int|string $categories, string $value): void
    {
        if (!is_array($categories)) {
            $categories = [$categories];
        }
        if (!in_array($value, ['public', 'private'])) {
            throw new \InvalidArgumentException('set_cat_status invalid param: ' . $value);
        }
        $catRepo = $this->categoryRepository;
        if ($value === 'public') {
            $uppercats = $this->getUppercatIds($categories);
            $catRepo->setStatus(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $uppercats), 'public');
        }
        if ($value === 'private') {
            $subcats = $this->categoryService->getSubcatIds($categories);
            $catRepo->setStatus(array_map(fn (int $v): int => $v, $subcats), 'private');

            $topCategories = [];
            $parentIds     = [];
            $allCategories = $catRepo->findDetailsByIds($categories);
            usort($allCategories, $this->categoryService->globalRankCompare(...));

            foreach ($allCategories as $cat) {
                $isTop = true;
                if (!empty($cat['id_uppercat'])) {
                    $catUppercatsRaw3 = $cat['uppercats'] ?? null;
                    foreach (explode(',', is_string($catUppercatsRaw3) ? $catUppercatsRaw3 : '') as $idUppercat) {
                        if (isset($topCategories[$idUppercat])) {
                            $isTop = false;
                            break;
                        }
                    }
                }
                if ($isTop) {
                    $catIdKey = is_scalar($cat['id'] ?? null) ? (string) $cat['id'] : '0';
                    $topCategories[$catIdKey] = $cat;
                    if (!empty($cat['id_uppercat'])) {
                        $parentIds[] = is_scalar($cat['id_uppercat']) ? (string) $cat['id_uppercat'] : '';
                    }
                }
            }

            $parentCats = count($parentIds) > 0 ? $catRepo->findStatusByIds($parentIds) : [];
            $tables     = [Tables::userAccess() => 'user_id', Tables::groupAccess() => 'group_id'];

            foreach ($topCategories as $topCategory) {
                $refCatId      = is_scalar($topCategory['id'] ?? null) ? (string) $topCategory['id'] : '0';
                $topCatUppercat = is_scalar($topCategory['id_uppercat'] ?? null) ? (string) $topCategory['id_uppercat'] : '';
                if (!empty($topCategory['id_uppercat']) && isset($parentCats[$topCatUppercat]) && $parentCats[$topCatUppercat]['status'] === 'private') {
                    $refCatId = $topCatUppercat;
                }
                $subCatsForRef = $this->categoryService->getSubcatIds([is_scalar($topCategory['id'] ?? null) ? (string) $topCategory['id'] : '0']);
                foreach ($tables as $table => $field) {
                    $refAccess = array_column($this->conn->executeQuery(
                        'SELECT ' . $field . ' FROM ' . $table . ' WHERE cat_id = ' . $refCatId
                    )->fetchAllAssociative(), $field);
                    if (count($refAccess) === 0) {
                        $refAccess[] = -1;
                    }
                    $this->conn->executeStatement(
                        'DELETE FROM ' . $table . ' WHERE ' . $field .
                        ' NOT IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $refAccess)) . ')' .
                        ' AND cat_id IN (' . implode(',', $subCatsForRef) . ')'
                    );
                }
            }
        }
    }

    /**
     * @param array<int|string>|int|string $catIds
     * @return array<string>
     */
    public function getUppercatIds(array|int|string $catIds): array
    {
        if (!is_array($catIds) || count($catIds) < 1) {
            return [];
        }
        $uppercats       = [];
        $uppercatStrings = $this->categoryRepository->findUppercatsByIds($catIds);
        foreach ($uppercatStrings as $uppercatsStr) {
            $uppercats = array_merge($uppercats, explode(',', $uppercatsStr));
        }
        return array_unique($uppercats);
    }

    /** @param int[]|int $categories */
    public function setRandomRepresentant(array|int $categories): void
    {
        if (!is_array($categories)) {
            $categories = [$categories];
        }
        $imgRepo = $this->imageRepository;
        $datas   = [];
        foreach ($categories as $categoryId) {
            $datas[] = ['id' => $categoryId, 'representative_picture_id' => $imgRepo->findRandomIdByCategoryId($categoryId)];
        }
        $this->conn->transactional(function () use ($datas): void {
            foreach ($datas as $row) {
                $this->conn->update(Tables::categories(), ['representative_picture_id' => $row['representative_picture_id']], ['id' => $row['id']]);
            }
        });
    }

    /**
     * @param int[]|int|string $catIds
     * @return string[]
     */
    public function getFulldirs(array|int|string $catIds): array
    {
        if (!is_array($catIds)) {
            $catIds = [$catIds];
        }
        if (count($catIds) === 0) {
            return [];
        }
        $catDirs = array_column($this->conn->executeQuery('SELECT id, dir FROM ' . Tables::categories() . ' WHERE dir IS NOT NULL')->fetchAllAssociative(), 'dir', 'id');
        $galleriesUrl = array_column($this->conn->executeQuery('SELECT id, galleries_url FROM ' . Tables::sites())->fetchAllAssociative(), 'galleries_url', 'id');
        $categories   = $this->conn->executeQuery(
            'SELECT id, uppercats, site_id FROM ' . Tables::categories() . ' WHERE dir IS NOT NULL AND id IN (' . wordwrap(implode(', ', $catIds), 80, "\n") . ')'
        )->fetchAllAssociative();
        $callback     = (fn (array $m): string => is_string($m[1]) && is_string($catDirs[$m[1]] ?? null) ? $catDirs[$m[1]] : '');
        $catFulldirs  = [];
        foreach ($categories as $category) {
            $catUppercatsRaw4 = $category['uppercats'] ?? null;
            $catIdRaw4        = $category['id']        ?? null;
            $catSiteIdRaw     = $category['site_id']   ?? null;
            $uppercats = str_replace(',', '/', is_scalar($catUppercatsRaw4) ? (string) $catUppercatsRaw4 : '');
            $catIdKey  = is_scalar($catIdRaw4) ? (string) $catIdRaw4 : '0';
            $siteIdKey = is_scalar($catSiteIdRaw) ? (string) $catSiteIdRaw : '0';
            $galleriesUrlRaw = $galleriesUrl[$siteIdKey] ?? null;
            $catFulldirs[$catIdKey]  = is_scalar($galleriesUrlRaw) ? (string) $galleriesUrlRaw : '';
            $catFulldirs[$catIdKey] .= (string) preg_replace_callback('/(\d+)/', $callback, $uppercats);
        }
        return $catFulldirs;
    }

    public function updateUppercats(): void
    {
        $catMap = array_column($this->conn->executeQuery('SELECT id, id_uppercat, uppercats FROM ' . Tables::categories())->fetchAllAssociative(), null, 'id');
        $datas  = [];
        foreach ($catMap as $id => $cat) {
            $upperList = [];
            $uppercat  = (string) $id;
            while ($uppercat) {
                $upperList[] = $uppercat;
                $next        = $catMap[$uppercat]['id_uppercat'] ?? null;
                $uppercat    = is_string($next) ? $next : '';
            }
            $newUppercats = implode(',', array_reverse($upperList));
            if ($newUppercats != $cat['uppercats']) {
                $datas[] = ['id' => $id, 'uppercats' => $newUppercats];
            }
        }
        $this->conn->transactional(function () use ($datas): void {
            foreach ($datas as $row) {
                $this->conn->update(Tables::categories(), ['uppercats' => $row['uppercats']], ['id' => $row['id']]);
            }
        });
    }

    public function updatePath(): void
    {
        $catIds   = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery('SELECT DISTINCT(storage_category_id) FROM ' . Tables::images() . ' WHERE storage_category_id IS NOT NULL')->fetchAllAssociative(), 'storage_category_id'));
        $fulldirs = $this->getFulldirs($catIds);
        foreach ($catIds as $catId) {
            $this->imageRepository->updatePathByStorageCategoryId($catId, $fulldirs[$catId] ?? '');
        }
    }

    /**
     * @param int[] $categoryIds
     *
     * @psalm-param array<int<1, max>> $categoryIds
     */
    public function moveCategories(array $categoryIds, int $newParent = -1): void
    {
        if (count($categoryIds) === 0) {
            return;
        }
        $newParent  = $newParent < 1 ? 'NULL' : $newParent;
        $categories = [];
        $catRepo    = $this->categoryRepository;
        $catIdsInt = $categoryIds;
        foreach ($catRepo->findByIds($catIdsInt) as $row) {
            $rowIdKey           = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0';
            $categories[$rowIdKey] = ['parent' => empty($row['id_uppercat']) ? 'NULL' : $row['id_uppercat'], 'status' => $row['status'], 'uppercats' => $row['uppercats']];
        }
        if ($newParent !== 'NULL') {
            $newParentUppercatsStr = $catRepo->findUppercatsStringById($newParent) ?? '';
            foreach ($categories as $category) {
                $catUppercats = is_string($category['uppercats'] ?? null) ? $category['uppercats'] : '';
                if (preg_match('/^' . $catUppercats . '(,|$)/', $newParentUppercatsStr)) {
                    PageState::current()->addError(Lang::t('You cannot move an album in its own sub album'));
                    return;
                }
            }
        }
        $catRepo->updateParent($catIdsInt, $newParent === 'NULL' ? null : ($newParent));
        $this->updateUppercats();
        $this->updateGlobalRank();
        $parentStatus = ($newParent === 'NULL') ? 'public' : ($catRepo->findStatusById($newParent) ?? 'public');
        if ($parentStatus === 'private') {
            $this->setCatStatus(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_keys($categories)), 'private');
        }
        PageState::current()->addInfo(Translator::get()->plural('%d album moved', '%d albums moved', count($categories)));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $catIdsInt, 'move', ['parent' => $newParent]));
    }

    /**
     * @param array<mixed> $options
     * @return array<mixed>
     */
    public function createVirtualCategory(string $categoryName, int|string|null $parentId = null, array $options = []): array
    {
        if (preg_match('/^\s*$/', $categoryName)) {
            return ['error' => Lang::t('The name of an album must not be empty')];
        }
        $rank = 0;
        if (Config::newcatDefaultPosition() === 'last') {
            $maxRank = $this->categoryRepository->findMaxRankForParent(($parentId === null || $parentId === '' || $parentId === 0) ? null : (int) $parentId);
            if ($maxRank !== null) {
                $rank = $maxRank + 1;
            }
        }
        // `rank` is a MySQL 8.0 reserved word — backtick the key so Connection::insert
        // concatenates it as a quoted identifier.
        $insert = ['name' => $categoryName, '`rank`' => $rank, 'global_rank' => 0];
        $insert['commentable'] = BoolUtil::toInt(isset($options['commentable']) && is_bool($options['commentable']) ? $options['commentable'] : Config::newcatDefaultCommentable());
        $insert['visible']     = BoolUtil::toInt(isset($options['visible']) && is_bool($options['visible']) ? $options['visible'] : Config::newcatDefaultVisible());
        $insert['status']      = (isset($options['status']) && $options['status'] === 'private') ? 'private' : Config::newcatDefaultStatus();
        if (isset($options['comment'])) {
            $cv = is_string($options['comment']) ? $options['comment'] : '';
            $insert['comment'] = Config::allowHtmlDescriptions() ? $cv : strip_tags($cv);
        }
        $uppercatsPrefix = '';
        if (($parentId !== null && $parentId !== '' && $parentId !== 0) && is_numeric($parentId)) {
            $parent = $this->categoryRepository->findCategoryById((int) $parentId);
            if ($parent !== null) {
                $insert['id_uppercat'] = $parent['id'];
                $insert['global_rank'] = (is_string($parent['global_rank'] ?? null) ? $parent['global_rank'] : '') . '.' . (string) $rank;
                if (isset($parent['visible']) && !BoolUtil::fromMixed($parent['visible'])) {
                    $insert['visible'] = 0;
                }
                if ($parent['status'] === 'private') {
                    $insert['status'] = 'private';
                }
                $uppercatsPrefix = (is_string($parent['uppercats'] ?? null) ? $parent['uppercats'] : '') . ',';
            }
        }
        $this->conn->insert(Tables::categories(), $insert);
        $insertedId = (int) $this->conn->lastInsertId();
        $this->conn->update(Tables::categories(), ['uppercats' => $uppercatsPrefix . $insertedId], ['id' => $insertedId]);
        $this->updateGlobalRank();
        $idUppercatRaw = $insert['id_uppercat'] ?? null;
        $idUppercatStr = is_string($idUppercatRaw) ? $idUppercatRaw : (is_numeric($idUppercatRaw) ? (string) $idUppercatRaw : '0');
        if ($insert['status'] === 'private' && isset($insert['id_uppercat']) && $insert['id_uppercat'] !== '' && $insert['id_uppercat'] !== 0 && ((isset($options['inherit']) && $options['inherit']) || Config::inheritanceByDefault())) {
            $grantedGrps = array_column($this->conn->executeQuery('SELECT group_id FROM ' . Tables::groupAccess() . ' WHERE cat_id = ' . $idUppercatStr)->fetchAllAssociative(), 'group_id');
            $inserts     = [];
            foreach ($grantedGrps as $grp) {
                $inserts[] = ['group_id' => $grp, 'cat_id' => $insertedId];
            }
            $this->conn->transactional(function () use ($inserts): void {
                foreach ($inserts as $row) {
                    $this->conn->insert(Tables::groupAccess(), $row);
                }
            });
            $grantedUsers = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userAccess() . ' WHERE cat_id = ' . $idUppercatStr)->fetchAllAssociative(), 'user_id'));
            $this->addPermissionOnCategory($insertedId, $grantedUsers);
        } elseif ($insert['status'] === 'private') {
            $userId = CurrentUser::get()->id;
            $this->addPermissionOnCategory($insertedId, array_unique(array_merge($this->userAdminService->getAdmins(), [$userId])));
        }
        $this->dispatcher->dispatch(new CreateVirtualCategory(array_merge(['id' => $insertedId], $insert)));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $insertedId, 'add'));
        return ['info' => Lang::t('Album added'), 'id' => $insertedId];
    }

    /**
     * @param int[] $images
     * @param int[] $categories
     */
    public function associateImagesToCategories(array $images, array $categories): void
    {
        if (count($images) === 0 || count($categories) === 0) {
            return;
        }
        $existing = [];
        foreach ($this->categoryRepository->findExistingImageCategoryLinks($images, $categories) as $row) {
            $catKey            = is_numeric($row['category_id']) ? (int) $row['category_id'] : 0;
            $existing[$catKey][] = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
        }
        $currentRankOf = array_column($this->conn->executeQuery(
            'SELECT category_id, MAX(`rank`) AS max_rank FROM ' . Tables::imageCategory() . ' WHERE `rank` IS NOT NULL AND category_id IN (' . implode(',', $categories) . ') GROUP BY category_id'
        )->fetchAllAssociative(), 'max_rank', 'category_id');

        $inserts = [];
        foreach ($categories as $categoryId) {
            if (!isset($currentRankOf[$categoryId])) {
                $currentRankOf[$categoryId] = 0;
            }
            if (!isset($existing[$categoryId])) {
                $existing[$categoryId] = [];
            }
            foreach ($images as $imageId) {
                if (!in_array($imageId, $existing[$categoryId])) {
                    $currentRankOf[$categoryId] = (is_numeric($currentRankOf[$categoryId]) ? (int) $currentRankOf[$categoryId] : 0) + 1;
                    // `rank` is a MySQL 8.0 reserved word — backtick the array key.
                    $inserts[] = ['image_id' => $imageId, 'category_id' => $categoryId, '`rank`' => $currentRankOf[$categoryId]];
                }
            }
        }
        if (count($inserts)) {
            $this->conn->transactional(function () use ($inserts): void {
                foreach ($inserts as $row) {
                    $this->conn->insert(Tables::imageCategory(), $row);
                }
            });
            $this->updateCategory($categories);
        }
    }

    /**
     * @param int[] $images
     *
     * @psalm-param array<int> $images
     */
    public function dissociateImagesFromCategory(array $images, string $category): int
    {
        $query = '
SELECT id FROM ' . Tables::imageCategory() . '
  INNER JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id = ' . $category . '
    AND id IN (' . implode(',', $images) . ')
    AND (category_id != storage_category_id OR storage_category_id IS NULL)
;';
        $dissociables = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');
        if (!empty($dissociables)) {
            $this->categoryRepository->deleteImageCategoryByCategoryAndImageIds(
                (int) $category,
                array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $dissociables)
            );
        }
        return count($dissociables);
    }

    /**
     * @param int[] $images
     * @param int[] $categories
     */
    public function moveImagesToCategories(array $images, array $categories): bool
    {
        if (count($images) === 0) {
            return false;
        }
        $query = 'DELETE ' . Tables::imageCategory() . '.* FROM ' . Tables::imageCategory() . ' JOIN ' . Tables::images() . ' ON image_id=id WHERE id IN (' . implode(',', $images) . ')';
        if (count($categories) > 0) {
            $query .= ' AND category_id NOT IN (' . implode(',', $categories) . ')';
        }
        $query .= ' AND (storage_category_id IS NULL OR storage_category_id != category_id)';
        $this->conn->executeStatement($query);
        if (count($categories) > 0) {
            $this->associateImagesToCategories($images, $categories);
        }
        return true;
    }

    /**
     * @param int[] $sources
     * @param int[] $destinations
     */
    public function associateCategoriesToCategories(array $sources, array $destinations): void
    {
        if (count($sources) === 0) {
            return;
        }
        $images = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($this->conn->executeQuery(
            'SELECT image_id FROM ' . Tables::imageCategory() . ' WHERE category_id IN (' . implode(',', $sources) . ')'
        )->fetchAllAssociative(), 'image_id'));
        $this->associateImagesToCategories($images, $destinations);
    }

    /**
     * @param int[]|int|string $categoryIds
     * @param int[]|int|string $userIds
     */
    public function addPermissionOnCategory(array|int|string $categoryIds, array|int|string $userIds): void
    {
        if (!is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        if (count($categoryIds) === 0 || count($userIds) === 0) {
            return;
        }
        $catIds = $this->getUppercatIds($categoryIds);
        if (isset($_POST['apply_on_sub'])) {
            $catIds = array_merge($catIds, $this->categoryService->getSubcatIds($categoryIds));
        }
        $privateCats = array_column($this->conn->executeQuery(
            'SELECT id FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_numeric($v) ? (string)(int)$v : '0', $catIds)) . ") AND status = 'private'"
        )->fetchAllAssociative(), 'id');
        if (count($privateCats) === 0) {
            return;
        }
        $inserts = [];
        foreach ($privateCats as $catId) {
            foreach ($userIds as $userId) {
                $inserts[] = ['user_id' => $userId, 'cat_id' => $catId];
            }
        }
        $this->conn->transactional(function () use ($inserts): void {
            foreach ($inserts as $row) {
                $this->conn->executeStatement(
                    'INSERT IGNORE INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (?, ?)',
                    [$row['user_id'], $row['cat_id']]
                );
            }
        });
    }

    /**
     * @param (int|string)[] $images
     *
     * @psalm-param array<int|string> $images
     */
    public function saveImagesOrder(int $categoryId, array $images): void
    {
        $currentRank = 0;
        $datas       = [];
        foreach ($images as $id) {
            $datas[] = ['category_id' => $categoryId, 'image_id' => $id, 'rank' => ++$currentRank];
        }
        $this->conn->transactional(function () use ($datas): void {
            foreach ($datas as $row) {
                // `rank` is a MySQL 8.0 reserved word — backtick the set-array key.
                $this->conn->update(Tables::imageCategory(), ['`rank`' => $row['rank']], ['image_id' => $row['image_id'], 'category_id' => $row['category_id']]);
            }
        });
    }

    /**
     * @param int[] $images
     * @param int[]|null $categories
     */
    public function fillLounge(array $images, ?array $categories): void
    {
        $inserts = [];
        foreach ($categories ?? [] as $categoryId) {
            foreach ($images as $imageId) {
                $inserts[] = ['image_id' => $imageId, 'category_id' => $categoryId];
            }
        }
        if (count($inserts)) {
            $this->conn->transactional(function () use ($inserts): void {
                foreach ($inserts as $row) {
                    $this->conn->executeStatement(
                        'INSERT IGNORE INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (?, ?)',
                        [$row['image_id'], $row['category_id']]
                    );
                }
            });
        }
    }

    /**
     * Age-out cleanup: if any image has been sitting in the upload lounge
     * longer than `lounge_max_duration`, drain the entire lounge into the
     * main image set via {@see self::emptyLounge()}. Called from
     * `CommonBootstrap::run()` once per request. Was `Util::checkLounge()`
     * before Phase 5.
     */
    public function checkLounge(): void
    {
        if (!Config::has('lounge_active') || !Config::loungeActive()) {
            return;
        }
        if (isset($_REQUEST['method']) && in_array($_REQUEST['method'], ['pwg.images.upload', 'pwg.images.uploadAsync'])) {
            return;
        }
        $query    = 'SELECT image_id, date_available, NOW() AS dbnow FROM ' . Tables::lounge() . ' JOIN ' . Tables::images() . ' ON image_id = id ORDER BY image_id ASC LIMIT 1;';
        $voyagers = $this->conn->executeQuery($query)->fetchAllAssociative();
        if (count($voyagers) === 0) {
            return;
        }
        $voyager      = $voyagers[0];
        $dbnowStr     = is_string($voyager['dbnow'] ?? null) ? $voyager['dbnow'] : '';
        $dateAvailStr = is_string($voyager['date_available'] ?? null) ? $voyager['date_available'] : '';
        $dbnowTs      = strtotime($dbnowStr);
        $dateAvailTs  = strtotime($dateAvailStr);
        $age          = ($dbnowTs !== false ? $dbnowTs : 0) - ($dateAvailTs !== false ? $dateAvailTs : 0);
        if ($age > Config::loungeMaxDuration()) {
            $this->emptyLounge();
        }
    }

    /** @return array<mixed>|null */
    public function emptyLounge(bool $invalidateUserCache = true): ?array
    {
        $execId = $this->mutex->acquire('empty_lounge');
        if ($execId === false) {
            return null;
        }
        $maxImageId = 0;
        $rows       = $this->conn->executeQuery('SELECT image_id, category_id FROM ' . Tables::lounge() . ' ORDER BY category_id ASC, image_id ASC')->fetchAllAssociative();
        $images     = [];
        foreach ($rows as $idx => $row) {
            if (is_numeric($row['image_id']) && (int) $row['image_id'] > $maxImageId) {
                $maxImageId = (int) $row['image_id'];
            }
            $images[] = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
            if (!isset($rows[$idx + 1]) || $rows[$idx + 1]['category_id'] != $row['category_id']) {
                $this->associateImagesToCategories($images, [is_numeric($row['category_id']) ? (int) $row['category_id'] : 0]);
                $images = [];
            }
        }
        $this->imageRepository->deleteLoungeBeforeId($maxImageId);
        if ($invalidateUserCache) {
            $this->userAdminService->invalidateUserCache();
        }
        $this->mutex->release('empty_lounge');
        $this->dispatcher->dispatch(new EmptyLounge($rows));
        return $rows;
    }
}
