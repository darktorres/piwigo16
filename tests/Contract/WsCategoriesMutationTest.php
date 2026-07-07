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

        $this->categoryId = (int) $response['result']['id'];
    }

    public function test_setInfo_updates_name(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = (int) $add['result']['id'];

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $this->categoryId,
            'name'        => 'ct_album_renamed_' . $this->categoryId,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setRepresentative_returns_ok(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = (int) $add['result']['id'];

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
        $this->categoryId = (int) $add['result']['id'];

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
        $parentId = (int) $parent['result']['id'];
        $childId  = (int) $child['result']['id'];

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
        $this->categoryId = (int) $add['result']['id'];

        $response = $this->callWs('pwg.categories.calculateOrphans', [
            'category_id' => [$this->categoryId],
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.calculateOrphans', $response);
    }

    public function test_delete_returns_ok(): void
    {
        $add   = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $id    = (int) $add['result']['id'];
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.delete', [
            'category_id'         => $id,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token'           => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // already deleted
    }
}
