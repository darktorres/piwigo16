<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use Piwigo\Search\SearchFilterRenderer;

/**
 * SearchFilterRenderer::filterAccessibleCategoryIds() -- P23 batch 4c's real
 * fix: search_filters.inc.php's "ALBUMS_FOUND" block's own
 * `user_cache_categories` JOIN was the actual permission filter (confirmed
 * SearchService::searchAllwords()'s category-name/comment match applies no
 * forbidden-categories condition of its own), so a search hit on a
 * forbidden category's name must never reach the display list. Pure
 * function, no DB/globals needed.
 */
final class SearchFilterRendererFilterAccessibleCategoryIdsTest extends \PHPUnit\Framework\TestCase
{
    public function test_excludes_a_forbidden_category_id(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([1, 2, 3], '2');

        self::assertSame([1, 3], $result);
    }

    public function test_excludes_multiple_forbidden_category_ids(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([1, 2, 3, 4], '2,4');

        self::assertSame([1, 3], $result);
    }

    public function test_keeps_every_id_when_forbidden_categories_is_null(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([1, 2, 3], null);

        self::assertSame([1, 2, 3], $result);
    }

    public function test_keeps_every_id_when_forbidden_categories_is_empty_string(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([1, 2, 3], '');

        self::assertSame([1, 2, 3], $result);
    }

    public function test_returns_empty_when_every_id_is_forbidden(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([1, 2], '1,2');

        self::assertSame([], $result);
    }

    public function test_returns_empty_for_empty_cat_ids(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([], '5');

        self::assertSame([], $result);
    }

    /**
     * Guards the `!== ''` short-circuit itself (as opposed to
     * test_keeps_every_id_when_forbidden_categories_is_empty_string(),
     * which only proves the *end result* looks like "no filtering"). If the
     * empty-string guard were ever weakened so that an empty
     * $forbiddenCategoriesCsv fell through to
     * `explode(',', '')` -- which yields `['']` -- `array_map(intval(...),
     * ...)` would coerce that lone '' element to catId 0, and 0 would then
     * wrongly be diffed out of the result. Using catId 0 here is what
     * makes that specific failure observable.
     */
    public function test_keeps_cat_id_zero_when_forbidden_categories_is_empty_string(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([0, 1, 2], '');

        self::assertSame([0, 1, 2], $result);
    }

    /**
     * Proves array_map(intval(...), ...) is doing real int coercion, not
     * just carrying the exploded strings through unchanged. array_diff()
     * compares by (string) cast, so canonical numeric strings like '2'
     * already match an int catId of 2 without any intval() help -- that's
     * why the other tests here don't catch a dropped intval(). Whitespace
     * (' 3 ') and a leading zero ('04') are non-canonical: their string
     * form never equals (string) 3 / (string) 4, so only a real intval()
     * coercion removes catIds 3 and 4 from the result.
     */
    public function test_forbidden_ids_are_int_coerced_not_compared_as_raw_strings(): void
    {
        $result = SearchFilterRenderer::filterAccessibleCategoryIds([3, 4, 5], ' 3 ,04');

        self::assertSame([5], $result);
    }
}
