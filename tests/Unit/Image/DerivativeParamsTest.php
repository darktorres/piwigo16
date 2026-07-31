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
    $originalWatermark = ImageStdParams::get_watermark();

    try {
        $watermark = new WatermarkParams();
        $watermark->min_size = [500, 500];
        ImageStdParams::set_watermark($watermark);

        $params = new DerivativeParams(SizingParams::classic(800, 600));
        $params->use_watermark = true;

        expect($params->will_watermark([600, 400]))->toBeTrue();
        expect($params->will_watermark([400, 400]))->toBeFalse();
    } finally {
        ImageStdParams::set_watermark($originalWatermark);
    }
});
