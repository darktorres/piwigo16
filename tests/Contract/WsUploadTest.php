<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsUploadTest extends ContractTestCase
{
    /** 1×1 white PNG, base64-decoded at runtime to avoid binary in source. */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    private ?int $uploadedImageId = null;

    private Connection $conn;

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
        if ($this->uploadedImageId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.images.delete', [
                'image_id'  => $this->uploadedImageId,
                'pwg_token' => $token,
            ]);
            $this->uploadedImageId = null;
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
     * Multipart POST for pwg.images.upload -- $_FILES['file'] is mandatory,
     * so http_build_query()-based callWs() can't express this request.
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function uploadMultipart(array $fields): array
    {
        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_upload_');
        self::assertNotFalse($tmpName);
        $tmpFile = $tmpName . '.png';
        file_put_contents($tmpFile, $this->pngBytes());

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
                ['method' => 'pwg.images.upload', 'file' => new \CURLFile($tmpFile, 'image/png', 'upload.png')],
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

    public function test_addSimple_uploads_image_and_returns_image_id(): void
    {
        // Write the tiny PNG to a temp file
        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_png_');
        self::assertNotFalse($tmpName);
        $tmpFile = $tmpName . '.png';
        $pngBytes = base64_decode(self::TINY_PNG_B64, true);
        self::assertNotFalse($pngBytes);
        file_put_contents($tmpFile, $pngBytes);

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch  = curl_init($url);
            self::assertNotFalse($ch, 'curl_init failed');

            $userAgent = self::USER_AGENT;
            $cookieJar = $this->cookieJar();
            assert($cookieJar !== '');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'method'   => 'pwg.images.addSimple',
                'category' => 1,
                'name'     => 'Contract Test Upload ' . uniqid(),
                'image'    => new \CURLFile($tmpFile, 'image/png', 'ct_upload.png'),
            ]);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
        } finally {
            @unlink($tmpFile);
        }

        self::assertIsString($body);
        self::assertLessThan(500, $status, sprintf('addSimple returned HTTP %d: %s', $status, $body));

        $response = json_decode($body, true);
        self::assertIsArray($response, 'addSimple response is not valid JSON: ' . $body);
        /** @var array<string, mixed> $response */
        self::assertSame('ok', $response['stat'], 'addSimple failed: ' . $body);

        self::assertMatchesSchema('pwg.images.addSimple', $response);

        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'addSimple result is not an array: ' . $body);
        $imageId = $result['image_id'] ?? null;
        self::assertIsNumeric($imageId, 'addSimple result.image_id is not numeric: ' . $body);
        self::assertGreaterThan(0, (int) $imageId);

        $this->uploadedImageId = (int) $imageId;
    }

    /**
     * Legacy Coupling Retirement gap-closure (Workstream C2): pwg.images.upload
     * used to die() a hardcoded raw JSON-RPC error string on this exact
     * condition (a multipart request with no $_FILES['file'] entry),
     * ignoring the request's real format=. Retargeted onto
     * `return new PwgError(103, ...)`, the same mechanism every other
     * error in this method already uses -- confirm it now honors format=
     * for both json and rest (PwgRestEncoder's XML output; 'xml' itself
     * isn't a recognized format= value, see WsInitializer's switch)
     * instead of always emitting raw JSON.
     */
    public function test_upload_missing_file_field_returns_a_properly_encoded_error(): void
    {
        $token = $this->getPwgToken();

        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_wrongfield_');
        self::assertNotFalse($tmpName);
        file_put_contents($tmpName, 'not a real upload');

        try {
            foreach (['json', 'rest'] as $format) {
                $url = $this->baseUrl . '/ws.php?format=' . $format;
                $ch  = curl_init($url);
                self::assertNotFalse($ch, 'curl_init failed');

                $cookieJar = $this->cookieJar();
                assert($cookieJar !== '');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'method'    => 'pwg.images.upload',
                    'pwg_token' => $token,
                    'name'      => 'wrong-field.png',
                    // Deliberately NOT named 'file' -- $_FILES is non-empty
                    // but $_FILES['file'] doesn't exist, the exact
                    // condition the former die() guarded.
                    'wrongfield' => new \CURLFile($tmpName, 'image/png', 'wrong-field.png'),
                ]);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

                $body   = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                unset($ch);

                self::assertIsString($body, "upload ({$format}) curl_exec failed");
                self::assertLessThan(500, $status, sprintf('upload (%s) returned HTTP %d: %s', $format, $status, $body));

                if ($format === 'json') {
                    $response = json_decode($body, true);
                    self::assertIsArray($response, 'upload (json) response is not valid JSON: ' . $body);
                    self::assertSame('fail', $response['stat'] ?? null, 'upload (json) did not report an error: ' . $body);
                    self::assertSame(103, $response['err'] ?? null);
                } else {
                    self::assertStringContainsString('<?xml', $body, 'upload (rest) response is not XML: ' . $body);
                    self::assertStringNotContainsString('jsonrpc', $body, 'upload (rest) leaked the old hardcoded JSON body');
                    self::assertStringContainsString('code="103"', $body, 'upload (rest) error body missing the expected code: ' . $body);
                }
            }
        } finally {
            @unlink($tmpName);
        }
    }

    public function test_upload_invalid_token_returns_error(): void
    {
        $response = $this->uploadMultipart([
            'pwg_token' => 'wrong',
            'category' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_upload_creates_a_new_photo_in_the_category(): void
    {
        $response = $this->uploadMultipart([
            'pwg_token' => $this->getPwgToken(),
            'category' => 1,
            'name' => 'Contract Test upload() ' . uniqid(),
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame('add', $result['add_status']);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $this->uploadedImageId = (int) $imageId;

        $category = $result['category'];
        self::assertIsArray($category);
        self::assertSame(1, $category['id']);
        self::assertIsInt($category['nb_photos']);
        self::assertGreaterThanOrEqual(1, $category['nb_photos']);
    }

    public function test_upload_format_of_disabled_returns_error(): void
    {
        // CurrentConfig::isFormatsEnabled()'s default (false, no 'enable_formats'
        // row in the fixture) is what's in effect for this WS request.
        $response = $this->uploadMultipart([
            'pwg_token' => $this->getPwgToken(),
            'format_of' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('formats are disabled', $response['message']);
    }

    public function test_upload_format_of_with_an_unauthorized_extension_returns_error(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('enable_formats', 'true')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            // CurrentConfig::formatExtensions()'s default doesn't include
            // 'png' -- the extension pulled from the (fake) upload filename
            // below via $params['name'].
            $response = $this->uploadMultipart([
                'pwg_token' => $this->getPwgToken(),
                'format_of' => 1,
                'name' => 'photo.png',
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(401, $response['err']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'enable_formats'");
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }

    public function test_upload_format_of_adds_a_format_to_an_existing_photo(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('enable_formats', 'true')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        $formatId = null;
        try {
            $response = $this->uploadMultipart([
                'pwg_token' => $this->getPwgToken(),
                'format_of' => 1,
                'name' => 'photo.tif',
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame(1, $result['image_id']);
            self::assertSame('add', $result['add_status']);

            $formatId = $this->conn->fetchOne(
                'SELECT format_id FROM ' . Tables::imageFormat() . ' WHERE image_id = 1 AND ext = ?',
                ['tif']
            );
            self::assertIsNumeric($formatId);
        } finally {
            if (is_numeric($formatId)) {
                // formats.delete (running as the same Apache/www-data process
                // that wrote the format file) unlinks the real pwg_format/
                // file on disk too -- a raw SQL DELETE would leave it behind,
                // owned by www-data and unremovable by this CLI test process.
                $this->callWs('pwg.images.formats.delete', [
                    'format_id' => (int) $formatId,
                    'pwg_token' => $this->getPwgToken(),
                ]);
            }
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'enable_formats'");
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }
}
