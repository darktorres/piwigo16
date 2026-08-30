<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function pictureCoiValue(int $imageId): ?string
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT coi FROM images WHERE id = %d', $imageId));
    H::dbClose($db);

    return is_array($row) && is_string($row['coi']) ? $row['coi'] : null;
}

it('renders the coi editor for a photo with no center of interest set yet', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi Photo');
    @unlink($image);

    expect(pictureCoiValue($imageId))
        ->toBeNull();

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture_coi no-coi-yet render');
});

it('submits a new center of interest, persists it, and invalidates derivative-URL-style config', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi Submit Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi Submit Photo');
    @unlink($image);

    $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
        'submit' => '1',
        'l' => '0.1',
        't' => '0.2',
        'r' => '0.8',
        'b' => '0.9',
    ]);

    expect($result['status'])->toBe(200);
    expect(pictureCoiValue($imageId))
        ->not->toBeNull();
    expect(pictureCoiValue($imageId))
        ->toHaveLength(4);
});

it('carries a real representative_ext into the deleted derivative_infos when set', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi RepExt Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi RepExt Photo');
    @unlink($image);

    $db = H::connect();
    // representative_ext is non-empty for a video/pdf/etc. upload (a
    // representative image with a different extension than the original
    // file) -- set directly here rather than via a real video/pdf upload,
    // which needs ffmpeg/imagemagick binaries this test env may not have.
    H::dbQuery($db, sprintf("UPDATE images SET representative_ext = 'jpg' WHERE id = %d", $imageId));

    try {
        $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
            'submit' => '1',
            'l' => '0.05',
            't' => '0.15',
            'r' => '0.75',
            'b' => '0.85',
        ]);

        expect($result['status'])->toBe(200);
        expect(pictureCoiValue($imageId))
            ->not->toBeNull();
    } finally {
        H::dbClose($db);
    }
});

it('resets a "questionmark" derivative_url_style (1) back to "auto" (0) for this render only', function (): void {
    // CurrentConfig::setDerivativeUrlStyle() (what render()'s reset calls)
    // only ever writes the in-process static -- there is no
    // confUpdateParam('derivative_url_style', ...) call anywhere in this
    // codebase, so the DB row is never actually touched (confirmed live:
    // the config table's value is still '1' after a real POST here). The
    // real, externally-observable effect is that THIS SAME response's
    // U_IMG now renders through the "auto" i.php? derivative route
    // instead of a plain static link -- assert that instead of a DB value
    // that was never meant to change.
    $snapshot = H::snapshotConfig(['derivative_url_style']);
    H::setConfigValue('derivative_url_style', '1');

    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi UrlStyle Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi UrlStyle Photo');
    @unlink($image);

    try {
        $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
            'submit' => '1',
            'l' => '0.1',
            't' => '0.2',
            'r' => '0.8',
            'b' => '0.9',
        ]);

        expect($result['status'])->toBe(200);
        // A not-yet-cached derivative under "always static link" (1) would
        // render a plain upload/... path with no i.php involved; seeing it
        // routed through i.php? here proves the in-request reset to "auto"
        // (0) took effect for this render.
        expect($result['body'])->toContain('i.php?/upload/');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders a real 404 "Page not found" response for a nonexistent image_id', function (): void {
    $page = H::asAdmin($this);

    // pageNotFound() -> RedirectService::redirectHtml() throws a real
    // ResponseReadyException with the given status code baked into the
    // response (a meta-refresh HTML body, NOT a Location header) -- a
    // genuine 404, not an opaque fetch(manual) redirect like this
    // renderer's own successful-submission paths elsewhere in this suite.
    $result = H::rawGet($page, '/admin.php?page=picture_coi&image_id=999999999');

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('Page not found');
});

