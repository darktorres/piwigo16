<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;

final class WsImagesTest extends ContractTestCase
{
    /**
     * Image ID 1 is the first photo seeded by the fixture.
     */
    private const int FIXTURE_IMAGE_ID = 1;

    /**
     * Fixture image 1's real md5sum (tests/Fixtures/piwigo-17.0.sql).
     */
    private const string FIXTURE_IMAGE_MD5 = '2e7ee450c4a4cffe42945205029782b9';

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    public function testGetInfoResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.images.getInfo', [
            'image_id' => self::FIXTURE_IMAGE_ID,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('images.getInfo', $response);
    }

    public function testGetInfoReturnsExpectedFields(): void
    {
        $response = $this->wsAdmin('pwg.images.getInfo', [
            'image_id' => self::FIXTURE_IMAGE_ID,
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame(self::FIXTURE_IMAGE_ID, $result['id']);
        self::assertIsString($result['file']);
        self::assertIsArray($result['derivatives']);
        self::assertIsArray($result['categories']);
        self::assertIsArray($result['tags']);
        self::assertNotEmpty($result['categories'], 'Fixture image must belong to at least one album');
    }

    public function testGetInfoOnMissingImageReturns404(): void
    {
        // ws.php sends HTTP 404 for WsErrorResponse(404, ...) — not 200+stat=fail.
        // The contract is: HTTP 404, body may or may not be valid JSON.
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_POSTFIELDS => http_build_query([
                'method' => 'pwg.images.getInfo',
                'image_id' => 999999,
            ]),
            CURLOPT_HTTPHEADER => $this->testHeader(),
        ]);

        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertSame(404, $status, 'Missing image_id must return HTTP 404');
    }

    // exist()'s two preg_split() failure guards (md5sum_list/filename_list,
    // both `preg_split('/[\s,;\|]/', ..., PREG_SPLIT_NO_EMPTY)`) are not
    // exercised anywhere in this file: preg_split() only returns false on a
    // genuine PCRE engine error (PREG_BACKTRACK_LIMIT_ERROR/
    // PREG_RECURSION_LIMIT_ERROR/etc, see
    // MetadataServiceTest::test_parse_svg_dimensions_returns_null_when_preg_replace_hits_the_backtrack_limit()
    // for the standard way this codebase forces one -- ini_set()'ing
    // pcre.backtrack_limit down before the call). This pattern is a plain,
    // non-backtracking character class with no quantifiers/groups at all,
    // so no crafted subject string can make it exceed the default 1,000,000
    // backtrack/recursion budget (verified: `php -i | grep pcre.` on this
    // box's own apache2 SAPI shows the untouched defaults). And since
    // Contract tests only ever reach this code over real HTTP
    // (ContractTestCase always curl()s a separate, already-running Apache
    // process -- see its own docblock), an ini_set() in this test process
    // can't reach the server process's PCRE settings either, unlike the
    // Integration-level MetadataServiceTest technique. Confirmed
    // unreachable from a black-box Contract test in this environment, not
    // just unattempted.

    public function testExistResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.images.exist', [
            'md5sum_list' => 'nonexistentmd5sumvalue0000000000',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.images.exist', $response);
    }

    public function testExistReturnsNullForUnknownMd5(): void
    {
        $response = $this->wsAdmin('pwg.images.exist', [
            'md5sum_list' => 'nonexistentmd5sumvalue0000000000',
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertNull($result['nonexistentmd5sumvalue0000000000']);
    }

    public function testExistReturnsIdForAKnownMd5(): void
    {
        // CurrentConfig::uniquenessMode()'s default ('md5sum') is what's in
        // effect here -- no config row override needed.
        $response = $this->wsAdmin('pwg.images.exist', [
            'md5sum_list' => self::FIXTURE_IMAGE_MD5,
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame(self::FIXTURE_IMAGE_ID, $result[self::FIXTURE_IMAGE_MD5]);
    }

    public function testExistInFilenameModeReturnsIdForAKnownFilename(): void
    {
        $this->upsertConfig('uniqueness_mode', '"filename"');
        // Raw SQL bypasses ConfigService::confUpdateParam()'s own cache
        // clear -- ConfigCachePool is a real FilesystemAdapter (no
        // ext-apcu here), files under _data/cache/ visible to both this
        // process and the Apache worker serving the WS call below, so a
        // stale cached row would otherwise survive the write.
        $this->configCachePool()
            ->clear();
        try {
            $response = $this->wsAdmin('pwg.images.exist', [
                'filename_list' => 'fixture-photo-1.jpg,nonexistent-file.jpg',
            ]);

            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame(self::FIXTURE_IMAGE_ID, $result['fixture-photo-1.jpg']);
            self::assertNull($result['nonexistent-file.jpg']);
        } finally {
            $this->conn->executeStatement(
                "DELETE FROM config WHERE param = 'uniqueness_mode'"
            );
            $this->configCachePool()
                ->clear();
        }
    }

    public function testCheckUploadResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.images.checkUpload');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.images.checkUpload', $response);
    }

    public function testCheckUploadContainsReadyForUploadFlag(): void
    {
        $response = $this->wsAdmin('pwg.images.checkUpload');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('ready_for_upload', $result);
        self::assertIsBool($result['ready_for_upload']);
    }

    public function testCheckUploadReportsNotReadyWhenTheUploadDirectoryIsNotWritable(): void
    {
        // UploadService::readyForUploadMessage()'s "directory exists but
        // isn't writable" branch: point CurrentConfig::uploadDir() (a plain
        // 'upload_dir' config row, same override mechanism as every other
        // config toggle in this file) at a dedicated directory this test
        // process itself creates and owns -- unlike the real shared
        // upload/buffer/ (owned by www-data, see WsImagesUploadGapsTest's
        // own doc note on why that one can't be chmod()'d from here), this
        // one genuinely is owned by this CLI process, so chmod() on it
        // actually takes effect. readyForUploadMessage() then also tries
        // `@chmod($upload_dir, 0777)` as a self-heal -- that attempt is made
        // by the *www-data* WS worker, which doesn't own this directory
        // either, so it silently fails and the non-writable state sticks.
        $relDir = 'readonly-upload-test-' . uniqid() . '/';
        $absDir = dirname(__DIR__, 2) . '/' . $relDir;
        mkdir($absDir);

        $encodedRelDir = json_encode($relDir);
        self::assertIsString($encodedRelDir);
        $this->upsertConfig('upload_dir', $encodedRelDir);
        $this->configCachePool()
            ->clear();
        chmod($absDir, 0o555);

        try {
            $response = $this->wsAdmin('pwg.images.checkUpload');

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertFalse($result['ready_for_upload']);
            self::assertIsString($result['message']);
            self::assertStringContainsString('chmod 777', $result['message']);
        } finally {
            chmod($absDir, 0o755);
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'upload_dir'");
            $this->configCachePool()
                ->clear();
            rmdir($absDir);
        }
    }
}
