<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryRefDateAggregate;
use Piwigo\Category\CategoryRefDateField;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;

/**
 * Admin-side category WRITE operations, kept separate from
 * Piwigo\Category\CategoryService. The split reflects admin-vs-gallery
 * call sites, not a strict read/write boundary -- CategoryService also
 * exposes write methods of its own (setCatVisible()/setCatStatus()/
 * setCatCommentable()/etc.).
 *
 * createVirtualCategory() delegates to the constructor-injected
 * CategoryService::createVirtualCategory() -- the same method the WS API
 * (Ws\Categories) calls directly -- and only adds a typed return shape
 * for admin call sites.
 *
 * Controller\Admin\CatOptionsSubController and AlbumSubController call
 * setCategoryOption(), setCategoryPermissions(), and saveImageOrder() as
 * single typed methods instead of repeating raw SQL/branching inline.
 */
final readonly class CategoryAdminService
{
    public function __construct(
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private HtmlRenderingInterface $htmlRenderer,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array{commentable?: bool, visible?: bool, status?: string, comment?: string, inherit?: bool} $options
     */
    public function createVirtualCategory(string $name, ActivityLoggerInterface $activityLogger, CurrentUser $currentUser, ?int $parentId = null, array $options = []): CreateCategoryResult
    {
        $result = $this->categoryService->createVirtualCategory($name, $activityLogger, $currentUser, $parentId, $options);

        if ($result->error !== null) {
            return CreateCategoryResult::failure($result->error);
        }

        return CreateCategoryResult::success(
            (string) $result->info,
            CategoryId::from((int) $result->id)
        );
    }

    /**
     * Deduplicated from two byte-for-byte-identical copies in
     * admin/cat_list.php and admin/albums.php.
     *
     * The mixed value here is a direct propagation of
     * {@see \Piwigo\Category\CategoryRepository::findRefDatesByCategoryIds()}'s
     * own by-design arbitrary value (its real type depends on which
     * column $field names) -- already documented there, not re-derived.
     *
     * @param list<int|string> $ids
     * @return array<int|string, mixed>
     */
    public function getCategoriesRefDate(array $ids, CategoryRefDateField $field = CategoryRefDateField::DateAvailable, CategoryRefDateAggregate $minmax = CategoryRefDateAggregate::Max): array
    {
        // we need to work on the whole tree under each category, even if we
        // don't want to sort sub categories
        $category_ids = $this->categoryService->getSubcatIds($ids);

        // An empty $category_ids (e.g. every id in $ids is unknown) would
        // build `WHERE category_id IN ()`, invalid SQL. The method's own
        // closing loop already falls back to null for any id with no
        // ref_date, so this returns that fallback directly instead of
        // running the query.
        if ($category_ids === []) {
            $return = [];
            foreach ($ids as $id) {
                $return[$id] = null;
            }

            return $return;
        }

        $ref_dates = $this->categoryService->getRefDatesByCategoryIds($category_ids, $field, $minmax);
        $uppercats_of = $this->categoryService->getUppercatsById($category_ids);

        foreach (array_keys($uppercats_of) as $cat_id) {
            $subcat_ids = [];

            foreach ($uppercats_of as $id => $uppercats) {
                if ((bool) preg_match('/(^|,)' . $cat_id . '(,|$)/', $uppercats)) {
                    $subcat_ids[] = $id;
                }
            }

            $to_compare = [];
            foreach ($subcat_ids as $id) {
                if (isset($ref_dates[$id])) {
                    $to_compare[] = $ref_dates[$id];
                }
            }

            if (count($to_compare) > 0) {
                $ref_dates[$cat_id] = $minmax === CategoryRefDateAggregate::Max ? max($to_compare) : min($to_compare);
            } else {
                $ref_dates[$cat_id] = null;
            }
        }

        $return = [];
        foreach ($ids as $id) {
            $return[$id] = $ref_dates[$id] ?? null;
        }

        return $return;
    }

    /**
     * Consolidates admin/cat_options.php's 8 switch-case branches
     * (comments/visible/status/representative x true/false) into one
     * parameterized method.
     *
     * Takes ActivityLoggerInterface as an explicit per-method parameter
     * rather than a constructor dependency, since this is the only
     * activity-writing method on this class.
     *
     * @param list<int> $catIds
     */
    public function setCategoryOption(array $catIds, string $section, bool $value, ActivityLoggerInterface $activityLogger): void
    {
        if ($catIds === []) {
            return;
        }

        match ($section) {
            'comments' => $this->categoryService->setCatCommentable($catIds, $value),
            'visible' => $this->categoryService->setCatVisible($catIds, $value),
            'status' => $this->categoryService->setCatStatus($catIds, $value ? 'public' : 'private'),
            'representative' => $value
                // theoretically, all categories in $catIds contain at least
                // one element when $value is true, so Piwigo can find a
                // representant (matches the original's own comment).
                ? $this->categoryService->setRandomRepresentant($catIds)
                : $this->categoryService->clearRepresentativePictures($catIds),
            default => null,
        };

        $activityLogger->record('album', $catIds, 'edit', [
            'section' => $section,
            'action' => $value ? 'trueify' : 'falsify',
        ]);
    }

    /**
     * Consolidates admin/cat_perm.php's group/user permission-management
     * block (status change + group/user grant/deny).
     *
     * @param list<int> $groupIds
     * @param list<int> $userIds
     */
    public function setCategoryPermissions(int $catId, string $currentStatus, string $newStatus, bool $applyOnSub, array $groupIds, array $userIds): void
    {
        if ($currentStatus !== $newStatus || ($currentStatus !== 'public' && $applyOnSub)) {
            $catIdsForStatus = [$catId];
            if ($applyOnSub) {
                $catIdsForStatus = array_merge($catIdsForStatus, $this->categoryService->getSubcatIds([$catId]));
            }
            $this->categoryService->setCatStatus($catIdsForStatus, $newStatus);
        }

        if ($newStatus !== 'private') {
            return;
        }

        $permissionRepository = new PermissionRepository($this->entityManager);

        // groups
        $groupsGranted = $this->categoryService->getAccessGroupIds(CategoryId::from($catId));

        $denyGroups = array_diff($groupsGranted, $groupIds);
        if (count($denyGroups) > 0) {
            // if you forbid access to an album, all sub-albums become
            // automatically forbidden
            $this->categoryService->denyGroupAccess($denyGroups, $this->categoryService->getSubcatIds([$catId]));
        }

        if (count($groupIds) > 0) {
            $catIdsForGrant = $this->categoryService->getUppercatIds([$catId]);
            if ($applyOnSub) {
                $catIdsForGrant = array_merge($catIdsForGrant, $this->categoryService->getSubcatIds([$catId]));
            }

            // Same "only private categories need explicit access rows"
            // filter PermissionService::addPermissionOnCategory() below
            // already applies to its own $userIds grant -- reuses that
            // same PermissionRepository instance rather than duplicating
            // its findPrivateCategoryIdsAmong() on CategoryRepository too.
            $privateCats = $permissionRepository->findPrivateCategoryIdsAmong(array_values($catIdsForGrant));

            $inserts = [];
            foreach ($privateCats as $privateCatId) {
                foreach ($groupIds as $groupId) {
                    $inserts[] = [
                        'group_id' => $groupId,
                        'cat_id' => $privateCatId,
                    ];
                }
            }

            $this->categoryService->grantGroupAccess($inserts);
        }

        // users
        $usersGranted = $this->categoryService->getAccessUserIds(CategoryId::from($catId));

        $denyUsers = array_diff($usersGranted, $userIds);
        if (count($denyUsers) > 0) {
            // if you forbid access to an album, all sub-album become
            // automatically forbidden
            $this->categoryService->denyUserAccess($denyUsers, $this->categoryService->getSubcatIds([$catId]));
        }

        if (count($userIds) > 0) {
            $this->permissionService
                ->addPermissionOnCategory($catId, $userIds, $applyOnSub);
        }
    }

    /**
     * Consolidates admin/element_set_ranks.php's category image_order
     * UPDATE (own row + optionally every sub-album).
     */
    public function saveImageOrder(int $catId, ?string $imageOrder, bool $applySubcats, RedirectServiceInterface $redirectService): void
    {
        $this->categoryService->updateImageOrder(CategoryId::from($catId), $imageOrder);

        if (! $applySubcats) {
            return;
        }

        $catInfo = $this->categoryService->getCategoryInfo($catId);
        if (! $catInfo instanceof CategoryInfo) {
            $this->htmlRenderer
                ->pageNotFound($redirectService, 'Requested album does not exist');
        }

        $this->categoryService->updateImageOrderForDescendants($catInfo->uppercats . ',', $imageOrder);
    }
}
