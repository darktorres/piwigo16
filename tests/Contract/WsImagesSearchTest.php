<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

/**
 * Ws\Images::search() (pwg.images.search) -- WsImagesFilteredSearchTest
 * covers the sibling filteredSearch.create() endpoint, but nothing exercised
 * search() itself yet. Covers the `order` param branch (super_order_by /
 * CurrentConfig::setOrderBy()) and the general non-empty-results shape.
 *
 * Assertions here don't pin an exact result count or list: this Contract
 * suite runs against a live, shared dev DB that other test files also
 * mutate (confirmed live -- a plain "Photo" query already matched several
 * non-fixture rows left over from other suites), so only order and
 * membership are asserted, never a fixed total.
 *
 * search()'s own `! is_array($search_items)` fallback (defends
 * SearchService::getQuickSearchResults()'s 'items' key, widened to `mixed`
 * only because a `qsearch_results` plugin hook could theoretically replace
 * it) is NOT chased here: getQuickSearchResultsNoCache() already
 * re-normalizes 'items' back to a real array immediately after firing that
 * exact hook (`is_array(...) ? array_values(...) : []`), and unconditionally
 * overwrites it with a guaranteed array (`$ids`) again before returning --
 * no legitimate WS-level request can make it anything else, and v17
 * doesn't load any plugin that could register a rogue handler for that hook
 * anyway (see project memory: "v17.0 intentionally breaks all PEM
 * extensions").
 */
final class WsImagesSearchTest extends ContractTestCase
{
    public function testSearchWithoutOrderReturnsAValidPagingShape(): void
    {
        $response = $this->ws('pwg.images.search', [
            'query' => 'Photo 1',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $paging = $result['paging'];
        self::assertIsArray($paging);
        self::assertSame(0, $paging['page']);
        self::assertSame(100, $paging['per_page']);
        self::assertIsArray($result['images']);
        self::assertGreaterThanOrEqual(1, $paging['count']);
    }

    public function testSearchWithOrderByNameSortsResultsAscending(): void
    {
        // 'order' non-empty -> OrderBy::fromWsOrderParam() returns a
        // non-empty OrderBy, which flips search()'s own super_order_by
        // branch (CurrentConfig::setOrderBy() + $super_order_by = true).
        // fromWsOrderParam()'s own regex expects "field direction"
        // (space-separated), not "field,direction" -- confirmed live: a
        // comma splits into two independent tokens, and a direction word
        // alone ("ASC"/"DESC") isn't a recognized sortable field, so it's
        // silently dropped rather than erroring.
        $response = $this->ws('pwg.images.search', [
            'query' => 'Photo',
            'order' => 'name asc',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);
        self::assertGreaterThanOrEqual(2, count($images), 'need at least 2 matches to prove a sort order');

        $names = [];
        foreach ($images as $image) {
            self::assertIsArray($image);
            self::assertIsString($image['name']);
            $names[] = $image['name'];
        }
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names, 'images must come back sorted by name ascending');

        // Fixture image 1 ("Photo 1") must be among the matches -- proves the
        // search really ran against real data, not an empty/cached result.
        $ids = array_column($images, 'id');
        self::assertContains(1, $ids);
    }

    public function testSearchWithOrderByIdDescSortsResultsDescending(): void
    {
        $response = $this->ws('pwg.images.search', [
            'query' => 'Photo',
            'order' => 'id desc',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);
        self::assertGreaterThanOrEqual(2, count($images));

        $ids = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($images, 'id'));
        $sortedDesc = $ids;
        rsort($sortedDesc, SORT_NUMERIC);
        self::assertSame($sortedDesc, $ids);
    }
}
