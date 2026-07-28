<?php

declare(strict_types=1);

use Piwigo\Image\SizingParams;

/**
 * Piwigo\Image\SizingParams -- had partial coverage (compute()'s ratio_h
 * >= ratio_w / crop_v branch, and add_url_tokens() entirely, were
 * untested; see /home/torres/.claude/plans/piped-enchanting-spark.md,
 * Wave 1). Every value below was independently confirmed by invoking the
 * real class before writing the assertion.
 *
 * Real finding, not fixed here (self-consistent, no observable bug --
 * add_url_tokens()'s result is only ever used as an internal cache-key
 * string, never parsed back character-by-character, see ImageStdParams::
 * get_custom()): SizingParams::classic()/square() both construct with an
 * *int* max_crop default/literal (0 and 1), but add_url_tokens()'s fast
 * paths check `=== 0.0`/`=== 1.0` (float, strict). An int 0/1 never
 * satisfies a strict float comparison in PHP, so every classic()/square()
 * instance actually falls through to the general (3-token) branch instead
 * of its own "intended" fast single-token path -- confirmed below.
 */
test('classic builds a plain ideal_size with max_crop 0 and null min_size', function (): void {
    $params = SizingParams::classic(100, 200);

    expect($params->ideal_size)->toBe([100, 200]);
    expect($params->max_crop)->toBe(0);
    expect($params->min_size)->toBeNull();
});

test('square builds an equal ideal/min size with max_crop 1', function (): void {
    $params = SizingParams::square(120);

    expect($params->ideal_size)->toBe([120, 120]);
    expect($params->max_crop)->toBe(1);
    expect($params->min_size)->toBe([120, 120]);
});

test('add_url_tokens takes the fast "s" single-token path only for an explicit float 0.0 max_crop', function (): void {
    $params = new SizingParams([100, 200], 0.0, null);
    $tokens = [];
    $params->add_url_tokens($tokens);

    expect($tokens)->toBe(['s100x200']);
});

test('add_url_tokens takes the fast "e" single-token path only for an explicit float 1.0 max_crop with matching min_size', function (): void {
    $params = new SizingParams([120, 120], 1.0, [120, 120]);
    $tokens = [];
    $params->add_url_tokens($tokens);

    expect($tokens)->toBe(['e120']);
});

test('add_url_tokens falls through to the general 2-token form for classic()\'s own int max_crop default', function (): void {
    $params = SizingParams::classic(100, 200);
    $tokens = [];
    $params->add_url_tokens($tokens);

    // sizeToUrl + fractionToChar(0) -- NOT the 's100x200' fast form, per
    // this file's own class docblock finding above.
    expect($tokens)->toBe(['100x200', 'a']);
});

test('add_url_tokens falls through to the general 3-token form for square()\'s own int max_crop literal', function (): void {
    $params = SizingParams::square(120);
    $tokens = [];
    $params->add_url_tokens($tokens);

    expect($tokens)->toBe([120, 'z', 120]);
});

test('add_url_tokens includes a 3rd min_size token only when min_size is set', function (): void {
    $withMinSize = new SizingParams([100, 100], 0.5, [50, 50]);
    $tokensWith = [];
    $withMinSize->add_url_tokens($tokensWith);
    expect($tokensWith)->toBe([100, 'n', 50]);

    $withoutMinSize = new SizingParams([100, 100], 0.5, null);
    $tokensWithout = [];
    $withoutMinSize->add_url_tokens($tokensWithout);
    expect($tokensWithout)->toBe([100, 'n']);
});

test('compute throws when max_crop > 0 but min_size is null', function (): void {
    $params = new SizingParams([100, 100], 0.5, null);

    expect(function () use ($params): void {
        $cropRect = null;
        $scaleSize = null;
        $params->compute([300, 300], null, $cropRect, $scaleSize);
    })->toThrow(LogicException::class, 'SizingParams::compute(): min_size must not be null when max_crop > 0');
});

test('compute with max_crop 0 pure-scales down without cropping', function (): void {
    $params = SizingParams::classic(100, 100);
    $params->max_crop = 0.0;

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 300], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBe([100, 100]);
});

test('compute is a no-op (no crop, no scale) when the input is already smaller than the ideal size', function (): void {
    $params = SizingParams::classic(500, 500);
    $params->max_crop = 0.0;

    $cropRect = null;
    $scaleSize = null;
    $params->compute([100, 100], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBeNull();
});

test('compute crops horizontally when the width ratio exceeds the height ratio', function (): void {
    $params = new SizingParams([100, 200], 0.5, [80, 160]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 300], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->l)->toBe(56.0);
    expect($cropRect->t)->toBe(0);
    expect($cropRect->r)->toBe(243.0);
    expect($cropRect->b)->toBe(300);
    expect($scaleSize)->toBe([100, 160]);
});

test('compute crops vertically when the height ratio exceeds the width ratio', function (): void {
    $params = new SizingParams([200, 100], 0.5, [150, 75]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 300], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->l)->toBe(0);
    expect($cropRect->t)->toBe(50.0);
    expect($cropRect->r)->toBe(300);
    expect($cropRect->b)->toBe(250.0);
    expect($scaleSize)->toBe([150, 100]);
});
