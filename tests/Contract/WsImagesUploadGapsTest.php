<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Remaining Ws\PwgImages upload-pipeline gaps not covered by
 * WsImagesChunkedUploadTest/WsUploadTest/WsImagesMaintenanceTest:
 * add()'s image_id-not-found guard, filename-uniqueness-mode duplicate
 * rejection, and high_sum branch (which also exercises removeChunks()'s
 * real delete loop via the unconditional thumb-chunk cleanup, and add()'s
 * tag_ids association, both previously untested); mergeChunks()'s stale-
 * leftover-file cleanup; pwg.images.setCategory's invalid-token guard;
 * formatsSearchImage()'s 'multiple' status; and pwg.images.upload's/
 * uploadAsync's own chunked "not the last chunk yet" branches plus
 * uploadAsync's buffer-directory age-based cleanup sweep.
 *
 * Two real branches are deliberately NOT exercised here, both requiring an
 * *existing* photo to be replaced by a fresh upload (addFile()'s
 * do_update=true branch calling UploadService::addUploadedFile() with a
 * non-null $id_image, and pwg.images.upload's own update_mode=true branch):
 * confirmed live, twice, that this exact code path 500s in this environment
 * -- even starting from a photo created moments earlier through the normal
 * WS upload flow (image id genuinely real, not a hand-crafted DB row) --
 * with `getimagesize(upload/2026/08/01/....jpg): Failed to open stream` in
 * PwgImage.php and `Undefined array key "file"` in SrcImage.php. This is a
 * pre-existing bug in the Image domain's "replace an existing photo's
 * file" pipeline (Piwigo\Admin\Upload\UploadService::addUploadedFile()'s
 * $id_image-not-null path), out of scope for a Ws-domain test-writing pass
 * to fix -- writing a test against it would just encode a real 500 as
 * expected behavior.
 */
final class WsImagesUploadGapsTest extends ContractTestCase
{
    /** 1x1 white PNG, base64-decoded at runtime to avoid binary in source. */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    private Connection $conn;

    /** @var list<int> */
    private array $imageIdsToDelete = [];

