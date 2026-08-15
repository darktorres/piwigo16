<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\CategoryTreeBuilder;

/**
 * Ws\CategoryTreeBuilder -- split out of the former WsHelper god-class
 * (P25 Stage 1 step 6), reached here through pwg.categories.getList.
 *
 * categoriesFlatlistToTree()'s 2 malformed-row guards (`! is_int($cat_id)
 * && ! is_string($cat_id)` and the sibling check on `$id_uppercat`) are
 * genuinely unreachable through the real pwg.categories.getList route:
 * every row's `id`/`id_uppercat` comes straight off categories'
 * `id`/`id_uppercat` columns (a real int PK / nullable int FK), never a
 * non-scalar value. test_categoriesFlatlistToTree_skips_*() below call the
 * method directly (via a locally-booted Kernel/container, same pattern as
 * WsServerTest's own test_run_without_a_request_handler_returns_unknown_request_format())
 * with a genuinely malformed row instead -- a real call to the real
 * instance method under test, not a mock, same "unreachable through any
 * real WS request" precedent as WsServerTest's own
 * test_checkType_accepts_an_array_of_booleans() for Server::checkType().
 */
final class CategoryTreeBuilderTest extends ContractTestCase
{
    public function testCategoriesFlatlistToTreeNestsAChildUnderItsParent(): void
    {
        // fixture category 2 ("Nested Sub Album") is a real child of
        // category 1 ("Sample Album") -- confirmed live via a direct DB
        // read before writing this assertion.
        $response = $this->ws('pwg.categories.getList', [
            'cat_id' => 1,
            'recursive' => true,
            'tree_output' => true,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertCount(1, $result, 'tree_output must return only the root, not a flat list');
        $root = $result[0];
        self::assertIsArray($root);
        self::assertSame(1, $root['id']);
        $subCategories = $root['sub_categories'];
        self::assertIsArray($subCategories);
        self::assertCount(1, $subCategories);
        $child = $subCategories[0];
        self::assertIsArray($child);
        self::assertSame(2, $child['id']);
        self::assertSame('Nested Sub Album', $child['name']);
    }

    /**
     * See this file's own class docblock: unreachable through the real WS
     * route (categories.id is a real int PK), so this calls the
     * real public method directly with a genuinely malformed row.
     */
    public function testCategoriesFlatlistToTreeSkipsARowWithANonScalarId(): void
    {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        try {
            $categoryTreeBuilder = Kernel::container()->get(CategoryTreeBuilder::class);
            self::assertInstanceOf(CategoryTreeBuilder::class, $categoryTreeBuilder);

            $tree = $categoryTreeBuilder->categoriesFlatlistToTree([
                [
                    'id' => ['not', 'scalar'],
                ],
                [
                    'id' => 1,
                    'name' => 'Valid Root',
                ],
            ]);
        } finally {
            Kernel::reset();
        }

        self::assertCount(1, $tree, 'the malformed row must be skipped entirely, not just left out of the tree');
        self::assertSame(1, $tree[0]['id']);
    }

    /**
     * Same rationale as the sibling test above, for the `id_uppercat`
     * guard instead: categories.id_uppercat is a real nullable int
     * FK, never a non-scalar value through the real WS route.
     */
    public function testCategoriesFlatlistToTreeSkipsAChildRowWithANonScalarUppercatId(): void
    {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        try {
            $categoryTreeBuilder = Kernel::container()->get(CategoryTreeBuilder::class);
            self::assertInstanceOf(CategoryTreeBuilder::class, $categoryTreeBuilder);

            $tree = $categoryTreeBuilder->categoriesFlatlistToTree([
                [
                    'id' => 1,
                    'name' => 'Root',
                ],
                [
                    'id' => 2,
                    'id_uppercat' => ['not', 'scalar'],
                    'name' => 'Bad Child',
                ],
            ]);
        } finally {
            Kernel::reset();
        }

        self::assertCount(1, $tree, 'the malformed child row must never be attached anywhere');
        self::assertSame(1, $tree[0]['id']);
        self::assertArrayNotHasKey('sub_categories', $tree[0]);
    }
}
