<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Category;

use PHPUnit\Framework\TestCase;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\ComputedCategoryRow;
use Piwigo\Tests\Support\CategoryInfoTestFactory;

/**
 * CategoryService::filterMenuRows() is the PHP-side equivalent of
 * get_categories_menu()'s two SQL WHERE branches. Pure function, no
 * DB/globals needed -- see tests/Integration/CategoryTreeCacheTest.php for
 * the permission-filtered row-set coverage.
 */
final class CategoryServiceFilterMenuRowsTest extends TestCase
{
    /**
     * @return array<int, ComputedCategoryRow>
     */
    private static function allRows(): array
    {
        return [
            1 => new ComputedCategoryRow(
                catId: 1,
                idUppercat: null,
                globalRank: '1',
                rank: 1,
                dateLast: null,
                nbImages: 0,
                userId: 1,
                nbCategories: 2,
                countCategories: 2,
                name: 'Sample Album',
            ),
            2 => new ComputedCategoryRow(
                catId: 2,
                idUppercat: 1,
                globalRank: '1.2',
                rank: 1,
                dateLast: null,
                nbImages: 0,
                userId: 1,
                name: 'Nested Sub Album',
            ),
            3 => new ComputedCategoryRow(
                catId: 3,
                idUppercat: 1,
                globalRank: '1.3',
                rank: 2,
                dateLast: null,
                nbImages: 0,
                userId: 1,
                name: 'Sibling Album',
            ),
        ];
    }

    public function testCollapsedViewKeepsOnlyRootCategoriesWhenNoCategoryIsViewed(): void
    {
        $rows = CategoryService::filterMenuRows(self::allRows(), null, false, false, '');

        self::assertSame([1], array_keys($rows));
    }

    public function testCollapsedViewAlsoKeepsDirectChildrenOfTheViewedCategory(): void
    {
        $rows = CategoryService::filterMenuRows(
            self::allRows(),
            CategoryInfoTestFactory::build(id: 1, uppercats: '1'),
            false,
            false,
            ''
        );

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function testExpandedViewShowsEveryRowWhenNoFilterIsActive(): void
    {
        $rows = CategoryService::filterMenuRows(self::allRows(), null, true, false, '');

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function testFilterEnabledForcesExpandedViewEvenWithoutTheExpandPreference(): void
    {
        // Matches the original SQL branch condition exactly: "always
        // expand when filter is activated" applies regardless of the
        // user's own 'expand' preference.
        $rows = CategoryService::filterMenuRows(self::allRows(), null, false, true, '');

        self::assertSame([1, 2, 3], array_keys($rows));
    }

    public function testVisibleCategoriesFilterRestrictsToTheListedIds(): void
    {
        $rows = CategoryService::filterMenuRows(self::allRows(), null, true, false, '1,3');

        self::assertSame([1, 3], array_keys($rows));
    }

    public function testVisibleCategoriesFilterCanExcludeEveryRow(): void
    {
        $rows = CategoryService::filterMenuRows(self::allRows(), null, true, false, '999');

        self::assertSame([], $rows);
    }
}
