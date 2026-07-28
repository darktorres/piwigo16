<?php

declare(strict_types=1);

use Piwigo\Auth\PwgTOTP;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * generateCode()/verifyCode() derive their 30-second counter from the
 * real wall clock (floor(time() / $timestamp)), so they can't be driven
 * to an exact, reproducible counter from the outside. The private
 * generateCodeFromTimestamp() is where the actual RFC 6238 algorithm
 * (HMAC-SHA1 + RFC 4226 dynamic truncation) lives, so it's invoked here
 * via Reflection -- matching this repo's existing
 * tests/Unit/Core/ShutdownHandlerTest.php precedent for exercising a
 * private static method directly -- with the published RFC 6238
 * Appendix B test vectors for the SHA1 secret ASCII('12345678901234567890').
 *
 * That ASCII secret is base32-encoded here as
 * 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' (independently derived via Python's
 * standard `base64.b32encode`, not via PwgBase32 itself, to avoid
 * circularity). It's a 20-byte secret -- 160 bits divides evenly by 5,
 * so PwgBase32::decode() round-trips it correctly (see PwgBase32Test's
 * documented bug: decode() only round-trips cleanly when no padding was
 * needed), which is exactly why generateSecret()'s own default length
 * is 20 bytes.
 */
const RFC6238_SHA1_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

function invokeGenerateCodeFromTimestamp(string $secret, float $counter): string
{
    $method = new ReflectionMethod(PwgTOTP::class, 'generateCodeFromTimestamp');
    $result = $method->invoke(null, $secret, $counter);
    if (! is_string($result)) {
        throw new \LogicException('generateCodeFromTimestamp() did not return a string');
    }
    return $result;
}

test('generateCodeFromTimestamp matches the RFC 6238 vector for T=59 (counter 1)', function (): void {
    // RFC 6238 Appendix B publishes the 8-digit code '94287082' for
    // T=59 with this secret; this class truncates to 6 digits via
    // $number % 1_000_000, which is exactly the last 6 digits of that
    // same $number, i.e. the tail of the published 8-digit value.
    expect(invokeGenerateCodeFromTimestamp(RFC6238_SHA1_SECRET_BASE32, 1.0))->toBe('287082');
});

test('generateCodeFromTimestamp matches the RFC 6238 vector for T=1111111109 (counter 37037036)', function (): void {
    // Published 8-digit code is '07081804' for this T; confirms the
    // counter (not just the secret) actually drives the HMAC input,
    // ruling out a stuck/hardcoded implementation.
    expect(invokeGenerateCodeFromTimestamp(RFC6238_SHA1_SECRET_BASE32, 37037036.0))->toBe('081804');
});

test('generateSecret produces a 32-character, unpadded base32 alphabet string by default', function (): void {
    $secret = PwgTOTP::generateSecret();

    expect($secret)->toHaveLength(32)
        ->and($secret)->toMatch('/^[A-Z2-7]{32}$/');
});

test('generateSecret honours an explicit byte length', function (): void {
    // 10 bytes = 80 bits = 16 base32 chars exactly (no padding needed
    // either, since encode() is always called with padding=false here).
    $secret = PwgTOTP::generateSecret(10);

    expect($secret)->toHaveLength(16);
});

test('generateSecret rejects a length below 1', function (): void {
    expect(fn (): string => PwgTOTP::generateSecret(0))
        ->toThrow(\InvalidArgumentException::class, 'generateSecret(): $length must be at least 1');
});

test('generateCode/verifyCode round-trip for a freshly generated secret', function (): void {
    $secret = PwgTOTP::generateSecret();
    $code = PwgTOTP::generateCode($secret);

    expect($code)->toHaveLength(6);
    expect(PwgTOTP::verifyCode($code, $secret))->toBeTrue();

    // Guaranteed different from the real code, regardless of what it
    // happened to be, so this can never coincidentally pass.
    $wrongCode = $code === '000000' ? '111111' : '000000';
    expect(PwgTOTP::verifyCode($wrongCode, $secret))->toBeFalse();
});

/**
 * getOtpAuthUrl()/getQrCode() had zero coverage before this file: only
 * generateCodeFromTimestamp()/generateSecret()/generateCode()/verifyCode()
 * were exercised above. Only getAbsoluteRootUrl() is ever called by
 * either method -- every other UrlServiceInterface method throws so a
 * regression that starts reaching one is caught immediately, matching
 * DerivativeImageTestFakeUrlService's own established shape.
 */
final class PwgTOTPTestFakeUrlService implements UrlServiceInterface
{
    #[\Override]
    public function getRootUrl(): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        return 'https://gallery.example.test/piwigo/';
    }

    #[\Override]
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function makeIndexUrl(array $params = []): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function makePictureUrl(array $params): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function parseSectionUrl(array $tokens, &$nextToken, \Piwigo\Core\RedirectServiceInterface $redirectService): array
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function getActionUrl($id, $whatPart, bool $download): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function getElementUrl(array $elementInfo): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function setMakeFullUrl(): void
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function unsetMakeFullUrl(): void
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function embellishUrl(string $url): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function getGalleryHomeUrl(): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function urlIsRemote(string $url): bool
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[\Override]
    public function getUserFavorites(): array
    {
        throw new \LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }
}

test('getOtpAuthUrl builds an otpauth:// url from the current user and a scheme-stripped-of-trailing-slash root url', function (): void {
    CurrentUser::set(new User(
        id: UserId::from(9),
        username: 'totp_user',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));

    try {
        $url = PwgTOTP::getOtpAuthUrl('JBSWY3DPEHPK3PXP', new PwgTOTPTestFakeUrlService());

        expect($url)->toBe(
            'otpauth://totp/totp_user:https://gallery.example.test/piwigo?secret=JBSWY3DPEHPK3PXP&issuer=Piwigo&algorithm=sha1&digits=6&period=30'
        );
    } finally {
        CurrentUser::reset();
    }
});

test('getQrCode returns a base64 PNG data uri encoding the same otpauth url as getOtpAuthUrl', function (): void {
    CurrentUser::set(new User(
        id: UserId::from(9),
        username: 'totp_user',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));

    try {
        $dataUri = PwgTOTP::getQrCode('JBSWY3DPEHPK3PXP', new PwgTOTPTestFakeUrlService());

        expect($dataUri)->toStartWith('data:image/png;base64,');
    } finally {
        CurrentUser::reset();
    }
});
