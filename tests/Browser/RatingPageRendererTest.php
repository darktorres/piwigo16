<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\RatingPageRenderer (admin.php?page=rating) -- already
 * GET-tested by AdminExtendedSmokeTest against an empty rate table; this
 * file adds real piwigo_rate rows so the actual report loop (~60 lines),
 * cat_id/user/guest filters, and order_by clamping all execute.
 *
 * Not exercised: the "? {user_id}" unknown-rater fallback (only reached
 * when a rate row's user_id has no matching users row) -- piwigo_rate's
 * own fk_rate_user_id FOREIGN KEY (ON DELETE CASCADE) guarantees every
 * real rate row's user_id always resolves to a real user (deleting the
 * user cascades to delete their rates too), so this can't actually happen
 * against this schema.
 */
function ratingPageDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function ratingPageInsertRate(int $imageId, int $userId, string $anonymousId, int $rate): void
{
    $db = H::connect();
    $prefix = ratingPageDbPrefix();
    H::dbQuery($db, sprintf(
        "INSERT INTO %srate (user_id, element_id, anonymous_id, rate, date) VALUES (%d, %d, '%s', %d, CURDATE())",
        $prefix,
        $userId,
        $imageId,
        H::dbEscape($db, $anonymousId),
        $rate
    ));
    H::dbClose($db);
}

function ratingPageDeleteRates(int $imageId): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf('DELETE FROM %srate WHERE element_id = %d', ratingPageDbPrefix(), $imageId));
    H::dbClose($db);
}

it('renders the rating report for a real rated photo, listing its rater', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Rating Page Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating Page Photo');
    @unlink($image);

    ratingPageInsertRate($imageId, 1, '', 5);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating');

        $page->assertSee('fixture_admin');
        $page->assertNoJavaScriptErrors();
    } finally {
        ratingPageDeleteRates($imageId);
    }
});

it('scopes the report to a specific album via cat=', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Rating Page Cat Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating Page Cat Photo');
    @unlink($image);

    ratingPageInsertRate($imageId, 1, '', 4);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating&cat=' . $albumId);

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'rating page cat= filter');
    } finally {
        ratingPageDeleteRates($imageId);
    }
});

it('filters to registered users only via users=user', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&users=user');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page users=user filter');
});

it('filters to guests only via users=guest', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&users=guest');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page users=guest filter');
});

it('clamps an out-of-range order_by index back to 0 instead of erroring', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&order_by=999');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page order_by clamp');
});

it('clamps a negative order_by index back to 0 instead of erroring', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&order_by=-5');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page negative order_by clamp');
});