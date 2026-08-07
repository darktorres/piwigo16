<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use LogicException;
use InvalidArgumentException;
use Piwigo\Auth\PwgTOTP;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\CurrentUser;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use ReflectionMethod;

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
 *
 * A mutation-testing sweep found a few confirmed-equivalent mutants in
 * generateCodeFromTimestamp()/verifyCode(), none worth chasing further:
 * - `ord(substr($hash, -1)[0])`: substr($hash, -1) is always exactly 1
 *   character, so indexing it with [0] or a mutated [-1] reaches the
 *   same (only) character either way.
 * - `substr($hash, $offset, 4)` widened to a mutated 5: unpack('N', ...)
 *   only ever consumes the format's own 4 bytes off the front of
 *   whatever string it's given: a longer $part changes nothing it
 *   reads, and $offset (0-15) + 5 never exceeds the 20-byte SHA1 digest
 *   either, so there's no out-of-range truncation to observe.
 * - `$timestamp + (float) $i` in verifyCode()'s loop: the loop's own `$i
 *   = -$check_interval` to `$check_interval` range is always symmetric
 *   around 0, so negating $i (a mutated `-` instead of `+`) produces the
 *   exact same *set* of checked interval timestamps, just visited in a
 *   different order -- unobservable via "did any of them match". The
 *   `(float)` cast on that same $i is likewise redundant: $timestamp is
 *   already a float, and PHP promotes int+float to float with the same
 *   value regardless of an explicit cast on the int operand.
 */
const RFC6238_SHA1_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

function invokeGenerateCodeFromTimestamp(string $secret, float $counter): string
{
    $method = new ReflectionMethod(PwgTOTP::class, 'generateCodeFromTimestamp');
    $result = $method->invoke(null, $secret, $counter);
    if (! is_string($result)) {
        throw new LogicException('generateCodeFromTimestamp() did not return a string');
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

test('generateCodeFromTimestamp still produces a code when PwgBase32::decode() returns a non-string', function (): void {
    // PwgBase32::decode('') returns null, not a string -- the (string)
    // cast on $key is what turns that into hash_hmac()'s required
    // empty-string key rather than a TypeError under strict_types=1.
    expect(invokeGenerateCodeFromTimestamp('', 1.0))->toBe('812658');
});

test('generateCodeFromTimestamp masks unpacked[1] with 0x7FFFFFFF, not a value with a cleared low bit', function (): void {
    // Every RFC 6238 vector above happens to land on an unpacked[1] with
    // its own low bit already 0, so they can't tell 0x7FFFFFFF apart
    // from a mutated 0x7FFFFFFE (both mask to the same value there).
    // Counter 3 for this same secret has a raw unpacked[1] whose low bit
    // is genuinely 1 (found by brute-force search, not RFC-published),
    // so clearing it would shift $number by 1 and change the final code.
    expect(invokeGenerateCodeFromTimestamp(RFC6238_SHA1_SECRET_BASE32, 3.0))->toBe('969429');
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
        ->toThrow(InvalidArgumentException::class, 'generateSecret(): $length must be at least 1');
});

test('generateSecret accepts the boundary length of exactly 1', function (): void {
    // The error message says "must be at least 1" -- 1 itself must be
    // accepted, not rejected (distinguishes `$length < 1` from a mutated
    // `<= 1` or `< 2`, both of which would incorrectly throw for 1).
    expect(fn (): string => PwgTOTP::generateSecret(1))->not->toThrow(InvalidArgumentException::class);
});

test('generateSecret never pads, even for a byte length whose bits do not land on a 40-bit boundary', function (): void {
    // 9 bytes = 72 bits, 72 % 40 = 32 -- a length that WOULD need '='
    // padding if `padding: false` were flipped to `true`. Both existing
    // length tests above use 20 and 10 bytes, whose bit counts (160 and
    // 80) both happen to divide evenly by 40, so neither can tell
    // padding=false apart from padding=true -- PwgBase32::encode() only
    // appends '=' when there's a nonzero remainder either way.
    $secret = PwgTOTP::generateSecret(9);

    expect($secret)->toHaveLength(15)
        ->and($secret)->not->toContain('=');
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

test('generateCode floors the raw time()/$timestamp ratio rather than rounding or ceiling it', function (): void {
    // Choosing a period ~1.5x the current real time() makes the ratio
    // land reliably in [0.5, 1) *regardless* of exactly which second
    // time() reads internally (a period this many orders of magnitude
    // larger than any realistic clock skew swamps a 1-tick difference):
    // floor() of that ratio is always 0, while both round() (which
    // rounds .667 up to 1) and ceil() (which rounds any non-integer up)
    // would instead select counter 1's code.
    $secret = PwgTOTP::generateSecret();
    $now = time();
    $period = $now + intdiv($now, 2);

    $code = PwgTOTP::generateCode($secret, $period);

    expect($code)->toBe(invokeGenerateCodeFromTimestamp($secret, 0.0));
});

test('verifyCode floors the raw time()/$timestamp ratio rather than rounding or ceiling it', function (): void {
    // Same period-selection trick as generateCode()'s own floor test
    // above, applied to verifyCode()'s independent `floor(time() /
    // $timestamp)` call -- check_interval=0 so only counter 0's code is
    // ever accepted, proving verifyCode() itself resolved "now" to
    // counter 0, not 1.
    $secret = PwgTOTP::generateSecret();
    $now = time();
    $period = $now + intdiv($now, 2);
    $counterZeroCode = invokeGenerateCodeFromTimestamp($secret, 0.0);

    expect(PwgTOTP::verifyCode($counterZeroCode, $secret, $period, 0))->toBeTrue();
});

test('verifyCode checks the full inclusive [-check_interval, +check_interval] range, including the upper bound', function (): void {
    // Default check_interval=1 must check counters -1, 0 *and* +1 -- a
    // mutated `$i <= $check_interval` to `$i < $check_interval` would
    // silently stop checking before reaching the "following" interval
    // (+1), rejecting a code that's genuinely still within the accepted
    // window. The same large-ratio trick pins "now" to counter 0, so
    // counter 1's code is unambiguously the +1 boundary.
    $secret = PwgTOTP::generateSecret();
    $now = time();
    $period = $now + intdiv($now, 2);
    $counterOneCode = invokeGenerateCodeFromTimestamp($secret, 1.0);

    expect(PwgTOTP::verifyCode($counterOneCode, $secret, $period))->toBeTrue();
});

test('getOtpAuthUrl builds an otpauth:// url from the current user and a scheme-stripped-of-trailing-slash root url', function (): void {
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(9),
        username: 'totp_user',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));

    try {
        $url = PwgTOTP::getOtpAuthUrl('JBSWY3DPEHPK3PXP', new PwgTOTPTestFakeUrlService(), CurrentUserTestFactory::get());

        expect($url)->toBe(
            'otpauth://totp/totp_user:https://gallery.example.test/piwigo?secret=JBSWY3DPEHPK3PXP&issuer=Piwigo&algorithm=sha1&digits=6&period=30'
        );
    } finally {
        CurrentUserTestFactory::get()->reset();
    }
});

test('getQrCode returns a base64 PNG data uri encoding the same otpauth url as getOtpAuthUrl', function (): void {
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(9),
        username: 'totp_user',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));

    try {
        $dataUri = PwgTOTP::getQrCode('JBSWY3DPEHPK3PXP', new PwgTOTPTestFakeUrlService(), CurrentUserTestFactory::get());

        expect($dataUri)->toStartWith('data:image/png;base64,');
    } finally {
        CurrentUserTestFactory::get()->reset();
    }
});
