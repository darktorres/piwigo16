<?php

declare(strict_types=1);

use Piwigo\Auth\PwgBase32;

/**
 * RFC 4648 section 10 publishes canonical base32 test vectors for the
 * ASCII strings 'f'/'fo'/'foo'/'foob'/'fooba'/'foobar'. Each encode()
 * expectation below is one of those published vectors, hand-traced once
 * against this class's own algorithm (5-bit regrouping + alphabet
 * lookup + '='-padding to a 40-bit/8-char boundary) to confirm it's a
 * faithful RFC 4648 implementation before trusting the vector.
 */
test('encode returns an empty string for empty input', function (): void {
    expect(PwgBase32::encode(''))->toBe('');
});

test('encode pads a single byte to the RFC 4648 vector', function (): void {
    expect(PwgBase32::encode('f'))->toBe('MY======');
});

test('encode without padding omits the trailing = characters', function (): void {
    expect(PwgBase32::encode('f', false))->toBe('MY');
});

test('encode a 3-byte input matches the RFC 4648 vector', function (): void {
    expect(PwgBase32::encode('foo'))->toBe('MZXW6===');
});

test('encode a 6-byte input matches the RFC 4648 vector', function (): void {
    expect(PwgBase32::encode('foobar'))->toBe('MZXW6YTBOI======');
});

test('encode a 2-byte input (16-bit remainder) matches the RFC 4648 vector', function (): void {
    // 2 bytes = 16 bits, so strlen($binaryString) % 40 === 16 -- a
    // distinct padding branch from the 1-byte (x=8) and 3-byte (x=24)
    // cases above, exercising the 4-padding-char '$x === 16' branch.
    expect(PwgBase32::encode('fo'))->toBe('MZXQ====');
});

test('encode a 4-byte input (32-bit remainder) matches the RFC 4648 vector', function (): void {
    // 4 bytes = 32 bits, so strlen($binaryString) % 40 === 32 -- the
    // remaining untested padding branch, appending a single '=' char.
    expect(PwgBase32::encode('foob'))->toBe('MZXW6YQ=');
});

test('decode returns null for empty input', function (): void {
    expect(PwgBase32::decode(''))->toBeNull();
});

test('decode rejects a padding-character count RFC 4648 never produces', function (): void {
    // Valid trailing '=' counts are 6, 4, 3, 1 or 0 -- 2 is never valid.
    expect(PwgBase32::decode('ABCDEF=='))->toBeFalse();
});

test('decode rejects padding characters that are not at the very end', function (): void {
    // One '=' is a valid count, but it must be the last character.
    expect(PwgBase32::decode('AB=CDEFG'))->toBeFalse();
});

test('decode rejects an invalid leading character of an 8-char block', function (): void {
    // '1' and '0' are not in the base32 alphabet (only 2-7 are used).
    expect(PwgBase32::decode('1AAAAAAA'))->toBeFalse();
});

test('decode a lone padding character returns an empty string', function (): void {
    // A single '=' passes the padding-count/position checks (count 1,
    // at the end), then str_replace strips it to '', leaving nothing
    // for the byte-decoding loop to iterate over.
    expect(PwgBase32::decode('='))->toBe('');
});

test('encode/decode round-trip correctly for a 5-byte input needing no padding', function (): void {
    // 5 bytes = 40 bits = exactly 8 base32 chars, so no '=' padding is
    // ever produced and the decode loop's fixed 8-char stride lines up
    // exactly with the real data -- this is the aligned case where the
    // round trip is correct end to end.
    expect(PwgBase32::encode('world'))->toBe('O5XXE3DE')
        ->and(PwgBase32::decode('O5XXE3DE'))->toBe('world');
});

test('decode crashes on a padded value instead of decoding it (real bug)', function (): void {
    // decode() strips the '=' characters before iterating in fixed
    // 8-character strides. For 'foo' (encoded 'MZXW6==='), stripping
    // padding leaves only 5 characters, so the inner loop reads past
    // the end of the character array for the missing 3 positions.
    // Under this file's declare(strict_types=1), the resulting
    // undefined-index null is passed to base_convert()'s non-nullable
    // string parameter, which throws instead of silently coercing --
    // so ANY encode() output that actually needed padding cannot be
    // decoded back via this method. Documented here rather than
    // silently asserted around.
    expect(fn (): string|false|null => PwgBase32::decode('MZXW6==='))->toThrow(\TypeError::class);
});
