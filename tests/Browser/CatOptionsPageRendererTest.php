<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * CatOptionsPageRenderer (admin.php?page=cat_options, dispatched via
 * Controller/Admin/CatOptionsSubController.php) -- 0% coverage before this
 * file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): both albums (1 "Sample
 * Album", 2 "Nested Sub Album") start out 'public'. afterEach restores
 * category 2 to public even if an assertion above fails mid-test, matching
 * tests/Browser/DerivativePermissionTest.php's own established pattern for
 * this exact fixture mutation helper.
 */
afterEach(function (): void {
    H::setCategoryPrivate(2, private: false);
});

/**
 * Extracts the <option value="id">Name</option> pairs inside a
 * <select name="$selectName"> block from raw page HTML. These plain
 * (non-selectize) <select multiple> elements don't reliably report
 * per-<option> visibility to Playwright's own assertSeeIn() (native listbox
 * internals), so this parses the already-fetched raw HTML directly -- the
 * same technique BrowserTestHelpers::assertNoServerErrors() uses for its
 * own error-marker scan.
 *
 * @return array<int, string> option text keyed by its numeric value attribute
 */
function doubleSelectOptions(string $html, string $selectName): array
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

it('lists both public albums as authorized and none as forbidden for the status section', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_options&section=status');
    $page->assertNoJavaScriptErrors();

    $page->assertSeeIn('#content legend', 'Manage authorizations for selected albums');

    $html = H::rawWebpage($page)->content();
    // displaySelectCategories() renders the full breadcrumb (uppercats-joined),
    // not just the leaf name -- category 2's own uppercats is '1,2', so it
    // shows as "Sample Album / Nested Sub Album", not bare "Nested Sub Album".
    // Checks the specific fixture categories by key rather than the whole
    // list, since other Browser tests sharing this DB create their own
    // (public, uncleaned-up) albums -- a real, not hypothetical, source of
    // pollution when this file runs as part of the full suite, not in
    // isolation.
    $trueOptions = doubleSelectOptions($html, 'cat_true[]');
    $falseOptions = doubleSelectOptions($html, 'cat_false[]');
    expect($trueOptions[1] ?? null)->toBe('Sample Album')
        ->and($trueOptions[2] ?? null)->toBe('Sample Album / Nested Sub Album')
        ->and($falseOptions)
        ->not->toHaveKey(1)
        ->and($falseOptions)
        ->not->toHaveKey(2);
});

it('splits albums between the true/false selects once one of them is private', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_options&section=status');
    $page->assertNoJavaScriptErrors();

    $html = H::rawWebpage($page)->content();
    // A renderer that swapped which query feeds "true" vs "false" (or
    // filtered on the wrong status value) would put "Nested Sub Album" in
    // the wrong select -- this checks both sides, not just one. Checked by
    // key (see the test above) rather than whole-array equality, for the
    // same shared-DB-pollution reason.
    $trueOptions = doubleSelectOptions($html, 'cat_true[]');
    $falseOptions = doubleSelectOptions($html, 'cat_false[]');
    expect($trueOptions[1] ?? null)->toBe('Sample Album')
        ->and($trueOptions)
        ->not->toHaveKey(2)
        ->and($falseOptions[2] ?? null)->toBe('Sample Album / Nested Sub Album')
        ->and($falseOptions)
        ->not->toHaveKey(1);
});

// Edge case: CatOptionsRequest::fromArrays() falls back to 'status' for any
// $_GET['section'] outside its 4-value allowlist -- this locks in that
// fallback actually reaches the rendered page, not just the DTO in
// isolation (already covered separately by
// tests/Unit/Admin/Request/CatOptionsRequestTest.php).
it('renders the comments section with its own legend/labels', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_options&section=comments');
    $page->assertNoJavaScriptErrors();

    $page->assertSeeIn('#content legend', 'Authorize users to add comments on selected albums');
});

it('renders the visible section with its own legend/labels', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_options&section=visible');
    $page->assertNoJavaScriptErrors();

    $page->assertSeeIn('#content legend', 'Lock albums');
});

it('falsifies commentable for a chosen album via the falsify submission', function (): void {
    $page = H::asAdmin($this);

    try {
        $result = H::adminPost($page, '/admin.php?page=cat_options&section=comments', [
            'pwg_token' => H::pwgToken($page),
            'cat_true' => ['1'],
            'falsify' => '1',
        ]);

        expect($result['status'])->toBe(200);

        $db = H::connect();
        $assoc = H::dbFetchAssoc($db, 'SELECT commentable FROM categories WHERE id = 1');
        H::dbClose($db);
        expect(is_array($assoc) ? (H::dbToBool($assoc['commentable']) ? 1 : 0) : -1)->toBe(0);
    } finally {
        // Fixture category 1 starts commentable=1 -- restore it via the
        // real trueify submission (the sibling of falsify, distinct
        // branches, both real production code) rather than a raw UPDATE.
        H::adminPost($page, '/admin.php?page=cat_options&section=comments', [
            'pwg_token' => H::pwgToken($page),
            'cat_false' => ['1'],
            'trueify' => '1',
        ]);
    }
});

it('falls back to the status section for an unrecognized section value', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=cat_options&section=bogus');
    $page->assertNoJavaScriptErrors();

    $page->assertSeeIn('#content legend', 'Manage authorizations for selected albums');
});
