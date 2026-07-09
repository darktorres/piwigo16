<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsImagesTest extends ContractTestCase
{
    /** Image ID 1 is the first photo seeded by the fixture. */
    private const int FIXTURE_IMAGE_ID = 1;

    public function test_getInfo_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.images.getInfo', ['image_id' => self::FIXTURE_IMAGE_ID]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('images.getInfo', $response);
    }

    public function test_getInfo_returns_expected_fields(): void
    {
        $response = $this->wsAdmin('pwg.images.getInfo', ['image_id' => self::FIXTURE_IMAGE_ID]);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame(self::FIXTURE_IMAGE_ID, $result['id']);
        self::assertIsString($result['file']);
        self::assertIsArray($result['derivatives']);
        self::assertIsArray($result['categories']);
        self::assertIsArray($result['tags']);
        self::assertNotEmpty($result['categories'], 'Fixture image must belong to at least one album');
    }

    public function test_getInfo_on_missing_image_returns_404(): void
    {
        // ws.php sends HTTP 404 for PwgError(404, ...) — not 200+stat=fail.
        // The contract is: HTTP 404, body may or may not be valid JSON.
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch  = curl_init($url);
        self::assertNotFalse($ch);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_POSTFIELDS     => http_build_query(['method' => 'pwg.images.getInfo', 'image_id' => 999999]),
            CURLOPT_HTTPHEADER     => $this->testHeader(),
        ]);

        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertSame(404, $status, 'Missing image_id must return HTTP 404');
    }

    public function test_exist_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.images.exist', [
            'md5sum_list' => 'nonexistentmd5sumvalue0000000000',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.images.exist', $response);
    }

    public function test_exist_returns_null_for_unknown_md5(): void
    {
        $response = $this->wsAdmin('pwg.images.exist', [
            'md5sum_list' => 'nonexistentmd5sumvalue0000000000',
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertNull($result['nonexistentmd5sumvalue0000000000']);
    }

    public function test_checkUpload_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.images.checkUpload');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.images.checkUpload', $response);
    }

    public function test_checkUpload_contains_ready_for_upload_flag(): void
    {
        $response = $this->wsAdmin('pwg.images.checkUpload');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('ready_for_upload', $result);
        self::assertIsBool($result['ready_for_upload']);
    }
}
