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
 *
 * A mutation-testing sweep found several confirmed-equivalent mutants in
 * this class, none worth chasing further:
 * - encode()'s `if ($input === '') { return ''; }` early return: without
 *   it, str_split('') and str_split('', 5) both already return [] on
 *   this PHP version, so the rest of the function still produces '' --
 *   the guard is real but its removal is unobservable.
 * - `self::$map[(int) base_convert(...)]` (line 85): base_convert(...,
 *   2, 10) never emits a leading-zero string, so PHP's own "canonical
 *   decimal string" array-key coercion already casts it to int with or
 *   without the explicit cast.
 * - `strlen($binaryString) % 40) !== 0` (line 88): $x is always a
 *   multiple of 8 (0/8/16/24/32) by construction, so `!== -1`/`!== 1`
 *   behave identically to `!== 0` for every reachable value.
 * - decode()'s final `max(0, min(255, ...))` clamp (line 142): the value
 *   being clamped comes from `base_convert()` of an exactly-8-character
 *   binary string, which can only ever represent 0-255 -- the clamp
 *   bounds are unreachable in either direction.
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

test('decode rejects a padding count of 6 when those chars are not the true trailing block', function (): void {
    // 'AB======' + a full valid second block: substr_count() sees 6 '='
    // total (an allowed count), but they sit at the end of the FIRST
    // block, not the end of the whole string -- the loop's
    // `substr($input, -6) !== '======'` check (index 0, allowedValues[0]
    // === 6) is the only thing that rejects this; without it, rtrim()
    // silently strips the misplaced '=' run per-block and this decodes
    // "successfully" to a wrong value instead of failing.
    expect(PwgBase32::decode('AB======' . 'ABCDEFGH'))->toBeFalse();
});

test('decode rejects a padding count of 1 when that char is not the true trailing block', function (): void {
    // Same shape as the count-6 case above, but for allowedValues[3] ===
    // 1 -- 'ABCDEFG=' ends its own (non-final) block in a single '=',
    // followed by a full valid block, so the misplaced pad char would
    // otherwise rtrim() away silently instead of being rejected.
    expect(PwgBase32::decode('ABCDEFG=' . 'ABCDEFGH'))->toBeFalse();
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

test('decode correctly decodes a padded value (regression test for a fixed bug)', function (): void {
    // decode() used to strip the '=' characters before iterating in
    // fixed 8-character strides, desyncing that stride from the real
    // data and reading past the end of the character array for any
    // input that actually needed padding (a real TypeError under
    // declare(strict_types=1)) -- fixed to decode block-by-block
    // against the un-stripped input instead. Covers all 4 non-zero
    // padding levels RFC 4648 defines, plus the zero-padding case.
    expect(PwgBase32::decode('MY======'))->toBe('f')
        ->and(PwgBase32::decode('MZXQ===='))->toBe('fo')
        ->and(PwgBase32::decode('MZXW6==='))->toBe('foo')
        ->and(PwgBase32::decode('MZXW6YQ='))->toBe('foob')
        ->and(PwgBase32::decode('MZXW6YTBOI======'))->toBe('foobar');
});

test('encode/decode round-trips for every input length from 1 to 8 bytes', function (): void {
    // Exercises every padding level (6/4/3/1/0 trailing '=' chars) plus
    // a 2-block input (6 bytes needs a full unpadded block followed by
    // a padded one), proving the fix generalizes beyond the single-block
    // RFC 4648 vectors above.
    foreach (['a', 'ab', 'abc', 'abcd', 'abcde', 'abcdef', 'abcdefg', 'abcdefgh'] as $plaintext) {
        expect(PwgBase32::decode(PwgBase32::encode($plaintext)))->toBe($plaintext);
    }
});

test('encode/decode round-trips without padding too', function (): void {
    // encode(..., padding: false) produces a final block shorter than 8
    // characters with no trailing '=' at all -- a different shape than
    // the padded cases above, also handled by decode()'s per-block
    // chunking (str_split()'s own shorter-final-chunk behavior).
    foreach (['a', 'ab', 'abc', 'abcd', 'abcde', 'abcdef'] as $plaintext) {
        expect(PwgBase32::decode(PwgBase32::encode($plaintext, false)))->toBe($plaintext);
    }
});