/**
 * picture_coi.ts's own coordinate maths, converted off jQuery in P49-A.
 *
 * The round trip is the assertion. With a COI already stored, Jcrop's init
 * callback calls animateTo() with pixel coordinates from_coi() derives
 * from the image's width/height, Jcrop reports the resulting selection
 * back through onChange(), and to_coi() divides by the same measurements
 * to refill #l/#t/#r/#b. Read those inputs and you should get the stored
 * fractions back.
 *
 * Read the inputs too early and you get a different answer every run:
 * animateTo() ANIMATES and onChange fires on every frame, so they are
 * populated long before they are final. Hence the settle wait below --
 * without it, sampling returned l=0.065 on one run and l=0.040 on the
 * next.
 *
 * The settled values ARE pinned, because everything feeding them is
 * fixed: H::makeTestImage() defaults to 200x150 (only its label varies),
 * and picture_coi.ts asks Jcrop for a 500x400 box. The stored 0.1/0.2/
 * 0.8/0.9 comes back as 0.12/0.2/0.8/0.92 -- Jcrop rounds the selection
 * to whole pixels, which on a 200px axis is 0.005 per pixel. Change
 * either the image size or the box and these move; that is a real signal,
 * not noise, and the diff will say which.
 *
 * What the invariants DO catch is the failure this conversion could
 * actually cause, and it is not a small numeric error. jQuery's
 * .width()/.height() are the content box; the obvious native swap,
 * offsetWidth/offsetHeight, reports 0 here, because by the time these
 * callbacks run Jcrop has replaced #jcrop with its own wrapper and the
 * original element is no longer laid out. from_coi() then divides by
 * zero and every coordinate comes back NaN -- checked, and the non-finite
 * assertion below is what names it. That is the failure mode
 * docs/PLAN.md already records for this campaign ("a hidden element has
 * no box"); jQuery's width() forces such an element into layout to
 * measure it, and the helper reproduces that.
 */
it('round-trips a stored center of interest back into the coordinate inputs', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Coi Roundtrip Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi Roundtrip Photo');
    @unlink($image);

    $stored = ['l' => 0.1, 't' => 0.2, 'r' => 0.8, 'b' => 0.9];
    $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
        'submit' => '1',
        'l' => (string) $stored['l'],
        't' => (string) $stored['t'],
        'r' => (string) $stored['r'],
        'b' => (string) $stored['b'],
    ]);
    expect($result['status'])->toBe(200);

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);

    // animateTo() ANIMATES, and onChange fires on every frame of it, so the
    // inputs are populated long before they are correct. Waiting only for
    // the first non-empty value samples mid-flight and returns a different
    // number on every run. Poll until two consecutive reads agree.
    $readAll = static fn (): array => [
        'l' => $page->script('document.querySelector("#l") ? document.querySelector("#l").value : ""'),
        't' => $page->script('document.querySelector("#t") ? document.querySelector("#t").value : ""'),
        'r' => $page->script('document.querySelector("#r") ? document.querySelector("#r").value : ""'),
        'b' => $page->script('document.querySelector("#b") ? document.querySelector("#b").value : ""'),
    ];

    // Three consecutive agreeing reads, not two: an animation can hold the
    // same frame across a single 100ms sample and look settled when it is
    // not.
    $settled = null;
    $agreements = 0;
    $previous = null;
    for ($attempt = 0; $attempt < 80; $attempt++) {
        $current = $readAll();
        if ($current['l'] !== '' && $current === $previous) {
            $agreements++;
            if ($agreements >= 2) {
                $settled = $current;
                break;
            }
        } else {
            $agreements = 0;
        }
        $previous = $current;
        usleep(100_000);
    }
    expect($settled)->not->toBeNull('the coordinate inputs never settled');

    $read = array_map(static fn (string $v): float => (float) $v, $settled);

    foreach ($read as $field => $actual) {
        // The invariant that actually discriminates. A wrong measurement
        // does not produce a slightly-off fraction here, it produces NaN,
        // because Jcrop has already replaced #jcrop with a wrapper and the
        // naive offsetWidth of the original is 0.
        expect(is_numeric($actual) && is_finite((float) $actual))
            ->toBeTrue("#{$field} came back non-finite ({$actual}) -- the image was measured while it had no box");
        expect((float) $actual)->toBeGreaterThanOrEqual(0.0);
        expect((float) $actual)->toBeLessThanOrEqual(1.0);
    }

    // ...and the rectangle survives as a rectangle.
    expect((float) $read['l'])->toBeLessThan((float) $read['r']);
    expect((float) $read['t'])->toBeLessThan((float) $read['b']);

    // The exact settled round trip, off a 200x150 image in a 500x400 box.
    expect($read)->toBe([
        'l' => 0.12,
        't' => 0.2,
        'r' => 0.8,
        'b' => 0.92,
    ]);

    $page->assertNoJavaScriptErrors();
});
