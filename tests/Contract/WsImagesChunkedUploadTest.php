<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use CURLFile;
use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;

/**
 * Ws\Images's older, 2-step chunked-upload API: addChunk, add --
 * pwg.images.addChunk buffers base64 chunks to disk keyed by
 * original_sum, pwg.images.add merges them and creates the photo. Distinct
 * from the newer addSimple/upload multipart flow covered in
 * WsUploadTest.php.
 *
 * Also covers mergeChunks()'s own write-failure guard, addChunk()'s
 * buffer-directory-creation guard, and addFile()'s 'high'-type/
 * do_update=true branches (the latter two calling
 * UploadService::addUploadedFile() with a non-null $id_image -- the
 * "replace an existing photo's file" path; see that method's own
 * docblock).
 *
 * mergeChunks()'s *other* guard (is_file() still true right after its own
 * unlink() call) is NOT chased here: upload/buffer is owned by www-data,
 * not this test process, and unlink() only depends on the *directory's*
 * write permission (which this process can't restrict without owning the
 * directory or root) -- not on the target file's own permission bits, and
 * the directory is world-writable with no sticky bit. No way to make
 * unlink() fail there short of an actual disk/OS-level fault.
 */
final class WsImagesChunkedUploadTest extends ContractTestCase
{
    /**
     * 1x1 white PNG, base64-decoded at runtime to avoid binary in source.
     */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    private Connection $conn;

    /**
     * @var list<int> image ids created by a test, deleted in tearDown if not already removed.
     */
    private array $createdImageIds = [];

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
        foreach ($this->createdImageIds as $id) {
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(self::TINY_PNG_B64, true);
        self::assertNotFalse($bytes);

        return $bytes;
    }

    public function testAddChunkWritesABufferFile(): void
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

    public function testAddChunkWithInvalidBase64ReturnsError(): void
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

    /**
     * SEC finding 3: neither original_sum nor type carried a WsParamType,
     * so Server::invoke() applied no coercion beyond rejecting arrays --
     * addChunk() built the buffer filename straight from the raw value.
     * A path-traversal-shaped original_sum used to escape the buffer
     * directory entirely (the "-<type>-<NNNNN>.block" suffix stayed
     * forced, so this was an arbitrary-directory write with a forced
     * extension, not arbitrary-file overwrite).
     */
    public function testAddChunkRejectsAPathTraversalOriginalSum(): void
    {
        $traversalTarget = sys_get_temp_dir() . '/pwg-addchunk-traversal-poc-file-00000.block';
        if (is_file($traversalTarget)) {
            unlink($traversalTarget);
        }

        $response = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64,
            'original_sum' => '../../../../../../tmp/pwg-addchunk-traversal-poc',
            'type' => 'file',
            'position' => 0,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid original_sum', $response['message']);
        self::assertFileDoesNotExist($traversalTarget);
    }

    public function testAddChunkRejectsAnInvalidType(): void
    {
        $sum = md5(uniqid());

        $response = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64,
            'original_sum' => $sum,
            'type' => 'not-a-real-type',
            'position' => 0,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid type', $response['message']);
    }

    public function testAddRejectsADuplicateMd5sumBeforeTouchingAnyChunk(): void
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

    public function testAddCreatesAPhotoFromASingleChunkWithCategoryAndTags(): void
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

        $name = $this->conn->fetchOne('SELECT name FROM images WHERE id = ?', [$imageId]);
        self::assertSame('Chunked upload test photo', $name);

        $categoryId = $this->conn->fetchOne(
            'SELECT category_id FROM image_category WHERE image_id = ?',
            [$imageId]
        );
        self::assertSame(1, $categoryId);
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
            $ch = curl_init($url);
            self::assertNotFalse($ch);

            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge(
                [
                    'method' => 'pwg.images.uploadAsync',
                    'file' => new CURLFile($tmpFile, 'image/png', 'chunk.png'),
                ],
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

    public function testUploadAsyncRejectsAMalformedOriginalSum(): void
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

    public function testUploadAsyncRejectsAChunkSumMismatch(): void
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

    public function testUploadAsyncCreatesAPhotoFromASingleChunk(): void
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

        $name = $this->conn->fetchOne('SELECT name FROM images WHERE id = ?', [$imageId]);
        self::assertSame('Async upload test photo', $name);
    }

