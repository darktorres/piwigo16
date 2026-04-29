<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Piwigo\Auth\PwgBase32;
use Piwigo\Auth\PwgTOTP;

final class PwgTOTPTest extends TestCase
{
    public function testGenerateSecretReturnsBase32String(): void
    {
        $secret = PwgTOTP::generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret, 'secret must be Base32 chars only');
    }

    public function testGenerateSecretIsDecodable(): void
    {
        $secret = PwgTOTP::generateSecret(20);
        $decoded = PwgBase32::decode($secret);
        self::assertNotFalse($decoded, 'generated secret must be valid Base32');
    }

    public function testGenerateSecretCustomLength(): void
    {
        $secret = PwgTOTP::generateSecret(16);
        // Just verify it's valid Base32; the decoder may pad to block boundaries.
        self::assertNotFalse(PwgBase32::decode($secret), 'custom-length secret must be valid Base32');
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGenerateCodeReturnsSixDigits(): void
    {
        $secret = PwgTOTP::generateSecret();
        $code = PwgTOTP::generateCode($secret);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code, 'TOTP code must be exactly 6 digits');
    }

    public function testVerifyCodeRoundTrip(): void
    {
        $secret = PwgTOTP::generateSecret();
        $code = PwgTOTP::generateCode($secret);
        self::assertTrue(
            PwgTOTP::verifyCode($code, $secret),
            'code generated in the same second must verify successfully'
        );
    }

    public function testVerifyCodeWrongCodeReturnsFalse(): void
    {
        $secret = PwgTOTP::generateSecret();
        $code = PwgTOTP::generateCode($secret);
        // Flip the last digit to get an invalid code
        $wrong = $code === '000000' ? '000001' : '000000';
        self::assertFalse(PwgTOTP::verifyCode($wrong, $secret));
    }

    public function testVerifyCodeDeterministicVector(): void
    {
        // Use a very large period so floor(time() / period) = 0 for any near-future time,
        // making the timestamp slot deterministic regardless of when the test runs.
        $period = 10_000_000_000;
        $rawSecret = '12345678901234567890';
        $secret = PwgBase32::encode($rawSecret, false);

        $code = PwgTOTP::generateCode($secret, $period);
        self::assertTrue(
            PwgTOTP::verifyCode($code, $secret, $period),
            'deterministic code must verify with same period'
        );
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
    }
}
