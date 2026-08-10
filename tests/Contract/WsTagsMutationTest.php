<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use RuntimeException;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;

/**
 * Ws\PwgTags::delete()'s own `else { return ['id' => []]; }` branch (when
 * $tag_ids is empty) is NOT chased here: `tag_id` is registered with
 * WsParamFlag::FORCE_ARRAY and no WsParamFlag::OPTIONAL/'default' key
 * (mandatory), so PwgServer::invoke() itself rejects any request that
 * doesn't supply at least one real element -- a bare `tag_id=` (or the
 * key omitted entirely) never reaches this method's own body at all
 * (confirmed live: it fails at the WS layer with "Missing parameters:
 * tag_id" first). Genuinely unreachable through the real WS route, not a
 * gap in test coverage.
 */
final class WsTagsMutationTest extends ContractTestCase
{
    private ?int $tagId = null;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[Override]
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

    public function test_add_with_a_duplicate_name_returns_error(): void
    {
        $name = 'ct_tag_dup_' . uniqid();
        $first = $this->callWs('pwg.tags.add', ['name' => $name]);
        $this->tagId = self::tagResultId($first);

        $response = $this->callWs('pwg.tags.add', ['name' => $name]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Tag "' . $name . '" already exists', $response['message']);
    }

    public function test_duplicate_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.tags.duplicate', [
            'tag_id' => 1,
            'copy_name' => 'irrelevant',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_merge_with_a_nonexistent_tag_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.tags.merge', [
            'merge_tag_id' => [999999],
            'destination_tag_id' => 1,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('All tags does not exist.', $response['message']);
    }

    /**
     * rename()'s and duplicate()'s shared RenderTagUrl dispatch has no
     * fallback anymore: dispatchChange() itself enforces that every
     * handler returns a RenderTagUrl instance, so a real plugin handler
     * returning anything else now fails the whole request loud (an
     * uncaught Error), rather than silently falling back to the raw tag
     * name. The default handler (StringHelper::str2url(), registered at
     * RequestBootstrap's own priority 50) always returns a real
     * RenderTagUrl, so reaching this for real needs a second,
     * higher-priority handler chained after it -- a real plugin file +
     * `plugins` activation row (PluginLoader::loadPlugins()
     * include_once()s it on every real request), the same established
     * technique as tests/Contract/WsHistoryTest.php's own 'get_history'
     * override test: EventDispatcher's singleton lives in the real
     * Apache-served process, not this Pest process, so it can't be reached
     * by registering a handler here directly. Scoped to a unique marker
     * tag name so it's a complete no-op for every other concurrent request
     * against this shared dev server while active.
     */
    public function test_rename_throws_when_a_render_tag_url_handler_returns_something_other_than_a_render_tag_url_instance(): void
    {
        $renameMarker = 'ct_tag_url_fallback_rename_' . uniqid();
        $pluginId = 'pwgtest-tags-render-url-fallback';
        $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
        $mainFile = $pluginDir . '/main.inc.php';

        if (! is_dir($pluginDir) && ! mkdir($pluginDir, 0o777, true) && ! is_dir($pluginDir)) {
            throw new RuntimeException('failed to create plugin dir: ' . $pluginDir);
        }
        file_put_contents($mainFile, <<<PHP
            <?php

            declare(strict_types=1);

            /*
            Plugin Name: WsTagsMutationTest -- render_tag_url Non-Instance Override
            Version: 1.0.0
            Description: Test-only fixture plugin (tests/Contract/WsTagsMutationTest.php).
            */

            \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
                \\Piwigo\\Event\\Tag\\RenderTagUrl::class,
                static function (\\Piwigo\\Event\\Tag\\RenderTagUrl \$event): mixed {
                    if (\$event->tagName === '{$renameMarker}') {
                        return 12345;
                    }

                    return \$event;
                },
                51
            );

            PHP);

        $this->conn->executeStatement(
            "INSERT INTO " . 'plugins' . " (id, state, version) VALUES (?, 'active', '1.0.0')",
            [$pluginId]
        );

        try {
            $token = $this->getPwgToken();

            $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_url_fallback_src_' . uniqid()]);
            $this->tagId = self::tagResultId($add);

            // display_errors is off (Core\ErrorCollector::install() forces
            // it, and php.ini already has it off too) -- the response body
            // is just the web server's own generic error page, never the
            // exception's own message/class. The HTTP status is the only
            // reliable, environment-independent signal that the request
            // failed instead of silently falling back.
            $status = $this->postWsStatus('pwg.tags.rename', [
                'tag_id' => $this->tagId,
                'new_name' => $renameMarker,
                'pwg_token' => $token,
            ]);

            self::assertSame(500, $status);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . 'plugins' . ' WHERE id = ?', [$pluginId]);
            @unlink($mainFile);
            @rmdir($pluginDir);
        }
    }

    /**
     * duplicate()'s own RenderTagUrl dispatch site (PwgTags::duplicate())
     * -- distinct from rename()'s own, see this file's own docblock above
     * for why it now fails loud too.
     */
    public function test_duplicate_throws_when_a_render_tag_url_handler_returns_something_other_than_a_render_tag_url_instance(): void
    {
        $duplicateMarker = 'ct_tag_url_fallback_duplicate_' . uniqid();
        $pluginId = 'pwgtest-tags-render-url-fallback-dup';
        $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
        $mainFile = $pluginDir . '/main.inc.php';

        if (! is_dir($pluginDir) && ! mkdir($pluginDir, 0o777, true) && ! is_dir($pluginDir)) {
            throw new RuntimeException('failed to create plugin dir: ' . $pluginDir);
        }
        file_put_contents($mainFile, <<<PHP
            <?php

            declare(strict_types=1);

            /*
            Plugin Name: WsTagsMutationTest -- render_tag_url Non-Instance Override (duplicate)
            Version: 1.0.0
            Description: Test-only fixture plugin (tests/Contract/WsTagsMutationTest.php).
            */

            \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
                \\Piwigo\\Event\\Tag\\RenderTagUrl::class,
                static function (\\Piwigo\\Event\\Tag\\RenderTagUrl \$event): mixed {
                    if (\$event->tagName === '{$duplicateMarker}') {
                        return 12345;
                    }

                    return \$event;
                },
                51
            );

            PHP);

        $this->conn->executeStatement(
            "INSERT INTO " . 'plugins' . " (id, state, version) VALUES (?, 'active', '1.0.0')",
            [$pluginId]
        );

        try {
            $token = $this->getPwgToken();

            $add = $this->callWs('pwg.tags.add', ['name' => 'ct_tag_url_fallback_dup_src_' . uniqid()]);
            $this->tagId = self::tagResultId($add);

            // See test_rename_throws_...()'s own comment above for why this
            // only asserts the HTTP status, not the response body.
            $status = $this->postWsStatus('pwg.tags.duplicate', [
                'tag_id' => $this->tagId,
                'copy_name' => $duplicateMarker,
                'pwg_token' => $token,
            ]);

            self::assertSame(500, $status);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . 'plugins' . ' WHERE id = ?', [$pluginId]);
            @unlink($mainFile);
            @rmdir($pluginDir);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * callWs()/callWsAllowingServerError() both json_decode() the response
     * body, which fails on a raw fatal-error response -- this test only
     * needs the HTTP status, so this skips the JSON round trip entirely.
     */
    private function postWsStatus(string $method, array $params): int
    {
        $ch = curl_init($this->baseUrl . '/ws.php?format=json');
        self::assertNotFalse($ch, 'curl_init failed');

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar());
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        return $status;
    }
}
