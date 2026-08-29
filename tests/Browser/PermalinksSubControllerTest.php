<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\PermalinksSubController (admin.php?page=
 * permalinks) -- category-permalink CRUD (set/clear/permanently-delete via
 * Piwigo\Permalink\PermalinkService) plus the GET-driven sort-link builder
 * shared by its "active permalinks" and "permalink history" tables.
 *
 * Every mutating test uses the real fixture's category id 2 ("Nested Sub
 * Album", uppercats "1,2") with a uniquely-suffixed permalink value
 * (uniqid()) -- `categories.permalink` carries a UNIQUE KEY and
 * `old_permalinks.permalink` is that table's own PRIMARY KEY, so a fixed
 * literal could collide with a concurrently-running test/suite -- and
 * restores both tables to their pre-test state via try/finally regardless
 * of where an assertion fails, since `categories`/`old_permalinks` are
 * shared, global state across the whole Browser suite run (same rationale
 * as BrowserTestHelpers::snapshotConfig()'s own docblock).
 */
function permalinksDb(): mysqli|Connection
{
    return H::connect();
}

/**
 * Reads a single category's current `permalink` column, or null if unset
 * (or the row doesn't exist). `is_array($row) && is_string($row['permalink']
 * ?? null)` mirrors BrowserTestHelpers::configValue()'s own narrowing --
 * H::dbFetchAssoc() already returns `array<string, mixed>|null`, but the
 * column itself could still be a real SQL NULL for an unset permalink, so
 * the value narrowing stays even though the query-level null narrowing is
 * now handled inside dbFetchAssoc() itself.
 */
function permalinksCategoryPermalink(mysqli|Connection $db, int $catId): ?string
{
    $row = H::dbFetchAssoc($db, sprintf('SELECT permalink FROM categories WHERE id = %d', $catId));

    $permalink = is_array($row) ? $row['permalink'] ?? null : null;

    return is_string($permalink) ? $permalink : null;
}

/**
 * Reads the `cat_id` of a single `old_permalinks` row by its `permalink`
 * (that table's own PRIMARY KEY), or null if no such row exists -- same
 * narrowing rationale as permalinksCategoryPermalink() above.
 */
function permalinksOldPermalinkCatId(mysqli|Connection $db, string $permalink): ?int
{
    $row = H::dbFetchAssoc($db, sprintf("SELECT cat_id FROM old_permalinks WHERE permalink = '%s'", H::dbEscape($db, $permalink)));

    return is_array($row) && is_numeric($row['cat_id'] ?? null) ? (int) $row['cat_id'] : null;
}

it('rejects a set_permalink submission without a valid CSRF token', function (): void {
    $page = H::asAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=permalinks', [
        'cat_id' => '2',
        'set_permalink' => '1',
        'permalink' => 'should-not-be-set',
    ]);

    expect($result['status'])->toBe(400);
    expect($result['body'])->toContain('missing token');

    $db = permalinksDb();
    $permalinkValue = permalinksCategoryPermalink($db, 2);
    H::dbClose($db);
    expect($permalinkValue)
        ->toBeNull();
});

it('rejects a delete_permanent request without a valid CSRF token', function (): void {
    $page = H::asAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=permalinks&delete_permanent=does-not-matter');

    expect($result['status'])->toBe(400);
    expect($result['body'])->toContain('missing token');
});

