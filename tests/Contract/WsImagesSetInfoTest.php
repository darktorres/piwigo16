<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Cache\CachePools;
use Piwigo\Db\DbConnection;

/**
 * Ws\Images::setInfo() (pwg.images.setInfo) -- had zero dedicated test
 * coverage: single_value_mode (fill_if_empty/replace/invalid),
 * multiple_value_mode (append/replace/invalid) for tag_ids, the categories
 * relation param (which delegates to the private addImageCategoryRelations()),
 * and the allow_html_descriptions-gated strip_tags() branch.
 */
final class WsImagesSetInfoTest extends ContractTestCase
{
    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement("DELETE FROM config WHERE param = 'allow_html_descriptions'");
        CachePools::config()->clear();
        parent::tearDown();
    }

    private function pwgToken(): string
    {
        $this->loginAsAdmin();

        return $this->getPwgToken();
    }

    private static function toIntOrFail(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    public function testSetInfoInvalidTokenReturnsError(): void
    {
        // admin_only is checked before the pwg_token comparison
        // (Server::invoke()'s own gate order) -- a guest caller never
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

    public function testSetInfoUnknownImageReturns404(): void
    {
        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => 999999,
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function testSetInfoInvalidSingleValueModeReturnsError(): void
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

    public function testSetInfoInvalidMultipleValueModeReturnsError(): void
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

    public function testSetInfoFillIfEmptySetsAnEmptyField(): void
    {
        $original = $this->conn->fetchOne('SELECT author FROM images WHERE id = 1');

        try {
            $this->conn->executeStatement("UPDATE images SET author = '' WHERE id = 1");

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'author' => 'Filled Author ' . uniqid(),
                'single_value_mode' => 'fill_if_empty',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newAuthor = $this->conn->fetchOne('SELECT author FROM images WHERE id = 1');
            self::assertIsString($newAuthor);
            self::assertStringStartsWith('Filled Author ', $newAuthor);
        } finally {
            $this->conn->executeStatement(
                'UPDATE images SET author = ? WHERE id = 1',
                [$original]
            );
        }
    }

    public function testSetInfoFillIfEmptyLeavesANonEmptyFieldUntouched(): void
    {
        $original = $this->conn->fetchOne('SELECT author FROM images WHERE id = 1');

        try {
            $this->conn->executeStatement(
                "UPDATE images SET author = 'Pre-Existing Author' WHERE id = 1"
            );

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'author' => 'Should Not Apply',
                'single_value_mode' => 'fill_if_empty',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newAuthor = $this->conn->fetchOne('SELECT author FROM images WHERE id = 1');
            self::assertSame('Pre-Existing Author', $newAuthor);
        } finally {
            $this->conn->executeStatement(
                'UPDATE images SET author = ? WHERE id = 1',
                [$original]
            );
        }
    }

    public function testSetInfoReplaceModeOverwritesANonEmptyField(): void
    {
        $original = $this->conn->fetchOne('SELECT author FROM images WHERE id = 1');

        try {
            $this->conn->executeStatement(
                "UPDATE images SET author = 'Old Author' WHERE id = 1"
            );

            $newValue = 'Replaced Author ' . uniqid();
            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'author' => $newValue,
                'single_value_mode' => 'replace',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newAuthor = $this->conn->fetchOne('SELECT author FROM images WHERE id = 1');
            self::assertSame($newValue, $newAuthor);
        } finally {
            $this->conn->executeStatement(
                'UPDATE images SET author = ? WHERE id = 1',
                [$original]
            );
        }
    }

    // The `$info_columns = ['name', 'author', 'comment', 'level',
    // 'date_creation']` array-literal's own opening-line statement (~2312)
    // shows as "uncovered" in raw line-coverage despite every test above
    // reaching and exercising it -- a known OPcache constant-array-folding
    // artifact: a pure-literal array with no variables gets folded at compile time,
    // so Xdebug can't attribute a real hit to that source line. Not a real
    // gap; no test added for it here.

    public function testSetInfoFileParamIsForbiddenOnSynchronizedPhotos(): void
    {
        // storage_category_id != 0 marks a photo as added by directory
        // synchronization rather than upload -- setInfo() refuses to let
        // the `file` column be edited for those.
        $filename = 'sync-photo-' . uniqid() . '.jpg';
        $this->conn->executeStatement(
            'INSERT INTO images (file, path, md5sum, storage_category_id) VALUES (?, ?, ?, ?)',
            [$filename, 'upload/' . $filename, md5($filename), 1]
        );
        $imageId = (int) $this->conn->lastInsertId();

        try {
            $response = $this->callWsAllowingServerError('pwg.images.setInfo', [
                'image_id' => $imageId,
                'file' => 'new-name.jpg',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(500, $response['err']);
            self::assertSame(
                '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization',
                $response['message']
            );
        } finally {
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }
    }

    public function testSetInfoFileParamThatStripsToEmptyIsSilentlyDropped(): void
    {
        // strip_tags('0') is still '0' (nothing to strip) -- the explicit
        // === '0' check exists precisely so a literal "0" filename isn't
        // treated as a real value, matching PHP's own "0" == falsy
        // convention. Since 'file' is the *only* field sent, $update ends
        // up empty and updateFields() is never even called.
        $original = $this->conn->fetchOne('SELECT file FROM images WHERE id = 1');
        self::assertIsString($original);

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => 1,
            'file' => '0',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);

        $newFile = $this->conn->fetchOne('SELECT file FROM images WHERE id = 1');
        self::assertSame($original, $newFile);
    }

    public function testSetInfoTagListAndTagIdsTogetherIsRejected(): void
    {
        // tag_list is the batch-manager unit mode's own temporary
        // $_REQUEST['tag_list'] array param (TagListRequest) -- mutually
        // exclusive with the public tag_ids param.
        $response = $this->callWsAllowingServerError('pwg.images.setInfo', [
            'image_id' => 1,
            'tag_ids' => '1',
            'tag_list' => ['tag-list-conflict-' . uniqid()],
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Do not use tag_list and tag_ids at the same time.', $response['message']);
    }

    public function testSetInfoTagListCreatesAndSetsTagsByName(): void
    {
        $originalTags = $this->conn->fetchFirstColumn(
            'SELECT tag_id FROM image_tag WHERE image_id = 1'
        );
        $tagName = 'setinfo-taglist-' . uniqid();

        try {
            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'tag_list' => [$tagName],
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat'], (string) json_encode($response));

            $afterTagNames = $this->conn->fetchFirstColumn(
                'SELECT t.name FROM tags' . ' t
                 INNER JOIN image_tag' . ' it ON it.tag_id = t.id
                 WHERE it.image_id = 1'
            );
            self::assertContains($tagName, $afterTagNames);
        } finally {
            $this->conn->executeStatement('DELETE FROM image_tag WHERE image_id = 1');
            foreach ($originalTags as $tagId) {
                $this->conn->executeStatement(
                    'INSERT INTO image_tag (image_id, tag_id) VALUES (1, ?)',
                    [$tagId]
                );
            }
            $this->conn->executeStatement('DELETE FROM tags WHERE name = ?', [$tagName]);
        }
    }

    public function testSetInfoStripsDisallowedHtmlTagsWhenHtmlDescriptionsAreDisabled(): void
    {
        $original = $this->conn->fetchOne('SELECT comment FROM images WHERE id = 1');

        $this->upsertConfig('allow_html_descriptions', 'false');
        CachePools::config()->clear();

        try {
            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'comment' => '<script>alert(1)</script><b>bold kept</b>',
                'single_value_mode' => 'replace',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $newComment = $this->conn->fetchOne('SELECT comment FROM images WHERE id = 1');
            self::assertIsString($newComment);
            self::assertStringNotContainsString('<script>', $newComment);
            self::assertStringContainsString('<b>bold kept</b>', $newComment);
        } finally {
            $this->conn->executeStatement(
                'UPDATE images SET comment = ? WHERE id = 1',
                [$original]
            );
        }
    }

    public function testSetInfoCategoriesReplaceModeReplacesAssociations(): void
    {
        $originalCats = $this->conn->fetchFirstColumn(
            'SELECT category_id FROM image_category WHERE image_id = 1'
        );

        try {
            $newCatId = $this->conn->fetchOne(
                'SELECT id FROM categories WHERE id != 1 ORDER BY id LIMIT 1'
            );
            self::assertIsNumeric($newCatId);

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'categories' => (string) $newCatId,
                'multiple_value_mode' => 'replace',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $afterCats = $this->conn->fetchFirstColumn(
                'SELECT category_id FROM image_category WHERE image_id = 1'
            );
            self::assertSame([$newCatId], array_map(self::toIntOrFail(...), $afterCats));
        } finally {
            $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = 1');
            foreach ($originalCats as $catId) {
                $this->conn->executeStatement(
                    'INSERT INTO image_category (image_id, category_id) VALUES (1, ?)',
                    [$catId]
                );
            }
        }
    }

    /**
     * Regression test for a real, pre-existing bug: setInfo() calls
     * addImageCategoryRelations() (which CAN return a WsErrorResponse for an
     * unknown category id -- confirmed via its own source) but never
     * checks or propagates that return value. WsErrorResponse's own constructor
     * still sets a real "500" HTTP status header as a side effect of
     * simply being constructed, so the response ends up with the
     * genuinely misleading combination of a 500 HTTP status alongside a
     * "stat":"ok" JSON body -- the validation failure is silently
     * swallowed rather than ever reaching the caller as an error. Out of
     * scope to fix here; this only documents the current, real behavior.
     */
    public function testSetInfoCategoriesWithAnUnknownCategorySilentlySwallowsTheError(): void
    {
        $response = $this->callWsAllowingServerError('pwg.images.setInfo', [
            'image_id' => 1,
            'categories' => '999999',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function testSetInfoTagIdsReplaceModeSetsExactTagSet(): void
    {
        $originalTags = $this->conn->fetchFirstColumn(
            'SELECT tag_id FROM image_tag WHERE image_id = 1'
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
                'SELECT tag_id FROM image_tag WHERE image_id = 1'
            );
            self::assertSame([1], array_map(self::toIntOrFail(...), $afterTags));
        } finally {
            $this->conn->executeStatement('DELETE FROM image_tag WHERE image_id = 1');
            foreach ($originalTags as $tagId) {
                $this->conn->executeStatement(
                    'INSERT INTO image_tag (image_id, tag_id) VALUES (1, ?)',
                    [$tagId]
                );
            }
        }
    }

    public function testSetInfoTagIdsAppendModeKeepsExistingTags(): void
    {
        $originalTags = $this->conn->fetchFirstColumn(
            'SELECT tag_id FROM image_tag WHERE image_id = 1'
        );

        try {
            $this->conn->executeStatement('DELETE FROM image_tag WHERE image_id = 1');
            $this->conn->executeStatement(
                'INSERT INTO image_tag (image_id, tag_id) VALUES (1, 1)'
            );

            $response = $this->callWs('pwg.images.setInfo', [
                'image_id' => 1,
                'tag_ids' => '2',
                'multiple_value_mode' => 'append',
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);

            $afterTags = array_map(self::toIntOrFail(...), $this->conn->fetchFirstColumn(
                'SELECT tag_id FROM image_tag WHERE image_id = 1'
            ));
            sort($afterTags);
            self::assertSame([1, 2], $afterTags);
        } finally {
            $this->conn->executeStatement('DELETE FROM image_tag WHERE image_id = 1');
            foreach ($originalTags as $tagId) {
                $this->conn->executeStatement(
                    'INSERT INTO image_tag (image_id, tag_id) VALUES (1, ?)',
                    [$tagId]
                );
            }
        }
    }
}
