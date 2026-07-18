<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

// P23 batch 8c: relocated unchanged from the deleted include/functions_category.inc.php
// -- get_subcat_ids() has real input-validation logic (not a pure 1-line
// delegate), but ~30 real call sites across install/ scripts, ws_functions/,
// admin/include/, and several already-migrated src/Piwigo/ classes made it
// too widely used to retarget every caller onto Piwigo\Category\CategoryService
// directly, same "relocate ubiquitous utilities as unchanged free functions"
// two-track precedent as get_root_url() (P23 batch 8c, functions_url.inc.php)
// and P23 batch 7's Piwigo\PluginConfig\functions.php.
//
// get_cat_info() is a pure 1-line delegate with only 6 real callers -- kept
// here anyway rather than retargeted, because
// Piwigo\Admin\Category\CategoryAdminService::saveImageOrder()'s own real
// caller is exercised by a Unit test
// (tests/Unit/Admin/Category/CategoryAdminServiceTest.php) that stubs
// get_cat_info() via same-namespace function shadowing (PHP resolves an
// unqualified call from inside `namespace Piwigo\Admin\Category` against
// that namespace's own function table first) -- retargeting that one call
// site onto CategoryService::getCategoryInfo() directly would require a
// real DB connection this isolated Unit test never bootstraps. Namespace
// shadowing wins over this file's global declaration regardless of load
// order, so relocating (not retargeting) keeps the stub working unchanged.
// Piwigo\Url\UrlService's own 2 real call sites already call
// CategoryService::getCategoryInfo() directly instead (no such stub
// conflict there) -- both paths return identical results, just a style
// difference forced by this one test's constraint.
//
// Guarded with function_exists() for the same composer-autoloader-vs-
// Arch\StructuralTest-class_exists-probe double-include hazard as every
// other file in this same autoload.files array -- see
// Piwigo\Url\functions.php's own docblock for the full explanation.

/**
 * Returns all subcategory identifiers of given category ids.
 *
 * @param array<int|string> $ids several callers (comments.php, admin/rating.php)
 *   wrap a raw, unvalidated $_GET value directly — the is_numeric() check
 *   in CategoryService::getSubcatIds() is a real guard, not dead code
 * @return list<int> array_values() below always reindexes the result
 */
if (! function_exists('get_subcat_ids')) {
    function get_subcat_ids(array $ids): array
    {
        $conn = DbConnection::build();

        /** @var array<int|string> $ids */
        return new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        )->getSubcatIds($ids);
    }
}

/**
 * Retrieves informations about a category.
 *
 * @return array<string, mixed>|null
 */
if (! function_exists('get_cat_info')) {
    function get_cat_info(int $id): ?array
    {
        $conn = DbConnection::build();

        return new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        )->getCategoryInfo($id);
    }
}

// P23 batch 8d file 3: get_uppercat_ids()/set_cat_visible()/set_cat_status()/
// set_random_representant()/create_virtual_category() stay permanent
// facades for two independent, structurally-forced reasons -- NOT the
// default "no delegators" outcome for this sub-batch's other 12 functions,
// every one of which retargets its real callers directly onto
// CategoryService.
//
// 1. tests/Unit/Admin/Category/CategoryAdminServiceTest.php uses the same
//    same-namespace-function-shadowing technique as get_cat_info()/
//    get_subcat_ids() above (see that docblock) to stub these 5 -- a real
//    CategoryService::xxx() method call from CategoryAdminService.php
//    isn't an unqualified function call, so it wouldn't be interceptable
//    the same way, and would force that isolated Unit test onto a real DB
//    connection it deliberately avoids.
// 2. get_uppercat_ids() alone has a second, independent reason:
//    Piwigo\Permission\PermissionService::addPermissionOnCategory() also
//    calls it bare, and PermissionService is ALREADY a constructor
//    dependency OF CategoryService -- retargeting that call would require
//    PermissionService to depend on CategoryService, a genuine circular
//    construction (CategoryService -> PermissionService -> CategoryService).
//    get_subcat_ids() above already has this exact shape for the identical
//    reason.

/**
 * Returns all uppercats category ids of the given category ids.
 *
 * @param array<int> $catIds
 * @return array<int>
 */
if (! function_exists('get_uppercat_ids')) {
    function get_uppercat_ids(array $catIds): array
    {
        $conn = DbConnection::build();

        return new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        )->getUppercatIds($catIds);
    }
}

/**
 * Change the **visible** property on a set of categories.
 *
 * @param int[] $categories
 */
if (! function_exists('set_cat_visible')) {
    function set_cat_visible(array $categories, bool|string $value, bool $unlockChild = false): ?false
    {
        $conn = DbConnection::build();

        return new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        )->setCatVisible($categories, $value, $unlockChild);
    }
}

/**
 * Change the **status** property on a set of categories : private or public.
 *
 * @param int[] $categories
 */
if (! function_exists('set_cat_status')) {
    function set_cat_status(array $categories, string $value): ?false
    {
        $conn = DbConnection::build();

        return new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        )->setCatStatus($categories, $value);
    }
}

/**
 * Set a new random representant to the categories.
 *
 * @param int[] $categories
 */
if (! function_exists('set_random_representant')) {
    function set_random_representant(array $categories): void
    {
        $conn = DbConnection::build();

        new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        )->setRandomRepresentant($categories);
    }
}

/**
 * Create a virtual category.
 *
 * @param array{commentable?: mixed, visible?: mixed, status?: mixed, comment?: mixed, inherit?: mixed} $options
 * @return array{error: string}|array{info: string, id: int|string}
 */
if (! function_exists('create_virtual_category')) {
    function create_virtual_category(string $categoryName, int|string|null $parentId = null, array $options = []): array
    {
        $conn = DbConnection::build();

        return new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        )->createVirtualCategory($categoryName, new ActivityService(new ActivityRepository($conn)), $parentId, $options);
    }
}
