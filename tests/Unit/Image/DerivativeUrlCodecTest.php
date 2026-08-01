<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeUrlCodec;

/**
 * Piwigo\Image\DerivativeUrlCodec -- pure, stateless derivative-filename-
 * token encoding helpers. Had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1) despite
 * being simple, deterministic, side-effect-free logic.
 */
test('derivativeToUrl truncates a derivative type to its first 2 characters', function (): void {
    expect(DerivativeUrlCodec::derivativeToUrl('square'))->toBe('sq');
    expect(DerivativeUrlCodec::derivativeToUrl('thumb'))->toBe('th');
});

/**
 * Confirmed-equivalent: line 33's IncrementInteger (`return $size[1];`
 * instead of `$size[0];`). Only reachable inside the `$size[0] ===
 * $size[1]` branch -- by definition, both elements are already equal
 * there, so returning either index produces the identical value for
 * every possible input, not just the test's own square-size case.
 */
test('sizeToUrl collapses a square size to a single number, keeps a rectangular one as WxH', function (): void {
    expect(DerivativeUrlCodec::sizeToUrl([100, 100]))->toBe(100);
    expect(DerivativeUrlCodec::sizeToUrl([100, 200]))->toBe('100x200');
});

/**
 * Confirmed-equivalent: line 55's UnwrapSubstr (`(int) $s` instead of
 * `(int) substr($s, 0, $pos)` for the WxH token's width half). PHP's
 * (int) cast on a string always stops at the first non-digit character
 * it encounters -- since $pos is strpos($s, 'x') (the position of that
 * exact stopping point, 'x' being non-numeric), casting the untruncated
 * $s produces exactly the same int as casting the substring up to it,
 * for every possible WxH token, not just '100x200'.
 */
test('urlToSize parses both the single-number and WxH token shapes, the exact inverse of sizeToUrl', function (): void {
    expect(DerivativeUrlCodec::urlToSize('100'))->toBe([100, 100]);
    expect(DerivativeUrlCodec::urlToSize('100x200'))->toBe([100, 200]);
});

test('sizeEquals compares both dimensions', function (): void {
    expect(DerivativeUrlCodec::sizeEquals([100, 200], [100, 200]))->toBeTrue();
    expect(DerivativeUrlCodec::sizeEquals([100, 200], [100, 201]))->toBeFalse();
    expect(DerivativeUrlCodec::sizeEquals([100, 200], [101, 200]))->toBeFalse();
});

test('charToFraction maps a-z linearly onto 0..1', function (): void {
    expect(DerivativeUrlCodec::charToFraction('a'))->toBe(0);
    expect(DerivativeUrlCodec::charToFraction('z'))->toBe(1);
    expect(DerivativeUrlCodec::charToFraction('n'))->toBe(0.52);
});

test('charToFraction reads only the FIRST character, ignoring the rest of a longer string', function (): void {
    // Kills line 72's DecrementInteger (`$char[0]` -> `$char[-1]`, PHP's
    // negative-index "last character" syntax) -- every other test here
    // passes a single-character string, where index 0 and index -1 are
    // the same character.
    expect(DerivativeUrlCodec::charToFraction('ab'))->toBe(0);
});

test('fractionToChar maps 0..1 back onto a-z, the near-inverse of charToFraction', function (): void {
    expect(DerivativeUrlCodec::fractionToChar(0.0))->toBe('a');
    expect(DerivativeUrlCodec::fractionToChar(1.0))->toBe('z');
    expect(DerivativeUrlCodec::fractionToChar(0.5))->toBe('n');
});

test('fractionToChar does not clamp a slightly out-of-range fraction (only the resulting codepoint is clamped)', function (): void {
    // codepoint = ord('a') + round(-1.0*25) = 97-25 = 72 = 'H' -- within
    // [0,255], so no clamping kicks in despite the negative fraction.
    expect(DerivativeUrlCodec::fractionToChar(-1.0))->toBe('H');
    // codepoint = 97 + round(2.0*25) = 147 -- also within [0,255].
    expect(ord(DerivativeUrlCodec::fractionToChar(2.0)))->toBe(147);
});

test('fractionToChar clamps a codepoint that would fall outside 0..255', function (): void {
    expect(ord(DerivativeUrlCodec::fractionToChar(-10.0)))->toBe(0);
    expect(ord(DerivativeUrlCodec::fractionToChar(20.0)))->toBe(255);
});

test('fractionToChar clamps a codepoint of exactly -1 to 0, not left unclamped', function (): void {
    // Kills line 83's DecrementInteger (`$codepoint < -1` instead of
    // `< 0`) -- the existing -10.0 case clamps too, but -1 is the ONE
    // value where `< -1` (false) and `< 0` (true) actually disagree;
    // anything further negative agrees on both. Left unclamped, chr(-1)
    // wraps to byte 255 (PHP's chr() wraps via modulo-256 semantics),
    // sharply different from the real, clamped chr(0).
    expect(ord(DerivativeUrlCodec::fractionToChar(-3.92)))->toBe(0);
});

test('fractionToChar clamps a codepoint of exactly 256 to 255, not left unclamped', function (): void {
    // Kills line 85's IncrementInteger (`$codepoint > 256` instead of
    // `> 255`) -- the existing 20.0 case clamps too, but 256 is the ONE
    // value where `> 256` (false) and `> 255` (true) actually disagree;
    // anything further above agrees on both. Left unclamped, chr(256)
    // wraps to byte 0, sharply different from the real, clamped chr(255).
    expect(ord(DerivativeUrlCodec::fractionToChar(6.36)))->toBe(255);
});

/**
 * Confirmed-equivalent: line 83's SmallerToSmallerOrEqual (`<= 0`
 * instead of `< 0`) and IncrementInteger (`< 1` instead of `< 0`), and
 * line 85's GreaterToGreaterOrEqual (`>= 255` instead of `> 255`) and
 * DecrementInteger (`> 254` instead of `> 255`). Each pair of conditions
 * disagrees on exactly ONE integer codepoint value (0 for the first
 * pair, 255 for the second) -- and at that exact value, the clamp
 * action itself is a no-op: $codepoint is already 0 (respectively 255),
 * so whether the assignment `$codepoint = 0` (respectively `= 255`)
 * actually executes changes nothing observable about the final
 * chr($codepoint) result. True for every possible codepoint, not just
 * a specific tested value -- this isn't a gap in test data, the
 * boundary itself has no behavioral effect to observe.
 */