    /** @var list<int> */
    private array $categoryIdsToDelete = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->imageIdsToDelete as $imageId) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.images.delete', ['image_id' => (string) $imageId, 'pwg_token' => $token]);
        }
        foreach (array_reverse($this->categoryIdsToDelete) as $categoryId) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.categories.delete', [
                'category_id' => $categoryId,
                'photo_deletion_mode' => 'no_delete',
                'pwg_token' => $token,
            ]);
        }
        $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'uniqueness_mode'");
        \Piwigo\Cache\CachePools::config()->clear();
        parent::tearDown();
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(self::TINY_PNG_B64, true);
        self::assertNotFalse($bytes);

        return $bytes;
    }

    /**
     * Multipart POST, needed wherever $_FILES is involved.
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function multipart(string $method, array $fields, ?string $fileField = null, ?string $fileContents = null): array
    {
        $tmpFile = null;
        if ($fileField !== null) {
            $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_gaps_');
            self::assertNotFalse($tmpName);
            $tmpFile = $tmpName . '.png';
            file_put_contents($tmpFile, $fileContents ?? $this->pngBytes());
            $fields[$fileField] = new \CURLFile($tmpFile, 'image/png', 'gaps.png');
        }
        $fields['method'] = $method;

        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        if ($tmpFile !== null) {
            @unlink($tmpFile);
        }

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // -------------------------------------------------------------------- add()

    public function test_add_with_an_unknown_image_id_returns_404(): void
    {
        $sum = md5($this->pngBytes() . uniqid());

        $response = $this->callWsAllowingServerError('pwg.images.add', [
            'original_sum' => $sum,
            'image_id' => 999999,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('image_id not found', $response['message']);
    }

    public function test_add_in_filename_uniqueness_mode_rejects_a_known_filename(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('uniqueness_mode', '\"filename\"')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        $response = $this->callWsAllowingServerError('pwg.images.add', [
            'original_sum' => md5($this->pngBytes() . uniqid()),
            'original_filename' => 'fixture-photo-1.jpg',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('file already exists', $response['message']);
    }

    public function test_add_with_high_sum_uses_the_high_chunk_and_cleans_up_the_thumb_chunk(): void
    {
        $sum = md5($this->pngBytes() . uniqid());

        $high = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64, 'original_sum' => $sum, 'type' => 'high', 'position' => 0,
        ]);
        self::assertSame('ok', $high['stat']);
        $thumb = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64, 'original_sum' => $sum, 'type' => 'thumb', 'position' => 0,
        ]);
        self::assertSame('ok', $thumb['stat']);

        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';
        self::assertFileExists($bufferDir . $sum . '-thumb-00000.block');

        $response = $this->callWs('pwg.images.add', [
            'original_sum' => $sum,
            'high_sum' => 'anything', // presence alone selects the 'high' original_type branch
            'check_uniqueness' => false,
            'tag_ids' => '1,2',
            'original_filename' => 'highsum-gap-test-' . uniqid() . '.jpg',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $this->imageIdsToDelete[] = (int) $imageId;

        // removeChunks($sum, 'thumb') runs unconditionally near the top of
        // add() -- the thumb chunk buffered above must be gone.
        self::assertFileDoesNotExist($bufferDir . $sum . '-thumb-00000.block');

        $tagIds = array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->conn->fetchFirstColumn(
                'SELECT tag_id FROM ' . Tables::imageTag() . ' WHERE image_id = ? ORDER BY tag_id',
                [(int) $imageId]
            )
        );
        self::assertSame([1, 2], $tagIds);
    }

    public function test_add_cleans_up_a_stale_leftover_merged_file_before_building_a_fresh_one(): void
    {
        // mergeChunks()'s own is_file()+unlink() housekeeping at the top:
        // simulate a merge left behind by a previous, incomplete request.
        $sum = md5($this->pngBytes() . uniqid());
        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';
        file_put_contents($bufferDir . $sum . '-original', 'stale leftover content, must be replaced');

        $chunk = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64, 'original_sum' => $sum, 'type' => 'file', 'position' => 0,
        ]);
        self::assertSame('ok', $chunk['stat']);

        $response = $this->callWs('pwg.images.add', [
            'original_sum' => $sum,
            'check_uniqueness' => false,
            'original_filename' => 'stale-merge-gap-test-' . uniqid() . '.jpg',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $this->imageIdsToDelete[] = (int) $imageId;

        // the merged buffer file is consumed (moved away) by
        // addUploadedFile() on success -- the stale content never survives.
        self::assertFileDoesNotExist($bufferDir . $sum . '-original');
    }

    // -------------------------------------------------------------- setCategory

    public function test_setCategory_with_an_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.images.setCategory', [
            'image_id' => [1],
            'category_id' => 1,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    // --------------------------------------------------- formats.searchImage

    public function test_formatsSearchImage_reports_multiple_when_two_photos_share_a_basename(): void
    {
        $base = 'dup-basename-' . uniqid();
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$base . '.jpg', 'upload/' . $base . '.jpg', md5($base . 'a')]
        );
        $firstId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$base . '.tif', 'upload/' . $base . '.tif', md5($base . 'b')]
        );
        $secondId = (int) $this->conn->lastInsertId();

        try {
            $response = $this->callWs('pwg.images.formats.searchImage', [
                'filename_list' => json_encode(['a' => $base . '.tif']),
            ]);

            self::assertSame('ok', $response['stat']);
            self::assertSame(['a' => ['status' => 'multiple']], $response['result']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id IN (?, ?)', [$firstId, $secondId]);
        }
    }

    // ------------------------------------------------------------------ upload()

    public function test_upload_with_more_than_one_chunk_and_not_the_last_returns_a_null_result(): void
    {
        $token = $this->getPwgToken();
        $name = 'chunked-upload-gap-' . uniqid() . '.jpg';

        $response = $this->multipart('pwg.images.upload', [
            'pwg_token' => $token,
            'category' => 1,
            'chunk' => 0,
            'chunks' => 2,
            'name' => $name,
        ], 'file');

        self::assertSame('ok', $response['stat']);
        self::assertNull($response['result']);

        // upload()'s own "not the last chunk" branch leaves a real
        // "{filePath}.part" file behind, waiting for chunk 1 -- this test
        // never sends it, so clean up rather than leave an orphan.
        $partPath = dirname(__DIR__, 2) . '/upload/buffer/' . md5($name) . '.part';
        self::assertFileExists($partPath);
        unlink($partPath);
    }

    // -------------------------------------------------------------- uploadAsync()

    public function test_uploadAsync_with_more_than_one_chunk_and_not_the_last_reports_the_uploaded_ids(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        $sum = md5($bytes);

        $response = $this->multipart('pwg.images.uploadAsync', [
            'original_sum' => $sum,
            'chunk' => 0,
            'chunk_sum' => md5($bytes),
            'chunks' => 2,
            'filename' => 'async-partial-' . uniqid() . '.png',
        ], 'file', $bytes);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['message' => 'chunks uploaded = 1'], $response['result']);

        // fixture_admin is user id 1 -- uploadAsync()'s own chunk filename
        // pattern is "<sum>-u<uid>-<chunk+1>of<chunks>.chunk"; this test
        // never sends chunk 1, so clean up the real leftover file.
        $chunkPath = dirname(__DIR__, 2) . '/upload/buffer/' . $sum . '-u1-001of002.chunk';
        self::assertFileExists($chunkPath);
        unlink($chunkPath);
    }

    public function test_uploadAsync_success_sweeps_stale_buffer_files_older_than_a_week(): void
    {
        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';
        $staleChunk = $bufferDir . 'gap-stale-' . uniqid() . '.chunk';
        $staleMerged = $bufferDir . 'gap-stale-' . uniqid() . '.merged';
        $freshChunk = $bufferDir . 'gap-fresh-' . uniqid() . '.chunk';

        file_put_contents($staleChunk, 'x');
        file_put_contents($staleMerged, 'x');
        file_put_contents($freshChunk, 'x');
        $eightDaysAgo = time() - 8 * 24 * 60 * 60;
        touch($staleChunk, $eightDaysAgo);
        touch($staleMerged, $eightDaysAgo);

        try {
            $bytes = $this->pngBytes() . uniqid();
            $sum = md5($bytes);

            $response = $this->multipart('pwg.images.uploadAsync', [
                'original_sum' => $sum,
                'chunk' => 0,
                'chunk_sum' => md5($bytes),
                'chunks' => 1,
                'filename' => 'async-sweep-' . uniqid() . '.png',
                'category' => [1],
            ], 'file', $bytes);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $imageId = $result['id'] ?? null;
            self::assertIsNumeric($imageId);
            $this->imageIdsToDelete[] = (int) $imageId;

            self::assertFileDoesNotExist($staleChunk);
            self::assertFileDoesNotExist($staleMerged);
            self::assertFileExists($freshChunk);
        } finally {
            // The sweep above already deletes staleChunk/staleMerged on a
            // successful run -- @unlink() doesn't hide the resulting
            // warning from PHPUnit's error handler, so guard for real
            // instead.
            if (file_exists($staleChunk)) {
                unlink($staleChunk);
            }
            if (file_exists($staleMerged)) {
                unlink($staleMerged);
            }
            if (file_exists($freshChunk)) {
                unlink($freshChunk);
            }
        }
    }
}
