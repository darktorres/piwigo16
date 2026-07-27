<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsSessionTest extends ContractTestCase
{
    public function test_getStatus_response_matches_schema(): void
    {
        $response = $this->ws('pwg.session.getStatus');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('session.getStatus', $response);
    }

    public function test_getStatus_returns_guest_for_anonymous_call(): void
    {
        $response = $this->ws('pwg.session.getStatus');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame('guest', $result['username']);
        self::assertSame('guest', $result['status']);
    }

    public function test_getStatus_returns_admin_after_login(): void
    {
        $response = $this->wsAdmin('pwg.session.getStatus');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame('fixture_admin', $result['username']);
        self::assertSame('webmaster', $result['status']);
        self::assertMatchesSchema('session.getStatus', $response);
    }

    public function test_login_with_bad_credentials_returns_fail(): void
    {
        $response = $this->ws('pwg.session.login', [
            'username' => 'nobody',
            'password' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertArrayHasKey('err', $response);
        self::assertArrayHasKey('message', $response);
    }

    public function test_logout_returns_ok(): void
    {
        $this->loginAsAdmin();
        $response = $this->callWs('pwg.session.logout', []);

        self::assertSame('ok', $response['stat']);
    }

    public function test_logout_clears_session(): void
    {
        $this->loginAsAdmin();
        $this->callWs('pwg.session.logout', []);

        $status = $this->callWs('pwg.session.getStatus', []);
        $result = $status['result'];
        self::assertIsArray($result);
        self::assertSame('guest', $result['status']);
    }

    /**
     * Creates a real api_key for the current (UI-authenticated) session and
     * returns the "pkid:secret" string UserBootstrap's HTTP_X_PIWIGO_API
     * header check (ApiKeyRequestFlag) expects.
     */
    private function createApiKeyHeaderValue(): string
    {
        $this->loginAsAdminViaUI();
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.api_key.create', [
            'key_name' => 'Contract test key ' . uniqid(),
            'duration' => 1,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $response['stat'], 'api_key.create failed: ' . json_encode($response));

        $result = $response['result'];
        self::assertIsArray($result);
        $authKey = $result['auth_key'];
        $secret = $result['apikey_secret'];
        self::assertIsString($authKey);
        self::assertIsString($secret);

        return $authKey . ':' . $secret;
    }

    public function test_login_via_api_key_header_returns_error(): void
    {
        // sessionLogin() itself refuses to run at all once
        // ApiKeyRequestFlag is active for this request (set by
        // UserBootstrap after a successful HTTP_X_PIWIGO_API handshake) --
        // logging in again through the WS method makes no sense for an
        // already-api-key-authenticated request.
        $apiKeyHeader = $this->createApiKeyHeaderValue();

        $ch = curl_init($this->baseUrl . '/ws.php?format=json');
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'method' => 'pwg.session.login',
            'username' => 'fixture_admin',
            'password' => 'fixture_admin',
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->testHeader(), ['X-Piwigo-Api: ' . $apiKeyHeader]));

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['stat']);
        self::assertSame(401, $decoded['err']);
        self::assertSame('Cannot use this method with an api key', $decoded['message']);
    }

    public function test_logout_via_api_key_header_returns_error(): void
    {
        $apiKeyHeader = $this->createApiKeyHeaderValue();

        $ch = curl_init($this->baseUrl . '/ws.php?format=json');
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['method' => 'pwg.session.logout']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->testHeader(), ['X-Piwigo-Api: ' . $apiKeyHeader]));

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['stat']);
        self::assertSame(401, $decoded['err']);
        self::assertSame('Cannot use this method with an api key', $decoded['message']);
    }

    public function test_login_via_pkid_authentication_key(): void
    {
        // sessionLogin()'s pkid-format branch: authenticates directly via
        // username=<pkid> (not fixture_admin's real username/password).
        // Uses a fresh, separate cookie jar (not this test's own, already
        // admin-authenticated-via-UI session from creating the key) so the
        // pkid login itself is what's proven to establish the session.
        $apiKeyValue = $this->createApiKeyHeaderValue();
        [$pkid, $secret] = explode(':', $apiKeyValue);

        $freshCookieJar = tempnam(sys_get_temp_dir(), 'pwg_ct_pkid_');
        self::assertNotFalse($freshCookieJar);

        try {
            $login = $this->wsWithCookieJar($freshCookieJar, 'pwg.session.login', [
                'username' => $pkid,
                'password' => $secret,
            ]);
            self::assertSame('ok', $login['stat']);

            $status = $this->wsWithCookieJar($freshCookieJar, 'pwg.session.getStatus');
            $result = $status['result'];
            self::assertIsArray($result);
            self::assertSame('fixture_admin', $result['username']);
        } finally {
            @unlink($freshCookieJar);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function wsWithCookieJar(string $cookieJar, string $method, array $params = []): array
    {
        assert($cookieJar !== '');
        $ch = curl_init($this->baseUrl . '/ws.php?format=json');
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
