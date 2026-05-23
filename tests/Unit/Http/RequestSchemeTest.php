<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Http\RequestScheme;

/**
 * Covers the proxy-aware request-scheme + client-IP helper. Every test
 * sets up its own $_SERVER + `trusted_proxies` so the cases are
 * independent.
 */
final class RequestSchemeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    #[\Override]
    protected function setUp(): void
    {
        /** @var array<string,mixed> $snapshot */
        $snapshot = $_SERVER;
        $this->serverBackup = $snapshot;
        Config::reset();
        RequestScheme::resetCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Config::reset();
        RequestScheme::resetCache();
    }

    public function test_no_proxy_plain_http_reports_http_and_remote_ip(): void
    {
        Config::override('trusted_proxies', '');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_FOR']);

        self::assertFalse(RequestScheme::isHttps());
        self::assertSame('203.0.113.5', RequestScheme::clientIp());
    }

    public function test_no_proxy_direct_https_reports_https(): void
    {
        Config::override('trusted_proxies', '');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTPS']       = 'on';

        self::assertTrue(RequestScheme::isHttps());
    }

    public function test_trusted_proxy_with_xforwarded_proto_https_reports_https(): void
    {
        Config::override('trusted_proxies', '10.0.0.0/8');
        $_SERVER['REMOTE_ADDR']            = '10.1.2.3';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        self::assertTrue(RequestScheme::isHttps());
    }

    public function test_untrusted_peer_cannot_spoof_https_via_forwarded_header(): void
    {
        // Critical security regression guard: if REMOTE_ADDR is not in the
        // trusted-proxy list, the X-Forwarded-Proto header MUST be ignored,
        // otherwise any internet attacker could mark their session cookie
        // Secure over plain HTTP.
        Config::override('trusted_proxies', '10.0.0.0/8');
        $_SERVER['REMOTE_ADDR']            = '198.51.100.99'; // outside trusted range
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        self::assertFalse(RequestScheme::isHttps());
    }

    public function test_trusted_proxy_returns_client_from_xforwarded_for_single_hop(): void
    {
        Config::override('trusted_proxies', '10.0.0.0/8');
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.5';

        self::assertSame('203.0.113.5', RequestScheme::clientIp());
    }

    public function test_trusted_proxy_chain_returns_rightmost_untrusted_hop(): void
    {
        // XFF order: client first, then each proxy hop. Walk right-to-left
        // and stop at the first untrusted IP — that's the real client.
        Config::override('trusted_proxies', '10.0.0.0/8,172.16.0.0/12');
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 172.16.1.1, 10.0.0.2';

        self::assertSame('203.0.113.5', RequestScheme::clientIp());
    }

    public function test_untrusted_peer_with_xforwarded_for_still_returns_remote_addr(): void
    {
        Config::override('trusted_proxies', '10.0.0.0/8');
        $_SERVER['REMOTE_ADDR']          = '198.51.100.99';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';

        self::assertSame('198.51.100.99', RequestScheme::clientIp());
    }

    public function test_malformed_trusted_proxy_entries_are_ignored(): void
    {
        // Garbage in the env var should degrade to "no proxy", not crash.
        Config::override('trusted_proxies', 'not-an-ip,10.0.0.0/8,999.999.999.999,bad/cidr');
        $_SERVER['REMOTE_ADDR']            = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        self::assertTrue(RequestScheme::isHttps()); // 10.0.0.0/8 still parsed
    }

    public function test_https_off_value_treated_as_http(): void
    {
        // Some SAPIs set $_SERVER['HTTPS']='off' rather than unsetting it.
        Config::override('trusted_proxies', '');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTPS']       = 'off';

        self::assertFalse(RequestScheme::isHttps());
    }
}
