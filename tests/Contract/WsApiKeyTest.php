<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsApiKeyTest extends ContractTestCase
{
    private ?string $pkid = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // api_key.* handlers require $_SESSION['connected_with'] === 'pwg_ui',
        // which is only set by a real identification.php form login, not by
        // pwg.session.login. Use the UI login path here.
        $this->loginAsAdminViaUI();
    }

    #[\Override]
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
        $token    = $this->getPwgToken();
        $response = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // create returns the raw key record; the plaintext secret is in apikey_secret,
        // and the key identifier (used as pkid in other calls) is in auth_key.
        self::assertArrayHasKey('apikey_secret', $response['result']);
        self::assertArrayHasKey('auth_key', $response['result']);

        $this->pkid = $response['result']['auth_key'];
    }

    public function test_get_returns_api_key_list(): void
    {
        $token  = $this->getPwgToken();
        $create = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);
        $this->pkid = $create['result']['auth_key'];

        $response = $this->callWs('pwg.users.api_key.get', [
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.users.api_key.get', $response);
    }

    public function test_edit_returns_ok_message(): void
    {
        $token  = $this->getPwgToken();
        $create = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);
        $this->pkid = $create['result']['auth_key'];

        $response = $this->callWs('pwg.users.api_key.edit', [
            'pkid'      => $this->pkid,
            'key_name'  => 'ct_key_edited',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertIsString($response['result']);
    }

    public function test_revoke_returns_ok_message(): void
    {
        $token  = $this->getPwgToken();
        $create = $this->callWs('pwg.users.api_key.create', [
            'key_name'  => 'ct_key_' . uniqid(),
            'duration'  => 1,
            'pwg_token' => $token,
        ]);
        $pkid = $create['result']['auth_key'];

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
