<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use CURLFile;
use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Cache\CachePools;
use Piwigo\Db\DbConnection;

/**
 * Ws\Images::upload() (`pwg.images.upload`) -- covers only the START of
 * the method (buffer-directory setup, the chunked/multipart file-name
 * resolution chain, the output-stream/input-stream plumbing, and the
 * format_of/update_mode branches, all above source line 1660). This is a
 * deliberately narrow split from WsUploadTest.php/WsImagesUploadGapsTest.php
 * (which already cover upload()'s "happy path" and its tail past line 1660
 * -- category/lounge handling, the final response shape) to keep coverage
 * of different parts of the same source file in separate test files
 * rather than one shared file.
 *
 * Every scenario below is deliberately steered to fail (or format_of-return)
 * *before* reaching upload()'s own "new photo" UploadService::addUploadedFile()
 * call: a real, pre-existing bug confirmed live while writing these tests --
 * calling pwg.images.upload for a genuinely new (non-format_of) photo
 * without a 'name' parameter produces a raw, unhandled
 * Doctrine\DBAL\Exception\NotNullConstraintViolationException ("Column
 * 'file' cannot be null") deep in the Image repository insert path, not a
 * graceful WsErrorResponse -- out of scope to chase here (same category as the
 * two branches WsImagesUploadGapsTest's own
 * docblock already documents this way).
 *
 * Two of upload()'s own `@fopen(...)` failure guards are NOT chased here
 * either: both open a stream this method itself just proved exists and is
 * readable moments earlier (a genuine is_uploaded_file() tmp file, or
 * php://input, which -- confirmed live -- opens successfully even for a
 * request with zero $_FILES entries) -- there's no legitimate way to make
 * either fopen() call fail without an actual OS-level fault or a race
 * condition, matching the class of "confirmed-infeasible" defensive guards
 * documented elsewhere in these upload tests (e.g.
 * WsImagesChunkedUploadTest's mergeChunks() unlink-failure note).
 */
