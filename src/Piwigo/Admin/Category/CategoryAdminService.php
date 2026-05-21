<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Activity\Details\DeleteAlbumDetails;
use Piwigo\Activity\Details\MoveAlbumDetails;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\Privacy;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\ExecutionMutex;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Event\Album\CreateVirtualCategory;
use Piwigo\Event\Album\DeleteCategories;
use Piwigo\Event\Album\EmptyLounge;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\LoungeRepository;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Site\SiteRepository;
use Piwigo\Users\CurrentUser;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class CategoryAdminService
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private LoungeRepository $loungeRepository,
        private PermissionRepository $permissionRepository,
        private SiteRepository $siteRepository,
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
        // Each deleteElements call has its own transaction; filesystem cleanup
        // in the physical-deletion modes runs before the inner DB delete.
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

        $this->categoryRepository->deleteCategoriesAndPermalinksAtomically($ids);
        $this->dispatcher->dispatch(new DeleteCategories($ids));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $ids, ActivityAction::Delete, new DeleteAlbumDetails($photoDeletionMode)));
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
        $scope = null;
        if ($ids === 'all') {
            $scope = null;
        } elseif (!is_array($ids)) {
            $scope = $ids;
        } else {
            if (count($ids) === 0) {
                return;
            }
            $scope = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_values($ids));
        }
        $wrongRep = $this->categoryRepository->findIdsWithDeadRepresentative($scope);
        if (count($wrongRep) > 0) {
            $this->categoryRepository->clearRepresentatives($wrongRep);
        }
        if (!Config::allowRandomRepresentative()) {
            $toRand = $this->categoryRepository->findIdsMissingRepresentativeAmong($scope);
            if (count($toRand) > 0) {
                $this->setRandomRepresentant($toRand);
            }
        }
    }

    public function categoriesIntegrity(): void
    {
        $this->categoryRepository->pruneOrphanRelations();
    }

    /**
     * Wires an image into the given category list (with optional rank-per-category
     * encoded as `catId,rank;catId2,rank2`). `replaceMode` removes existing
     * associations not present in the new set.
     */
    public function addImageCategoryRelations(int $imageId, string $categoriesString, bool $replaceMode = false): \Piwigo\Ws\PwgError|true
    {
        $catIds             = [];
        $rankOnCategory     = [];
        $searchCurrentRanks = false;
        if ($categoriesString === '') {
            if ($replaceMode) {
                $this->categoryRepository->deleteImageCategoryByImageIds([$imageId]);
                $this->updateCategory([]);
            }
            return true;
        }
        $tokens = explode(';', $categoriesString);
        foreach ($tokens as $token) {
            $parts = explode(',', $token);
            $catId = $parts[0];
            $rank  = $parts[1] ?? null;
            if (!preg_match('/^\d+$/', $catId)) {
                continue;
            }
            $catIds[]               = $catId;
            $rankOnCategory[$catId] = ($rank === null || $rank === '') ? 'auto' : $rank;
            if ($rankOnCategory[$catId] === 'auto') {
                $searchCurrentRanks = true;
            }
        }
        $catIds = array_unique($catIds);
        if (count($catIds) === 0) {
            if ($replaceMode) {
                $this->categoryRepository->deleteImageCategoryByImageIds([$imageId]);
                $this->updateCategory([]);
            }
            return true;
        }
        $catIdsInt     = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catIds);
        $dbCatIds      = $this->categoryRepository->findExistingIdsAmong($catIdsInt);
        $unknownCatIds = array_diff($catIdsInt, $dbCatIds);
        if (count($unknownCatIds) !== 0) {
            return new \Piwigo\Ws\PwgError(500, '[addImageCategoryRelations] the following categories are unknown: ' . implode(', ', $unknownCatIds));
        }
        $existingCatIds = $this->categoryRepository->findCategoryIdsByImageId($imageId);
        if ($replaceMode) {
            $toRemoveCatIds = array_values(array_diff($existingCatIds, $catIdsInt));
            if (count($toRemoveCatIds) > 0) {
                $this->categoryRepository->removeImageFromCategories($imageId, $toRemoveCatIds);
                $this->updateCategory($toRemoveCatIds);
            }
        }
        $newCatIds = array_values(array_diff($catIdsInt, $existingCatIds));
        if (count($newCatIds) === 0) {
            return true;
        }
        if ($searchCurrentRanks) {
            $currentRankOf = $this->categoryRepository->findMaxImageRankPerCategoryIn($newCatIds);
            foreach ($newCatIds as $catId) {
                if (!isset($currentRankOf[$catId])) {
                    $currentRankOf[$catId] = 0;
                }
                if ($rankOnCategory[$catId] === 'auto') {
                    $rankOnCategory[$catId] = $currentRankOf[$catId] + 1;
                }
            }
        }
        $inserts = [];
        foreach ($newCatIds as $catId) {
            $inserts[] = [
                'image_id'    => $imageId,
                'category_id' => $catId,
                'rank'        => is_numeric($rankOnCategory[$catId]) ? (int) $rankOnCategory[$catId] : 0,
            ];
        }
        $this->categoryRepository->insertImageCategoryLinks($inserts);
        $this->updateCategory($newCatIds);
        return true;
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
            $idInt = is_numeric($id) ? (int) $id : 0;
            $keyed[(string) $idInt] = ['id' => $idInt, 'rank' => $currentRank];
        }
        $rows = array_values($keyed);
        $this->categoryRepository->setRanks($rows);
        $this->updateGlobalRank();
    }

    public function updateGlobalRank(): int
    {
        $catMap          = [];
        $currentRank     = 0;
        $currentUppercat = null;
        foreach ($this->categoryRepository->getAllForRankUpdate() as $row) {
            $rowUppercatId = $row->idUppercat?->value;
            if ($rowUppercatId !== $currentUppercat) {
                $currentRank     = 0;
                $currentUppercat = $rowUppercatId;
            }
            ++$currentRank;
            $catMap[$row->id->value] = [
                'rank'         => $currentRank,
                'rank_changed' => $currentRank !== $row->rank,
                'global_rank'  => $row->globalRank,
                'uppercats'    => $row->uppercats,
            ];
        }
        $datas    = [];
        $callback = (fn (array $m): string => is_string($m[1]) ? (string) ($catMap[(int) $m[1]]['rank'] ?? 0) : '0');
        foreach ($catMap as $id => $cat) {
            $replaceResult = preg_replace_callback('/(\d+)/', $callback, str_replace(',', '.', $cat['uppercats']));
            $newGlobalRank = is_string($replaceResult) ? $replaceResult : '';
            if ($cat['rank_changed'] || $newGlobalRank !== $cat['global_rank']) {
                $datas[] = ['id' => $id, 'rank' => $cat['rank'], 'global_rank' => $newGlobalRank];
            }
        }
        $this->categoryRepository->setRanksAndGlobalRanks($datas);
        return count($datas);
    }

    /** @param int[] $categories */
    public function setCatVisible(array $categories, string $value): void
    {
        if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
            throw new \InvalidArgumentException('set_cat_visible invalid param');
        }
        $catRepo = $this->categoryRepository;
        if ($value) {
            $cats = $this->getUppercatIds($categories);
            $catRepo->setVisible(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $cats), true);
        } else {
            $subcats = $this->categoryService->getSubcatIds($categories);
            $catRepo->setVisible(array_map(fn (int $v): int => $v, $subcats), false);
        }
    }

    /** @param int[]|int|string $categories */
    public function setCatStatus(array|int|string $categories, Privacy $value): void
    {
        if (!is_array($categories)) {
            $categories = [$categories];
        }
        $catRepo = $this->categoryRepository;
        if ($value === Privacy::Public) {
            $uppercats = $this->getUppercatIds($categories);
            $catRepo->setStatus(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $uppercats), $value);
        }
        if ($value === Privacy::Private) {
            $subcats = $this->categoryService->getSubcatIds($categories);
            $catRepo->setStatus(array_map(fn (int $v): int => $v, $subcats), $value);

            $topCategories = [];
            $parentIds     = [];
            $allCategories = $catRepo->findDetailsByIds($categories);
            usort($allCategories, static fn (\Piwigo\Category\Projection\CategoryDetail $a, \Piwigo\Category\Projection\CategoryDetail $b): int => strnatcasecmp((string) $a->globalRank, (string) $b->globalRank));

            foreach ($allCategories as $cat) {
                $isTop = true;
                if ($cat->idUppercat !== null) {
                    foreach (explode(',', $cat->uppercats) as $idUppercat) {
                        if (isset($topCategories[(int) $idUppercat])) {
                            $isTop = false;
                            break;
                        }
                    }
                }
                if ($isTop) {
                    $topCategories[$cat->id->value] = $cat;
                    if ($cat->idUppercat !== null) {
                        $parentIds[] = $cat->idUppercat->value;
                    }
                }
            }

            $parentCats = count($parentIds) > 0 ? $catRepo->findStatusByIds($parentIds) : [];

            foreach ($topCategories as $topCategory) {
                $refCatId         = $topCategory->id->value;
                $topCatUppercatId = $topCategory->idUppercat?->value;
                if ($topCatUppercatId !== null && isset($parentCats[$topCatUppercatId]) && $parentCats[$topCatUppercatId] === Privacy::Private) {
                    $refCatId = $topCatUppercatId;
                }
                $subCatsForRef = array_values($this->categoryService->getSubcatIds([(string) $topCategory->id->value]));

                $refUserIds = $this->permissionRepository->findUserAccessUserIdsByCategoryId($refCatId);
                $this->permissionRepository->deleteUserAccessNotInForCategoryIds($refUserIds, $subCatsForRef);

                $refGroupIds = $this->permissionRepository->findGroupAccessGroupIdsByCategoryId($refCatId);
                $this->permissionRepository->deleteGroupAccessNotInForCategoryIds($refGroupIds, $subCatsForRef);
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
        $this->categoryRepository->setRepresentatives($datas);
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
        $catIdsInt    = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_values($catIds));
        $catDirs      = $this->categoryRepository->findAllIdToDirMap();
        $galleriesUrl = $this->siteRepository->findIdToGalleriesUrlMap();
        $categories   = $this->categoryRepository->findUppercatsAndSiteByIds($catIdsInt);
        $callback     = (fn (array $m): string => is_string($m[1]) && isset($catDirs[(int) $m[1]]) ? $catDirs[(int) $m[1]] : '');
        $catFulldirs  = [];
        foreach ($categories as $category) {
            $uppercats               = str_replace(',', '/', $category->uppercats);
            $catIdKey                = (string) $category->id->value;
            $siteIdInt               = $category->siteId ?? 0;
            $catFulldirs[$catIdKey]  = $galleriesUrl[$siteIdInt] ?? '';
            $catFulldirs[$catIdKey] .= (string) preg_replace_callback('/(\d+)/', $callback, $uppercats);
        }
        return $catFulldirs;
    }

    public function updateUppercats(): void
    {
        $catMap = $this->categoryRepository->findAllIdUppercatRowsKeyedById();
        $datas  = [];
        foreach ($catMap as $id => $cat) {
            $upperList   = [];
            $uppercatId  = $id;
            while ($uppercatId !== null) {
                $upperList[] = (string) $uppercatId;
                $next        = $catMap[$uppercatId] ?? null;
                $uppercatId  = $next?->idUppercat?->value;
            }
            $newUppercats = implode(',', array_reverse($upperList));
            if ($newUppercats !== $cat->uppercats) {
                $datas[] = ['id' => $id, 'uppercats' => $newUppercats];
            }
        }
        $this->categoryRepository->setUppercatsBatch($datas);
    }

    public function updatePath(): void
    {
        $catIds   = $this->imageRepository->findDistinctStorageCategoryIds();
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
        foreach ($catRepo->findByIds($catIdsInt) as $category) {
            $categories[(string) $category->id->value] = [
                'parent'    => $category->idUppercat !== null ? $category->idUppercat->value : 'NULL',
                'status'    => $category->status->value,
                'uppercats' => $category->uppercats,
            ];
        }
        if ($newParent !== 'NULL') {
            $newParentUppercatsStr = $catRepo->findUppercatsStringById($newParent) ?? '';
            foreach ($categories as $category) {
                if (preg_match('/^' . $category['uppercats'] . '(,|$)/', $newParentUppercatsStr)) {
                    PageState::current()->addError(Lang::t('You cannot move an album in its own sub album'));
                    return;
                }
            }
        }
        $catRepo->updateParent($catIdsInt, $newParent === 'NULL' ? null : ($newParent));
        $this->updateUppercats();
        $this->updateGlobalRank();
        $parentStatus = ($newParent === 'NULL') ? Privacy::Public : ($catRepo->findStatusById($newParent) ?? Privacy::Public);
        if ($parentStatus === Privacy::Private) {
            $this->setCatStatus(array_map(static fn (int|string $v): int => (int) $v, array_keys($categories)), Privacy::Private);
        }
        PageState::current()->addInfo(Translator::get()->plural('%d album moved', '%d albums moved', count($categories)));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $catIdsInt, ActivityAction::Move, new MoveAlbumDetails((int) $newParent)));
    }

    /** @param array<mixed> $options */
    public function createVirtualCategory(string $categoryName, int|string|null $parentId = null, array $options = []): CreateCategoryResult
    {
        if (preg_match('/^\s*$/', $categoryName)) {
            return CreateCategoryResult::error(Lang::t('The name of an album must not be empty'));
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
        $insert['status']      = (isset($options['status']) && $options['status'] === Privacy::Private->value) ? Privacy::Private->value : Config::newcatDefaultStatus();
        if (isset($options['comment'])) {
            $cv = is_string($options['comment']) ? $options['comment'] : '';
            $insert['comment'] = Config::allowHtmlDescriptions() ? $cv : strip_tags($cv);
        }
        $uppercatsPrefix = '';
        if (($parentId !== null && $parentId !== '' && $parentId !== 0) && is_numeric($parentId)) {
            $parent = $this->categoryRepository->findCategoryById((int) $parentId);
            if ($parent !== null) {
                $insert['id_uppercat'] = $parent->id->value;
                $insert['global_rank'] = ($parent->globalRank ?? '') . '.' . (string) $rank;
                if (!$parent->visible) {
                    $insert['visible'] = 0;
                }
                if ($parent->status === Privacy::Private) {
                    $insert['status'] = Privacy::Private->value;
                }
                $uppercatsPrefix = $parent->uppercats . ',';
            }
        }
        $insertedId = $this->categoryRepository->insertVirtualAndFixUppercats($insert, $uppercatsPrefix);
        $this->updateGlobalRank();
        $idUppercatInt = $insert['id_uppercat'] ?? 0;
        if ($insert['status'] === Privacy::Private->value && $idUppercatInt !== 0 && ((isset($options['inherit']) && $options['inherit']) || Config::inheritanceByDefault())) {
            $grantedGrps = $this->permissionRepository->findGroupAccessGroupIdsByCategoryId($idUppercatInt);
            $groupInserts = [];
            foreach ($grantedGrps as $grp) {
                $groupInserts[] = ['group_id' => $grp, 'cat_id' => $insertedId];
            }
            $this->permissionRepository->insertGroupAccessRows($groupInserts);
            $grantedUsers = $this->permissionRepository->findUserAccessUserIdsByCategoryId($idUppercatInt);
            $this->addPermissionOnCategory($insertedId, $grantedUsers);
        } elseif ($insert['status'] === Privacy::Private->value) {
            $userId = CurrentUser::get()->id;
            $this->addPermissionOnCategory($insertedId, array_unique(array_merge($this->userAdminService->getAdmins(), [$userId])));
        }
        $this->dispatcher->dispatch(new CreateVirtualCategory(array_merge(['id' => $insertedId], $insert)));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $insertedId, ActivityAction::Add));
        return CreateCategoryResult::success($insertedId, Lang::t('Album added'));
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
        foreach ($this->categoryRepository->findExistingImageCategoryLinks($images, $categories) as $link) {
            $existing[$link->categoryId->value][] = $link->imageId->value;
        }
        $currentRankOf = $this->categoryRepository->findMaxImageRankPerCategoryIn(array_values($categories));

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
                    $currentRankOf[$categoryId] = $currentRankOf[$categoryId] + 1;
                    $inserts[] = ['image_id' => $imageId, 'category_id' => $categoryId, 'rank' => $currentRankOf[$categoryId]];
                }
            }
        }
        if (count($inserts)) {
            $this->categoryRepository->insertImageCategoryLinks($inserts);
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
        $dissociables = $this->categoryRepository->findDissociableImageIdsForCategory((int) $category, array_values($images));
        if (!empty($dissociables)) {
            $this->categoryRepository->deleteImageCategoryByCategoryAndImageIds((int) $category, $dissociables);
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
        $this->categoryRepository->deleteVirtualImageCategoryLinksExcept(array_values($images), array_values($categories));
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
        $images = $this->categoryRepository->findImageIdsLinkedToCategories(array_values($sources));
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
        $catIdsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_values($catIds));
        $privateCats = $this->categoryRepository->findPrivateByIds($catIdsInt);
        if (count($privateCats) === 0) {
            return;
        }
        $inserts = [];
        foreach ($privateCats as $catId) {
            foreach ($userIds as $userId) {
                $inserts[] = ['user_id' => (int) $userId, 'cat_id' => $catId];
            }
        }
        $this->permissionRepository->insertUserAccessIgnoreDuplicates($inserts);
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
        $this->categoryRepository->setImageRanksInCategory($datas);
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
        $this->loungeRepository->insertIgnoreDuplicates($inserts);
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
        $voyager = $this->loungeRepository->findOldestEntry();
        if ($voyager === null) {
            return;
        }
        $dbnowTs     = strtotime($voyager->dbnow);
        $dateAvailTs = strtotime($voyager->dateAvailable);
        $age         = ($dbnowTs !== false ? $dbnowTs : 0) - ($dateAvailTs !== false ? $dateAvailTs : 0);
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
        $rows       = $this->loungeRepository->findAllEntries();
        $images     = [];
        foreach ($rows as $idx => $row) {
            if ($row['image_id'] > $maxImageId) {
                $maxImageId = $row['image_id'];
            }
            $images[] = $row['image_id'];
            if (!isset($rows[$idx + 1]) || $rows[$idx + 1]['category_id'] !== $row['category_id']) {
                $this->associateImagesToCategories($images, [$row['category_id']]);
                $images = [];
            }
        }
        $this->loungeRepository->deleteBeforeId($maxImageId);
        if ($invalidateUserCache) {
            $this->userAdminService->invalidateUserCache();
        }
        $this->mutex->release('empty_lounge');
        $this->dispatcher->dispatch(new EmptyLounge($rows));
        return $rows;
    }
}
