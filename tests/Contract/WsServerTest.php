<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\PwgServer -- the generic WS dispatcher itself (invoke()'s own gates,
 * checkType()'s scalar bool/float branches, the reflection.* methods,
 * isAuthorizedMethodForAPIKEY()), reached through ws.php rather than any
 * one pwg.* domain method's own test file.
 *
 * checkType()'s array-of-bool/array-of-float branches are NOT chased here:
 * no real WS method registration in WsDefaultMethods.php combines
 * WsParamFlag::ACCEPT_ARRAY/FORCE_ARRAY with WsParamType::BOOL or
 * WsParamType::FLOAT (confirmed via a full grep of that file) -- genuinely
 * unreachable dead code through the real WS route, not a gap.
 *
 * run()'s own "no request handler" branch (`! $this->_requestHandler
 * instanceof PwgRequestHandler`) is also unreachable in practice:
 * WsInitializer::init() hardcodes $requestFormat = 'rest' and always
 * constructs a PwgRestRequestHandler for it, so _requestHandler is never
 * null by the time run() checks it through the real ws.php entry point.
 */
final class WsServerTest extends ContractTestCase
{
    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    public function test_invoke_with_an_unknown_method_name_returns_invalid_method(): void
    {
        // PwgError's constructor mirrors this WS err (501) onto the real
        // HTTP status -- callWs()'s generic "< 500" sanity guard would
        // wrongly reject this well-formed 501, so this uses the
        // guard-free variant instead (see its own docblock).
        $response = $this->callWsAllowingServerError('pwg.not.a.real.method', []);

        self::assertSame('fail', $response['stat']);
        self::assertSame(501, $response['err']);
        self::assertSame('Method name is not valid', $response['message']);
    }

    public function test_invoke_a_post_only_method_via_get_returns_405(): void
    {
        $url = $this->baseUrl . '/ws.php?format=json&' . http_build_query([
            'method' => 'pwg.images.addComment',
            'image_id' => 1,
            'author' => 'x',
            'content' => 'y',
            'key' => 'z',
        ]);
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body);
        self::assertSame(405, $status);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['stat']);
        self::assertSame(405, $decoded['err']);
        self::assertSame('This method requires HTTP POST', $decoded['message']);
    }

    public function test_invoke_when_guest_access_is_disabled_returns_access_denied_for_a_non_session_method(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('guest_access', 'false')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            $response = $this->ws('pwg.getVersion');

            self::assertSame('fail', $response['stat']);
            self::assertSame(401, $response['err']);
            self::assertSame('Access denied', $response['message']);

            // 'pwg.session.*' methods are explicitly exempted by
            // WsHelper::isInvokeAllowed() -- still reachable even with
            // guest access disabled site-wide.
            $status = $this->ws('pwg.session.getStatus');
            self::assertSame('ok', $status['stat']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'guest_access'");
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }

    public function test_checkType_rejects_a_non_boolean_scalar(): void
    {
        // pwg.users.setInfo's 'expand' param is WsParamType::BOOL, no
        // ACCEPT_ARRAY/FORCE_ARRAY -- a plain scalar checkType() call.
        $this->loginAsAdmin();
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.setInfo', [
            'user_id' => 1,
            'expand' => 'banana',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('expand must be a boolean', $response['message']);
    }

    public function test_checkType_rejects_a_non_float_scalar(): void
    {
        $response = $this->ws('pwg.images.rate', [
            'image_id' => 1,
            'rate' => 'not-a-number',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('rate must be a float', $response['message']);
    }

    public function test_reflection_getMethodList_includes_every_non_hidden_method(): void
    {
        $response = $this->ws('reflection.getMethodList');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $methods = $result['methods'];
        self::assertIsArray($methods);
        self::assertContains('pwg.getVersion', $methods);
        self::assertContains('reflection.getMethodList', $methods);
        self::assertContains('reflection.getMethodDetails', $methods);
        self::assertGreaterThan(50, count($methods));
    }

    public function test_reflection_getMethodDetails_with_an_unknown_method_returns_error(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'not.a.real.method']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Requested method does not exist', $response['message']);
    }

    public function test_reflection_getMethodDetails_describes_a_method_with_no_params(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.getVersion']);

        self::assertSame('ok', $response['stat']);
        self::assertSame([
            'name' => 'pwg.getVersion',
            'description' => 'Returns the Piwigo version.',
            'params' => [],
            'options' => [],
        ], $response['result']);
    }

    public function test_reflection_getMethodDetails_describes_every_param_type_flag_combination(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.images.setRank']);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame(['admin_only' => true, 'post_only' => true], $result['options']);
        $params = $result['params'];
        self::assertIsArray($params);
        self::assertSame([
            ['name' => 'image_id', 'optional' => false, 'acceptArray' => true, 'type' => 'int positive notnull'],
            ['name' => 'category_id', 'optional' => false, 'acceptArray' => false, 'type' => 'int positive notnull'],
            ['name' => 'rank', 'optional' => true, 'acceptArray' => false, 'type' => 'int positive notnull'],
        ], $params);
    }

    public function test_reflection_getMethodDetails_describes_defaultValue_maxValue_info_and_float(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.images.search']);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $params = $result['params'];
        self::assertIsArray($params);
        $byName = array_column($params, null, 'name');

        self::assertSame(
            ['name' => 'per_page', 'optional' => true, 'acceptArray' => false, 'type' => 'int positive', 'defaultValue' => 100, 'maxValue' => 500],
            $byName['per_page']
        );
        self::assertSame(
            ['name' => 'order', 'optional' => true, 'acceptArray' => false, 'type' => 'mixed', 'info' => 'id, file, name, hit, rating_score, date_creation, date_available, random'],
            $byName['order']
        );
        self::assertSame(
            ['name' => 'f_min_rate', 'optional' => true, 'acceptArray' => false, 'type' => 'float'],
            $byName['f_min_rate']
        );
    }

    /**
     * pwg.users.setInfo is on CurrentConfig::apiKeyForbiddenMethods()'s own
     * default list -- an API-key-authenticated request must be refused
     * before invoke() ever runs the method body, regardless of a
     * (deliberately wrong) pwg_token.
     */
    public function test_invoke_via_api_key_for_a_forbidden_method_returns_access_denied(): void
    {
        // api_key.create requires $_SESSION['connected_with'] === 'pwg_ui'
        // (ApiKeyService::connectedWithPwgUi()) -- loginAsAdminViaUI(), not
        // the WS pwg.session.login, matches WsSessionTest's own pattern.
        $this->loginAsAdminViaUI();
        $token = $this->getPwgToken();

        $createResponse = $this->callWs('pwg.users.api_key.create', [
            'key_name' => 'Contract server test key ' . uniqid(),
            'duration' => 1,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $createResponse['stat']);
        $createResult = $createResponse['result'];
        self::assertIsArray($createResult);
        $authKey = $createResult['auth_key'];
        $secret = $createResult['apikey_secret'];
        self::assertIsString($authKey);
        self::assertIsString($secret);

        try {
            $ch = curl_init($this->baseUrl . '/ws.php?format=json');
            self::assertNotFalse($ch);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'method' => 'pwg.users.setInfo',
                'user_id' => 1,
                'pwg_token' => 'irrelevant-because-the-method-gate-runs-first',
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->testHeader(), ['X-Piwigo-Api: ' . $authKey . ':' . $secret]));

            $body = curl_exec($ch);
            unset($ch);

            self::assertIsString($body);
            $decoded = json_decode($body, true);
            self::assertIsArray($decoded);
            self::assertSame('fail', $decoded['stat']);
            self::assertSame(401, $decoded['err']);
            self::assertSame('Access denied', $decoded['message']);
        } finally {
            $revokeToken = $this->getPwgToken();
            $this->callWs('pwg.users.api_key.revoke', ['pwg_token' => $revokeToken]);
        }
    }
}
