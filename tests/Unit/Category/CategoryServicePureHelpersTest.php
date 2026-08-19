<?php

declare(strict_types=1);

use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\ComputedCategoryRow;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Lang\Translator;
use Piwigo\Tests\Support\HtmlServiceTestFactory;

// CategoryService's own DB-backed methods are covered by
// tests/Integration/CategoryServiceTest.php -- this file covers its
// pure, static, dependency-free helpers instead (comparators + a couple
// of pure computation functions extracted specifically to be testable
// without the free functions' former globals -- see each method's own
// docblock).

/**
 * getDisplayImagesCount() takes Lang as an explicit parameter -- no
 * Kernel::boot() anywhere in this file, so a bare, never-.load()'d
 * instance is enough to satisfy the type. Translator::plural()'s own
 * ngettext() call falls back to the raw singular/plural English text
 * passed in when nothing has been loaded, which is exactly what these
 * tests assert against.
 */
function category_service_pure_helpers_test_lang(): Lang
{
    return new Lang(new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

test('compareByGlobalRank sorts naturally by the global_rank string', function (): void {
    expect(CategoryService::compareByGlobalRank([
        'global_rank' => '1.2',
    ], [
        'global_rank' => '1.10',
    ]))->toBeLessThan(0);
    expect(CategoryService::compareByGlobalRank([
        'global_rank' => '1.10',
    ], [
        'global_rank' => '1.2',
    ]))->toBeGreaterThan(0);
    expect(CategoryService::compareByGlobalRank([
        'global_rank' => null,
    ], [
        'global_rank' => null,
    ]))->toBe(0);
});

test('compareByGlobalRank casts non-string scalars to string before comparing', function (): void {
    // Both sides are ints here (is_scalar() but not string) -- strnatcasecmp()
    // requires strings, and this file is declare(strict_types=1), so if
    // either (string) cast were dropped this would throw a TypeError
    // instead of comparing naturally.
    expect(CategoryService::compareByGlobalRank([
        'global_rank' => 5,
    ], [
        'global_rank' => 10,
    ]))->toBeLessThan(0);
});

test('compareByRank sorts numerically by the rank column, treating non-numeric as 0', function (): void {
    expect(CategoryService::compareByRank([
        'rank' => 5,
    ], [
        'rank' => 2,
    ]))->toBe(3);
    expect(CategoryService::compareByRank([
        'rank' => 2,
    ], [
        'rank' => 5,
    ]))->toBe(-3);
    expect(CategoryService::compareByRank([
        'rank' => 'not-numeric',
    ], [
        'rank' => 3,
    ]))->toBe(-3);
});

test('compareByRank truncates fractional ranks on both sides via the (int) cast', function (): void {
    // Fractional values force the (int) cast to actually narrow something --
    // without it, subtracting two floats returns a float, which the method's
    // own `: int` return type (under strict_types=1) rejects with a TypeError.
    expect(CategoryService::compareByRank([
        'rank' => 5.9,
    ], [
        'rank' => 2.9,
    ]))->toBe(3);
});

test('compareByRank falls back to 0 for a non-numeric rank on the right side too', function (): void {
    expect(CategoryService::compareByRank([
        'rank' => 5,
    ], [
        'rank' => 'bogus',
    ]))->toBe(5);
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

test('isRecentCategory is false when last_photo_date is an empty string', function (): void {
    $now = new DateTimeImmutable('2026-06-15');

    expect(CategoryService::isRecentCategory('2026-06-14', 7, '', $now))->toBeFalse();
});

test('isRecentCategory zeroes out the today-threshold time-of-day down to the second', function (): void {
    $now = new DateTimeImmutable('2026-06-15');

    // thresholdFromToday = today (zeroed to midnight) - 5 days = 2026-06-10
    // 00:00:00. last_photo_date is far enough in the future that its own
    // threshold never wins, so thresholdFromToday alone decides the result.
    // date_last sits exactly 1 second before that threshold: correct if
    // setTime() truly zeroes hour/minute/second, but >= the threshold (so
    // "recent") if any of the three were left un-zeroed (each rolls the
    // date back an extra day, past this date_last).
    expect(CategoryService::isRecentCategory('2026-06-09 23:59:59', 5, '2026-06-15', $now))->toBeFalse();
});

function catMenuRow(int $id, ?int $idUppercat): ComputedCategoryRow
{
    return new ComputedCategoryRow(
        catId: $id,
        idUppercat: $idUppercat,
        globalRank: null,
        rank: null,
        dateLast: null,
        nbImages: 0,
        userId: 0,
        name: 'Category ' . $id,
    );
}

test('filterMenuRows returns every row unfiltered when expanded and no visible-categories filter is active', function (): void {
    // expand=true skips the uppercat-restriction branch entirely (it only
    // applies when BOTH expand and filterEnabled are false); an empty
    // visibleCategoriesCsv then short-circuits the second branch too.
    $rows = [catMenuRow(1, null), catMenuRow(2, 1)];

    $result = CategoryService::filterMenuRows($rows, null, true, false, '');

    expect($result)
        ->toBe($rows);
});

test('filterMenuRows restricts to direct children of the current category page when not expanded and not filtered', function (): void {
    $rows = [
        catMenuRow(1, null),
        catMenuRow(2, 5),
        catMenuRow(3, 7),
    ];
    $categoryPage = [
        'uppercats' => '5,9',
    ];

    $result = CategoryService::filterMenuRows($rows, $categoryPage, false, false, '');

    // Row 1 (top-level, id_uppercat null) always passes; row 2's
    // id_uppercat (5) is in the page's own uppercats chain; row 3's (7)
    // is not.
    expect(array_values(array_map(static fn (ComputedCategoryRow $r): int => $r->catId, $result)))
        ->toBe([1, 2]);
});

test('filterMenuRows restricts to the visible-categories csv when a filter is active', function (): void {
    $rows = [catMenuRow(1, null), catMenuRow(2, null), catMenuRow(3, null)];

    $result = CategoryService::filterMenuRows($rows, null, true, true, '1,3');

    expect(array_values(array_map(static fn (ComputedCategoryRow $r): int => $r->catId, $result)))
        ->toBe([1, 3]);
});

test('filterMenuRows treats a categoryPage with no uppercats key as having no uppercat restriction', function (): void {
    // categoryPage !== null (true) but is_scalar($uppercatsRaw) is false
    // (the key is missing, so it's null) -- only row 1 (top-level) should
    // pass; row 2 must NOT be pulled in via an empty/zero uppercatIds list.
    $rows = [catMenuRow(1, null), catMenuRow(2, 0)];

    $result = CategoryService::filterMenuRows($rows, [], false, false, '');

    expect(array_values(array_map(static fn (ComputedCategoryRow $r): int => $r->catId, $result)))
        ->toBe([1]);
});

test('filterMenuRows treats an empty-string uppercats value the same as absent', function (): void {
    $rows = [catMenuRow(1, null), catMenuRow(2, 0)];

    $result = CategoryService::filterMenuRows($rows, [
        'uppercats' => '',
    ], false, false, '');

    expect(array_values(array_map(static fn (ComputedCategoryRow $r): int => $r->catId, $result)))
        ->toBe([1]);
});

test('filterMenuRows string-casts a non-string scalar uppercats value before exploding it', function (): void {
    // uppercats=0 (int, scalar, !== '') takes the restriction branch and
    // must produce uppercatIds=[0] via the (string) cast -- without it,
    // explode() would receive a raw int under strict_types and throw.
    $rows = [catMenuRow(1, null), catMenuRow(2, 0)];

    $result = CategoryService::filterMenuRows($rows, [
        'uppercats' => 0,
    ], false, false, '');

    expect(array_values(array_map(static fn (ComputedCategoryRow $r): int => $r->catId, $result)))
        ->toBe([1, 2]);
});

// A mutation-testing sweep found 3 confirmed-equivalent mutants inside
// getDisplayImagesCount()'s recursive direct/remainder split, none worth
// chasing further:
// - The `.=` building $displayText from the recursive call's own return
//   value can't be told apart from a mutated `=`: $displayText is always
//   '' at that exact point (its own first write in the method), so
//   appending to '' and assigning outright produce the identical string.
// - The recursive call's own literal `0` third argument
//   (`self::getDisplayImagesCount($catNbImages, $catNbImages, 0, ...)`)
//   is unobservable regardless of what it's mutated to: that call's
//   first two arguments are always the *same* value, so inside that
//   invocation `$catNbImages === $catCountImages` is unconditionally
//   true, which alone satisfies line 341's `||` regardless of
//   $catCountCategories -- the sub-album branch can never fire from this
//   specific recursive call shape, no matter what value reaches it.
// - `$catNbImages = 0;` right after the split: the only later read of
//   $catNbImages is that same `===` comparison against the post-split
//   $catCountImages, which is always strictly positive at that point
//   (the split only runs when the original $catNbImages was strictly
//   less than $catCountImages) -- 0 and a mutated -1 are equally never
//   equal to it.
test('getDisplayImagesCount reports a flat photo count when there are no sub-albums', function (): void {
    $result = CategoryService::getDisplayImagesCount(category_service_pure_helpers_test_lang(), 0, 12, 0);

    expect($result)
        ->toBe('12 photos');
});

test('getDisplayImagesCount returns an empty string when there are no images at all', function (): void {
    expect(CategoryService::getDisplayImagesCount(category_service_pure_helpers_test_lang(), 0, 0, 0))->toBe('');
});

test('getDisplayImagesCount uses the singular form for exactly one image', function (): void {
    expect(CategoryService::getDisplayImagesCount(category_service_pure_helpers_test_lang(), 0, 1, 0))->toBe('1 photo');
});

test('getDisplayImagesCount splits direct vs sub-album counts when both exist', function (): void {
    // 5 photos directly in this album, 20 total (including sub-albums) --
    // the recursive self-call formats the direct-count portion first.
    $result = CategoryService::getDisplayImagesCount(category_service_pure_helpers_test_lang(), 5, 20, 2, true, ' | ');

    expect($result)
        ->toBe('5 photos | 15 photos in 2 sub-albums');
});

test('getDisplayImagesCount only recurses into the direct-count split when direct images are strictly fewer than the total', function (): void {
    // 1 direct photo out of 5 total -- exercises the recursive branch with
    // singular phrasing on the direct-count part and plural on the
    // remainder, pinned to an exact string so the recursive call's
    // concatenation order/separator can't silently drop or reorder.
    expect(CategoryService::getDisplayImagesCount(category_service_pure_helpers_test_lang(), 1, 5, 0, true, '-'))->toBe('1 photo-4 photos');
});

test('getDisplayImagesCount reports sub-albums after a direct/remainder split when they land on the same count', function (): void {
    // 3 direct out of 4 total leaves exactly 1 remaining "sub-album" photo --
    // the post-split catNbImages (0) must NOT equal the post-split
    // catCountImages (1), or the sub-album text would be wrongly skipped.
    expect(CategoryService::getDisplayImagesCount(category_service_pure_helpers_test_lang(), 3, 4, 2, true, '-'))
        ->toBe('3 photos-1 photo in 2 sub-albums');
});

function catComputedRow(int $id, ?int $idUppercat, int $nbCategories, int $countImages, int $countCategories, int $nbImages = 0): ComputedCategoryRow
{
    return new ComputedCategoryRow(
        catId: $id,
        idUppercat: $idUppercat,
        globalRank: null,
        rank: null,
        dateLast: null,
        nbImages: $nbImages,
        userId: 0,
        nbCategories: $nbCategories,
        countCategories: $countCategories,
        countImages: $countImages,
    );
}

test('removeComputedCategory decrements the parent\'s own counters and bubbles up to grandparents', function (): void {
    $cats = [
        1 => catComputedRow(1, null, 2, 100, 5),
        2 => catComputedRow(2, 1, 1, 40, 1),
    ];
    $removed = catComputedRow(3, 2, 0, 10, 0, nbImages: 10);

    CategoryService::removeComputedCategory($cats, $removed);

    // Direct parent (id=2): nb_categories decremented, its own
    // count_images/count_categories reduced by the removed leaf's counts.
    expect($cats[2]->nbCategories)->toBe(0);
    expect($cats[2]->countImages)->toBe(30);
    expect($cats[2]->countCategories)->toBe(0);

    // Grandparent (id=1) bubbles up the same count_images/count_categories
    // reduction, but its own nb_categories (direct-children count) is
    // untouched -- only the immediate parent's nb_categories changes.
    expect($cats[1]->nbCategories)->toBe(2);
    expect($cats[1]->countImages)->toBe(90);
    expect($cats[1]->countCategories)->toBe(4);
});

test('removeComputedCategory subtracts both the removed leaf itself and its own sub-category count from the parent', function (): void {
    // The removed category's own count_categories (4) must be added to the
    // flat "1" for the leaf itself -- 1 + 4, not 1 - 4.
    $cats = [
        1 => catComputedRow(1, null, 3, 50, 10),
    ];
    $removed = catComputedRow(2, 1, 0, 5, 4, nbImages: 5);

    CategoryService::removeComputedCategory($cats, $removed);

    expect($cats[1]->countCategories)->toBe(5);
});

test('removeComputedCategory does nothing when the category has no known parent in the map', function (): void {
    $cats = [
        1 => catComputedRow(1, null, 3, 50, 2),
    ];
    $original = $cats[1];
    $removed = catComputedRow(9, null, 0, 5, 0, nbImages: 5);

    CategoryService::removeComputedCategory($cats, $removed);

    expect($cats[1])->toBe($original);
});
