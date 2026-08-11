<?php

declare(strict_types=1);

use Piwigo\Image\ImageRect;

/**
 * Piwigo\Image\ImageRect -- the center-of-interest crop math used by
 * derivative generation. Had zero dedicated coverage. Every
 * expected value below was independently confirmed by invoking the real
 * class before writing the assertion -- the COI-adjusted crop branches
 * are not something to hand-trace and trust blindly.
 */
test('constructing from [width, height] sets l/t to 0 and r/b to the given size', function (): void {
    $rect = new ImageRect([200, 100]);

    expect($rect->l)
        ->toBe(0);
    expect($rect->t)
        ->toBe(0);
    expect($rect->r)
        ->toBe(200);
    expect($rect->b)
        ->toBe(100);
    expect($rect->width())
        ->toBe(200.0);
    expect($rect->height())
        ->toBe(100.0);
});

test('cropH with no coi splits the crop evenly between left and right', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->cropH(50, null);

    expect($rect->l)
        ->toBe(25.0);
    expect($rect->r)
        ->toBe(175.0);
    expect($rect->width())
        ->toBe(150.0);
});

test('cropH is a no-op when the requested crop is not smaller than the current width', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->cropH(250, null);

    expect($rect->l)
        ->toBe(0);
    expect($rect->r)
        ->toBe(200);
});

test('cropH with a coi spanning the whole rectangle behaves the same as no coi', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->cropH(50, 'aaza');

    expect($rect->l)
        ->toBe(25.0);
    expect($rect->r)
        ->toBe(175.0);
});

test('cropH leans the crop toward the side with less room when the coi is off-center (tight on the right)', function (): void {
    $rect = new ImageRect([200, 100]);
    // left=n (0.52*200=104), right=y (0.96*200=192) -- only 8px of room to
    // the right of the coi, less than the default 25px half-crop, so the
    // right side effectively can't be cropped as much as usual.
    $rect->cropH(50, 'n_y_');

    expect($rect->l)
        ->toBe(42.0);
    expect($rect->r)
        ->toBe(192.0);
});

test('cropH leans the crop toward the side with less room when the coi is off-center (tight on the left)', function (): void {
    $rect = new ImageRect([200, 100]);
    // left=c (0.08*200=16), right=n (0.52*200=104) -- only 16px of room to
    // the left of the coi.
    $rect->cropH(50, 'c_n_');

    expect($rect->l)
        ->toBe(16.0);
    expect($rect->r)
        ->toBe(166.0);
});

test('cropV with no coi splits the crop evenly between top and bottom', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->cropV(20, null);

    expect($rect->t)
        ->toBe(10.0);
    expect($rect->b)
        ->toBe(90.0);
    expect($rect->height())
        ->toBe(80.0);
});

test('cropV is a no-op when the requested crop is not smaller than the current height', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->cropV(150, null);

    expect($rect->t)
        ->toBe(0);
    expect($rect->b)
        ->toBe(100);
});

test('cropV leans the crop toward the side with less room when the coi is off-center (tight on the top)', function (): void {
    $rect = new ImageRect([200, 100]);
    // top=c (0.08*100=8), bottom=n (0.52*100=52) -- only 8px of room above the coi.
    $rect->cropV(20, '_c_n');

    expect($rect->t)
        ->toBe(8.0);
    expect($rect->b)
        ->toBe(88.0);
});

test('cropV leans the crop toward the side with less room when the coi is off-center (tight on the bottom)', function (): void {
    $rect = new ImageRect([200, 100]);
    // top=n (0.52*100=52), bottom=y (0.96*100=96) -- only 4px of room below the coi.
    $rect->cropV(20, '_n_y');

    expect($rect->t)
        ->toBe(16.0);
    expect($rect->b)
        ->toBe(96.0);
});

test('cropH is a no-op when the requested crop exactly equals the current width, not just when larger', function (): void {
    // Kills line 68's SmallerOrEqualToSmaller (`< $pixels` instead of
    // `<=`) -- the sibling no-op test above uses pixels=250, comfortably
    // past the width=200 boundary either way.
    $rect = new ImageRect([200, 100]);
    $rect->cropH(200, null);

    expect($rect->l)
        ->toBe(0);
    expect($rect->r)
        ->toBe(200);
});

test('cropV is a no-op when the requested crop exactly equals the current height, not just when larger', function (): void {
    // Kills line 98's SmallerOrEqualToSmaller.
    $rect = new ImageRect([200, 100]);
    $rect->cropV(100, null);

    expect($rect->t)
        ->toBe(0);
    expect($rect->b)
        ->toBe(100);
});

/**
 * Every scenario below was independently confirmed via a real,
 * hand-instrumented trace of cropH()'s exact arithmetic (coil/coir/
 * availableL/availableR/tlcrop, at every intermediate step) before
 * writing the assertion -- these boundary cases are precision-
 * sensitive by design and not something to hand-trace and trust
 * blindly, same discipline as this file's own top docblock states for
 * the simpler cases above.
 */
