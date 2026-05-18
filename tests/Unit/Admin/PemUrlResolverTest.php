<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Piwigo\Admin\PemUrlResolver;
use Piwigo\Config\Config;

final class PemUrlResolverTest extends TestCase
{
    /** @var array<mixed> */
    private array $serverBackup = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        Config::loadArray([]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function test_alternative_pem_url_takes_precedence(): void
    {
        Config::loadArray(['alternative_pem_url' => 'https://mirror.example/pem']);

        self::assertSame('https://mirror.example/pem', new PemUrlResolver()->url());
    }

    public function test_empty_alternative_pem_url_falls_through_to_host(): void
    {
        Config::loadArray(['alternative_pem_url' => '']);
        $_SERVER['HTTPS']     = 'on';
        $_SERVER['HTTP_HOST'] = 'gallery.example.com';

        self::assertSame('https://gallery.example.com/piwigo16-ext', new PemUrlResolver()->url());
    }

    public function test_http_when_https_unset_or_off(): void
    {
        Config::loadArray([]);
        $_SERVER['HTTP_HOST'] = 'gallery.example.com';
        unset($_SERVER['HTTPS']);

        self::assertSame('http://gallery.example.com/piwigo16-ext', new PemUrlResolver()->url());

        $_SERVER['HTTPS'] = 'off';
        self::assertSame('http://gallery.example.com/piwigo16-ext', new PemUrlResolver()->url());
    }

    public function test_localhost_fallback_when_host_missing(): void
    {
        Config::loadArray([]);
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);

        self::assertSame('http://localhost/piwigo16-ext', new PemUrlResolver()->url());
    }
}
