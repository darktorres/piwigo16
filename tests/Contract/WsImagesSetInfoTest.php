<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\PwgImages::setInfo() (pwg.images.setInfo) -- had zero dedicated test
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave
 * 1): single_value_mode (fill_if_empty/replace/invalid),
 * multiple_value_mode (append/replace/invalid) for tag_ids, the categories
 * relation param (which delegates to the private addImageCategoryRelations()),
 * and the allow_html_descriptions-gated strip_tags() branch.
 */
final class WsImagesSetInfoTest extends ContractTestCase
{
    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'allow_html_descriptions'");
        \Piwigo\Cache\CachePools::config()->clear();
        parent::tearDown();
    }

    private function pwgToken(): string
    {
        $this->loginAsAdmin();

        return $this->getPwgToken();
    }

    /**
     * setInfo()'s own single_value_mode/multiple_value_mode/"file on a
     * synced photo" validation errors are real, deliberate
     * `new PwgError(500, ...)` returns (see PwgError::__construct(): any
     * code in [400,600) sets a matching real HTTP status header) -- the
     * shared callWs()'s own `< 500` guard exists to catch genuine crashes
     * and would wrongly reject this well-formed application error, same
     * shape as WsCategoriesMutationTest's own callWsAllowingServerError().
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callWsAllowingServerError(string $method, array $params): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function toIntOrFail(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    public function test_setInfo_invalid_token_returns_error(): void
    {
        // admin_only is checked before the pwg_token comparison
        // (PwgServer::invoke()'s own gate order) -- a guest caller never
        // reaches the token check at all, so this must already be logged
        // in as admin for the 403 (not a 401 "not authenticated") to fire.
        $this->loginAsAdmin();

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => 1,
            'pwg_token' => 'not-the-real-token',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_setInfo_unknown_image_returns_404(): void
    {
        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => 999999,
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function test_setInfo_invalid_single_value_mode_returns_error(): void
    {
        $response = $this->callWsAllowingServerError('pwg.images.setInfo', [
            'image_id' => 1,
            'name' => 'Anything',
            'single_value_mode' => 'not-a-real-mode',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertIsString($response['message']);
        self::assertStringContainsString('single_value_mode', $response['message']);
    }

    public function test_setInfo_invalid_multiple_value_mode_returns_error(): void
    {
        $response = $this->callWsAllowingServerError('pwg.images.setInfo', [
            'image_id' => 1,
            'tag_ids' => '1',
            'multiple_value_mode' => 'not-a-real-mode',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertIsString($response['message']);
        self::assertStringContainsString('multiple_value_mode', $response['message']);
    }

    public function test_setInfo_fill_if_empty_sets_an_empty_field(): void
    {
        $original = $this->conn->fetchOne('SELECT author FROM ' . Tables::images() . ' WHERE id = 1');

        try {
            $this->conn->executeStatement("UPDATE " . Tables::images() . " SET author = '' WHERE id = 1");

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'author' => 'Filled Author ' . uniqid(),
                'single_value_mode' => 'fill_if_empty',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newAuthor = $this->conn->fetchOne('SELECT author FROM ' . Tables::images() . ' WHERE id = 1');
            self::assertIsString($newAuthor);
            self::assertStringStartsWith('Filled Author ', $newAuthor);
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::images() . ' SET author = ? WHERE id = 1',
                [$original]
            );
        }
    }

    public function test_setInfo_fill_if_empty_leaves_a_non_empty_field_untouched(): void
    {
        $original = $this->conn->fetchOne('SELECT author FROM ' . Tables::images() . ' WHERE id = 1');

        try {
            $this->conn->executeStatement(
                "UPDATE " . Tables::images() . " SET author = 'Pre-Existing Author' WHERE id = 1"
            );

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'author' => 'Should Not Apply',
                'single_value_mode' => 'fill_if_empty',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newAuthor = $this->conn->fetchOne('SELECT author FROM ' . Tables::images() . ' WHERE id = 1');
            self::assertSame('Pre-Existing Author', $newAuthor);
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::images() . ' SET author = ? WHERE id = 1',
                [$original]
            );
        }
    }

    public function test_setInfo_replace_mode_overwrites_a_non_empty_field(): void
    {
        $original = $this->conn->fetchOne('SELECT author FROM ' . Tables::images() . ' WHERE id = 1');

        try {
            $this->conn->executeStatement(
                "UPDATE " . Tables::images() . " SET author = 'Old Author' WHERE id = 1"
            );

            $newValue = 'Replaced Author ' . uniqid();
            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'author' => $newValue,
                'single_value_mode' => 'replace',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newAuthor = $this->conn->fetchOne('SELECT author FROM ' . Tables::images() . ' WHERE id = 1');
            self::assertSame($newValue, $newAuthor);
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::images() . ' SET author = ? WHERE id = 1',
                [$original]
            );
        }
    }

    public function test_setInfo_strips_disallowed_html_tags_when_html_descriptions_are_disabled(): void
    {
        $original = $this->conn->fetchOne('SELECT comment FROM ' . Tables::images() . ' WHERE id = 1');

        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('allow_html_descriptions', 'false')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'comment' => '<script>alert(1)</script><b>bold kept</b>',
                'single_value_mode' => 'replace',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newComment = $this->conn->fetchOne('SELECT comment FROM ' . Tables::images() . ' WHERE id = 1');
            self::assertIsString($newComment);
            self::assertStringNotContainsString('<script>', $newComment);
            self::assertStringContainsString('<b>bold kept</b>', $newComment);
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::images() . ' SET comment = ? WHERE id = 1',
                [$original]
            );
        }
    }

    public function test_setInfo_categories_replace_mode_replaces_associations(): void
    {
        $originalCats = $this->conn->fetchFirstColumn(
            'SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = 1'
        );

        try {
            $newCatId = $this->conn->fetchOne(
                'SELECT id FROM ' . Tables::categories() . ' WHERE id != 1 ORDER BY id LIMIT 1'
            );
            self::assertIsNumeric($newCatId);

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'categories' => (string) (int) $newCatId,
                'multiple_value_mode' => 'replace',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $afterCats = $this->conn->fetchFirstColumn(
                'SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = 1'
            );
            self::assertSame([(int) $newCatId], array_map(self::toIntOrFail(...), $afterCats));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = 1');
            foreach ($originalCats as $catId) {
                $this->conn->executeStatement(
                    'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (1, ?)',
                    [$catId]
                );
            }
        }
    }

    /**
     * Regression test for a real, pre-existing bug: setInfo() calls
     * addImageCategoryRelations() (which CAN return a PwgError for an
     * unknown category id -- confirmed via its own source) but never
     * checks or propagates that return value. PwgError's own constructor
     * still sets a real "500" HTTP status header as a side effect of
     * simply being constructed, so the response ends up with the
     * genuinely misleading combination of a 500 HTTP status alongside a
     * "stat":"ok" JSON body -- the validation failure is silently
     * swallowed rather than ever reaching the caller as an error. Out of
     * scope to fix here (a test-writing pass, not a bug-fixing one); this
     * only documents the current, real behavior.
     */
    public function test_setInfo_categories_with_an_unknown_category_silently_swallows_the_error(): void
    {
        $response = $this->callWsAllowingServerError('pwg.images.setInfo', [
            'image_id' => 1,
            'categories' => '999999',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setInfo_tag_ids_replace_mode_sets_exact_tag_set(): void
    {
        $originalTags = $this->conn->fetchFirstColumn(
            'SELECT tag_id FROM ' . Tables::imageTag() . ' WHERE image_id = 1'
        );

        try {
            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'tag_ids' => '1',
                'multiple_value_mode' => 'replace',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $afterTags = $this->conn->fetchFirstColumn(
                'SELECT tag_id FROM ' . Tables::imageTag() . ' WHERE image_id = 1'
            );
            self::assertSame([1], array_map(self::toIntOrFail(...), $afterTags));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = 1');
            foreach ($originalTags as $tagId) {
                $this->conn->executeStatement(
                    'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (1, ?)',
                    [$tagId]
                );
            }
        }
    }

    public function test_setInfo_tag_ids_append_mode_keeps_existing_tags(): void
    {
        $originalTags = $this->conn->fetchFirstColumn(
            'SELECT tag_id FROM ' . Tables::imageTag() . ' WHERE image_id = 1'
        );

        try {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = 1');
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (1, 1)'
            );

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'tag_ids' => '2',
                'multiple_value_mode' => 'append',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $afterTags = array_map(self::toIntOrFail(...), $this->conn->fetchFirstColumn(
                'SELECT tag_id FROM ' . Tables::imageTag() . ' WHERE image_id = 1'
            ));
            sort($afterTags);
            self::assertSame([1, 2], $afterTags);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageTag() . ' WHERE image_id = 1');
            foreach ($originalTags as $tagId) {
                $this->conn->executeStatement(
                    'INSERT INTO ' . Tables::imageTag() . ' (image_id, tag_id) VALUES (1, ?)',
                    [$tagId]
                );
            }
        }
    }
}
