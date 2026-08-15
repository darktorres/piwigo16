<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsSessionTest extends ContractTestCase
{
    public function testGetStatusResponseMatchesSchema(): void
    {
        $response = $this->ws('pwg.session.getStatus');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('session.getStatus', $response);
    }

    public function testGetStatusReturnsGuestForAnonymousCall(): void
    {
        $response = $this->ws('pwg.session.getStatus');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame('guest', $result['username']);
        self::assertSame('guest', $result['status']);
    }

    public function testGetStatusReturnsAdminAfterLogin(): void
    {
        $response = $this->wsAdmin('pwg.session.getStatus');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame('fixture_admin', $result['username']);
        self::assertSame('webmaster', $result['status']);
        self::assertMatchesSchema('session.getStatus', $response);
    }

    /**
     * sessionGetStatus() hides 'save_visits'/'connected_with' from any
     * client whose User-Agent starts with 'PiwigoRemoteSync' (that client
     * doesn't support receiving them) -- every other test in this file uses
     * the fixed USER_AGENT constant, so this branch is otherwise never hit.
     */
    public function testGetStatusOmitsSaveVisitsAndConnectedWithForPiwigoRemoteSyncUserAgent(): void
    {
        $response = $this->wsWithUserAgent('PiwigoRemoteSync/1.0', 'pwg.session.getStatus');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayNotHasKey('save_visits', $result);
        self::assertArrayNotHasKey('connected_with', $result);
    }

    /**
     * Contrast with the PiwigoRemoteSync test above: a normal client keeps both fields.
     */
    public function testGetStatusIncludesSaveVisitsAndConnectedWithForANormalUserAgent(): void
    {
        $response = $this->ws('pwg.session.getStatus');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('save_visits', $result);
        self::assertArrayHasKey('connected_with', $result);
    }

    /**
     * sessionGetStatus() also hides 'available_sizes' from any client
     * whose User-Agent starts with 'Apache-HttpClient/' (a distinct
     * compatibility exception from the PiwigoRemoteSync one above -- keyed
     * on str_starts_with() against a different literal prefix).
     */
    public function testGetStatusOmitsAvailableSizesForApacheHttpClientUserAgent(): void
    {
        $response = $this->wsWithUserAgent('Apache-HttpClient/4.5.13 (Java/1.8)', 'pwg.session.getStatus');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayNotHasKey('available_sizes', $result);
    }

    public function testLoginWithBadCredentialsReturnsFail(): void
    {
        $response = $this->ws('pwg.session.login', [
            'username' => 'nobody',
            'password' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertArrayHasKey('err', $response);
        self::assertArrayHasKey('message', $response);
    }

    public function testLogoutReturnsOk(): void
    {
        $this->loginAsAdmin();
        $response = $this->callWs('pwg.session.logout', []);

        self::assertSame('ok', $response['stat']);
    }

    public function testLogoutClearsSession(): void
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

    public function testLoginViaApiKeyHeaderReturnsError(): void
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

    public function testLogoutViaApiKeyHeaderReturnsError(): void
    {
        $apiKeyHeader = $this->createApiKeyHeaderValue();

        $ch = curl_init($this->baseUrl . '/ws.php?format=json');
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'method' => 'pwg.session.logout',
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

    public function testLoginViaPkidAuthenticationKey(): void
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
     * SEC finding 2: `pwg.images.uploadAsync`'s username/password credential
     * path (handled by `UserBootstrap::initialize()`, before `Server::
     * invoke()` ever dispatches -- no real multipart upload payload is
     * needed to reach it) used to unconditionally overwrite
     * `$_SESSION['connected_with']` with `'pwg.images.uploadAsync'` right
     * after `LoginHandler` had correctly set it to
     * `'ws_session_login_api_key'`. That erased the marker
     * `Server::isAuthorizedMethodForAPIKEY()` checks, so every method on
     * `apiKeyForbiddenMethods` became callable for the rest of the session --
     * an API key is a deliberately restricted credential, and this silently
     * laundered it into an unrestricted one.
     */
    public function testUploadAsyncLoginViaApiKeyDoesNotLiftApiKeyRestrictions(): void
    {
        $apiKeyValue = $this->createApiKeyHeaderValue();
        [$pkid, $secret] = explode(':', $apiKeyValue);

        $freshCookieJar = tempnam(sys_get_temp_dir(), 'pwg_ct_uploadasync_');
        self::assertNotFalse($freshCookieJar);

        try {
            $this->wsWithCookieJar($freshCookieJar, 'pwg.images.uploadAsync', [
                'username' => $pkid,
                'password' => $secret,
            ]);

            $response = $this->wsWithCookieJar($freshCookieJar, 'pwg.users.getAuthKey');

            self::assertSame('fail', $response['stat']);
            self::assertSame(401, $response['err']);
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge([
            'method' => $method,
        ], $params)));
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

    /**
     * Same as callWs(), but with a caller-supplied User-Agent instead of
     * the fixed USER_AGENT constant -- sessionGetStatus()'s own
     * PiwigoRemoteSync/Apache-HttpClient compatibility branches key off
     * the real HTTP User-Agent header, which callWs() always hardcodes.
     * @param non-empty-string $userAgent
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function wsWithUserAgent(string $userAgent, string $method, array $params = []): array
    {
        $ch = curl_init($this->baseUrl . '/ws.php?format=json');
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge([
            'method' => $method,
        ], $params)));
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
