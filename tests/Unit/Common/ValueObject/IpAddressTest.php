<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Override;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<IpAddress> */
final class IpAddressTest extends StringVoContract
{
    #[Override]
    protected static function voClass(): string
    {
        return IpAddress::class;
    }

    #[Override]
    protected static function validSample(): string
    {
        return '203.0.113.42';
    }

    #[Override]
    protected static function otherVoClass(): string
    {
        return Username::class;
    }

    /** @return iterable<string, array{string}> */
    #[Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'          => [''];
        yield 'garbage'        => ['not an ip'];
        yield 'ipv4 too many octets' => ['192.168.1.1.1'];
        yield 'ipv4 octet out of range' => ['999.168.1.1'];
        yield 'truncated ipv4 prefix' => ['192.168.1'];
        yield 'trailing whitespace' => ['203.0.113.42 '];
    }

    public function testFromAcceptsValidIpv6(): void
    {
        $vo = IpAddress::from('2001:db8::1');
        self::assertSame('2001:db8::1', $vo->value);
    }

    public function testFromRemoteAddrReturnsNullWhenUnset(): void
    {
        $original = $_SERVER;
        unset($_SERVER['REMOTE_ADDR']);

        try {
            self::assertNull(IpAddress::fromRemoteAddr());
        } finally {
            $_SERVER = $original;
        }
    }

    public function testFromRemoteAddrReturnsNullWhenNotAString(): void
    {
        $original = $_SERVER;
        // Not realistic under a real web SAPI, but $_SERVER is untyped --
        // this is exactly the shape 4 of the original 12 call sites
        // (Env/EphemeralKeyService x2/MailService) didn't guard against
        // before this VO centralised the read.
        $_SERVER['REMOTE_ADDR'] = ['spoofed'];

        try {
            self::assertNull(IpAddress::fromRemoteAddr());
        } finally {
            $_SERVER = $original;
        }
    }

    public function testFromRemoteAddrReturnsNullWhenInvalidIp(): void
    {
        $original = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = 'not an ip';

        try {
            self::assertNull(IpAddress::fromRemoteAddr());
        } finally {
            $_SERVER = $original;
        }
    }

    public function testFromRemoteAddrReturnsVoWhenValid(): void
    {
        $original = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        try {
            $vo = IpAddress::fromRemoteAddr();
            self::assertNotNull($vo);
            self::assertSame('198.51.100.7', $vo->value);
        } finally {
            $_SERVER = $original;
        }
    }
}
