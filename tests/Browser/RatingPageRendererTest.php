<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function ratingPageInsertRate(int $imageId, int $userId, string $anonymousId, int $rate): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf("INSERT INTO rate (user_id, element_id, anonymous_id, rate, date) VALUES (%d, %d, '%s', %d, CURRENT_DATE)", $userId, $imageId, H::dbEscape($db, $anonymousId), $rate));
    H::dbClose($db);
}

function ratingPageDeleteRates(int $imageId): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf('DELETE FROM rate WHERE element_id = %d', $imageId));
    H::dbClose($db);
}

it('renders the rating report for a real rated photo, listing its rater', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating Page Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating Page Cat Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&users=user');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page users=user filter');
});

it('filters to guests only via users=guest', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&users=guest');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page users=guest filter');
});

it('clamps an out-of-range order_by index back to 0 instead of erroring', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&order_by=999');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page order_by clamp');
});

it('clamps a negative order_by index back to 0 instead of erroring', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=rating&order_by=-5');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'rating page negative order_by clamp');
});
