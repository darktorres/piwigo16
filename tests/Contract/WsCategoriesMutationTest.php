<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsCategoriesMutationTest extends ContractTestCase
{
    private ?int $categoryId = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->categoryId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.categories.delete', [
                'category_id'         => $this->categoryId,
                'photo_deletion_mode' => 'no_delete',
                'pwg_token'           => $token,
            ]);
            $this->categoryId = null;
        }

        parent::tearDown();
    }

    public function test_add_returns_category_shape(): void
    {
        $response = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.add', $response);

        $this->categoryId = $this->extractResultId($response);
    }

    public function test_setInfo_updates_name(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $this->categoryId,
            'name'        => 'ct_album_renamed_' . $this->categoryId,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setRepresentative_returns_ok(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        // Associate fixture image 1 with this album first
        $token = $this->getPwgToken();
        $this->callWs('pwg.images.setCategory', [
            'image_id'    => [1],
            'category_id' => $this->categoryId,
            'pwg_token'   => $token,
        ]);

        $response = $this->callWs('pwg.categories.setRepresentative', [
            'category_id' => $this->categoryId,
            'image_id'    => 1,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_deleteRepresentative_returns_ok(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        $response = $this->callWs('pwg.categories.deleteRepresentative', [
            'category_id' => $this->categoryId,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_move_returns_move_shape(): void
    {
        $token  = $this->getPwgToken();
        $parent = $this->callWs('pwg.categories.add', ['name' => 'ct_parent_' . uniqid()]);
        $child  = $this->callWs('pwg.categories.add', ['name' => 'ct_child_' . uniqid()]);
        $parentId = $this->extractResultId($parent);
        $childId  = $this->extractResultId($child);

        $response = $this->callWs('pwg.categories.move', [
            'category_id' => $childId,
            'parent'      => $parentId,
            'pwg_token'   => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.move', $response);

        // clean up
        $this->callWs('pwg.categories.delete', [
            'category_id'         => $parentId,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token'           => $token,
        ]);
    }

    public function test_calculateOrphans_returns_orphan_info(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        $response = $this->callWs('pwg.categories.calculateOrphans', [
            'category_id' => [$this->categoryId],
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.calculateOrphans', $response);
    }

    public function test_delete_returns_ok(): void
    {
        $add   = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $id    = $this->extractResultId($add);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.delete', [
            'category_id'         => $id,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token'           => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // already deleted
    }

    /**
     * Extracts and int-casts the 'id' field from a WS mutation response's
     * 'result' object (e.g. pwg.categories.add), narrowing the otherwise
     * mixed decoded-JSON response so it can flow into typed contexts (the
     * $categoryId property, subsequent WS call params, etc). Fails the test
     * with a diagnostic message if the response doesn't have the expected
     * shape, rather than silently producing a bogus 0.
     *
     * @param array<string, mixed> $response
     */
    private function extractResultId(array $response): int
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            $encoded = json_encode($response);
            self::fail('WS response result is not an array: ' . ($encoded === false ? 'null' : $encoded));
        }

        $id = $result['id'] ?? null;
        if (!is_numeric($id)) {
            $encoded = json_encode($response);
            self::fail('WS response result.id is not numeric: ' . ($encoded === false ? 'null' : $encoded));
        }

        return (int) $id;
    }
}