test('cropH uses the exact floor()-truncated coi position, not a rounded or ceiling one', function (): void {
    // Kills line 74's FloorToRound/FloorToCiel -- every sibling coi
    // test above happens to land on whole-number coi positions (a
    // fraction * width with no remainder), where floor()/round()/
    // ceil() all agree. width=97 (not a multiple of 25) with coi 'b'
    // (fraction 0.04) gives a genuinely fractional intermediate
    // (97*0.04=3.88): floor=3, but round/ceil=4.
    $rect = new ImageRect([97, 100]);
    $rect->cropH(10, 'b_n_');

    expect($rect->l)
        ->toBe(3.0);
    expect($rect->r)
        ->toBe(90.0);
});

test('cropV uses the exact floor()-truncated coi position, not a rounded or ceiling one', function (): void {
    // Kills line 101's FloorToRound/FloorToCiel.
    $rect = new ImageRect([100, 97]);
    $rect->cropV(10, '_b_n');

    expect($rect->t)
        ->toBe(3.0);
    expect($rect->b)
        ->toBe(90.0);
});

test('cropH uses the exact ceil()-rounded-up coi position, not a floored or rounded one', function (): void {
    // Kills line 75's CeilToFloor/CeilToRound. width=97 with coi 'y'
    // (fraction 0.96) gives 97*0.96=93.12: ceil=94, but floor/round=93.
    $rect = new ImageRect([97, 100]);
    $rect->cropH(8, 'n_y_');

    expect($rect->l)
        ->toBe(5.0);
    expect($rect->r)
        ->toBe(94.0);
});

test('cropV uses the exact ceil()-rounded-up coi position, not a floored or rounded one', function (): void {
    // Kills line 104's CeilToFloor/CeilToRound.
    $rect = new ImageRect([100, 97]);
    $rect->cropV(8, '_n_y');

    expect($rect->t)
        ->toBe(5.0);
    expect($rect->b)
        ->toBe(94.0);
});

test('cropH computes availableL by SUBTRACTING the current left edge from the coi position, not adding it', function (): void {
    // Kills line 76's MinusToPlus (`$coil + (float) $this->l` instead
    // of `-`). Every sibling test either has a fresh rect (left edge
    // still 0, where +/- coincide) or hits the ternary's other branch
    // entirely -- a pre-existing (simulated prior crop) left offset of
    // 1.0 that's still strictly less than coil (3, so the true branch
    // still applies) is required for the two operators to actually
    // disagree.
    $rect = new ImageRect([97, 100]);
    $rect->l = 1.0;
    $rect->cropH(10, 'b_n_');

    expect($rect->l)
        ->toBe(3.0);
    expect($rect->r)
        ->toBe(89.0);
});

test('cropV computes availableT by SUBTRACTING the current top edge from the coi position, not adding it', function (): void {
    // Kills line 106's MinusToPlus.
    $rect = new ImageRect([100, 97]);
    $rect->t = 1.0;
    $rect->cropV(10, '_b_n');

    expect($rect->t)
        ->toBe(3.0);
    expect($rect->b)
        ->toBe(89.0);
});

test('cropH defaults availableL to exactly 0.0, not a placeholder, when the coi is not to the right of the current left edge', function (): void {
    // Kills line 76's DecrementFloat/IncrementFloat (the ternary's
    // `: 0.0` false-branch literal) -- every sibling test's coi always
    // sits to the right of the current left edge, never reaching this
    // branch. A pre-existing (simulated prior crop) left offset of
    // 5.0, with a coi[0] resolving to a position at or left of it (coi
    // 'a' => fraction 0, position 0), forces it.
    $rect = new ImageRect([97, 100]);
    $rect->l = 5.0;
    $rect->cropH(30, 'a_n_');

    expect($rect->l)
        ->toBe(5.0);
    expect($rect->r)
        ->toBe(67.0);
});

test('cropV defaults availableT to exactly 0.0, not a placeholder, when the coi is not below the current top edge', function (): void {
    // Kills line 106's DecrementFloat/IncrementFloat.
    $rect = new ImageRect([100, 97]);
    $rect->t = 5.0;
    $rect->cropV(30, '_a_n');

    expect($rect->t)
        ->toBe(5.0);
    expect($rect->b)
        ->toBe(67.0);
});

test('cropH defaults availableR to exactly 0.0, not a placeholder, when the coi is not to the left of the current right edge', function (): void {
    // Kills line 77's DecrementFloat/IncrementFloat -- coi[2] 'z'
    // (fraction 1.0) resolves coir to exactly $this->r, so it is never
    // "to the left" of the right edge.
    $rect = new ImageRect([97, 100]);
    $rect->cropH(2, 'b_z_');

    expect($rect->l)
        ->toBe(2.0);
    expect($rect->r)
        ->toBe(97.0);
});

