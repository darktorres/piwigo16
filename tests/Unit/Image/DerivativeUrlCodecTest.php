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

test('sizeToUrl collapses a square size to a single number, keeps a rectangular one as WxH', function (): void {
    expect(DerivativeUrlCodec::sizeToUrl([100, 100]))->toBe(100);
    expect(DerivativeUrlCodec::sizeToUrl([100, 200]))->toBe('100x200');
});

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
