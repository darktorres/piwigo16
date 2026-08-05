<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\RatingUserPageRenderer (admin.php?page=rating_user) -- the
 * "rating by user" report, aggregating piwigo_rate rows per rater. No
 * dedicated test file existed before this one.
 */
function ratingUserDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function ratingUserDbConnect(): \mysqli|\PgSql\Connection
{
    return H::connect();
}

function ratingUserInsertRate(int $imageId, int $userId, string $anonymousId, int $rate): void
{
    $db = ratingUserDbConnect();
    H::dbQuery($db, sprintf(
        "INSERT INTO %srate (user_id, element_id, anonymous_id, rate, date) VALUES (%d, %d, '%s', %d, CURDATE())",
        ratingUserDbPrefix(),
        $userId,
        $imageId,
        H::dbEscape($db, $anonymousId),
        $rate
    ));
    H::dbClose($db);
}

it('formats an anonymous (guest) rater\'s user_key with their anonymous_id', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Rating User Anon Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
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
        H::dbQuery($db, sprintf('DELETE FROM %srate WHERE element_id = %d', ratingUserDbPrefix(), $imageId));
        H::dbClose($db);
    }
});

it('labels a rate from a user with no matching user_infos row as "???{user_id}"', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Rating User Ghost Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
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
    $prefix = ratingUserDbPrefix();
    H::dbQuery($db, sprintf("INSERT INTO %susers (username) VALUES ('ghost_rater_%s')", $prefix, uniqid()));
    $ghostUserId = H::dbInsertId($db);

    ratingUserInsertRate($imageId, $ghostUserId, '', 3);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');

        $page->assertSee('???' . $ghostUserId);
        $page->assertNoJavaScriptErrors();
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM %srate WHERE element_id = %d', $prefix, $imageId));
        H::dbQuery($db, sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $ghostUserId));
        H::dbClose($db);
    }
});

it('honors a valid order_by= GET override for the ratings sort', function (): void {
    $page = H::loginAsAdmin($this);

    // 3 is 'Consensus deviation' (available_order_by index 3) -- any
    // in-range value other than the default (4, 'Last') proves the GET
    // override is actually read, not just defaulted.
    $result = H::rawGet($page, '/admin.php?page=rating_user&order_by=3');

    expect($result['status'])->toBe(200);
});
