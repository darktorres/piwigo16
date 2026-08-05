<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;

final class WsApiKeyTest extends ContractTestCase
{
    private ?string $pkid = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // api_key.* handlers require $_SESSION['connected_with'] === 'pwg_ui',
        // which is only set by a real identification.php form login, not by
        // pwg.session.login. Tests that need an authorized session call
        // loginAsAdminViaUI() themselves -- calling it unconditionally here
        // would pollute the forbidden-for-guest/not-connected-via-pwg-ui
        // tests below, which rely on the cookie jar starting out
        // unauthenticated (confirmed live: with an unconditional login here,
        // "forbidden for guest" hit the CSRF-token guard instead of the
        // guest guard, and "not connected via pwg ui" actually succeeded).
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->pkid !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.users.api_key.revoke', [
                'pkid'      => $this->pkid,
                'pwg_token' => $token,
            ]);
            $this->pkid = null;
        }

        parent::tearDown();
    }

    public function test_create_returns_secret_and_pkid(): void
    {
        $this->loginAsAdminViaUI();
        $token    = $this->getPwgToken();
        $response = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        // create returns the raw key record; the plaintext secret is in apikey_secret,
        // and the key identifier (used as pkid in other calls) is in auth_key.
        self::assertArrayHasKey('apikey_secret', $result);
        self::assertArrayHasKey('auth_key', $result);

        $authKey = $result['auth_key'];
        self::assertIsString($authKey);
        $this->pkid = $authKey;
    }

    public function test_get_returns_api_key_list(): void
    {
        $this->loginAsAdminViaUI();
        $token  = $this->getPwgToken();
        $create = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);
        $createResult = $create['result'];
        self::assertIsArray($createResult);
        $authKey = $createResult['auth_key'];
        self::assertIsString($authKey);
        $this->pkid = $authKey;

        $response = $this->callWs('pwg.users.api_key.get', [
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.users.api_key.get', $response);
    }

    public function test_edit_returns_ok_message(): void
    {
        $this->loginAsAdminViaUI();
        $token  = $this->getPwgToken();
        $create = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);
        $createResult = $create['result'];
        self::assertIsArray($createResult);
        $authKey = $createResult['auth_key'];
        self::assertIsString($authKey);
        $this->pkid = $authKey;

        $response = $this->callWs('pwg.users.api_key.edit', [
            'pkid'      => $this->pkid,
            'key_name'  => 'ct_key_edited',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertIsString($response['result']);
    }

    public function test_create_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.users.api_key.create', [
            'key_name' => 'x', 'duration' => 1, 'pwg_token' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function test_create_forbidden_when_not_connected_via_pwg_ui(): void
    {
        // pwg.session.login (not identification.php) never sets
        // $_SESSION['connected_with'] = 'pwg_ui' -- ApiKeyService::
        // connectedWithPwgUi() is false even for a real, logged-in admin.
        $this->loginAsAdmin();
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.api_key.create', [
            'key_name' => 'x', 'duration' => 1, 'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function test_revoke_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.users.api_key.revoke', [
            'pkid' => 'pkid-20260101-abcdefghijklmnopqrst', 'pwg_token' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function test_revoke_invalid_token_returns_error(): void
    {
        $this->loginAsAdminViaUI();
        $response = $this->callWs('pwg.users.api_key.revoke', [
            'pkid' => 'pkid-20260101-abcdefghijklmnopqrst', 'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_revoke_a_wellformed_but_nonexistent_pkid_returns_error(): void
    {
        $this->loginAsAdminViaUI();
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.api_key.revoke', [
            'pkid' => 'pkid-20260101-abcdefghijklmnopqrst', 'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('API Key not found', $response['message']);
    }

    public function test_edit_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.users.api_key.edit', [
            'pkid' => 'pkid-20260101-abcdefghijklmnopqrst', 'key_name' => 'x', 'pwg_token' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function test_edit_invalid_token_returns_error(): void
    {
        $this->loginAsAdminViaUI();
        $response = $this->callWs('pwg.users.api_key.edit', [
            'pkid' => 'pkid-20260101-abcdefghijklmnopqrst', 'key_name' => 'x', 'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_edit_a_wellformed_but_nonexistent_pkid_returns_error(): void
    {
        $this->loginAsAdminViaUI();
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.api_key.edit', [
            'pkid' => 'pkid-20260101-abcdefghijklmnopqrst', 'key_name' => 'x', 'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('API Key not found', $response['message']);
    }

    public function test_get_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.users.api_key.get', ['pwg_token' => 'irrelevant']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function test_get_invalid_token_returns_error(): void
    {
        $this->loginAsAdminViaUI();
        $response = $this->callWs('pwg.users.api_key.get', ['pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_revoke_returns_ok_message(): void
    {
        $this->loginAsAdminViaUI();
        $token  = $this->getPwgToken();
        $create = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);
        $createResult = $create['result'];
        self::assertIsArray($createResult);
        $pkid = $createResult['auth_key'];
        self::assertIsString($pkid);

        $response = $this->callWs('pwg.users.api_key.revoke', [
            'pkid'      => $pkid,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertIsString($response['result']);
        // already revoked — clear pkid so tearDown doesn't double-revoke
        $this->pkid = null;
    }
}
