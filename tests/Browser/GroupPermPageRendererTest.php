<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * GroupPermPageRenderer (admin.php?page=group_perm&group_id=X, dispatched
 * via Controller/Admin/GroupPermSubController.php) -- 0% coverage before
 * this file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): piwigo_group_access grants
 * group 1 "Editors" access to BOTH category 1 "Sample Album" and category 2
 * "Nested Sub Album", but group 3 "Guests" access to category 1 only -- so
 * once category 2 is marked private, group 1's own permission page must
 * list it as already-authorized while group 3's own page must list the
 * exact same album as still-forbidden. Asserting both sides for both
 * groups catches a renderer that queried the wrong group's group_access
 * rows (a real transposition bug shape).
 */
afterEach(function (): void {
    H::setCategoryPrivate(2, private: false);
});

/**
 * @return array<int, string> option text keyed by its numeric value attribute
 */
function groupPermSelectOptions(string $html, string $selectName): array
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

it('lists the private album as already-authorized for the group that has access to it', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=group_perm&group_id=1');
    $page->assertNoJavaScriptErrors();

    // Not assertSeeIn('#content h2', ...): admin.tpl's own shared footer
    // script (`if (jQuery('h2').length > 0) { jQuery('h1').html(jQuery('h2').html()); }`)
    // copies this exact h2 into the h1 too, so a plain page-wide assertSee()
    // (already proven unambiguous -- the string is specific enough not to
    // appear anywhere else) avoids relying on this renderer's own <h2> being
    // the only element bearing this text.
    $page->assertSee('Manage permissions for the group "Editors"');

    // Not toBe([]) for cat_false: the double-select lists *every* private
    // album in the whole gallery, a shared, ever-growing resource across
    // this whole Browser suite run -- other test files' own leftover
    // private albums (never category 2, which this test doesn't touch)
    // can genuinely populate it, so only category 2's own classification
    // is this test's real claim (confirmed live: with 10+ accumulated
    // private albums from earlier tests, exact-array equality broke here
    // even though category 2 itself behaved correctly).
    $html = H::rawWebpage($page)->content();
    expect(groupPermSelectOptions($html, 'cat_true[]'))->toHaveKey(2, 'Sample Album / Nested Sub Album');
    expect(groupPermSelectOptions($html, 'cat_false[]'))->not->toHaveKey(2);
});

it('lists the same private album as still-forbidden for a group without access to it', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=group_perm&group_id=3');
    $page->assertNoJavaScriptErrors();

    $page->assertSee('Manage permissions for the group "Guests"');

    // See the sibling test above for why an exact array can't be asserted
    // against this shared, ever-growing list.
    $html = H::rawWebpage($page)->content();
    expect(groupPermSelectOptions($html, 'cat_true[]'))->not->toHaveKey(2);
    expect(groupPermSelectOptions($html, 'cat_false[]'))->toHaveKey(2, 'Sample Album / Nested Sub Album');
});

it('falsifies (revokes) a private album\'s access for a group that currently has it', function (): void {
    H::setCategoryPrivate(2, private: true);
    $page = H::loginAsAdmin($this);

    try {
        // Group 1 ("Editors") has real group_access to category 2 in the
        // fixture -- falsify with cat_true=[2] revokes it via
        // GroupService::removeAccess().
        $result = H::adminPost($page, '/admin.php?page=group_perm&group_id=1', [
            'pwg_token' => H::pwgToken($page),
            'cat_true' => ['2'],
            'falsify' => '1',
        ]);
        expect($result['status'])->toBe(200);

        $db = new mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $row = $db->query(sprintf('SELECT COUNT(*) AS c FROM %sgroup_access WHERE group_id = 1 AND cat_id = 2', $prefix));
        $assoc = $row instanceof mysqli_result ? $row->fetch_assoc() : null;
        expect(is_array($assoc) ? (int) $assoc['c'] : -1)->toBe(0);
        $db->close();
    } finally {
        // Restore the fixture's own group 1 <-> category 2 group_access row
        // via the real trueify submission (this class's own sibling
        // branch), not a raw INSERT, so other Browser tests sharing this
        // fixture (e.g. this file's own earlier tests) see it again.
        H::adminPost($page, '/admin.php?page=group_perm&group_id=1', [
            'pwg_token' => H::pwgToken($page),
            'cat_false' => ['2'],
            'trueify' => '1',
        ]);
        H::setCategoryPrivate(2, private: false);
    }
});

it('trueifies (grants) a private album\'s access for a group that currently lacks it', function (): void {
    H::setCategoryPrivate(2, private: true);
    $page = H::loginAsAdmin($this);

    try {
        // Group 3 ("Guests") has no group_access to category 2 in the
        // fixture -- trueify with cat_false=[2] grants it via
        // GroupService::addAccess().
        $result = H::adminPost($page, '/admin.php?page=group_perm&group_id=3', [
            'pwg_token' => H::pwgToken($page),
            'cat_false' => ['2'],
            'trueify' => '1',
        ]);
        expect($result['status'])->toBe(200);

        $db = new mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $prefix = getenv('PIWIGO_DB_PREFIX');
        $prefix = $prefix !== false ? $prefix : 'piwigo_';
        $row = $db->query(sprintf('SELECT COUNT(*) AS c FROM %sgroup_access WHERE group_id = 3 AND cat_id = 2', $prefix));
        $assoc = $row instanceof mysqli_result ? $row->fetch_assoc() : null;
        expect(is_array($assoc) ? (int) $assoc['c'] : -1)->toBe(1);

        // Restore fixture state (group 3 had no access to category 2).
        $db->query('DELETE FROM ' . $prefix . 'group_access WHERE group_id = 3 AND cat_id = 2');
        $db->close();
    } finally {
        H::setCategoryPrivate(2, private: false);
    }
});

// Edge/error condition: group_id is a required GET param with no default --
// GroupPermPageRenderer::render() calls fatalError() (HTTP 500, rendered
// inline rather than a silent redirect) when it's missing.
it('shows a fatal error when group_id is missing', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=group_perm');

    $page->assertSee('group_id URL parameter is missing');
});
