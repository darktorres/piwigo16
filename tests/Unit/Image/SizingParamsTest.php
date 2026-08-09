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

/**
 * Confirmed-equivalent: line 100's, 101's, 106's (x2), 113's (x2), 123's,
 * and 124's RemoveDoubleCast. $destCrop->width()/height() are always
 * float (ImageRect's own return type), so PHP promotes every one of
 * these divisions/multiplications to float the moment either operand
 * is float, regardless of which side carries the explicit (float) cast
 * -- same rule already established for ImageRect.php/DerivativeImage.php.
 * Also confirmed-equivalent: line 108's and line 115's
 * RemoveIntegerCast (the (int) cast on crop_h()/crop_v()'s own $pixels
 * argument) -- that parameter is untyped, and ImageRect's own crop
 * methods already do float arithmetic on it internally regardless of
 * whether the caller pre-cast it to int. All 8 live sed-verified
 * against the full suite.
 *
 * Also confirmed-equivalent: line 126's GreaterToGreaterOrEqual
 * (`$ratio_w >= $ratio_h` instead of `>`), algebraically proven, not
 * just spot-checked: at the exact point ratio_w === ratio_h, both
 * branches compute the SAME final scale_size. Ratios can only tie when
 * ideal_size's own aspect ratio exactly matches the input's (ratio_w =
 * in_w/ideal_w, ratio_h = in_h/ideal_h, tied means ideal_w/ideal_h =
 * in_w/in_h) -- under that constraint, floor(in_w / ratio_h) always
 * equals ideal_w exactly (no rounding loss, since ideal_w is already a
 * real int), so the "vertical" branch's own scale_size[0] and the
 * "horizontal" branch's scale_size[0] = ideal_size[0] converge on the
 * identical value, for every possible tied-ratio input, not just a
 * tested case. Live sed-verified with a genuinely non-integer tied
 * ratio (30/7 = 60/14) against the full suite too, confirming the
 * floor() rounding doesn't break the equivalence.
 */
