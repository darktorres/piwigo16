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

        self::assertSame('guest', $response['result']['username']);
        self::assertSame('guest', $response['result']['status']);
    }

    public function test_getStatus_returns_admin_after_login(): void
    {
        $response = $this->wsAdmin('pwg.session.getStatus');

        self::assertSame('fixture_admin', $response['result']['username']);
        self::assertSame('webmaster', $response['result']['status']);
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
        self::assertSame('guest', $status['result']['status']);
    }
}
