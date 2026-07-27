<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\PwgImages's older, 2-step chunked-upload API (Wave 1 of the
 * coverage-gap closure plan, see
 * /home/torres/.claude/plans/piped-enchanting-spark.md): addChunk (0/31),
 * add (0/97) -- pwg.images.addChunk buffers base64 chunks to disk keyed by
 * original_sum, pwg.images.add merges them and creates the photo. Distinct
 * from the newer addSimple/upload multipart flow covered in
 * WsUploadTest.php.
 */
final class WsImagesChunkedUploadTest extends ContractTestCase
{
    /** 1x1 white PNG, base64-decoded at runtime to avoid binary in source. */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    private Connection $conn;

    /** @var list<int> image ids created by a test, deleted in tearDown if not already removed. */
    private array $createdImageIds = [];

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
        foreach ($this->createdImageIds as $id) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(self::TINY_PNG_B64, true);
        self::assertNotFalse($bytes);

        return $bytes;
    }

    /**
     * ContractTestCase::callWs() hard-asserts HTTP status < 500 (guarding
     * against a genuine server crash) -- PwgError(500, ...) is itself a
     * real, intentional business-logic response several PwgImages methods
     * return, which also sets a real HTTP 500 status (PwgError's own
     * constructor, codes 400-599). Bypass that guard the same way
     * test_getInfo_on_missing_image_returns_404 bypasses callWs() for its
     * own non-2xx status, just for POST + JSON decode.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callWsAllowingServerError(string $method, array $params): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch  = curl_init($url);
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

    public function test_addChunk_writes_a_buffer_file(): void
    {
        $sum = md5($this->pngBytes() . uniqid());

        $response = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64,
            'original_sum' => $sum,
            'type' => 'file',
            'position' => 0,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertNull($response['result']);

        $bufferPath = dirname(__DIR__, 2) . '/upload/buffer/' . $sum . '-file-00000.block';
        self::assertFileExists($bufferPath);
        self::assertSame($this->pngBytes(), file_get_contents($bufferPath));

        unlink($bufferPath);
    }

    public function test_addChunk_with_invalid_base64_returns_error(): void
    {
        $sum = md5(uniqid());

        $response = $this->callWsAllowingServerError('pwg.images.addChunk', [
            'data' => "not valid base64 !! \x01\x02",
            'original_sum' => $sum,
            'type' => 'file',
            'position' => 0,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
    }

    public function test_add_rejects_a_duplicate_md5sum_before_touching_any_chunk(): void
    {
        // Fixture image 1's real md5sum -- check_uniqueness (default true)
        // rejects this before addChunk is even needed.
        $response = $this->callWsAllowingServerError('pwg.images.add', [
            'original_sum' => '2e7ee450c4a4cffe42945205029782b9',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('file already exists', $response['message']);
    }

    public function test_add_creates_a_photo_from_a_single_chunk_with_category_and_tags(): void
    {
        $sum = md5($this->pngBytes() . uniqid());

        $chunkResponse = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64,
            'original_sum' => $sum,
            'type' => 'file',
            'position' => 0,
        ]);
        self::assertSame('ok', $chunkResponse['stat']);

        $response = $this->callWs('pwg.images.add', [
            'original_sum' => $sum,
            'original_filename' => 'chunked-test-' . uniqid() . '.png',
            'name' => 'Chunked upload test photo',
            'check_uniqueness' => false,
            'categories' => '1',
            'tag_ids' => '',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $imageId = (int) $imageId;
        $this->createdImageIds[] = $imageId;
        self::assertIsString($result['url']);

        $name = $this->conn->fetchOne('SELECT name FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        self::assertSame('Chunked upload test photo', $name);

        $categoryId = $this->conn->fetchOne(
            'SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = ?',
            [$imageId]
        );
        self::assertIsNumeric($categoryId);
        self::assertSame(1, (int) $categoryId);
    }

    /**
     * Multipart POST (form-data), the transport pwg.images.uploadAsync
     * requires for its $_FILES['file'] entry -- http_build_query() (used by
     * callWs()/callWsAllowingServerError() above) can't express a file part.
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function uploadAsyncMultipart(array $fields, string $fileContents): array
    {
        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_async_');
        self::assertNotFalse($tmpName);
        $tmpFile = $tmpName . '.png';
        file_put_contents($tmpFile, $fileContents);

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch  = curl_init($url);
            self::assertNotFalse($ch);

            $cookieJar = $this->cookieJar();
            assert($cookieJar !== '');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge(
                ['method' => 'pwg.images.uploadAsync', 'file' => new \CURLFile($tmpFile, 'image/png', 'chunk.png')],
                $fields
            ));
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

            $body = curl_exec($ch);
            unset($ch);
        } finally {
            @unlink($tmpFile);
        }

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function test_uploadAsync_rejects_a_malformed_original_sum(): void
    {
        $response = $this->uploadAsyncMultipart([
            'original_sum' => 'not-32-hex-chars',
            'chunk' => 0,
            'chunk_sum' => md5(''),
            'chunks' => 1,
            'filename' => 'async-test.png',
        ], $this->pngBytes());

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid original_sum', $response['message']);
    }

    public function test_uploadAsync_rejects_a_chunk_sum_mismatch(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        $originalSum = md5($bytes);

        $response = $this->uploadAsyncMultipart([
            'original_sum' => $originalSum,
            'chunk' => 0,
            'chunk_sum' => md5('this-does-not-match-the-real-chunk'),
            'chunks' => 1,
            'filename' => 'async-test.png',
        ], $bytes);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('MD5 checksum chunk file mismatched', $response['message']);
    }

    public function test_uploadAsync_creates_a_photo_from_a_single_chunk(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        $originalSum = md5($bytes);

        $response = $this->uploadAsyncMultipart([
            'original_sum' => $originalSum,
            'chunk' => 0,
            'chunk_sum' => md5($bytes),
            'chunks' => 1,
            'filename' => 'async-test-' . uniqid() . '.png',
            'category' => [1],
            'name' => 'Async upload test photo',
        ], $bytes);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $imageId = $result['id'] ?? null;
        self::assertIsNumeric($imageId);
        $imageId = (int) $imageId;
        $this->createdImageIds[] = $imageId;

        $name = $this->conn->fetchOne('SELECT name FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        self::assertSame('Async upload test photo', $name);
    }

    public function test_uploadAsync_nonexistent_image_id_returns_error(): void
    {
        $bytes = $this->pngBytes() . uniqid();

        $response = $this->uploadAsyncMultipart([
            'original_sum' => md5($bytes),
            'chunk' => 0,
            'chunk_sum' => md5($bytes),
            'chunks' => 1,
            'filename' => 'async-test.png',
            'image_id' => 999999,
        ], $bytes);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    // ---------------------------------------------------------------- addFile

    private function insertThrowawayImage(?string $md5sum): int
    {
        $filename = 'addfile-test-' . uniqid() . '.jpg';
        // filesize (bytes) comfortably bigger than the 70-byte TINY_PNG_B64
        // fixture used to build a "smaller replacement" merged file below.
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum, width, height, filesize) VALUES (?, ?, ?, ?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, $md5sum, 200, 150, 1000]
        );
        $id = (int) $this->conn->lastInsertId();
        $this->createdImageIds[] = $id;

        return $id;
    }

    public function test_addFile_missing_image_id_returns_404(): void
    {
        $response = $this->callWsAllowingServerError('pwg.images.addFile', [
            'image_id' => 999999,
            'type' => 'thumb',
            'sum' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function test_addFile_on_a_photo_without_md5sum_returns_error(): void
    {
        $imageId = $this->insertThrowawayImage(null);

        $response = $this->callWsAllowingServerError('pwg.images.addFile', [
            'image_id' => $imageId,
            'type' => 'thumb',
            'sum' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('[ws_images_addFile] image_id ' . $imageId . ' has no md5sum', $response['message']);
    }

    public function test_addFile_thumb_type_is_a_no_op_success(): void
    {
        // Since Piwigo 2.4, thumbnails are always server-generated -- the
        // 'thumb' branch just discards any buffered chunks for this md5sum
        // and reports success without touching the photo at all.
        $imageId = $this->insertThrowawayImage(md5(uniqid()));

        $response = $this->callWs('pwg.images.addFile', [
            'image_id' => $imageId,
            'type' => 'thumb',
            'sum' => 'irrelevant',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(true, $response['result']);
    }

    public function test_addFile_with_a_smaller_replacement_keeps_the_original(): void
    {
        // fixture-sized throwaway (200x150, 50 bytes) vs. the tiny 1x1 test
        // PNG -- none of width/height/filesize is bigger, so addFile()'s
        // do_update stays false and the merged buffer file is discarded
        // without ever reaching UploadService::addUploadedFile().
        $md5sum = md5(uniqid());
        $imageId = $this->insertThrowawayImage($md5sum);

        $chunkResponse = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64,
            'original_sum' => $md5sum,
            'type' => 'file',
            'position' => 0,
        ]);
        self::assertSame('ok', $chunkResponse['stat']);

        $response = $this->callWs('pwg.images.addFile', [
            'image_id' => $imageId,
            'type' => 'file',
            'sum' => $md5sum,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(true, $response['result']);

        $bufferPath = dirname(__DIR__, 2) . '/upload/buffer/' . $md5sum . '-original';
        self::assertFileDoesNotExist($bufferPath);
    }
}
