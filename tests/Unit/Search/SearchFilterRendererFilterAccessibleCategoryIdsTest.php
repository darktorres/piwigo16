<?php

declare(strict_types=1);

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
}
