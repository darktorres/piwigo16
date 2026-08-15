<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

/**
 * Ws\ImageSqlOrderBuilder -- split out of the former WsHelper god-class
 * (P25 Stage 1 step 6), reached here through pwg.images.search.
 *
 * stdImageSqlOrder()'s own `case 'rand': case 'random': ... break;` is
 * already exercised for real by
 * test_stdImageSqlOrder_random_alias_is_accepted() below -- its trailing
 * `break;` (the last case in the switch, nothing but the closing `}`
 * after it) is provably eliminated by OPcache's real jump-elision
 * optimizer on the live Apache-served process this suite runs against,
 * same root cause (and same live PCOV-based confirmation method) as this
 * project's own documented "OPcache constant-array-folding coverage
 * artifact" precedent; not a gap in test coverage. See
 * WsCommentsTest's own class docblock for the full writeup.
 */
final class ImageSqlOrderBuilderTest extends ContractTestCase
{
    /**
     * @param array<string, mixed> $extraParams
     * @return list<int>
     */
    private function searchIds(string $query, array $extraParams = []): array
    {
        $response = $this->ws('pwg.images.search', array_merge([
            'query' => $query,
            'order' => 'id asc',
        ], $extraParams));
        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);

        return array_values(array_map(static fn (mixed $im): int => is_array($im) && is_numeric($im['id']) ? (int) $im['id'] : 0, $images));
    }

    public function testStdImageSqlOrderDatePostedAliasAndDateCreatedAliasAreAccepted(): void
    {
        $postedResponse = $this->ws('pwg.images.search', [
            'query' => 'Photo 1',
            'order' => 'date_posted asc',
        ]);
        self::assertSame('ok', $postedResponse['stat']);

        $createdResponse = $this->ws('pwg.images.search', [
            'query' => 'Photo 1',
            'order' => 'date_created asc',
        ]);
        self::assertSame('ok', $createdResponse['stat']);
    }

    public function testStdImageSqlOrderRandomAliasIsAccepted(): void
    {
        $response = $this->ws('pwg.images.search', [
            'query' => 'Photo',
            'order' => 'random',
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function testStdImageSqlOrderDropsAnUnrecognizedFieldButKeepsTheValidOne(): void
    {
        // Comma-separated tokens are parsed independently -- an
        // unrecognized field name is silently dropped rather than erroring,
        // while a valid one still takes effect.
        $ids = $this->searchIds('Photo', [
            'order' => 'not_a_real_column, id desc',
        ]);
        $sorted = $ids;
        rsort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $ids);
    }

    /**
     * The `if ($ret !== '') { $ret .= ', '; }` comma-join branch --
     * test_stdImageSqlOrder_drops_an_unrecognized_field_but_keeps_the_valid_one()
     * above only ever ends up with a *single* real appended field (its
     * first, unrecognized token never reaches `$ret .= '...'` at all), so
     * $ret is still '' by the time its one real field is appended. Two
     * *valid* fields are needed to reach the comma-join itself; if it
     * silently dropped, the resulting SQL ("i.hit asc i.id desc", no
     * comma) would be a real MySQL syntax error -- a 500 here, not a
     * quietly-wrong sort order.
     */
    public function testStdImageSqlOrderJoinsMultipleValidFieldsWithAComma(): void
    {
        // All 5 fixture images share hit=0 -- ties on the primary sort key
        // fall through to the secondary "id desc", which only takes effect
        // if the 2 fields were joined into one real ORDER BY clause.
        $ids = $this->searchIds('Photo', [
            'order' => 'hit asc, id desc',
        ]);
        self::assertSame([5, 4, 3, 2, 1], $ids);
    }
}
