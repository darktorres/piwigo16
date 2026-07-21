<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsUploadTest extends ContractTestCase
{
    /** 1×1 white PNG, base64-decoded at runtime to avoid binary in source. */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    private ?int $uploadedImageId = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
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
}
