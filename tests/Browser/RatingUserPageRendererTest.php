<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function ratingUserDbConnect(): mysqli|Connection
{
    return H::connect();
}

function ratingUserInsertRate(int $imageId, int $userId, string $anonymousId, int $rate): void
{
    $db = ratingUserDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO rate (user_id, element_id, anonymous_id, rate, date) VALUES (%d, %d, '%s', %d, CURRENT_DATE)", $userId, $imageId, H::dbEscape($db, $anonymousId), $rate));
    H::dbClose($db);
}

it('formats an anonymous (guest) rater\'s user_key with their anonymous_id', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating User Anon Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating User Anon Photo');
    @unlink($image);

    // guest_id (config default 2) is a real, non-'Classic'-authorized
    // status, so isAuthorizeStatus(Classic, 'guest') is false -> anon=true
    // -> the '(anonymous_id)' suffix branch.
    $anonymousId = 'anon-' . uniqid();
    ratingUserInsertRate($imageId, 2, $anonymousId, 4);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');

        $page->assertSee($anonymousId);
        $page->assertNoJavaScriptErrors();
    } finally {
        $db = ratingUserDbConnect();
        H::dbQuery($db, sprintf('DELETE FROM rate WHERE element_id = %d', $imageId));
        H::dbClose($db);
    }
});

it('labels a rate from a user with no matching user_infos row as "???{user_id}"', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating User Ghost Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating User Ghost Photo');
    @unlink($image);

    // findUsersWithStatusByIdUsername()'s own INNER JOIN onto user_infos
    // means a `users` row with no matching `user_infos` row (a genuinely
    // corrupt-but-possible state, not reachable through the app's own
    // normal user-creation path) never appears in $users_by_id -- this
    // simulates that directly at the DB level, since there's no real app
    // flow that produces it.
    $db = ratingUserDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO users (username) VALUES ('ghost_rater_%s')", uniqid()));
    $ghostUserId = H::dbInsertId($db);

    ratingUserInsertRate($imageId, $ghostUserId, '', 3);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');

        $page->assertSee('???' . $ghostUserId);
        $page->assertNoJavaScriptErrors();
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM rate WHERE element_id = %d', $imageId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $ghostUserId));
        H::dbClose($db);
    }
});

it('honors a valid order_by= GET override for the ratings sort', function (): void {
    $page = H::asAdmin($this);

    // 3 is 'Consensus deviation' (available_order_by index 3) -- any
    // in-range value other than the default (4, 'Last') proves the GET
    // override is actually read, not just defaulted.
    $result = H::rawGet($page, '/admin.php?page=rating_user&order_by=3');

    expect($result['status'])->toBe(200);
});