test('cropV defaults availableB to exactly 0.0, not a placeholder, when the coi is not above the current bottom edge', function (): void {
    // Kills line 107's DecrementFloat/IncrementFloat -- coi[3] 'z'
    // (fraction 1.0) resolves coib to exactly $this->b, so it is never
    // "above" the bottom edge.
    $rect = new ImageRect([100, 97]);
    $rect->cropV(2, '_b_z');

    expect($rect->t)
        ->toBe(2.0);
    expect($rect->b)
        ->toBe(97.0);
});

test('cropH only applies the coi-adjusted crop once the available room genuinely reaches the requested pixel count, not one short', function (): void {
    // Kills line 78's GreaterOrEqualToGreater (`> $pixels` instead of
    // `>=`) -- pinned at the exact boundary (availableL + availableR
    // === pixels === 46), where availableL(0) is small enough to force
    // a real, observable tlcrop adjustment if (and only if) the block
    // is actually entered.
    $rect = new ImageRect([97, 100]);
    $rect->cropH(46, 'a_n_');

    expect($rect->l)
        ->toBe(0.0);
    expect($rect->r)
        ->toBe(51.0);
});

test('cropV only applies the coi-adjusted crop once the available room genuinely reaches the requested pixel count, not one short', function (): void {
    // Kills line 108's GreaterOrEqualToGreater.
    $rect = new ImageRect([100, 97]);
    $rect->cropV(46, '_a_n');

    expect($rect->t)
        ->toBe(0.0);
    expect($rect->b)
        ->toBe(51.0);
});

test('cropH only widens the crop toward the tight side when it genuinely has LESS room than an even split, not exactly as much', function (): void {
    // Kills line 81's SmallerToSmallerOrEqual (`<= $tlcrop` instead of
    // `< $tlcrop`). Pinned so availableR === the current (unadjusted)
    // tlcrop exactly (3 === floor(7/2)), with an ODD pixel count so a
    // wrongly-triggered adjustment (`$pixels - $availableR`) computes
    // a genuinely different value (7-3=4) than leaving tlcrop
    // unchanged (3) -- an even pixel count can't expose this, since
    // floor(pixels/2) and pixels-floor(pixels/2) coincide there.
    $rect = new ImageRect([97, 100]);
    $rect->cropH(7, 'n_y_');

    expect($rect->l)
        ->toBe(3.0);
    expect($rect->r)
        ->toBe(93.0);
});

test('cropV only widens the crop toward the tight side when it genuinely has LESS room than an even split, not exactly as much', function (): void {
    // Kills line 111's SmallerToSmallerOrEqual.
    $rect = new ImageRect([100, 97]);
    $rect->cropV(7, '_n_y');

    expect($rect->t)
        ->toBe(3.0);
    expect($rect->b)
        ->toBe(93.0);
});

/**
 * Confirmed-equivalent: line 76's GreaterToGreaterOrEqual (`$coil >=
 * $this->l` instead of `>`), line 77's SmallerToSmallerOrEqual (`$coir
 * <= $this->r` instead of `<`), line 79's SmallerToSmallerOrEqual
 * (`$availableL <= $tlcrop` instead of `<`), and the mirrored cropV
 * equivalents at lines 106/107/109. At the EXACT boundary each
 * compares (coil === l, coir === r, availableL === the current
 * tlcrop), the ternary's own "true" branch computes a value
 * mathematically identical to its "false" branch (coil - l = 0 = the
 * literal 0.0 fallback; r - coir = 0 = the same fallback; tlcrop =
 * availableL = tlcrop, a no-op reassignment) -- entering the branch at
 * that exact point changes nothing observable, for every possible
 * input at that boundary, not just one tested case. Live sed-verified
 * a representative one of each against the full suite too.
 *
 * Also confirmed-equivalent: every RemoveDoubleCast mutation in this
 * file (lines 52, 57, 74, 75, 76, 77, 82, 86, 87, 104, 105, 106, 107,
 * 112, 116, 117 -- ~24 mutation IDs total, one or two per line). Each
 * one removes exactly ONE (float) cast from a two-operand arithmetic
 * expression where the OTHER operand is either still explicitly cast,
 * or is itself always float already (floor()/ceil() results, or a
 * running total built exclusively from float arithmetic) -- PHP
 * promotes the ENTIRE expression to float the moment either operand is
 * float, regardless of which side carries the explicit cast, so the
 * numeric result is identical either way. True for every possible
 * input, not just a tested case; live sed-verified a representative
 * sample (lines 52 x2, 74 x2, 76, 77, 81, 87 x2) against the full
 * suite too.
 */
test('cropH and cropV tolerate an empty-string coi the same as null', function (): void {
    $rect = new ImageRect([200, 100]);
    $rect->cropH(50, '');
    $rect->cropV(20, '');

    expect($rect->l)
        ->toBe(25.0);
    expect($rect->r)
        ->toBe(175.0);
    expect($rect->t)
        ->toBe(10.0);
    expect($rect->b)
        ->toBe(90.0);
});
