<?php

declare(strict_types=1);

use Piwigo\Category\CategoryService;

// CategoryService's own DB-backed methods are covered by
// tests/Integration/CategoryServiceTest.php -- this file covers its
// pure, static, dependency-free helpers instead (comparators + a couple
// of pure computation functions extracted specifically to be testable
// without the free functions' former globals -- see each method's own
// docblock).

test('compareByGlobalRank sorts naturally by the global_rank string', function (): void {
    expect(CategoryService::compareByGlobalRank(['global_rank' => '1.2'], ['global_rank' => '1.10']))->toBeLessThan(0);
    expect(CategoryService::compareByGlobalRank(['global_rank' => '1.10'], ['global_rank' => '1.2']))->toBeGreaterThan(0);
    expect(CategoryService::compareByGlobalRank(['global_rank' => null], ['global_rank' => null]))->toBe(0);
});

test('compareByRank sorts numerically by the rank column, treating non-numeric as 0', function (): void {
    expect(CategoryService::compareByRank(['rank' => 5], ['rank' => 2]))->toBe(3);
    expect(CategoryService::compareByRank(['rank' => 2], ['rank' => 5]))->toBe(-3);
    expect(CategoryService::compareByRank(['rank' => 'not-numeric'], ['rank' => 3]))->toBe(-3);
});

test('isRecentCategory is false when either date is null or empty', function (): void {
    $now = new DateTimeImmutable('2026-06-15');

    expect(CategoryService::isRecentCategory(null, 7, '2026-06-14', $now))->toBeFalse();
    expect(CategoryService::isRecentCategory('2026-06-14', 7, null, $now))->toBeFalse();
    expect(CategoryService::isRecentCategory('', 7, '2026-06-14', $now))->toBeFalse();
});

test('isRecentCategory is true when the category date is within the recent-period threshold', function (): void {
    $now = new DateTimeImmutable('2026-06-15');

    // 3 days ago, recent_period=7 -> well within the last-7-days threshold.
    expect(CategoryService::isRecentCategory('2026-06-12', 7, '2026-06-14', $now))->toBeTrue();
});

test('isRecentCategory is false when the category date is older than both thresholds', function (): void {
    $now = new DateTimeImmutable('2026-06-15');

    // 30 days ago, recent_period=7, last_photo_date recent -> outside both
    // the today-minus-period AND the last-photo-minus-1-day thresholds.
    expect(CategoryService::isRecentCategory('2026-05-01', 7, '2026-06-14', $now))->toBeFalse();
});

/**
 * @return array{cat_id: int, id_uppercat: ?int, global_rank: ?string,
 *   rank: ?int, date_last: ?string, nb_images: int, user_id: mixed,
 *   nb_categories: int, count_categories: int, count_images: int,
 *   max_date_last: ?string, name: string, permalink: ?string, id: int}
 */
function catMenuRow(int $id, ?int $idUppercat): array
{
    return [
        'cat_id' => $id,
        'id_uppercat' => $idUppercat,
        'global_rank' => null,
        'rank' => null,
        'date_last' => null,
        'nb_images' => 0,
        'user_id' => null,
        'nb_categories' => 0,
        'count_categories' => 0,
        'count_images' => 0,
        'max_date_last' => null,
        'name' => 'Category ' . $id,
        'permalink' => null,
        'id' => $id,
    ];
}

test('filterMenuRows returns every row unfiltered when expanded and no visible-categories filter is active', function (): void {
    // expand=true skips the uppercat-restriction branch entirely (it only
    // applies when BOTH expand and filterEnabled are false); an empty
    // visibleCategoriesCsv then short-circuits the second branch too.
    $rows = [catMenuRow(1, null), catMenuRow(2, 1)];

    $result = CategoryService::filterMenuRows($rows, null, true, false, '');

    expect($result)->toBe($rows);
});

test('filterMenuRows restricts to direct children of the current category page when not expanded and not filtered', function (): void {
    $rows = [
        catMenuRow(1, null),
        catMenuRow(2, 5),
        catMenuRow(3, 7),
    ];
    $categoryPage = ['uppercats' => '5,9'];

    $result = CategoryService::filterMenuRows($rows, $categoryPage, false, false, '');

    // Row 1 (top-level, id_uppercat null) always passes; row 2's
    // id_uppercat (5) is in the page's own uppercats chain; row 3's (7)
    // is not.
    expect(array_column($result, 'id'))->toBe([1, 2]);
});

test('filterMenuRows restricts to the visible-categories csv when a filter is active', function (): void {
    $rows = [catMenuRow(1, null), catMenuRow(2, null), catMenuRow(3, null)];

    $result = CategoryService::filterMenuRows($rows, null, true, true, '1,3');

    expect(array_column($result, 'id'))->toBe([1, 3]);
});

test('getDisplayImagesCount reports a flat photo count when there are no sub-albums', function (): void {
    $result = CategoryService::getDisplayImagesCount(0, 12, 0);

    expect($result)->toBe('12 photos');
});

test('getDisplayImagesCount splits direct vs sub-album counts when both exist', function (): void {
    // 5 photos directly in this album, 20 total (including sub-albums) --
    // the recursive self-call formats the direct-count portion first.
    $result = CategoryService::getDisplayImagesCount(5, 20, 2, true, ' | ');

    expect($result)->toContain('5 photos');
    expect($result)->toContain('15 photos');
});

/**
 * @return array{cat_id: int, id_uppercat: ?int, global_rank: ?string,
 *   rank: ?int, date_last: ?string, nb_images: int, user_id: mixed,
 *   nb_categories: int, count_categories: int, count_images: int,
 *   max_date_last: ?string}
 */
function catComputedRow(int $id, ?int $idUppercat, int $nbCategories, int $countImages, int $countCategories): array
{
    return [
        'cat_id' => $id,
        'id_uppercat' => $idUppercat,
        'global_rank' => null,
        'rank' => null,
        'date_last' => null,
        'nb_images' => 0,
        'user_id' => null,
        'nb_categories' => $nbCategories,
        'count_categories' => $countCategories,
        'count_images' => $countImages,
        'max_date_last' => null,
    ];
}

test('removeComputedCategory decrements the parent\'s own counters and bubbles up to grandparents', function (): void {
    $cats = [
        1 => catComputedRow(1, null, 2, 100, 5),
        2 => catComputedRow(2, 1, 1, 40, 1),
    ];
    $removed = catComputedRow(3, 2, 0, 10, 0);
    $removed['nb_images'] = 10;

    CategoryService::removeComputedCategory($cats, $removed);

    // Direct parent (id=2): nb_categories decremented, its own
    // count_images/count_categories reduced by the removed leaf's counts.
    expect($cats[2]['nb_categories'])->toBe(0);
    expect($cats[2]['count_images'])->toBe(30);
    expect($cats[2]['count_categories'])->toBe(0);

    // Grandparent (id=1) bubbles up the same count_images/count_categories
    // reduction, but its own nb_categories (direct-children count) is
    // untouched -- only the immediate parent's nb_categories changes.
    expect($cats[1]['nb_categories'])->toBe(2);
    expect($cats[1]['count_images'])->toBe(90);
    expect($cats[1]['count_categories'])->toBe(4);
});

test('removeComputedCategory does nothing when the category has no known parent in the map', function (): void {
    $cats = [1 => catComputedRow(1, null, 3, 50, 2)];
    $original = $cats[1];
    $removed = catComputedRow(9, null, 0, 5, 0);
    $removed['nb_images'] = 5;

    CategoryService::removeComputedCategory($cats, $removed);

    expect($cats[1])->toBe($original);
});
