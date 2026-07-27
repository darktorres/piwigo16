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

    $html = H::rawWebpage($page)->content();
    expect(groupPermSelectOptions($html, 'cat_true[]'))->toBe([2 => 'Sample Album / Nested Sub Album']);
    expect(groupPermSelectOptions($html, 'cat_false[]'))->toBe([]);
});

it('lists the same private album as still-forbidden for a group without access to it', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=group_perm&group_id=3');
    $page->assertNoJavaScriptErrors();

    $page->assertSee('Manage permissions for the group "Guests"');

    $html = H::rawWebpage($page)->content();
    expect(groupPermSelectOptions($html, 'cat_true[]'))->toBe([]);
    expect(groupPermSelectOptions($html, 'cat_false[]'))->toBe([2 => 'Sample Album / Nested Sub Album']);
});

// Edge/error condition: group_id is a required GET param with no default --
// GroupPermPageRenderer::render() calls fatalError() (HTTP 500, rendered
// inline rather than a silent redirect) when it's missing.
it('shows a fatal error when group_id is missing', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=group_perm');

    $page->assertSee('group_id URL parameter is missing');
});
