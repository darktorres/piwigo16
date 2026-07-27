<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsTopLevelTest extends ContractTestCase
{
    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }
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

    public function test_getMissingDerivatives_invalid_types_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.getMissingDerivatives', ['types' => ['not-a-real-derivative-type']]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid types', $response['message']);
    }

    public function test_ratesDelete_removes_all_rates_for_a_user(): void
    {
        $status = $this->wsAdmin('pwg.session.getStatus');
        $statusResult = $status['result'];
        self::assertIsArray($statusResult);
        $userId = $this->conn->fetchOne(
            'SELECT id FROM ' . Tables::users() . ' WHERE username = ?',
            ['fixture_admin']
        );
        self::assertIsNumeric($userId);

        $rateResponse = $this->wsAdmin('pwg.images.rate', ['image_id' => 1, 'rate' => 5]);
        self::assertSame('ok', $rateResponse['stat']);

        $before = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::rate() . ' WHERE user_id = ? AND element_id = 1',
            [$userId]
        );
        self::assertIsNumeric($before);
        self::assertSame(1, (int) $before);

        $response = $this->wsAdmin('pwg.rates.delete', ['user_id' => (int) $userId, 'image_id' => 1]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(1, $response['result']);

        $after = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::rate() . ' WHERE user_id = ? AND element_id = 1',
            [$userId]
        );
        self::assertIsNumeric($after);
        self::assertSame(0, (int) $after);
    }

    public function test_ratesDelete_with_no_matching_rate_returns_zero(): void
    {
        $userId = $this->conn->fetchOne(
            'SELECT id FROM ' . Tables::users() . ' WHERE username = ?',
            ['fixture_admin']
        );
        self::assertIsNumeric($userId);

        $response = $this->wsAdmin('pwg.rates.delete', ['user_id' => (int) $userId, 'image_id' => 999999]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(0, $response['result']);
    }
}
