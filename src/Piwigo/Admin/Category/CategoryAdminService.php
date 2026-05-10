<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageRepository;
use Piwigo\Lang\Translator;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;

final readonly class CategoryAdminService
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    public function deleteSite(mixed $id): void
    {
        $repo        = ServiceLocator::get(CategoryRepository::class);
        $intId       = is_numeric($id) ? (int) $id : 0;
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
        $ids        = ServiceLocator::get(CategoryService::class)->getSubcatIds($ids);
        $elementIds = ServiceLocator::get(ImageRepository::class)->findIdsByStorageCategoryIds($ids);
        ServiceLocator::get(ImageAdminService::class)->deleteElements($elementIds);

        if ($photoDeletionMode === 'delete_orphans' || $photoDeletionMode === 'force_delete') {
            $catRepo         = ServiceLocator::get(CategoryRepository::class);
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
                ServiceLocator::get(ImageAdminService::class)->deleteElements($imageIdsToDelete, true);
            }
        }

        $catRepo2  = ServiceLocator::get(CategoryRepository::class);
        $userRepo2 = ServiceLocator::get(UserRepository::class);
        $catRepo2->deleteImageCategoryByCategoryIds($ids);
        $userRepo2->deleteUserAccessByCategoryIds($ids);
        $catRepo2->deleteGroupAccessByCategoryIds($ids);
        $catRepo2->deleteByIds($ids);
        $catRepo2->deletePermalinksByCategoryIds($ids);
        $userRepo2->deleteUserCacheByCategoryIds($ids);

        EventDispatcher::notify('delete_categories', $ids);
        ServiceLocator::get(Util::class)->pwgActivity('album', $ids, 'delete', ['photo_deletion_mode' => $photoDeletionMode]);
    }

    public function imagesIntegrity(): void
    {
        $catRepo       = ServiceLocator::get(CategoryRepository::class);
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
        $wrongRep = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id');
        if (count($wrongRep) > 0) {
            ServiceLocator::get(CategoryRepository::class)->clearRepresentatives(
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
            $toRand = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id'));
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
            $orphans = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), $column)));
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
        Dml::massUpdates(Tables::categories(), ['primary' => ['id'], 'update' => ['rank']], array_values($keyed));
        $this->updateGlobalRank();
    }

    public function updateGlobalRank(): int
    {
        $catMap         = [];
        $currentRank    = 0;
        $currentUppercat = '';
        foreach (ServiceLocator::get(CategoryRepository::class)->getAllForRankUpdate() as $row) {
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
        Dml::massUpdates(Tables::categories(), ['primary' => ['id'], 'update' => ['rank', 'global_rank']], $datas);
        return count($datas);
    }

    /** @param int[]|int|string $categories */
    public function setCatVisible(array|int|string $categories, bool|string $value, bool $unlockChild = false): void
    {
        if (!is_array($categories)) {
            $categories = [$categories];
        }
        if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
            trigger_error('set_cat_visible invalid param', E_USER_WARNING);
            return;
        }
        $catRepo = ServiceLocator::get(CategoryRepository::class);
        if ($value) {
            $cats = $this->getUppercatIds($categories);
            if ($unlockChild) {
                $cats = array_merge($cats, ServiceLocator::get(CategoryService::class)->getSubcatIds($categories));
            }
            $catRepo->setVisible(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $cats), true);
        } else {
            $subcats = ServiceLocator::get(CategoryService::class)->getSubcatIds($categories);
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
            trigger_error('set_cat_status invalid param ' . $value, E_USER_WARNING);
            return;
        }
        $catRepo = ServiceLocator::get(CategoryRepository::class);
        if ($value === 'public') {
            $uppercats = $this->getUppercatIds($categories);
            $catRepo->setStatus(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $uppercats), 'public');
        }
        if ($value === 'private') {
            $subcats = ServiceLocator::get(CategoryService::class)->getSubcatIds($categories);
            $catRepo->setStatus(array_map(fn (int $v): int => $v, $subcats), 'private');

            $topCategories = [];
            $parentIds     = [];
            $allCategories = $catRepo->findDetailsByIds($categories);
            usort($allCategories, ServiceLocator::get(CategoryService::class)->globalRankCompare(...));

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
                $subCatsForRef = ServiceLocator::get(CategoryService::class)->getSubcatIds([is_scalar($topCategory['id'] ?? null) ? (string) $topCategory['id'] : '0']);
                foreach ($tables as $table => $field) {
                    $refAccess = array_column(DbConnection::get()->executeQuery(
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
        $uppercatStrings = ServiceLocator::get(CategoryRepository::class)->findUppercatsByIds($catIds);
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
        $imgRepo = ServiceLocator::get(ImageRepository::class);
        $datas   = [];
        foreach ($categories as $categoryId) {
            $datas[] = ['id' => $categoryId, 'representative_picture_id' => $imgRepo->findRandomIdByCategoryId($categoryId)];
        }
        Dml::massUpdates(Tables::categories(), ['primary' => ['id'], 'update' => ['representative_picture_id']], $datas);
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
        $catDirs = array_column(DbConnection::get()->executeQuery('SELECT id, dir FROM ' . Tables::categories() . ' WHERE dir IS NOT NULL')->fetchAllAssociative(), 'dir', 'id');
        $galleriesUrl = array_column(DbConnection::get()->executeQuery('SELECT id, galleries_url FROM ' . Tables::sites())->fetchAllAssociative(), 'galleries_url', 'id');
        $categories   = DbConnection::get()->executeQuery(
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
        $catMap = array_column(DbConnection::get()->executeQuery('SELECT id, id_uppercat, uppercats FROM ' . Tables::categories())->fetchAllAssociative(), null, 'id');
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
        Dml::massUpdates(Tables::categories(), ['primary' => ['id'], 'update' => ['uppercats']], $datas);
    }

    public function updatePath(): void
    {
        $catIds   = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(DbConnection::get()->executeQuery('SELECT DISTINCT(storage_category_id) FROM ' . Tables::images() . ' WHERE storage_category_id IS NOT NULL')->fetchAllAssociative(), 'storage_category_id'));
        $fulldirs = $this->getFulldirs($catIds);
        foreach ($catIds as $catId) {
            ServiceLocator::get(ImageRepository::class)->updatePathByStorageCategoryId($catId, $fulldirs[$catId] ?? '');
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
        $catRepo    = ServiceLocator::get(CategoryRepository::class);
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
        ServiceLocator::get(Util::class)->pwgActivity('album', $catIdsInt, 'move', ['parent' => $newParent]);
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
            $maxRank = ServiceLocator::get(CategoryRepository::class)->findMaxRankForParent(($parentId === null || $parentId === '' || $parentId === 0) ? null : (int) $parentId);
            if ($maxRank !== null) {
                $rank = $maxRank + 1;
            }
        }
        $insert = ['name' => $categoryName, 'rank' => $rank, 'global_rank' => 0];
        $insert['commentable'] = BoolUtil::toString(isset($options['commentable']) && is_bool($options['commentable']) ? $options['commentable'] : Config::newcatDefaultCommentable());
        $insert['visible']     = BoolUtil::toString(isset($options['visible']) && is_bool($options['visible']) ? $options['visible'] : Config::newcatDefaultVisible());
        $insert['status']      = (isset($options['status']) && $options['status'] === 'private') ? 'private' : Config::newcatDefaultStatus();
        if (isset($options['comment'])) {
            $cv = is_string($options['comment']) ? $options['comment'] : '';
            $insert['comment'] = Config::allowHtmlDescriptions() ? $cv : strip_tags($cv);
        }
        $uppercatsPrefix = '';
        if (($parentId !== null && $parentId !== '' && $parentId !== 0) && is_numeric($parentId)) {
            $parent = ServiceLocator::get(CategoryRepository::class)->findCategoryById((int) $parentId);
            if ($parent !== null) {
                $insert['id_uppercat'] = $parent['id'];
                $insert['global_rank'] = (is_string($parent['global_rank'] ?? null) ? $parent['global_rank'] : '') . '.' . (string) $insert['rank'];
                if ($parent['visible'] === 'false') {
                    $insert['visible'] = 'false';
                }
                if ($parent['status'] === 'private') {
                    $insert['status'] = 'private';
                }
                $uppercatsPrefix = (is_string($parent['uppercats'] ?? null) ? $parent['uppercats'] : '') . ',';
            }
        }
        Dml::singleInsert(Tables::categories(), $insert);
        $insertedId = (int) DbConnection::get()->lastInsertId();
        Dml::singleUpdate(Tables::categories(), ['uppercats' => $uppercatsPrefix . $insertedId], ['id' => $insertedId]);
        $this->updateGlobalRank();
        $idUppercatRaw = $insert['id_uppercat'] ?? null;
        $idUppercatStr = is_string($idUppercatRaw) ? $idUppercatRaw : (is_numeric($idUppercatRaw) ? (string) $idUppercatRaw : '0');
        if ($insert['status'] === 'private' && isset($insert['id_uppercat']) && $insert['id_uppercat'] !== '' && $insert['id_uppercat'] !== 0 && ((isset($options['inherit']) && $options['inherit']) || Config::inheritanceByDefault())) {
            $grantedGrps = array_column(DbConnection::get()->executeQuery('SELECT group_id FROM ' . Tables::groupAccess() . ' WHERE cat_id = ' . $idUppercatStr)->fetchAllAssociative(), 'group_id');
            $inserts     = [];
            foreach ($grantedGrps as $grp) {
                $inserts[] = ['group_id' => $grp, 'cat_id' => $insertedId];
            }
            Dml::massInserts(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts);
            $grantedUsers = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(DbConnection::get()->executeQuery('SELECT user_id FROM ' . Tables::userAccess() . ' WHERE cat_id = ' . $idUppercatStr)->fetchAllAssociative(), 'user_id'));
            $this->addPermissionOnCategory($insertedId, $grantedUsers);
        } elseif ($insert['status'] === 'private') {
            $userId = CurrentUser::get()->id;
            $this->addPermissionOnCategory($insertedId, array_unique(array_merge(ServiceLocator::get(UserAdminService::class)->getAdmins(), [$userId])));
        }
        EventDispatcher::notify('create_virtual_category', array_merge(['id' => $insertedId], $insert));
        ServiceLocator::get(Util::class)->pwgActivity('album', $insertedId, 'add');
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
        foreach (ServiceLocator::get(CategoryRepository::class)->findExistingImageCategoryLinks($images, $categories) as $row) {
            $catKey            = is_numeric($row['category_id']) ? (int) $row['category_id'] : 0;
            $existing[$catKey][] = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
        }
        $currentRankOf = array_column(DbConnection::get()->executeQuery(
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
                    $inserts[] = ['image_id' => $imageId, 'category_id' => $categoryId, 'rank' => $currentRankOf[$categoryId]];
                }
            }
        }
        if (count($inserts)) {
            Dml::massInserts(Tables::imageCategory(), array_keys($inserts[0]), $inserts);
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
        $dissociables = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id');
        if (!empty($dissociables)) {
            ServiceLocator::get(CategoryRepository::class)->deleteImageCategoryByCategoryAndImageIds(
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
        $images = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(DbConnection::get()->executeQuery(
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
            $catIds = array_merge($catIds, ServiceLocator::get(CategoryService::class)->getSubcatIds($categoryIds));
        }
        $privateCats = array_column(DbConnection::get()->executeQuery(
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
        Dml::massInserts(Tables::userAccess(), ['user_id', 'cat_id'], $inserts, ['ignore' => true]);
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
        Dml::massUpdates(Tables::imageCategory(), ['primary' => ['image_id', 'category_id'], 'update' => ['rank']], $datas);
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
            Dml::massInserts(Tables::lounge(), array_keys($inserts[0]), $inserts, ['ignore' => true]);
        }
    }

    /** @return array<mixed>|null */
    public function emptyLounge(bool $invalidateUserCache = true): ?array
    {
        $logger = LoggerRegistry::current();
        if (Config::has('empty_lounge_running')) {
            [$runningExecId, $runningExecStartTime] = explode('-', (string) Config::emptyLoungeRunning());
            if (time() - (int) $runningExecStartTime > 60) {
                $logger->debug('empty_lounge, exec=' . $runningExecId . ', timeout stopped by another call');
                ServiceLocator::get(ConfigService::class)->confDeleteParam('empty_lounge_running');
            }
        }
        $execId = StringUtil::generateKey(4);
        $logger->debug('empty_lounge, exec=' . $execId . ', begins');
        $this->conn->executeStatement('INSERT IGNORE INTO ' . Tables::config() . ' SET param="empty_lounge_running", value=?', [$execId . '-' . time()]);
        $emptyLoungeRunning = $this->conn->executeQuery('SELECT value FROM ' . Tables::config() . ' WHERE param = "empty_lounge_running"')->fetchOne();
        [$runningExecId] = explode('-', is_string($emptyLoungeRunning) ? $emptyLoungeRunning : '');
        if ($runningExecId != $execId) {
            $logger->debug('empty_lounge, exec=' . $execId . ', skip');
            return null;
        }
        $logger->debug('empty_lounge, exec=' . $execId . ' wins the race!');
        $maxImageId = 0;
        $rows       = DbConnection::get()->executeQuery('SELECT image_id, category_id FROM ' . Tables::lounge() . ' ORDER BY category_id ASC, image_id ASC')->fetchAllAssociative();
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
        ServiceLocator::get(ImageRepository::class)->deleteLoungeBeforeId($maxImageId);
        if ($invalidateUserCache) {
            ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        }
        ServiceLocator::get(ConfigService::class)->confDeleteParam('empty_lounge_running');
        $logger->debug('empty_lounge, exec=' . $execId . ', ends');
        EventDispatcher::notify('empty_lounge', $rows);
        return $rows;
    }
}
