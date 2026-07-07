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

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_POSTFIELDS     => [
                    'method'      => 'pwg.images.addSimple',
                    'category'    => 1,
                    'name'        => 'Contract Test Upload ' . uniqid(),
                    'image'       => new \CURLFile($tmpFile, 'image/png', 'ct_upload.png'),
                ],
                CURLOPT_COOKIEJAR      => $this->cookieJar(),
                CURLOPT_COOKIEFILE     => $this->cookieJar(),
                CURLOPT_HTTPHEADER     => $this->testHeader(),
            ]);

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
        self::assertSame('ok', $response['stat'], 'addSimple failed: ' . $body);

        self::assertMatchesSchema('pwg.images.addSimple', $response);
        self::assertGreaterThan(0, (int) $response['result']['image_id']);

        $this->uploadedImageId = (int) $response['result']['image_id'];
    }
}