it('sets a category permalink, lists it among active permalinks, clears it into history, then permanently deletes the history entry', function (): void {
    $page = H::asAdmin($this);
    $token = H::pwgToken($page);
    $catId = 2;
    $permalink = 'permalinks-subctrl-' . uniqid();
    $db = permalinksDb();

    try {
        // set_permalink + a non-empty permalink value -> the
        // PermalinkService::setCatPermalink() branch.
        $setResult = H::adminPost($page, '/admin.php?page=permalinks', [
            'pwg_token' => $token,
            'cat_id' => (string) $catId,
            'set_permalink' => '1',
            'permalink' => $permalink,
        ]);
        expect($setResult['status'])->toBe(200);
        // The very same response re-lists active permalinks *after* the
        // mutation -- this string only appears once the just-set permalink
        // round-trips through the "active permalinks" foreach (the
        // uppercats-narrowing + getCatDisplayNameCache() lines).
        expect($setResult['body'])->toContain($permalink);

        // The album picker comes back with the just-edited album already
        // chosen. `$selected_cat` carries the posted cat_id, and
        // permalinks.latte compares it against `(string) $optKey` with a
        // strict in_array -- so an int would silently select nothing.
        // n:attr renders the bare HTML5 attribute, not selected="selected".
        expect($setResult['body'])->toContain(sprintf('value="%d" selected', $catId));

        expect(permalinksCategoryPermalink($db, $catId))
            ->toBe($permalink);

        // set_permalink + an empty permalink value + save=1 -> the
        // PermalinkService::deleteCatPermalink() branch. Since a real
        // permalink now exists for this category and save=1, it also
        // records the old value into old_permalinks (needed by the next
        // step below).
        $clearResult = H::adminPost($page, '/admin.php?page=permalinks', [
            'pwg_token' => $token,
            'cat_id' => (string) $catId,
            'set_permalink' => '1',
            'permalink' => '',
            'save' => '1',
        ]);
        expect($clearResult['status'])->toBe(200);

        expect(permalinksCategoryPermalink($db, $catId))
            ->toBeNull();

        expect(permalinksOldPermalinkCatId($db, $permalink))
            ->toBe($catId);

        // delete_permanent (GET, CSRF-gated) -> PermalinkService::
        // deleteOldPermalinkByValue(), permanently removing the history row.
        $deleteResult = H::rawGet(
            $page,
            '/admin.php?page=permalinks&delete_permanent=' . urlencode($permalink) . '&pwg_token=' . $token
        );
        expect($deleteResult['status'])->toBe(200);

        expect(permalinksOldPermalinkCatId($db, $permalink))
            ->toBeNull();
    } finally {
        H::dbQuery($db, sprintf('UPDATE categories SET permalink = NULL WHERE id = %d', $catId));
        H::dbQuery($db, sprintf("DELETE FROM old_permalinks WHERE permalink = '%s'", H::dbEscape($db, $permalink)));
        H::dbClose($db);
    }
});

it('fatal-errors on an unexpected URL GET key while building the sort links', function (): void {
    $page = H::asAdmin($this);

    // Any GET key other than page/psf/dpsf/pwg_token/delete_permanent is
    // rejected by parseSortVariables()'s own allowlist -- confirmed live
    // this is the deliberate "an attacker-controlled unknown key is on the
    // querystring" guard, not a typo, since `page` (present on every real
    // navigation) is itself allow-listed.
    $result = H::rawGet($page, '/admin.php?page=permalinks&unexpected_key=1');

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('unexpected URL get key');
});

it('marks the active-permalinks sort column as already-selected when psf matches it', function (): void {
    $page = H::asAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=permalinks&psf=id');

    expect($result['status'])->toBe(200);
    // parseSortVariables() builds its base URL from $_SERVER['REQUEST_URI']
    // (the app-root path, e.g. "/piwigo17", plus "/admin.php") -- not
    // hardcoded, since it depends on where this environment's app root is
    // mounted (see .env.test's PIWIGO_BASE_URL).
    $appRootPath = (string) parse_url(H::baseUrl(), PHP_URL_PATH);
    // Matched field ('id' === psf): parseSortVariables() skips its own
    // addUrlParams() call (so the link's href stays the bare base URL,
    // with no "psf=id" appended) and wraps the sort arrow in <em> to mark
    // it as the currently-active column.
    expect($result['body'])->toContain(
        '<a href="' . $appRootPath . '/admin.php?page=permalinks" title="Sort order"><em>' . "\u{2193}" . '</em></a>'
    );
    // Contrast: an unmatched field ('permalink') still gets a real
    // switch-to-this-column link, proving the two fields took different
    // branches for the very same request.
    expect($result['body'])->toContain('psf=permalink');
});