    public function testUploadAsyncNonexistentImageIdReturnsError(): void
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
            'INSERT INTO images (file, path, md5sum, width, height, filesize) VALUES (?, ?, ?, ?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, $md5sum, 200, 150, 1000]
        );
        $id = (int) $this->conn->lastInsertId();
        $this->createdImageIds[] = $id;

        return $id;
    }

    public function testAddFileMissingImageIdReturns404(): void
    {
        $response = $this->callWsAllowingServerError('pwg.images.addFile', [
            'image_id' => 999999,
            'type' => 'thumb',
            'sum' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function testAddFileOnAPhotoWithoutMd5sumReturnsError(): void
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

    public function testAddFileThumbTypeIsANoOpSuccess(): void
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
        self::assertTrue($response['result']);
    }

    public function testAddFileWithASmallerReplacementKeepsTheOriginal(): void
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

        // addFile() reads EXIF from the existing DB row's addfile-test-*.jpg
        // filename against the merged buffer's real (non-JPEG) test-fixture
        // bytes -- exif_read_data() warns "File not supported" (confirmed
        // live), same wrong-extension-real-content tradeoff MetadataService's
        // own docblock already documents.
        $response = $this->callWs('pwg.images.addFile', [
            'image_id' => $imageId,
            'type' => 'file',
            'sum' => $md5sum,
        ], allowPhpWarnings: true);

        self::assertSame('ok', $response['stat']);
        self::assertTrue($response['result']);

        $bufferPath = dirname(__DIR__, 2) . '/upload/buffer/' . $md5sum . '-original';
        self::assertFileDoesNotExist($bufferPath);
    }

    /**
     * Real, uncompressible-enough bytes to clear pwgImageInfos()'s
     * floor(bytes/1024) KB rounding (see test_addFile_with_a_bigger_
     * replacement_updates_the_original below) -- a plain solid-fill PNG
     * compresses far too well to ever measure as more than 0KB.
     */
    private function biggerPngBytes(): string
    {
        $img = imagecreatetruecolor(64, 64);
        self::assertNotFalse($img);
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $color = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                self::assertNotFalse($color);
                imagesetpixel($img, $x, $y, $color);
            }
        }
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        self::assertGreaterThan(1024, strlen($bytes));

        return $bytes;
    }

    // ----------------------------------------------------------- mergeChunks

    public function testAddWithAStrayDirectoryShapedLikeAChunkSkipsTheUnlinkAndStillSucceeds(): void
    {
        // mergeChunks()'s own `file_get_contents($chunk) === false` write-
        // failure guard: its return value is never checked by either real
        // caller (add()/addFile()), so a failing merge doesn't fail the WS
        // call -- it just leaves that one "chunk" file on disk (its own
        // unlink() is skipped by the guard's early return). A directory can
        // never really collide with a chunk filename in production (only
        // addChunk() ever names files this way, always as plain files) --
        // this is only a vehicle to exercise the guard itself.
        $sum = md5($this->pngBytes() . uniqid());

        $chunk = $this->callWs('pwg.images.addChunk', [
            'data' => self::TINY_PNG_B64,
            'original_sum' => $sum,
            'type' => 'file',
            'position' => 0,
        ]);
        self::assertSame('ok', $chunk['stat']);

        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';
        // Sorts after position 00000 above, so mergeChunks() processes the
        // real chunk (which alone is already a complete, valid PNG) first.
        $strayDir = $bufferDir . $sum . '-file-00001.block';
        mkdir($strayDir);

        try {
            // The real photo-creation response is still 'ok' -- but this
            // test environment's own strict-error-reporting layer promotes
            // the underlying `file_get_contents(): ... Is a directory`
            // NOTICE onto the real HTTP status line too (confirmed live:
            // "HTTP/1.1 500 [merge_chunks] error while writting chunks for
            // ..."), independent of and in addition to the JSON body's own
            // 'ok' status -- callWsAllowingServerError() is needed here
            // just to skip callWs()'s own `assertLessThan(500, $status)`
            // guard, not because of a deliberately-returned WsErrorResponse (its
            // usual rationale, see its own docblock).
            $response = $this->callWsAllowingServerError('pwg.images.add', [
                'original_sum' => $sum,
                'check_uniqueness' => false,
                'original_filename' => 'stray-dir-chunk-' . uniqid() . '.jpg',
            ], allowPhpWarnings: true);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $imageId = $result['image_id'];
            self::assertIsNumeric($imageId);
            $this->createdImageIds[] = (int) $imageId;

            // The early return skips this chunk's own unlink() -- it's
            // never cleaned up by mergeChunks() itself.
            self::assertDirectoryExists($strayDir);
        } finally {
            rmdir($strayDir);
        }
    }

    // -------------------------------------------------------------- addChunk

    public function testAddChunkBufferDirectoryCreationFailureReturnsError(): void
    {
        // FilesystemHelper::mkgetdir() only ever calls mkdir() when the
        // target doesn't already exist as a directory -- upload/buffer is
        // normally always present, and it's owned by www-data (not this
        // test process), so its permissions can't be restricted from here.
        // A plain *file* temporarily occupying that exact path makes
        // mkdir() fail with EEXIST regardless of ownership, which is enough
        // to reach the branch without root or www-data privileges.
        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer';
        $backupDir = $bufferDir . '_test_backup_' . uniqid();

        rename($bufferDir, $backupDir);
        touch($bufferDir);

        try {
            $response = $this->callWsAllowingServerError('pwg.images.addChunk', [
                'data' => self::TINY_PNG_B64,
                'original_sum' => md5(uniqid()),
                'type' => 'file',
                'position' => 0,
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(500, $response['err']);
            self::assertSame('error during buffer directory creation', $response['message']);
        } finally {
            unlink($bufferDir);
            rename($backupDir, $bufferDir);
        }
    }

    // ---------------------------------------------------- addFile (continued)

    public function testAddFileHighTypeAlwaysReplacesTheOriginal(): void
    {
        // Unlike 'file', the 'high' type never runs the do_update
        // width/height/filesize comparison at all -- it unconditionally
        // calls UploadService::addUploadedFile() with the existing
        // image_id (the "replace an existing photo's file" path, see this
        // file's own class docblock).
        $md5sum = md5(uniqid());
        $imageId = $this->insertThrowawayImage($md5sum);

        $chunkResponse = $this->callWs('pwg.images.addChunk', [
            'data' => base64_encode($this->biggerPngBytes()),
            'original_sum' => $md5sum,
            'type' => 'high',
            'position' => 0,
        ]);
        self::assertSame('ok', $chunkResponse['stat']);

        // Same wrong-extension-real-content exif_read_data() warning as
        // test_addFile_with_a_smaller_replacement_keeps_the_original above
        // (confirmed live) -- the 'high' type always reaches
        // addUploadedFile(), unlike the 'file' type's do_update-gated path.
        $response = $this->callWs('pwg.images.addFile', [
            'image_id' => $imageId,
            'type' => 'high',
            'sum' => $md5sum,
        ], allowPhpWarnings: true);

        self::assertSame('ok', $response['stat']);
        self::assertNull($response['result']);
    }

    public function testAddFileWithABiggerReplacementUpdatesTheOriginal(): void
    {
        // Mirrors test_addFile_with_a_smaller_replacement_keeps_the_original
        // above, but the replacement now wins the comparison -- do_update
        // becomes true and addFile() proceeds to
        // UploadService::addUploadedFile() (see this file's own class
        // docblock).
        $md5sum = md5(uniqid());
        $imageId = $this->insertThrowawayImage($md5sum);
        // insertThrowawayImage()'s own fixed filesize (1000, i.e. ~1MB) is
        // tuned for the "smaller" test above -- drop it low enough that
        // even a modest real replacement clears it (do_update only needs
        // *one* of width/height/filesize to grow).
        $this->conn->executeStatement(
            'UPDATE images SET filesize = 0 WHERE id = ?',
            [$imageId]
        );

        $chunkResponse = $this->callWs('pwg.images.addChunk', [
            'data' => base64_encode($this->biggerPngBytes()),
            'original_sum' => $md5sum,
            'type' => 'file',
            'position' => 0,
        ]);
        self::assertSame('ok', $chunkResponse['stat']);

        // Same wrong-extension-real-content exif_read_data() warning as the
        // 'high'-type/smaller-replacement tests above (confirmed live) --
        // do_update forces this into the same addUploadedFile() path, just
        // non-deterministically (observed both with and without the warning
        // across runs, likely timing-dependent on biggerPngBytes()'s own
        // random per-pixel content).
        $response = $this->callWs('pwg.images.addFile', [
            'image_id' => $imageId,
            'type' => 'file',
            'sum' => $md5sum,
        ], allowPhpWarnings: true);

        self::assertSame('ok', $response['stat']);
        self::assertNull($response['result']);
    }
}
