<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

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
    function get_subcat_ids($ids): array
    {
        $conn = DbConnection::build();

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
