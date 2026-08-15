<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;

/**
 * Ws\ImageUrlBuilder -- split out of the former WsHelper god-class (P25
 * Stage 1 step 6), reached here through pwg.images.getInfo.
 */
final class ImageUrlBuilderTest extends ContractTestCase
{
    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    public function testStdGetUrlsUsesTheElementUrlServiceForANonOriginalRepresentative(): void
    {
        // representative_ext non-empty -> SrcImage::isOriginal() is false
        // (IS_MIMETYPE branch instead) -- stdGetUrls()'s else branch
        // (urlService->getElementUrl()) instead of the isOriginal()
        // element_url/getUrl() branch.
        $this->conn->executeStatement(
            'INSERT INTO images (file, path, md5sum, representative_ext, width, height) VALUES (?, ?, ?, ?, ?, ?)',
            ['video-helper-test.mp4', 'upload/video-helper-test.mp4', md5('video-helper-test'), 'mp4', 200, 150]
        );
        $imageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO image_category (image_id, category_id) VALUES (?, 1)',
            [$imageId]
        );

        try {
            $response = $this->ws('pwg.images.getInfo', [
                'image_id' => $imageId,
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertIsString($result['element_url']);
            self::assertStringContainsString('/upload/video-helper-test.mp4', $result['element_url']);
            self::assertIsString($result['download_url']);
            self::assertStringContainsString('part=e&download', $result['download_url']);
        } finally {
            $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }
    }
}