final class WsImagesUploadStartTest extends ContractTestCase
{
    /**
     * 1×1 white PNG, base64-decoded at runtime to avoid binary in source.
     */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

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
        $this->conn->executeStatement("DELETE FROM config WHERE param = 'enable_formats'");
        CachePools::config()->clear();
        parent::tearDown();
    }

    private function pwgToken(): string
    {
        return $this->getPwgToken();
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(self::TINY_PNG_B64, true);
        self::assertNotFalse($bytes);

        return $bytes;
    }

    private function bufferDir(): string
    {
        return dirname(__DIR__, 2) . '/upload/buffer';
    }

    private function enableFormats(): void
    {
        $this->upsertConfig('enable_formats', 'true');
        CachePools::config()->clear();
    }

    // ------------------------------------------------ buffer directory setup

    public function testUploadBufferDirectoryCreationFailureReturnsError(): void
    {
        // Same rationale/technique as
        // WsImagesChunkedUploadTest::test_addChunk_buffer_directory_creation_failure_returns_error --
        // FilesystemHelper::mkgetdir() only calls mkdir() when the target
        // isn't already a directory; upload/buffer is owned by www-data (not
        // this test process), so a plain *file* temporarily occupying that
        // exact path is the only way to force mkdir()'s EEXIST failure
        // without root or www-data privileges.
        $bufferDir = $this->bufferDir();
        $backupDir = $bufferDir . '_test_backup_' . uniqid();

        rename($bufferDir, $backupDir);
        touch($bufferDir);

        try {
            $response = $this->callWsAllowingServerError('pwg.images.upload', [
                'pwg_token' => $this->pwgToken(),
                'category' => 1,
                'name' => 'irrelevant.png',
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(500, $response['err']);
            self::assertSame('error during buffer directory creation', $response['message']);
        } finally {
            unlink($bufferDir);
            rename($backupDir, $bufferDir);
        }
    }

    // --------------------------------------------- file name resolution chain

    public function testUploadWithoutANameFieldFallsBackToTheUploadedFilesOwnName(): void
    {
        // Omitting 'name' makes chunkedUploadRequest->requestNamePresent
        // false; a genuine 'file[]' bracket-syntax field makes PHP populate
        // $_FILES['file']['name']/['error']/['tmp_name'] as *arrays* (multi-
        // file syntax), not scalars -- UploadedFileRequest::fromArray()'s
        // is_string()/is_int() guards then store null for each, so
        // ->present is still true (the key exists) but ->name is null.
        // Together this reaches the elseif branch (uploaded_file->present)
        // for the internal buffer filename, then -- since ->tmpName is also
        // null -- the very next real guard ("Failed to move uploaded file"),
        // well before upload()'s own buggy "new photo" insert path.
        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_uploadstart_');
        self::assertNotFalse($tmpName);
        $tmpFile = $tmpName . '.png';
        file_put_contents($tmpFile, $this->pngBytes());

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch = curl_init($url);
            self::assertNotFalse($ch);
            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'method' => 'pwg.images.upload',
                'pwg_token' => $this->pwgToken(),
                'category' => 1,
                'file[]' => new CURLFile($tmpFile, 'image/png', 'array-style.png'),
            ]);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
            $body = curl_exec($ch);
            unset($ch);
        } finally {
            @unlink($tmpFile);
        }

        self::assertIsString($body);
        $response = json_decode($body, true);
        self::assertIsArray($response);
        self::assertSame('fail', $response['stat']);
        self::assertSame(103, $response['err']);
        self::assertSame('Failed to move uploaded file.', $response['message']);

        // The internal buffer filename is md5('') (uploaded_file->name is
        // null, not a string, so the md5() input collapses to '') -- a real
        // .part file was opened for it before the early return above (which
        // skips its own cleanup on this branch).
        $partPath = $this->bufferDir() . '/' . md5('') . '.part';
        self::assertFileExists($partPath);
        unlink($partPath);
    }

    public function testUploadWithoutANameOrFileFieldFallsBackToAGeneratedName(): void
    {
        // Neither 'name' nor a 'file' field at all (some *other* field
        // still makes $_FILES non-empty, matching WsUploadTest's own
        // test_upload_missing_file_field_returns_a_properly_encoded_error)
        // -- requestNamePresent and uploaded_file->present are both false,
        // reaching the else branch (uniqid() fallback) for the internal
        // buffer filename, then the "Failed to move uploaded file" guard
        // just past it (uploaded_file->present is false for the 'file' key
        // specifically).
        $before = scandir($this->bufferDir());
        self::assertIsArray($before);

        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_uploadstart_');
        self::assertNotFalse($tmpName);
        file_put_contents($tmpName, 'not a real upload');

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch = curl_init($url);
            self::assertNotFalse($ch);
            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'method' => 'pwg.images.upload',
                'pwg_token' => $this->pwgToken(),
                'category' => 1,
                'wrongfield' => new CURLFile($tmpName, 'image/png', 'wrong-field.png'),
            ]);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
            $body = curl_exec($ch);
            unset($ch);
        } finally {
            @unlink($tmpName);
        }

        self::assertIsString($body);
        $response = json_decode($body, true);
        self::assertIsArray($response);
        self::assertSame('fail', $response['stat']);
        self::assertSame(103, $response['err']);
        self::assertSame('Failed to move uploaded file.', $response['message']);

        // The generated buffer filename (md5(uniqid('file_'))) isn't
        // predictable -- find and remove whatever new .part file this
        // request left behind, identified by diffing against the directory
        // snapshot taken before the request rather than assuming a fixed
        // pre-existing file list.
        $after = scandir($this->bufferDir());
        self::assertIsArray($after);
        $leftover = array_values(array_diff($after, $before));
        self::assertCount(1, $leftover, 'expected exactly one new file in the buffer dir');
        self::assertStringEndsWith('.part', $leftover[0]);
        unlink($this->bufferDir() . '/' . $leftover[0]);
    }

    public function testUploadPartFileOpenFailureReturnsError(): void
    {
        // Predicting the internal buffer filename (md5(requestName), the IF
        // branch above the elseif/else pair) lets a directory be planted at
        // the exact "{filePath}.part" path fopen(..., 'wb') is about to
        // open for writing -- fopen() can't open a directory for writing,
        // so this reaches the guard without any directory permission
        // changes at all.
        $name = 'part-collision-' . uniqid();
        $hash = md5($name);
        $strayDir = $this->bufferDir() . '/' . $hash . '.part';
        mkdir($strayDir);

        try {
            // Unlike the >= 500 WsErrorResponses elsewhere in this file, err=102
            // isn't mirrored onto the real HTTP status (WsErrorResponse's
            // constructor only does that for codes >= 400) -- a plain
            // callWs() is fine here, confirmed live (HTTP 200).
            $response = $this->callWs('pwg.images.upload', [
                'pwg_token' => $this->pwgToken(),
                'category' => 1,
                'name' => $name,
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(102, $response['err']);
            self::assertSame('Failed to open output stream.', $response['message']);
        } finally {
            rmdir($strayDir);
        }
    }

    // --------------------------------------------------------- format_of

    public function testUploadFormatOfReadsTheRawRequestBodyWhenNoFilesArePosted(): void
    {
        // A plain (non-multipart) POST -- no $_FILES entry at all -- takes
        // upload()'s own php://input branch instead of the $_FILES one.
        // format_of doesn't touch getimagesize()/pwgImageInfos() on the
        // resulting file at all (see UploadService::addFormat()), so an
        // empty/garbage body doesn't crash it the way a genuine "new photo"
        // upload would.
        $this->enableFormats();

        $response = $this->callWs('pwg.images.upload', [
            'pwg_token' => $this->pwgToken(),
            'format_of' => 999999,
            'name' => 'raw-body-probe.tif',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('upload : image_id not found', $response['message']);

        // The merged buffer file (named md5('raw-body-probe.tif'), no
        // .part suffix -- it's already been renamed by the time the
        // format_of/image_id check runs) is left behind on this error path.
        $mergedPath = $this->bufferDir() . '/' . md5('raw-body-probe.tif');
        self::assertFileExists($mergedPath);
        unlink($mergedPath);
    }

    // --------------------------------------------------------- update_mode

    public function testUploadUpdateModeWithAnExistingMatchingFilenameMarksAddStatusUpdate(): void
    {
        $filename = 'update-mode-probe-' . uniqid() . '.jpg';
        $this->conn->executeStatement(
            'INSERT INTO images (file, path, md5sum, width, height, filesize) VALUES (?, ?, ?, ?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename), 1, 1, 0]
        );
        $existingId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO image_category (image_id, category_id) VALUES (?, ?)',
            [$existingId, 1]
        );

        try {
            $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_uploadstart_');
            self::assertNotFalse($tmpName);
            $tmpFile = $tmpName . '.png';
            file_put_contents($tmpFile, $this->pngBytes());

            try {
                $url = $this->baseUrl . '/ws.php?format=json';
                $ch = curl_init($url);
                self::assertNotFalse($ch);
                $cookieJar = $this->cookieJar();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'method' => 'pwg.images.upload',
                    'pwg_token' => $this->pwgToken(),
                    'category' => 1,
                    'name' => $filename,
                    'update_mode' => 'true',
                    'file' => new CURLFile($tmpFile, 'image/png', 'replacement.png'),
                ]);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
                $body = curl_exec($ch);
                unset($ch);
            } finally {
                @unlink($tmpFile);
            }

            self::assertIsString($body);
            $response = json_decode($body, true);
            self::assertIsArray($response);
            /** @var array<string, mixed> $response */
            self::assertSame('ok', $response['stat'], (string) json_encode($response));
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame($existingId, $result['image_id']);
            self::assertSame('update', $result['add_status']);
        } finally {
            $token = $this->pwgToken();
            $this->callWs('pwg.images.delete', [
                'image_id' => $existingId,
                'pwg_token' => $token,
            ]);
        }
    }
}
