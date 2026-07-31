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
 * One real branch used to be deliberately NOT exercised here (addFile()'s
 * do_update=true branch calling UploadService::addUploadedFile() with a
 * non-null $id_image, and pwg.images.upload's own update_mode=true branch):
 * confirmed live, twice, that this exact code path 500'd in this
 * environment -- even starting from a photo created moments earlier through
 * the normal WS upload flow (image id genuinely real, not a hand-crafted DB
 * row) -- with `getimagesize(upload/2026/08/01/....jpg): Failed to open
 * stream` in PwgImage.php and `Undefined array key "file"` in SrcImage.php.
 * That was a real, pre-existing bug in the Image domain's "replace an
 * existing photo's file" pipeline (UploadService::addUploadedFile()'s
 * $id_image-not-null path: a relative-not-absolute $file_path, plus a
 * SELECT that never fetched the `file` column SrcImage's constructor
 * trusted as present) -- since fixed (commit 6abce47d17, "close 25-class
 * coverage-gap batch"). pwg.images.upload's update_mode=true branch is now
 * exercised in WsUploadTest::test_upload_update_mode_replaces_an_existing_photo_by_filename_in_category().
 * addFile()'s own do_update=true branch is a different WS method, out of
 * this file's/this pass's scope to re-verify.
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
        // Distinct from $freshChunk: uploadAsync()'s cleanup runs two
        // separate glob()+foreach passes (one for '*.chunk', one for
        // '*.merged') -- each has its own "keep" (debug log, not-old-enough)
        // branch, so a *.chunk-only fresh file only proves the first pass's
        // keep-branch, not the second's.
        $freshMerged = $bufferDir . 'gap-fresh-' . uniqid() . '.merged';

        file_put_contents($staleChunk, 'x');
        file_put_contents($staleMerged, 'x');
        file_put_contents($freshChunk, 'x');
        file_put_contents($freshMerged, 'x');
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
            self::assertFileExists($freshMerged);
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
            if (file_exists($freshMerged)) {
                unlink($freshMerged);
            }
        }
    }

    // ---------------------------------------------------- uploadAsync() merge
    //
    // Three uploadAsync() branches deliberately NOT exercised anywhere in
    // this file, all confirmed (not just assumed) unreachable from a
    // black-box Contract/HTTP test in this environment:
    //
    // - mkgetdir()'s buffer-directory-creation failure (~1747-1748): the
    //   target directory (upload/buffer/) already exists for every real
    //   request (shared by every other upload test), so mkgetdir() takes
    //   its is_writable($dir) fast path, never the mkdir() one -- and that
    //   directory is www-data-owned (`stat -c '%U' upload/buffer` ->
    //   www-data), so this CLI test process cannot chmod it non-writable
    //   (chmod requires file ownership, not just group membership) to force
    //   that check to fail either. Flipping it via `sudo`/root would affect
    //   every other test sharing the same directory, sequential test run or
    //   not.
    // - flock($fp, LOCK_EX)'s own failure branch (~1826-1828): PHP's
    //   flock() without LOCK_NB blocks until it acquires the lock rather
    //   than returning false on contention, so a second real competing
    //   holder just delays this branch, never fails it -- only a
    //   filesystem without real advisory-lock support would trigger this,
    //   not available here.
    // - "chunk deleted by preceding merge" (~1838-1846): requires a second,
    //   genuinely concurrent uploadAsync request for the same original_sum
    //   to lose the flock() race *after* this request's own count-check
    //   loop already saw every chunk present, then find them gone once its
    //   turn comes -- real OS-level request concurrency (not reproducible
    //   deterministically from a single sequential curl-based test client,
    //   and PHP's default session-file locking would very likely serialize
    //   two same-session requests anyway, collapsing the race back into the
    //   same non-triggering sequential case covered by
    //   test_uploadAsync_merge_already_in_progress_reports_the_uploaded_chunk_list()
    //   above).

    public function test_uploadAsync_missing_file_field_returns_error(): void
    {
        // No $_FILES['file'] entry at all (a regular, non-multipart POST) --
        // UploadedFileRequest::fromFilesKey('file')->tmpName is null,
        // triggering uploadAsync()'s own "missing uploaded chunk file"
        // guard before any buffer-directory work happens.
        $response = $this->callWsAllowingServerError('pwg.images.uploadAsync', [
            'original_sum' => md5(uniqid()),
            'chunk' => 0,
            'chunk_sum' => md5(''),
            'chunks' => 1,
            'filename' => 'missing-file-field-' . uniqid() . '.png',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('missing uploaded chunk file', $response['message']);
    }

    public function test_uploadAsync_merge_already_in_progress_reports_the_uploaded_chunk_list(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        $sum = md5($bytes);
        $userId = 1; // fixture_admin

        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';
        $outputFilepath = $bufferDir . $sum . '-u' . $userId . '.merged';
        // Simulates another uploadAsync request already mid-merge for this
        // exact original_sum: mergeChunks()'s own "does the merge output
        // already exist and is it openable" check (fopen($output, 'rb'))
        // fires before this request ever gets to create/lock it itself.
        file_put_contents($outputFilepath, 'in-progress merge, not this request\'s');

        try {
            $response = $this->multipart('pwg.images.uploadAsync', [
                'original_sum' => $sum,
                'chunk' => 0,
                'chunk_sum' => md5($bytes),
                'chunks' => 1,
                'filename' => 'merge-in-progress-' . uniqid() . '.png',
            ], 'file', $bytes);

            self::assertSame('ok', $response['stat']);
            self::assertSame(['message' => 'chunks uploaded = 1'], $response['result']);
        } finally {
            @unlink($outputFilepath);
            // The request above still writes its own real chunk file
            // (uploadAsync()'s file-write step runs unconditionally, before
            // the "already merging" check) -- clean it up too.
            $chunkPath = $bufferDir . $sum . '-u' . $userId . '-001of001.chunk';
            if (file_exists($chunkPath)) {
                unlink($chunkPath);
            }
        }
    }

    public function test_uploadAsync_cannot_create_the_merge_file_returns_error(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        $sum = md5($bytes);
        $userId = 1;

        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';
        $outputFilepath = $bufferDir . $sum . '-u' . $userId . '.merged';
        // A broken symlink: file_exists()/is_file() report false (so
        // mergeChunks()'s own "already exists" check above doesn't
        // intercept first), but fopen(..., 'wb') still fails because the
        // link target's directory doesn't exist -- deterministically
        // reproduces "unable to create merge file" without touching any
        // shared directory's permissions.
        symlink('/nonexistent-dir-' . uniqid() . '/target.merged', $outputFilepath);
        self::assertFalse(file_exists($outputFilepath));

        try {
            $response = $this->multipart('pwg.images.uploadAsync', [
                'original_sum' => $sum,
                'chunk' => 0,
                'chunk_sum' => md5($bytes),
                'chunks' => 1,
                'filename' => 'merge-create-fail-' . uniqid() . '.png',
            ], 'file', $bytes);

            self::assertSame('fail', $response['stat']);
            self::assertSame(500, $response['err']);
            self::assertIsString($response['message']);
            self::assertStringStartsWith('error while creating merged ', $response['message']);
        } finally {
            @unlink($outputFilepath);
            $chunkPath = $bufferDir . $sum . '-u' . $userId . '-001of001.chunk';
            if (file_exists($chunkPath)) {
                unlink($chunkPath);
            }
        }
    }

    public function test_uploadAsync_merge_read_failure_on_one_chunk_returns_error(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        $sum = md5($bytes);
        $userId = 1;
        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';
        $firstChunkPath = $bufferDir . $sum . '-u' . $userId . '-001of002.chunk';
        $secondChunkPath = $bufferDir . $sum . '-u' . $userId . '-002of002.chunk';
        $outputFilepath = $bufferDir . $sum . '-u' . $userId . '.merged';

        $first = $this->multipart('pwg.images.uploadAsync', [
            'original_sum' => $sum,
            'chunk' => 0,
            'chunk_sum' => md5($bytes),
            'chunks' => 2,
            'filename' => 'merge-read-fail-' . uniqid() . '.png',
        ], 'file', $bytes);
        self::assertSame('ok', $first['stat']);
        self::assertFileExists($firstChunkPath);

        // Replace the already-uploaded first chunk with a directory:
        // file_exists()/fopen(..., 'rb') both still succeed for a directory
        // (confirmed: PHP's fopen() opens directories for reading on
        // Linux), so mergeChunks()'s own "not all chunks yet" count-check
        // still counts it as present -- but file_get_contents() on a
        // directory returns '' (not false), and fwrite($fp, '') returns
        // int(0), which is falsy -- so the merge loop's own
        // `$contents === false || ! fwrite(...)` guard still trips on the
        // second half of that OR, deterministically, without touching any
        // shared directory's permissions.
        unlink($firstChunkPath);
        mkdir($firstChunkPath);

        try {
            $second = $this->multipart('pwg.images.uploadAsync', [
                'original_sum' => $sum,
                'chunk' => 1,
                'chunk_sum' => md5($bytes),
                'chunks' => 2,
                'filename' => 'merge-read-fail-' . uniqid() . '.png',
            ], 'file', $bytes);

            self::assertSame('fail', $second['stat']);
            self::assertSame(500, $second['err']);
            self::assertSame('error while merging chunk 1', $second['message']);
        } finally {
            @rmdir($firstChunkPath);
            if (file_exists($secondChunkPath)) {
                unlink($secondChunkPath);
            }
            if (file_exists($outputFilepath)) {
                unlink($outputFilepath);
            }
        }
    }

    public function test_uploadAsync_merged_md5_mismatch_returns_error(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        // A well-formed but wrong original_sum: the per-chunk chunk_sum
        // check (matched against the real content below) passes, so the
        // mismatch only surfaces once the merged file's own md5 is checked
        // against original_sum.
        $wrongSum = md5(uniqid() . 'not-the-real-content');
        $userId = 1;
        $bufferDir = dirname(__DIR__, 2) . '/upload/buffer/';

        try {
            $response = $this->multipart('pwg.images.uploadAsync', [
                'original_sum' => $wrongSum,
                'chunk' => 0,
                'chunk_sum' => md5($bytes),
                'chunks' => 1,
                'filename' => 'merged-md5-mismatch-' . uniqid() . '.png',
            ], 'file', $bytes);

            self::assertSame('fail', $response['stat']);
            self::assertSame(500, $response['err']);
            self::assertSame('MD5 checksum merged file mismatched', $response['message']);
        } finally {
            $chunkPath = $bufferDir . $wrongSum . '-u' . $userId . '-001of001.chunk';
            if (file_exists($chunkPath)) {
                unlink($chunkPath);
            }
            $mergedPath = $bufferDir . $wrongSum . '-u' . $userId . '.merged';
            if (file_exists($mergedPath)) {
                unlink($mergedPath);
            }
        }
    }

    public function test_uploadAsync_sets_tags_and_applies_a_higher_privacy_level(): void
    {
        $bytes = $this->pngBytes() . uniqid();
        $sum = md5($bytes);

        $adminUserId = $this->conn->fetchOne(
            "SELECT id FROM " . Tables::users() . " WHERE username = 'fixture_admin'"
        );
        self::assertIsNumeric($adminUserId);

        $originalLevel = $this->conn->fetchOne(
            'SELECT level FROM ' . Tables::userInfos() . ' WHERE user_id = ?',
            [(int) $adminUserId]
        );
        self::assertIsNumeric($originalLevel);

        try {
            // uploadAsync()'s own "trick to bypass get_sql_condition_FandF"
            // (Ws\PwgImages.php ~1919-1925) only fires when the requested
            // level is both non-zero and strictly greater than the caller's
            // *own* current level -- fixture_admin's own level defaults to 8
            // (the fixture's webmaster row, tests/Fixtures/piwigo-17.0.sql),
            // so force it down to 0 first for a level=4 upload to genuinely
            // take that branch. RequestBootstrap re-reads user_infos fresh
            // on every request (UserBootstrap::buildUser()), so this is
            // visible to the WS call below without re-logging in.
            $this->conn->executeStatement(
                'UPDATE ' . Tables::userInfos() . ' SET level = 0 WHERE user_id = ?',
                [(int) $adminUserId]
            );

            $response = $this->multipart('pwg.images.uploadAsync', [
                'original_sum' => $sum,
                'chunk' => 0,
                'chunk_sum' => md5($bytes),
                'chunks' => 1,
                'filename' => 'level-trick-' . uniqid() . '.png',
                'category' => [1],
                'level' => 4,
                'tag_ids' => '1,2',
            ], 'file', $bytes);

            self::assertSame('ok', $response['stat'], (string) json_encode($response));
            $result = $response['result'];
            self::assertIsArray($result);
            $imageId = $result['id'] ?? null;
            self::assertIsNumeric($imageId);
            $this->imageIdsToDelete[] = (int) $imageId;

            $tagIds = array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                $this->conn->fetchFirstColumn(
                    'SELECT tag_id FROM ' . Tables::imageTag() . ' WHERE image_id = ? ORDER BY tag_id',
                    [(int) $imageId]
                )
            );
            self::assertSame([1, 2], $tagIds);

            $storedLevel = $this->conn->fetchOne(
                'SELECT level FROM ' . Tables::images() . ' WHERE id = ?',
                [(int) $imageId]
            );
            self::assertSame(4, is_numeric($storedLevel) ? (int) $storedLevel : -1);
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::userInfos() . ' SET level = ? WHERE user_id = ?',
                [(int) $originalLevel, (int) $adminUserId]
            );
        }
    }
}
