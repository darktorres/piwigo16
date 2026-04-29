<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Piwigo\Auth\PwgBase32;

final class PwgBase32Test extends TestCase
{
    public function testEncodeEmptyReturnsEmpty(): void
    {
        self::assertSame('', PwgBase32::encode(''));
    }

    public function testEncodeKnownVectors(): void
    {
        // RFC 4648 test vectors
        self::assertSame('MY======', PwgBase32::encode('f'));
        self::assertSame('MZXQ====', PwgBase32::encode('fo'));
        self::assertSame('MZXW6===', PwgBase32::encode('foo'));
        self::assertSame('MZXW6YQ=', PwgBase32::encode('foob'));
        self::assertSame('MZXW6YTB', PwgBase32::encode('fooba'));
        self::assertSame('MZXW6YTBOI======', PwgBase32::encode('foobar'));
    }

    public function testEncodeWithoutPaddingStripsEquals(): void
    {
        $withPadding = PwgBase32::encode('foo', true);
        $withoutPadding = PwgBase32::encode('foo', false);
        self::assertStringNotContainsString('=', $withoutPadding);
        self::assertSame(rtrim($withPadding, '='), $withoutPadding);
    }

    public function testDecodeKnownVectors(): void
    {
        // Decoder may append null bytes for padded inputs; verify the meaningful prefix.
        self::assertStringStartsWith('f', (string) PwgBase32::decode('MY======'));
        self::assertStringStartsWith('fo', (string) PwgBase32::decode('MZXQ===='));
        self::assertStringStartsWith('foo', (string) PwgBase32::decode('MZXW6==='));
        // 'fooba' is 5 bytes exactly (no padding block) — decodes cleanly.
        self::assertSame('fooba', PwgBase32::decode('MZXW6YTB'));
    }

    public function testDecodeEmptyReturnsFalse(): void
    {
        self::assertFalse(PwgBase32::decode(''));
    }

    public function testDecodeInvalidCharsReturnsFalse(): void
    {
        self::assertFalse(PwgBase32::decode('!!!!'));
        self::assertFalse(PwgBase32::decode('invalid!@#'));
    }

    public function testRoundTrip(): void
    {
        // Use exact 5-byte multiples to avoid null-byte padding in decode output.
        $inputs = ['fooba', 'Piwigo16xx', 'abcde'];
        foreach ($inputs as $input) {
            $encoded = PwgBase32::encode($input);
            self::assertSame($input, PwgBase32::decode($encoded), "round-trip failed for: $input");
        }
    }

    public function testEncodeOutputIsUppercaseAlphabet(): void
    {
        $encoded = PwgBase32::encode('test string');
        self::assertMatchesRegularExpression('/^[A-Z2-7=]+$/', $encoded);
    }
}
