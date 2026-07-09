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
}
