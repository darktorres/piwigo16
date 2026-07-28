<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsTagsMutationTest extends ContractTestCase
{
    private ?int $tagId = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->tagId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.tags.delete', [
                'tag_id'    => [$this->tagId],
                'pwg_token' => $token,
            ]);
            $this->tagId = null;
        }

        parent::tearDown();
    }

    /**
     * Narrows a decoded WS response down to its 'result' object, asserting
     * the shape at every level. callWs() returns array<string, mixed>, so
     * nothing below the top level is known without explicit checks.
     *
     * @param array<string, mixed> $response
     * @return array<array-key, mixed>
     */
    private static function tagResult(array $response): array
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            self::fail('WS response "result" is not an array');
        }

        return $result;
    }

    /** @param array<string, mixed> $response */
    private static function tagResultId(array $response): int
    {
        $result = self::tagResult($response);
        $id     = $result['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && is_numeric($id))) {
            self::fail('WS response "result.id" is missing or not numeric');
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $response */
    private static function tagResultName(array $response): string
    {
        $result = self::tagResult($response);
        $name   = $result['name'] ?? null;
        if (!is_string($name)) {
            self::fail('WS response "result.name" is missing or not a string');
        }

        return $name;
    }

    public function test_add_returns_tag_shape(): void
    {
        $response = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.tags.add', $response);

        $this->tagId = self::tagResultId($response);
    }

    public function test_rename_returns_tag_object(): void
    {
        $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);
        $this->tagId = self::tagResultId($add);
        $token = $this->getPwgToken();

        $newName  = 'ct_tag_renamed_' . $this->tagId;
        $response = $this->callWs('pwg.tags.rename', [
            'tag_id'    => $this->tagId,
            'new_name'  => $newName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame($newName, self::tagResultName($response));
    }

    public function test_duplicate_returns_new_tag_info(): void
    {
        $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);
        $this->tagId = self::tagResultId($add);
        $token = $this->getPwgToken();

        $copyName = 'ct_tag_copy_' . $this->tagId;
        $response = $this->callWs('pwg.tags.duplicate', [
            'tag_id'    => $this->tagId,
            'copy_name' => $copyName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $copyId = self::tagResultId($response);
        self::assertSame($copyName, self::tagResultName($response));

        // clean up the copy too
        $this->callWs('pwg.tags.delete', [
            'tag_id'    => [$copyId],
            'pwg_token' => $token,
        ]);
    }

    public function test_merge_returns_ok(): void
    {
        $token = $this->getPwgToken();
        $src   = $this->callWs('pwg.tags.add', ['name' => 'ct_merge_src_' . uniqid()]);
        $dst   = $this->callWs('pwg.tags.add', ['name' => 'ct_merge_dst_' . uniqid()]);
        $srcId = self::tagResultId($src);
        $dstId = self::tagResultId($dst);

        $response = $this->callWs('pwg.tags.merge', [
            'merge_tag_id'       => [$srcId],
            'destination_tag_id' => $dstId,
            'pwg_token'          => $token,
        ]);

        self::assertSame('ok', $response['stat']);

        // src was consumed by merge; delete dst
        $this->callWs('pwg.tags.delete', [
            'tag_id'    => [$dstId],
            'pwg_token' => $token,
        ]);
    }

    public function test_delete_returns_ok(): void
    {
        $add   = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);
        $id    = self::tagResultId($add);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.tags.delete', [
            'tag_id'    => [$id],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // already deleted
    }

    public function test_delete_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.tags.delete', [
            'tag_id' => [1],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_delete_with_a_nonexistent_tag_id_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.tags.delete', [
            'tag_id' => [999999],
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('All tags does not exist.', $response['message']);
    }

    public function test_rename_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.tags.rename', [
            'tag_id' => 1,
            'new_name' => 'irrelevant',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_rename_a_nonexistent_tag_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.tags.rename', [
            'tag_id' => 999999,
            'new_name' => 'irrelevant',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This tag does not exist.', $response['message']);
    }

    public function test_rename_to_an_already_used_name_returns_error(): void
    {
        $token = $this->getPwgToken();
        $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_a_' . uniqid()]);
        $this->tagId = self::tagResultId($add);
        $otherAdd = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_b_' . uniqid()]);
        $otherId = self::tagResultId($otherAdd);
        $otherName = self::tagResultName($otherAdd);

        try {
            $response = $this->callWs('pwg.tags.rename', [
                'tag_id' => $this->tagId,
                'new_name' => $otherName,
                'pwg_token' => $token,
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(1003, $response['err']);
            self::assertSame('This name is already token', $response['message']);
        } finally {
            $this->callWs('pwg.tags.delete', ['tag_id' => [$otherId], 'pwg_token' => $token]);
        }
    }

    public function test_duplicate_a_nonexistent_tag_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.tags.duplicate', [
            'tag_id' => 999999,
            'copy_name' => 'irrelevant',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This tag does not exist.', $response['message']);
    }

    public function test_duplicate_with_a_name_already_taken_returns_error(): void
    {
        $token = $this->getPwgToken();
        $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);
        $this->tagId = self::tagResultId($add);
        $otherAdd = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_taken_' . uniqid()]);
        $otherId = self::tagResultId($otherAdd);
        $otherName = self::tagResultName($otherAdd);

        try {
            $response = $this->callWs('pwg.tags.duplicate', [
                'tag_id' => $this->tagId,
                'copy_name' => $otherName,
                'pwg_token' => $token,
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(1003, $response['err']);
            self::assertSame('This name is already taken.', $response['message']);
        } finally {
            $this->callWs('pwg.tags.delete', ['tag_id' => [$otherId], 'pwg_token' => $token]);
        }
    }

    public function test_duplicate_copies_the_tags_image_associations(): void
    {
        $token = $this->getPwgToken();
        $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_with_images_' . uniqid()]);
        $this->tagId = self::tagResultId($add);
        // fixture image 1 always exists.
        $this->callWs('pwg.images.setInfo', [
            'image_id' => 1,
            'tag_ids' => (string) $this->tagId,
            'multiple_value_mode' => 'append',
            'pwg_token' => $token,
        ]);

        $copyName = 'ct_tag_copy_with_images_' . uniqid();
        $response = $this->callWs('pwg.tags.duplicate', [
            'tag_id' => $this->tagId,
            'copy_name' => $copyName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = self::tagResult($response);
        self::assertSame(1, $result['count']);
        $copyId = self::tagResultId($response);

        // clean up the copy + the association it created
        $this->callWs('pwg.tags.delete', ['tag_id' => [$copyId], 'pwg_token' => $token]);
    }

    public function test_merge_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.tags.merge', [
            'merge_tag_id' => [1],
            'destination_tag_id' => 2,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_merge_moves_the_source_tags_images_into_the_destination(): void
    {
        $token = $this->getPwgToken();
        $src = $this->callWs('pwg.tags.add', ['name' => 'ct_merge_img_src_' . uniqid()]);
        $dst = $this->callWs('pwg.tags.add', ['name' => 'ct_merge_img_dst_' . uniqid()]);
        $srcId = self::tagResultId($src);
        $dstId = self::tagResultId($dst);

        $this->callWs('pwg.images.setInfo', [
            'image_id' => 1,
            'tag_ids' => (string) $srcId,
            'multiple_value_mode' => 'append',
            'pwg_token' => $token,
        ]);

        $response = $this->callWs('pwg.tags.merge', [
            'merge_tag_id' => [$srcId],
            'destination_tag_id' => $dstId,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = self::tagResult($response);
        self::assertSame($dstId, $result['destination_tag']);
        self::assertSame([$srcId], $result['deleted_tag']);
        self::assertIsArray($result['images_in_merged_tag']);
        self::assertContains(1, $result['images_in_merged_tag']);

        $this->callWs('pwg.tags.delete', ['tag_id' => [$dstId], 'pwg_token' => $token]);
    }
}
