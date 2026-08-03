<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\WatermarkParams;

/**
 * Piwigo\Image\DerivativeParams -- will_watermark() (partial) and
 * add_url_tokens() (fully) were uncovered (see /home/torres/.claude/plans/
 * piped-enchanting-spark.md, Wave 1); the rest is covered here too for a
 * complete, direct pass over this small, pure-logic-except-one-static-read
 * class.
 */
test('use_watermark/sharpen/last_mod_time/type default to their documented values', function (): void {
    $params = new DerivativeParams(SizingParams::classic(100, 100));

    expect($params->use_watermark)->toBeFalse();
    expect($params->sharpen)->toBe(0.0);
    expect($params->last_mod_time)->toBe(0);
    expect($params->type)->toBe(ImageStdParams::CUSTOM);
});

test('add_url_tokens delegates to the sizing object', function (): void {
    $params = new DerivativeParams(new SizingParams([100, 200], 0.0, null));
    $tokens = [];
    $params->add_url_tokens($tokens);

    expect($tokens)->toBe(['s100x200']);
});

/**
 * Confirmed-equivalent: line 72's UnwrapArrayMap (`array_map(intval(...),
 * $scale_size)` instead of the bare $scale_size). Every assignment to
 * $scale_size[0]/[1] inside SizingParams::compute()'s non-null branch is
 * already an int -- either $this->ideal_size[N] (documented `int[]`) or
 * an explicit `(int) floor(...)` cast -- so by the time compute_final_size()
 * reads it back, array_map(intval(...)) has nothing left to convert.
 */
test('compute_final_size returns the scaled-down size when scaling occurs', function (): void {
    $params = new DerivativeParams(SizingParams::classic(100, 100));
    $params->sizing->max_crop = 0.0;

    expect($params->compute_final_size([300, 300]))->toBe([100, 100]);
});

test('compute_final_size returns the original input size unchanged when no scaling is needed', function (): void {
    $params = new DerivativeParams(SizingParams::classic(100, 100));
    $params->sizing->max_crop = 0.0;

    expect($params->compute_final_size([50, 50]))->toBe([50, 50]);
});

test('max_width/max_height read the sizing object\'s ideal_size', function (): void {
    $params = new DerivativeParams(SizingParams::classic(800, 600));

    expect($params->max_width())->toBe(800);
    expect($params->max_height())->toBe(600);
});

test('is_identity is true only when the input fits within the ideal size on both dimensions', function (): void {
    $params = new DerivativeParams(SizingParams::classic(100, 100));

    expect($params->is_identity([50, 50]))->toBeTrue();
    expect($params->is_identity([100, 100]))->toBeTrue();
    expect($params->is_identity([200, 50]))->toBeFalse();
    expect($params->is_identity([50, 200]))->toBeFalse();
});

test('is_identity compares width against width and height against height independently, not a swapped pair', function (): void {
    // Kills line 97's IncrementInteger and line 98's DecrementInteger
    // (both swap which ideal_size element a given in_size element is
    // compared against) -- indistinguishable from the sibling test above
    // with its square 100x100 ideal_size, since swapping identical
    // values changes nothing observable. A non-square ideal_size is
    // required.
    $params = new DerivativeParams(SizingParams::classic(200, 100));

    // Fits ideal width (200) but exceeds ideal height (100) when read
    // straight; a width<->height swap on the FIRST comparison would
    // instead check in_size[0] against ideal_size[1] (100), wrongly
    // failing this on width alone.
    expect($params->is_identity([150, 50]))->toBeTrue();
    // Fits ideal height (100) but exceeds ideal width (200) when read
    // straight; a swap on the SECOND comparison would instead check
    // in_size[1] against ideal_size[0] (200), wrongly passing this.
    expect($params->is_identity([50, 150]))->toBeFalse();
});

test('will_watermark is always false when use_watermark is off', function (): void {
    $params = new DerivativeParams(SizingParams::classic(800, 600));
    $params->use_watermark = false;

    expect($params->will_watermark([600, 400]))->toBeFalse();
});

test('__serialize exposes last_mod_time, sizing, and sharpen -- not type or use_watermark', function (): void {
    $sizing = SizingParams::classic(100, 100);
    $params = new DerivativeParams($sizing);
    $params->last_mod_time = 1785118235;
    $params->sharpen = 0.5;
    $params->use_watermark = true;

    expect($params->__serialize())->toBe([
        'last_mod_time' => 1785118235,
        'sizing' => $sizing,
        'sharpen' => 0.5,
    ]);

    // Confirms it's the real PHP serialization hook, not just a
    // same-named public method: the config storage format this powers
    // (see Fixtures/piwigo-17.0.sql's own `derivatives` config row) is a
    // plain serialize() of a DerivativeParams tree, which only ever
    // reaches __serialize() through this exact mechanism.
    $serialized = serialize($params);
    expect($serialized)->toContain('s:13:"last_mod_time";i:1785118235;');
    expect($serialized)->toContain('s:7:"sharpen";d:0.5;');
    expect($serialized)->not->toContain('use_watermark');
});

test('will_watermark is true once the output is at least as large as the watermark\'s min_size on either dimension', function (): void {
    $originalWatermark = ImageStdParams::current()->get_watermark();

    try {
        $watermark = new WatermarkParams();
        $watermark->min_size = [500, 500];
        ImageStdParams::current()->set_watermark($watermark);

        $params = new DerivativeParams(SizingParams::classic(800, 600));
        $params->use_watermark = true;

        expect($params->will_watermark([600, 400]))->toBeTrue();
        expect($params->will_watermark([400, 400]))->toBeFalse();
    } finally {
        ImageStdParams::current()->set_watermark($originalWatermark);
    }
});

test('will_watermark compares each dimension independently, against exactly its own min_size element, not a swapped or off-by-one pair', function (): void {
    // Kills line 112's SmallerOrEqualToSmaller/IncrementInteger and line
    // 113's SmallerOrEqualToSmaller/DecrementInteger (x2) -- the sibling
    // test above uses a SQUARE min_size (500x500), so a width<->height
    // element swap on either comparison changes nothing observable. A
    // non-square min_size, plus boundary (`<=` vs `<`) values, is
    // required to distinguish all 5.
    $originalWatermark = ImageStdParams::current()->get_watermark();

    try {
        $watermark = new WatermarkParams();
        $watermark->min_size = [100, 300];
        ImageStdParams::current()->set_watermark($watermark);

        $params = new DerivativeParams(SizingParams::classic(800, 600));
        $params->use_watermark = true;

        // Exactly meets the WIDTH condition (100 <= 100) at the boundary;
        // fails the height condition outright -- kills the first
        // comparison's `<=`->`<` (would now read 100<100, false) and its
        // min_size[0]->min_size[1] swap (would compare 300<=100, also
        // false), either of which would wrongly flip this to false.
        expect($params->will_watermark([100, 50]))->toBeTrue();
        // Exactly meets the HEIGHT condition (300 <= 300) at the
        // boundary; fails the width condition outright -- kills the
        // second comparison's `<=`->`<` and its out_size[1]->out_size[0]
        // swap (would compare 300<=50, false), either of which would
        // wrongly flip this to false.
        expect($params->will_watermark([50, 300]))->toBeTrue();
        // Fails both conditions when read straight -- but the second
        // comparison's min_size[1]->min_size[0] swap would instead check
        // 100<=150 (true), wrongly flipping this to true.
        expect($params->will_watermark([50, 150]))->toBeFalse();
    } finally {
        ImageStdParams::current()->set_watermark($originalWatermark);
    }
});
