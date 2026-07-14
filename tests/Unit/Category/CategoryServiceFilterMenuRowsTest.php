<?php

declare(strict_types=1);

use Piwigo\Category\CategoryService;

/**
 * CategoryService::filterMenuRows() -- the PHP-side replacement (P23 batch
 * 3b) for get_categories_menu()'s two SQL WHERE branches. Pure function, no
 * DB/globals needed -- see tests/Integration/CategoryTreeCacheTest.php for
 * the permission-filtered row-set half of this same batch's change.
 */
final class CategoryServiceFilterMenuRowsTest extends \PHPUnit\Framework\TestCase
{
    private const array ALL_ROWS = [
        1 => ['id' => 1, 'id_uppercat' => null, 'name' => 'Sample Album'],
        2 => ['id' => 2, 'id_uppercat' => 1, 'name' => 'Nested Sub Album'],
        3 => ['id' => 3, 'id_uppercat' => 1, 'name' => 'Sibling Album'],
    ];

    public function test_collapsed_view_keeps_only_root_categories_when_no_category_is_viewed(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, false, false, '');

        self::assertSame([1], array_keys($rows));
    }

    public function test_collapsed_view_also_keeps_direct_children_of_the_viewed_category(): void
    {
        $rows = CategoryService::filterMenuRows(
            self::ALL_ROWS,
            ['id' => 1, 'uppercats' => '1'],
            false,
            false,
            ''
        );

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function test_expanded_view_shows_every_row_when_no_filter_is_active(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, true, false, '');

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function test_filter_enabled_forces_expanded_view_even_without_the_expand_preference(): void
    {
        // Matches the original SQL branch condition exactly: "always
        // expand when filter is activated" applies regardless of the
        // user's own 'expand' preference.
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, false, true, '');

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function test_visible_categories_filter_restricts_to_the_listed_ids(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, true, false, '1,3');

        self::assertSame([1, 3], array_keys($rows));
    }

    public function test_visible_categories_filter_can_exclude_every_row(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, true, false, '999');

        self::assertSame([], $rows);
    }
}
