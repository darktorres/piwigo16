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

    public function test_add_returns_tag_shape(): void
    {
        $response = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.tags.add', $response);

        $this->tagId = (int) $response['result']['id'];
    }

    public function test_rename_returns_tag_object(): void
    {
        $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);
        $this->tagId = (int) $add['result']['id'];
        $token = $this->getPwgToken();

        $newName  = 'ct_tag_renamed_' . $this->tagId;
        $response = $this->callWs('pwg.tags.rename', [
            'tag_id'    => $this->tagId,
            'new_name'  => $newName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame($newName, $response['result']['name']);
    }

    public function test_duplicate_returns_new_tag_info(): void
    {
        $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_' . uniqid()]);
        $this->tagId = (int) $add['result']['id'];
        $token = $this->getPwgToken();

        $copyName = 'ct_tag_copy_' . $this->tagId;
        $response = $this->callWs('pwg.tags.duplicate', [
            'tag_id'    => $this->tagId,
            'copy_name' => $copyName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertArrayHasKey('id', $response['result']);
        self::assertSame($copyName, $response['result']['name']);

        // clean up the copy too
        $this->callWs('pwg.tags.delete', [
            'tag_id'    => [(int) $response['result']['id']],
            'pwg_token' => $token,
        ]);
    }

    public function test_merge_returns_ok(): void
    {
        $token = $this->getPwgToken();
        $src   = $this->callWs('pwg.tags.add', ['name' => 'ct_merge_src_' . uniqid()]);
        $dst   = $this->callWs('pwg.tags.add', ['name' => 'ct_merge_dst_' . uniqid()]);
        $srcId = (int) $src['result']['id'];
        $dstId = (int) $dst['result']['id'];

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
        $id    = (int) $add['result']['id'];
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.tags.delete', [
            'tag_id'    => [$id],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // already deleted
    }
}
