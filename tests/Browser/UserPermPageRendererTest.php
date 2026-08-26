<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * UserPermPageRenderer (admin.php?page=user_perm&user_id=X, dispatched via
 * Controller/Admin/UserPermSubController.php) -- 0% coverage before this
 * file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): user_group puts
 * user 4 "power_user" in group 3 "Guests" only, and group_access
 * grants group 3 access to category 1 "Sample Album" only (not category 2)
 * -- so user 4's "authorized thanks to group" list always shows "Sample
 * Album" regardless of any per-category private/public mutation, while the
 * private-albums double-select only starts showing content once a category
 * is actually marked private.
 *
 * Not exercised: the `$category['uppercats'] === null || ! is_string(...)`
 * defensive `continue` inside the group-authorized-categories loop.
 * findCategoriesAuthorizedViaGroupsForUser()'s own query always selects
 * `c.uppercats` from a real `categories` row, a genuine `NOT NULL
 * DEFAULT ''` column (tests/Fixtures/piwigo-17.0.sql's own CREATE TABLE),
 * so a real row's value is always a string -- the "power_user" test below
 * already drives this exact loop with real data (category 1) without ever
 * tripping it.
 */
afterEach(function (): void {
    H::setCategoryPrivate(2, private: false);
});

/**
 * @return array<int, string> option text keyed by its numeric value attribute
 */
function userPermSelectOptions(string $html, string $selectName): array
{
    $pattern = '/<select[^>]*name="' . preg_quote($selectName, '/') . '"[^>]*>(.*?)<\/select>/s';
    if (preg_match($pattern, $html, $matches) !== 1) {
        throw new RuntimeException("Could not find <select name=\"{$selectName}\"> in page HTML");
    }

    preg_match_all('/<option value="(\d+)"[^>]*>([^<]*)<\/option>/', $matches[1], $optionMatches, PREG_SET_ORDER);

    $options = [];
    foreach ($optionMatches as $optionMatch) {
        $options[(int) $optionMatch[1]] = trim($optionMatch[2]);
    }

    return $options;
}

// Edge/error condition: user_id is a required GET param with no default --
// UserPermPageRenderer::render() calls fatalError() (HTTP 500, rendered
// inline) when it's missing.
it('shows a fatal error when user_id is missing', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm');

    $page->assertSee('user_id URL parameter is missing');
});

// Edge/null branch: a user with no group memberships at all (guest, user_id
// 2) never populates $group_rows, so the "authorized thanks to groups"
// fieldset is never rendered (isset($categories_because_of_groups) stays
// false) -- a genuinely different branch from user 4's own case below.
it('shows no group-based album access for a user with no group memberships', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm&user_id=2');
    $page->assertNoJavaScriptErrors();

    $page->assertSee('Manage permissions for the user "guest"');
    $page->assertDontSee('Albums authorized thanks to group associations');
});

it('shows the album granted through group membership, with no private albums yet', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm&user_id=4');
    $page->assertNoJavaScriptErrors();

    // Not assertSeeIn('#content h2', ...): admin.latte's own shared footer
    // script copies the page's <h2> into its <h1> too, so a plain
    // page-wide assertSee() (this string is specific enough not to appear
    // anywhere else) avoids relying on this renderer's own <h2> being the
    // only element bearing this text.
    $page->assertSee('Manage permissions for the user "power_user"');
    // "Albums authorized thanks to group associations" fieldset -- only
    // rendered when $group_rows is non-empty (see the null-branch test
    // above for a user with none).
    $page->assertSee('Albums authorized thanks to group associations');
    $page->assertSee('Sample Album');

    // Not toBe([]) for cat_false: the double-select lists *every* private
    // album in the whole gallery, a shared, ever-growing resource across
    // this whole Browser suite run -- other test files' own leftover
    // private albums (never category 2, which this test doesn't touch)
    // can genuinely populate it, so only category 2's own absence is this
    // test's real claim.
    $html = H::rawWebpage($page)->content();
    expect(userPermSelectOptions($html, 'cat_true[]'))
        ->not->toHaveKey(2);
    expect(userPermSelectOptions($html, 'cat_false[]'))
        ->not->toHaveKey(2);
});

it('lists the newly-private album as forbidden once it stops being covered by the group grant', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm&user_id=4');
    $page->assertNoJavaScriptErrors();

    $html = H::rawWebpage($page)->content();
    // user 4's only group (3 "Guests") has no group_access row for category
    // 2, so once it's private it must show up as forbidden, not authorized.
    // Not toBe([...]) for cat_false: see the sibling test above for why an
    // exact array can't be asserted against this shared, ever-growing list.
    expect(userPermSelectOptions($html, 'cat_true[]'))
        ->not->toHaveKey(2);
    expect(userPermSelectOptions($html, 'cat_false[]'))
        ->toHaveKey(2, 'Sample Album / Nested Sub Album');
});

it('trueifies then falsifies direct user_access for a private album', function (): void {
    H::setCategoryPrivate(2, private: true);
    $page = H::asAdmin($this);
    $db = H::connect();

    try {
        // user_access has no rows at all in this fixture (see this
        // file's own docblock) -- trueify grants user 4 direct access to
        // category 2 via PermissionService::addPermissionOnCategory().
        $trueifyResult = H::adminPost($page, '/admin.php?page=user_perm&user_id=4', [
            'pwg_token' => H::pwgToken($page),
            'cat_false' => ['2'],
            'trueify' => '1',
        ]);
        expect($trueifyResult['status'])->toBe(200);

        $assoc = H::dbFetchAssoc($db, 'SELECT COUNT(*) AS c FROM user_access WHERE user_id = 4 AND cat_id = 2');
        expect(is_array($assoc) ? (int) $assoc['c'] : -1)->toBe(1);

        // falsify then removes it again via removeUserAccess().
        $falsifyResult = H::adminPost($page, '/admin.php?page=user_perm&user_id=4', [
            'pwg_token' => H::pwgToken($page),
            'cat_true' => ['2'],
            'falsify' => '1',
        ]);
        expect($falsifyResult['status'])->toBe(200);

        $assoc2 = H::dbFetchAssoc($db, 'SELECT COUNT(*) AS c FROM user_access WHERE user_id = 4 AND cat_id = 2');
        expect(is_array($assoc2) ? (int) $assoc2['c'] : -1)->toBe(0);
    } finally {
        H::dbQuery($db, 'DELETE FROM user_access WHERE user_id = 4 AND cat_id = 2');
        H::dbClose($db);
    }
});
