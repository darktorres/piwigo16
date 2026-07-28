<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Covers several small Ws classes only reachable through non-default
 * ws.php configurations:
 *  - Ws\WsInitializer's format=php/format=xmlrpc encoder-selection
 *    branches (every other Contract test uses the default format=json).
 *  - Ws\Protocol\PwgSerialPhpEncoder (format=php) and
 *    Ws\Protocol\PwgXmlRpcEncoder (format=xmlrpc, both success and
 *    PwgError/fault branches).
 *  - Ws\Protocol\PwgRestRequestHandler's own "missing method name" guard
 *    (a request with no `method` POST field at all).
 *  - Ws\WsDefaultMethods::register()'s available_permission_levels
 *    empty-config fallback (`[0, 1, 2, 4, 8]`), which exists specifically
 *    to keep `max($available_permission_levels)` from crashing when the
 *    config value is empty.
 */
final class WsAlternateFormatsTest extends ContractTestCase
{
    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    private function rawCall(string $format, string $postFields): string
    {
        $url = $this->baseUrl . '/ws.php?format=' . $format;
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);

        return $body;
    }

    public function test_format_php_encodes_a_successful_response_as_a_serialized_array(): void
    {
        $body = $this->rawCall('php', 'method=pwg.getVersion');

        $decoded = unserialize($body, ['allowed_classes' => false]);
        self::assertIsArray($decoded);
        self::assertSame('ok', $decoded['stat']);
        self::assertIsString($decoded['result']);
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $decoded['result']);
    }

    public function test_format_xmlrpc_encodes_a_successful_response(): void
    {
        $body = $this->rawCall('xmlrpc', 'method=pwg.getVersion');

        self::assertStringContainsString('<methodResponse>', $body);
        self::assertMatchesRegularExpression('#<string>\d+\.\d+.*</string>#', $body);
    }

    public function test_format_xmlrpc_encodes_a_pwgerror_as_a_fault(): void
    {
        $body = $this->rawCall('xmlrpc', 'method=pwg.not.a.real.method');

        self::assertStringContainsString('<fault>', $body);
        self::assertStringContainsString('<name>faultCode</name>', $body);
        self::assertStringContainsString('<int>501</int>', $body);
        self::assertStringContainsString('Method name is not valid', $body);
    }

    public function test_missing_method_name_returns_invalid_method_error(): void
    {
        // No `method=` field at all -- WsRawRequest::fromGlobals()->method
        // stays null, hitting PwgRestRequestHandler::handleRequest()'s own
        // dedicated guard rather than PwgServer::invoke()'s
        // "not isset($this->_methods[...])" check (which needs a non-null,
        // just-unrecognized method name).
        $body = $this->rawCall('json', 'not_method=irrelevant');

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['stat']);
        self::assertSame(501, $decoded['err']);
        self::assertSame('Missing "method" name', $decoded['message']);
    }

    public function test_empty_available_permission_levels_config_falls_back_to_the_default_set(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('available_permission_levels', '[]')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            // max($available_permission_levels) would throw a ValueError on
            // a genuinely empty array -- WsDefaultMethods::register()'s own
            // fallback to [0, 1, 2, 4, 8] is what keeps this method
            // registration (and every other 'level'-typed param
            // registration) from crashing on every single WS request.
            $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.images.setPrivacyLevel']);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $params = $result['params'];
            self::assertIsArray($params);
            $byName = array_column($params, null, 'name');
            self::assertIsArray($byName['level']);
            self::assertSame(8, $byName['level']['maxValue']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'available_permission_levels'");
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }
}
