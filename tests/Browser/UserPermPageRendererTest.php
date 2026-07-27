<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * UserPermPageRenderer (admin.php?page=user_perm&user_id=X, dispatched via
 * Controller/Admin/UserPermSubController.php) -- 0% coverage before this
 * file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): piwigo_user_group puts
 * user 4 "power_user" in group 3 "Guests" only, and piwigo_group_access
 * grants group 3 access to category 1 "Sample Album" only (not category 2)
 * -- so user 4's "authorized thanks to group" list always shows "Sample
 * Album" regardless of any per-category private/public mutation, while the
 * private-albums double-select only starts showing content once a category
 * is actually marked private.
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
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm');

    $page->assertSee('user_id URL parameter is missing');
});

// Edge/null branch: a user with no group memberships at all (guest, user_id
// 2) never populates $group_rows, so the "authorized thanks to groups"
// fieldset is never rendered (isset($categories_because_of_groups) stays
// false) -- a genuinely different branch from user 4's own case below.
it('shows no group-based album access for a user with no group memberships', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm&user_id=2');
    $page->assertNoJavaScriptErrors();

    $page->assertSee('Manage permissions for the user "guest"');
    $page->assertDontSee('Albums authorized thanks to group associations');
});

it('shows the album granted through group membership, with no private albums yet', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm&user_id=4');
    $page->assertNoJavaScriptErrors();

    // Not assertSeeIn('#content h2', ...): admin.tpl's own shared footer
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

    $html = H::rawWebpage($page)->content();
    expect(userPermSelectOptions($html, 'cat_true[]'))->toBe([]);
    expect(userPermSelectOptions($html, 'cat_false[]'))->toBe([]);
});

it('lists the newly-private album as forbidden once it stops being covered by the group grant', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_perm&user_id=4');
    $page->assertNoJavaScriptErrors();

    $html = H::rawWebpage($page)->content();
    // user 4's only group (3 "Guests") has no group_access row for category
    // 2, so once it's private it must show up as forbidden, not authorized.
    expect(userPermSelectOptions($html, 'cat_true[]'))->toBe([]);
    expect(userPermSelectOptions($html, 'cat_false[]'))->toBe([2 => 'Sample Album / Nested Sub Album']);
});
