<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * Admin-side category WRITE operations -- deliberately separate from
 * Piwigo\Category\CategoryService (P19), which was read-only (gallery
 * browsing) when this split was made; CategoryService has since grown
 * several of its own write methods too (setCatVisible()/setCatStatus()/
 * setCatCommentable()/etc.) -- the split is about admin-vs-gallery call
 * sites, not a strict read/write boundary anymore. Matches the doc's own
 * reference file inventory: "Admin/Category/: CategoryAdminService,
 * CreateCategoryResult".
 *
 * createVirtualCategory() delegates to the constructor-injected
 * CategoryService::createVirtualCategory() (Legacy Coupling Retirement
 * Phase 4a) rather than reimplementing it -- the same real method the WS
 * API (Ws\PwgCategories) already calls directly; this service only adds
 * a typed return shape for the admin call sites.
 *
 * setCategoryOption()/setCategoryPermissions()/saveImageOrder() are real
 * new consolidations: getCategoriesRefDate() existed as two
 * byte-for-byte-identical copies (admin/cat_list.php and admin/albums.php,
 * confirmed via direct diff) and the other two replace inline
 * switch/if-chains that were never shared anywhere, moved here so
 * Controller\Admin\CatOptionsSubController/AlbumSubController (the real,
 * routed successors to the legacy admin/cat_options.php, admin/cat_perm.php,
 * and admin/element_set_ranks.php `include` pages, all 3 fully ported)
 * can call one typed method each instead of repeating raw SQL/branching
 * inline.
 */
final class CategoryAdminService
{
    public function __construct(
        private CategoryService $categoryService,
    ) {}

    /**
     * @param array{commentable?: bool, visible?: bool, status?: string, comment?: string, inherit?: bool} $options
     */
    public function createVirtualCategory(string $name, ActivityLoggerInterface $activityLogger, ?int $parentId = null, array $options = []): CreateCategoryResult
    {
        /** @var array{error?: string, info?: string, id?: int|string} $result */
        $result = $this->categoryService->createVirtualCategory($name, $activityLogger, $parentId, $options);

        if (isset($result['error'])) {
            return CreateCategoryResult::failure($result['error']);
        }

        return CreateCategoryResult::success(
            $result['info'] ?? '',
            isset($result['id']) ? (int) $result['id'] : 0
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
    public function getCategoriesRefDate(array $ids, string $field = 'date_available', string $minmax = 'max'): array
    {
        // we need to work on the whole tree under each category, even if we
        // don't want to sort sub categories
        $category_ids = $this->categoryService->getSubcatIds($ids);

        // Real pre-existing bug, found by Legacy Coupling Retirement Phase
        // 4a's Integration-test migration (the old Unit test's
        // get_subcat_ids() stub always echoed its input back verbatim,
        // masking this): an empty $category_ids (e.g. every id in $ids is
        // unknown) built `WHERE category_id IN ()`, invalid SQL. The
        // method's own closing loop already falls back to null for any id
        // with no ref_date, so skipping straight there is the correct fix,
        // not just a guard to avoid the crash.
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
                $ref_dates[$cat_id] = $minmax === 'max' ? max($to_compare) : min($to_compare);
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
     * P23 batch 8f-4: takes ActivityLoggerInterface as an explicit
     * per-method parameter (this class's only activity-writing method, 17
     * construction sites -- same per-method-injection reasoning as
     * CategoryService::deleteCategories()), replacing the formerly-bare
     * pwg_activity() call kept unqualified purely for
     * CategoryAdminServiceTest's function-shadowing spy; that test now
     * passes a fake logger through this parameter instead.
     *
     * @param list<int> $catIds
     */
    public function setCategoryOption(array $catIds, string $section, bool $value, \Piwigo\Core\ActivityLoggerInterface $activityLogger): void
    {
        if ($catIds === []) {
            return;
        }

        match ($section) {
            // Docs/PLAN-REPLAY-AUDIT.md gap-closure, 2026-07-23: this whole
            // match used to call the bare global pwg_query()/query2array()
            // free functions for 2 of its 4 branches -- neither has ever
            // had a real production definition (only a namespace-shadowing
            // spy in CategoryAdminServiceTest.php, invisible outside that
            // test process), so toggling "comments" or un-toggling
            // "representative" here fatal-errored on every real request.
            // Retargeted onto the same typed repository/service methods the
            // "visible"/"status" branches already correctly used.
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

        $conn = DbConnection::build();
        $permissionRepository = new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn));

        // groups
        $groupsGranted = $this->categoryService->getAccessGroupIds($catId);

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
        $usersGranted = $this->categoryService->getAccessUserIds($catId);

        $denyUsers = array_diff($usersGranted, $userIds);
        if (count($denyUsers) > 0) {
            // if you forbid access to an album, all sub-album become
            // automatically forbidden
            $this->categoryService->denyUserAccess($denyUsers, $this->categoryService->getSubcatIds([$catId]));
        }

        if (count($userIds) > 0) {
            \Piwigo\Bootstrap\CoreDomainAccessor::permissionService()
                ->addPermissionOnCategory($catId, $userIds, $applyOnSub);
        }
    }

    /**
     * Consolidates admin/element_set_ranks.php's category image_order
     * UPDATE (own row + optionally every sub-album).
     */
    public function saveImageOrder(int $catId, ?string $imageOrder, bool $applySubcats, RedirectServiceInterface $redirectService): void
    {
        $this->categoryService->updateImageOrder($catId, $imageOrder);

        if (! $applySubcats) {
            return;
        }

        $catInfo = $this->categoryService->getCategoryInfo($catId);
        if ($catInfo === null) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->pageNotFound($redirectService, 'Requested album does not exist');
        }

        $this->categoryService->updateImageOrderForDescendants($catInfo['uppercats'] . ',', $imageOrder);
    }
}
