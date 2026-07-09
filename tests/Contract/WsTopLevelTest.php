<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsTopLevelTest extends ContractTestCase
{
    public function test_getVersion_returns_version_string(): void
    {
        $response = $this->wsAdmin('pwg.getVersion');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getVersion', $response);

        $result = $response['result'];
        self::assertIsString($result);
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $result);
    }

    public function test_getInfos_returns_install_statistics(): void
    {
        $response = $this->wsAdmin('pwg.getInfos');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getInfos', $response);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('infos', $result);
        $infos = $result['infos'];
        self::assertIsArray($infos);

        $names = array_column($infos, 'name');
        self::assertContains('version', $names);
        self::assertContains('nb_elements', $names);
        self::assertContains('nb_categories', $names);
    }

    public function test_getCacheSize_returns_size_info(): void
    {
        $response = $this->wsAdmin('pwg.getCacheSize');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getCacheSize', $response);
    }

    public function test_getCacheSize_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.getCacheSize');

        self::assertSame('fail', $response['stat']);
    }

    public function test_getMissingDerivatives_returns_url_list(): void
    {
        $response = $this->wsAdmin('pwg.getMissingDerivatives', ['max_urls' => 10]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getMissingDerivatives', $response);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('urls', $result);
        self::assertIsArray($result['urls']);
    }
}