test('compute enters the crop-eligibility block only once width ratio reaches exactly 1, not before', function (): void {
    // Kills line 102's GreaterToGreaterOrEqual and DecrementInteger for
    // ratio_w's own `> 1` comparison -- ratio_h is pinned <1 so the
    // outer if's truth depends purely on ratio_w's own boundary.
    $params = new SizingParams([100, 100], 0.5, [10, 60]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([100, 50], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBeNull();
});

test('compute enters the crop-eligibility block only once height ratio reaches exactly 1, not before', function (): void {
    // Kills line 102's GreaterToGreaterOrEqual and DecrementInteger for
    // ratio_h's own `> 1` comparison (mirror of the sibling test above).
    $params = new SizingParams([100, 100], 0.5, [60, 10]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([50, 100], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBeNull();
});

test('compute enters the crop-eligibility block from width ratio alone, not requiring both ratios over the boundary', function (): void {
    // Kills line 102's BooleanOrToBooleanAnd (both ratios required
    // instead of either) and IncrementInteger for ratio_w's own
    // threshold (`> 2` instead of `> 1`) together -- ratio_w pinned at
    // exactly 2 with ratio_h clearly below 1.
    $params = new SizingParams([100, 300], 0.5, [80, 240]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([200, 150], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->l)->toBe(50.0);
    expect($cropRect->r)->toBe(150.0);
});

test('compute enters the crop-eligibility block from height ratio alone, not requiring both ratios over the boundary', function (): void {
    // Kills line 102's IncrementInteger for ratio_h's own threshold
    // (`> 2` instead of `> 1`) -- mirror of the sibling test above.
    $params = new SizingParams([300, 100], 0.5, [240, 80]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([150, 200], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->t)->toBe(50.0);
    expect($cropRect->b)->toBe(150.0);
});

test('compute picks the vertical crop branch, not the horizontal one, when width and height ratios are exactly tied', function (): void {
    // Kills line 103's GreaterToGreaterOrEqual -- unlike line 126's own
    // tied-ratio boundary (provably equivalent, see the docblock
    // above), this one has real, independently-chosen min_size
    // consequences per branch (h < min_size[1] vs w < min_size[0]), so
    // a tie genuinely distinguishes real code's own branch choice
    // (real code takes vertical here, cropping nothing since 100 is
    // not < 90; the mutant would wrongly take horizontal instead,
    // checking 100 < 180, and crop).
    $params = new SizingParams([100, 100], 0.5, [90, 180]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([200, 200], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBe([100, 100]);
});

/**
 * Confirmed-equivalent: line 105's and line 112's SmallerToSmallerOrEqual
 * (`<=` instead of `<`), algebraically proven, not just spot-checked --
 * live sed-mutate-and-rerun against the two tests below (still passing
 * unchanged with either mutation applied) confirmed it. At the exact
 * point h === min_size[1] (or w === min_size[0]), the crop amount this
 * branch would compute is always exactly 0: h === min_size[1] rearranges
 * to destCrop->width() === floor(destCrop->height() * ideal_size[0] /
 * min_size[1]), which is exactly what idealCropPx subtracts from
 * destCrop->width() a few lines below -- so idealCropPx = 0 at that
 * exact boundary, for every possible input reaching it, not just a
 * tested case. Entering the crop-application branch one comparison
 * early doesn't change anything: a 0-pixel crop is a real no-op both
 * inside ImageRect::crop_h()/crop_v() and in the final cropped
 * dimensions, whether the branch is entered or not.
 */
test('compute\'s horizontal crop is a true no-op once available height reaches exactly min_size, not just close to it', function (): void {
    $params = new SizingParams([100, 200], 0.5, [80, 100]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 300], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBe([100, 100]);
});

test('compute\'s vertical crop is a true no-op once available width reaches exactly min_size, not just close to it', function (): void {
    $params = new SizingParams([200, 100], 0.5, [100, 75]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 300], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBe([100, 100]);
});

test('compute\'s vertical max-crop-pixels floors the fractional ideal-crop distance, not rounds it', function (): void {
    // Kills line 113's FloorToRound/FloorToCeil -- mirrors the exact
    // numbers of the existing "compute crops horizontally" test above,
    // swapped onto the vertical branch, to reach floor()'s own exact
    // .5 fraction (187.5) at line 113 specifically (the sibling
    // "compute crops vertically" test's own numbers happen to divide
    // evenly there, coincidentally not exercising this rounding at
    // all).
    $params = new SizingParams([200, 100], 0.5, [160, 80]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 300], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->t)->toBe(56.0);
    expect($cropRect->b)->toBe(243.0);
});

test('compute\'s horizontal max-crop-pixels uses real rounding, not floor or ceil, at a .3 fraction', function (): void {
    // Kills line 107's RoundToCeil -- round(50.3) = 50 = floor(50.3),
    // but ceil(50.3) = 51, so only the ceil mutant is exposed here.
    $params = new SizingParams([10, 200], 0.503, [5, 190]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([100, 300], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->l)->toBe(25.0);
    expect($cropRect->r)->toBe(75.0);
});

test('compute\'s horizontal max-crop-pixels uses real rounding, not floor or ceil, at a .7 fraction', function (): void {
    // Kills line 107's RoundToFloor -- round(50.7) = 51 = ceil(50.7),
    // but floor(50.7) = 50, so only the floor mutant is exposed here
    // (the sibling test above covers the ceil mutant, using a fraction
    // on the other side of .5).
    $params = new SizingParams([10, 200], 0.507, [5, 190]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([100, 300], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->l)->toBe(25.0);
    expect($cropRect->r)->toBe(74.0);
});

test('compute\'s vertical max-crop-pixels uses real rounding, not floor or ceil, at a .3 fraction', function (): void {
    // Kills line 114's RoundToCeil (mirror of line 107's own .3 test).
    $params = new SizingParams([200, 10], 0.503, [190, 5]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 100], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->t)->toBe(25.0);
    expect($cropRect->b)->toBe(75.0);
});

test('compute\'s vertical max-crop-pixels uses real rounding, not floor or ceil, at a .7 fraction', function (): void {
    // Kills line 114's RoundToFloor (mirror of line 107's own .7 test).
    $params = new SizingParams([200, 10], 0.507, [190, 5]);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 100], null, $cropRect, $scaleSize);

    if ($cropRect === null) {
        throw new RuntimeException('Expected compute() to produce a crop rect');
    }
    expect($cropRect->t)->toBe(25.0);
    expect($cropRect->b)->toBe(74.0);
});

test('compute\'s own scale-size ratio check enters at exactly width ratio 1, not before', function (): void {
    // Kills line 125's GreaterToGreaterOrEqual and DecrementInteger for
    // ratio_w -- max_crop=0 skips the earlier crop-eligibility block
    // entirely, isolating this second, scale_size-only ratio check.
    $params = new SizingParams([100, 100], 0.0, null);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([100, 50], null, $cropRect, $scaleSize);

    expect($scaleSize)->toBeNull();
});

test('compute\'s own scale-size ratio check enters at exactly height ratio 1, not before', function (): void {
    // Kills line 125's GreaterToGreaterOrEqual and DecrementInteger for
    // ratio_h (mirror of the sibling test above).
    $params = new SizingParams([100, 100], 0.0, null);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([50, 100], null, $cropRect, $scaleSize);

    expect($scaleSize)->toBeNull();
});

test('compute\'s own scale-size ratio check enters from width ratio alone, not requiring both ratios over the boundary', function (): void {
    // Kills line 125's BooleanOrToBooleanAnd and IncrementInteger for
    // ratio_w together.
    $params = new SizingParams([100, 300], 0.0, null);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([200, 150], null, $cropRect, $scaleSize);

    expect($scaleSize)->toBe([100, 75]);
});

test('compute\'s own scale-size ratio check enters from height ratio alone, not requiring both ratios over the boundary', function (): void {
    // Kills line 125's IncrementInteger for ratio_h (mirror of the
    // sibling test above).
    $params = new SizingParams([300, 100], 0.0, null);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([150, 200], null, $cropRect, $scaleSize);

    expect($scaleSize)->toBe([75, 100]);
});

test('compute\'s horizontal scale-size branch floors the scaled height, not rounds it', function (): void {
    // Kills line 128's FloorToRound -- 157/2.5 = 62.8, floor = 62,
    // round = 63.
    $params = new SizingParams([40, 999], 0.0, null);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([100, 157], null, $cropRect, $scaleSize);

    expect($scaleSize)->toBe([40, 62]);
});

test('compute\'s vertical scale-size branch floors the scaled width, not rounds it', function (): void {
    // Kills line 130's FloorToRound (mirror of the sibling test above).
    $params = new SizingParams([999, 40], 0.0, null);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([157, 100], null, $cropRect, $scaleSize);

    expect($scaleSize)->toBe([62, 40]);
});

test('compute leaves crop_rect null when the (unmodified) crop dimensions still exactly match the real input, without comparing the wrong axis', function (): void {
    // Kills line 138's DecrementInteger/IncrementInteger (comparing
    // width against in_size[1] or height against in_size[0] instead of
    // their own matching index) -- a genuinely non-square input where
    // nothing crops or scales at all is what exposes an axis mix-up: a
    // swapped comparison would find width(300) !== in_size[1](200) (or
    // the mirrored height/in_size[0] pair) even though nothing actually
    // changed.
    $params = new SizingParams([500, 500], 0.0, null);

    $cropRect = null;
    $scaleSize = null;
    $params->compute([300, 200], null, $cropRect, $scaleSize);

    expect($cropRect)->toBeNull();
    expect($scaleSize)->toBeNull();
});
