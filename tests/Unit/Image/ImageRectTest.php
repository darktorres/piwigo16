<?php

declare(strict_types=1);

use Piwigo\Image\ImageRect;

/**
 * Piwigo\Image\ImageRect -- the center-of-interest crop math used by
 * derivative generation. Had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1). Every
 * expected value below was independently confirmed by invoking the real
 * class before writing the assertion -- the COI-adjusted crop branches
 * are not something to hand-trace and trust blindly.
 */
test('constructing from [width, height] sets l/t to 0 and r/b to the given size', function (): void {
    $rect = new ImageRect([200, 100]);

    expect($rect->l)->toBe(0);
    expect($rect->t)->toBe(0);
    expect($rect->r)->toBe(200);
    expect($rect->b)->toBe(100);
    expect($rect->width())->toBe(200.0);
    expect($rect->height())->toBe(100.0);
});

test('crop_h with no coi splits the crop evenly between left and right', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->crop_h(50, null);

    expect($rect->l)->toBe(25.0);
    expect($rect->r)->toBe(175.0);
    expect($rect->width())->toBe(150.0);
});

test('crop_h is a no-op when the requested crop is not smaller than the current width', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->crop_h(250, null);

    expect($rect->l)->toBe(0);
    expect($rect->r)->toBe(200);
});

test('crop_h with a coi spanning the whole rectangle behaves the same as no coi', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->crop_h(50, 'aaza');

    expect($rect->l)->toBe(25.0);
    expect($rect->r)->toBe(175.0);
});

test('crop_h leans the crop toward the side with less room when the coi is off-center (tight on the right)', function (): void {
    $rect = new ImageRect([200, 100]);
    // left=n (0.52*200=104), right=y (0.96*200=192) -- only 8px of room to
    // the right of the coi, less than the default 25px half-crop, so the
    // right side effectively can't be cropped as much as usual.
    $rect->crop_h(50, 'n_y_');

    expect($rect->l)->toBe(42.0);
    expect($rect->r)->toBe(192.0);
});

test('crop_h leans the crop toward the side with less room when the coi is off-center (tight on the left)', function (): void {
    $rect = new ImageRect([200, 100]);
    // left=c (0.08*200=16), right=n (0.52*200=104) -- only 16px of room to
    // the left of the coi.
    $rect->crop_h(50, 'c_n_');

    expect($rect->l)->toBe(16.0);
    expect($rect->r)->toBe(166.0);
});

test('crop_v with no coi splits the crop evenly between top and bottom', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->crop_v(20, null);

    expect($rect->t)->toBe(10.0);
    expect($rect->b)->toBe(90.0);
    expect($rect->height())->toBe(80.0);
});

test('crop_v is a no-op when the requested crop is not smaller than the current height', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->crop_v(150, null);

    expect($rect->t)->toBe(0);
    expect($rect->b)->toBe(100);
});

test('crop_v leans the crop toward the side with less room when the coi is off-center (tight on the top)', function (): void {
    $rect = new ImageRect([200, 100]);
    // top=c (0.08*100=8), bottom=n (0.52*100=52) -- only 8px of room above the coi.
    $rect->crop_v(20, '_c_n');

    expect($rect->t)->toBe(8.0);
    expect($rect->b)->toBe(88.0);
});

test('crop_v leans the crop toward the side with less room when the coi is off-center (tight on the bottom)', function (): void {
    $rect = new ImageRect([200, 100]);
    // top=n (0.52*100=52), bottom=y (0.96*100=96) -- only 4px of room below the coi.
    $rect->crop_v(20, '_n_y');

    expect($rect->t)->toBe(16.0);
    expect($rect->b)->toBe(96.0);
});

test('crop_h and crop_v tolerate an empty-string coi the same as null', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->crop_h(50, '');
    $rect->crop_v(20, '');

    expect($rect->l)->toBe(25.0);
    expect($rect->r)->toBe(175.0);
    expect($rect->t)->toBe(10.0);
    expect($rect->b)->toBe(90.0);
});
