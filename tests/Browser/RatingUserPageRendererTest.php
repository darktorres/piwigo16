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

// The "consensus deviation (top)" column, whose value used to disappear
// exactly when it was 0. `cdTop` is `?float` -- null when none of the
// user's rated elements is in the top-rated set -- and the template
// guarded it with `!empty()`, which swallows a real 0.0 alongside the
// null. 0.0 is not a corner case: `$dev = abs($rate - $average)` is
// exactly 0 whenever the user is the sole rater of the element, so any
// gallery where one person rated the top photos shows an empty cell
// meaning "perfect agreement" and an empty cell meaning "nothing to
// average" side by side, with no way to tell them apart. Fixed in P58-B2
// (`!== null`), which is what moved power_user's row in
// admin-rating-user-rows.html from a blank cell to 0.000.
//
// Driven through a real rating rather than the fixture's own, so the
// value is derived here and not merely re-read from the snapshot.
it('renders a zero consensus-deviation-top as 0.000 rather than an empty cell', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating User Zero CdTop Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating User Zero CdTop Photo');
    @unlink($image);

    // A single rater on a single element: the element's average IS this
    // rate, so $dev is 0.0 for it, and with the element in the top-rated
    // set cdTop averages to exactly 0.0.
    $soleRaterId = 'anon-cdtop-' . uniqid();
    ratingUserInsertRate($imageId, 2, $soleRaterId, 5);

    try {
        // consensus_top_number is a real GET parameter, and it has to be
        // set here rather than left at the config default (10): the
        // top-rated set is `ORDER BY rating_score DESC LIMIT n` over EVERY
        // image, so with a default-sized window this photo competes for a
        // slot against whatever the rest of the suite has uploaded and
        // rated, and losing that race makes cdTop null -- blank for the
        // right reason, which would let the test pass while proving
        // nothing. A window wider than the fixture can fill puts every
        // rated image in the set, so this row's cdTop is 0.0 by
        // construction.
        $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0&consensus_top_number=1000');
        $html = H::rawWebpage($page)->content();

        // The anonymous rater's cell is "<username>(<anonymous id>)", not
        // the bare id -- same shape the anonymous-suffix test above asserts.
        if (preg_match('#<td class="usr">[^<]*' . preg_quote($soleRaterId, '#') . '[^<]*</td>(.*?)</tr>#s', $html, $m) !== 1) {
            throw new RuntimeException('no row rendered for the sole rater');
        }
        $cells = [];
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $m[1], $cellMatches);
        $cells = $cellMatches[1];

        // Columns after the username: last-date, count, avg, cv, cd, cdTop.
        // cdTop is index 5 and must carry a formatted zero, not ''.
        expect($cells[5])->toBe('0.000');
        $page->assertNoJavaScriptErrors();
    } finally {
        $db = ratingUserDbConnect();
        H::dbQuery($db, sprintf('DELETE FROM rate WHERE element_id = %d', $imageId));
        H::dbClose($db);
    }
});

/**
 * P49-A conversion of themes/admin/default/js/rating_user.ts's own delete
 * flow -- 0% JS coverage before this: every other test in this file only
 * asserts the rendered page. This drives the full round trip: the
 * delegated .del click (reading the username via `this.closest("tr")`),
 * the still-jQuery jquery-confirm dialog it opens, its confirm button
 * (which reads `event.target` from the ORIGINAL click, not `this`, since
 * the action callback runs later against the dialog's own click), the
 * real ajax delete, and the dataTables row removal
 * (`oTable.row(tr).remove().draw()`).
 *
 * `.dataTable()`/`.DataTable()` and `.confirm()` stay jQuery (P49-B groups
 * 7 and 5) -- only the DOM work around them converted.
 */
it('deletes a user\'s ratings via the trash icon and its confirm dialog', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Rating User Delete Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rating User Delete Photo');
    @unlink($image);

    $anonymousId = 'del-' . uniqid();
    ratingUserInsertRate($imageId, 2, $anonymousId, 4);

    try {
        $page = H::navigateOk($page, '/admin.php?page=rating_user&f_min_rates=0');
        $page->assertSee($anonymousId);

        $page->click('tr:has-text("' . $anonymousId . '") a.del');

        $page->assertPresent('.jconfirm');
        // Scoped to .jconfirm itself, not the whole page -- the row behind
        // the dialog still shows the anonymous id regardless of whether
        // the dialog's own title interpolated it correctly, so a
        // page-wide assertSee()/assertSeeSettled() would pass even if the
        // title were built from the wrong (or no) username.
        $dialogText = $page->script(
            "document.querySelector('.jconfirm').textContent"
        );
        expect($dialogText)
            ->toContain($anonymousId);

        $page->click('.jconfirm button.btn-red');

        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (!document.body.textContent.includes('{$anonymousId}')) {
                        return resolve(true);
                    }
                    if (Date.now() > deadline) {
                        return reject(new Error('row for {$anonymousId} was never removed'));
                    }
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'rating_user delete via trash icon');

        $remaining = ratingUserDbConnect();
        $count = H::fetchAssocOrFail($remaining, sprintf(
            "SELECT COUNT(*) AS c FROM rate WHERE element_id = %d AND anonymous_id = '%s'",
            $imageId,
            H::dbEscape($remaining, $anonymousId)
        ));
        H::dbClose($remaining);
        expect((int) $count['c'])->toBe(0);
    } finally {
        $db = ratingUserDbConnect();
        H::dbQuery($db, sprintf('DELETE FROM rate WHERE element_id = %d', $imageId));
        H::dbClose($db);
    }
});
