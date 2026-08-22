<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Category;

use PHPUnit\Framework\TestCase;
use Piwigo\Category\CategoryService;
use Piwigo\Tests\Support\CategoryInfoTestFactory;

/**
 * CategoryService::filterMenuRows() is the PHP-side equivalent of
 * get_categories_menu()'s two SQL WHERE branches. Pure function, no
 * DB/globals needed -- see tests/Integration/CategoryTreeCacheTest.php for
 * the permission-filtered row-set coverage.
 */
final class CategoryServiceFilterMenuRowsTest extends TestCase
{
    private const array ALL_ROWS = [
        1 => [
            'cat_id' => 1,
            'id_uppercat' => null,
            'global_rank' => '1',
            'rank' => 1,
            'date_last' => null,
            'nb_images' => 0,
            'user_id' => 1,
            'nb_categories' => 2,
            'count_categories' => 2,
            'count_images' => 0,
            'max_date_last' => null,
            'name' => 'Sample Album',
            'permalink' => null,
            'id' => 1,
        ],
        2 => [
            'cat_id' => 2,
            'id_uppercat' => 1,
            'global_rank' => '1.2',
            'rank' => 1,
            'date_last' => null,
            'nb_images' => 0,
            'user_id' => 1,
            'nb_categories' => 0,
            'count_categories' => 0,
            'count_images' => 0,
            'max_date_last' => null,
            'name' => 'Nested Sub Album',
            'permalink' => null,
            'id' => 2,
        ],
        3 => [
            'cat_id' => 3,
            'id_uppercat' => 1,
            'global_rank' => '1.3',
            'rank' => 2,
            'date_last' => null,
            'nb_images' => 0,
            'user_id' => 1,
            'nb_categories' => 0,
            'count_categories' => 0,
            'count_images' => 0,
            'max_date_last' => null,
            'name' => 'Sibling Album',
            'permalink' => null,
            'id' => 3,
        ],
    ];

    public function testCollapsedViewKeepsOnlyRootCategoriesWhenNoCategoryIsViewed(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, false, false, '');

        self::assertSame([1], array_keys($rows));
    }

    public function testCollapsedViewAlsoKeepsDirectChildrenOfTheViewedCategory(): void
    {
        $rows = CategoryService::filterMenuRows(
            self::ALL_ROWS,
            CategoryInfoTestFactory::build(id: 1, uppercats: '1'),
            false,
            false,
            ''
        );

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function testExpandedViewShowsEveryRowWhenNoFilterIsActive(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, true, false, '');

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function testFilterEnabledForcesExpandedViewEvenWithoutTheExpandPreference(): void
    {
        // Matches the original SQL branch condition exactly: "always
        // expand when filter is activated" applies regardless of the
        // user's own 'expand' preference.
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, false, true, '');

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function testVisibleCategoriesFilterRestrictsToTheListedIds(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, true, false, '1,3');

        self::assertSame([1, 3], array_keys($rows));
    }

    public function testVisibleCategoriesFilterCanExcludeEveryRow(): void
    {
        $rows = CategoryService::filterMenuRows(self::ALL_ROWS, null, true, false, '999');

        self::assertSame([], $rows);
    }
}
