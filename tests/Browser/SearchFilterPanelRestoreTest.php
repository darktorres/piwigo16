<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// mcs.ts rebuilds the whole search-filter panel from `global_params` on
// every load of a saved search: which "search in" checkboxes are ticked,
// which custom date/ratio/rating/file-type boxes are ticked, which chips
// each filter's badge shows, and which panels carry `filter-filled`.
//
// None of that is visible to the snapshot instruments. The server renders
// every checkbox unticked and every badge showing its placeholder label;
// the ticks and the labels only exist after mcs.ts's own setup pass has
// run against the page-data island. Golden-html captures the pre-JS
// markup, and visual regression photographs the page with every filter
// panel collapsed.
//
// This is the interaction coverage for that setup pass. It is also what
// surfaced the empty file-type and rating option lists: both are asserted
// here as ticked checkboxes, which cannot be ticked if the server never
// rendered them.

/**
 * Builds a `search` row directly, the same raw-SQL shape
 * SearchFilterRendererExtraFiltersTest.php already uses.
 *
 * Direct insertion rather than POST /api/v1/images/searches: the endpoint
 * cannot express two of the field shapes this test needs to restore --
 * `expert` is dropped from the REST surface outright
 * (ImageFilteredSearchCreateController's own docblock), and `ratings` is
 * only persisted when `rate` is enabled at *creation* time. The row still
 * goes through SearchRules::fromArray()/toArray() on read, so what reaches
 * the browser is the normalized shape either way.
 *
 * @param array<string, mixed> $rules
 */
function filterPanelRestoreInsertSearchRow(array $rules): string
{
    $db = H::connect();

    $uuid = 'psk-' . date('Ymd') . '-' . substr(md5(uniqid('', true)), 0, 10);
    $rulesJson = json_encode($rules, JSON_THROW_ON_ERROR);

    H::dbQuery($db, sprintf("INSERT INTO search (rules, created_on, created_by, search_uuid, forked_from) VALUES ('%s', NOW(), NULL, '%s', NULL)", H::dbEscape($db, $rulesJson), H::dbEscape($db, $uuid)));
    H::dbClose($db);

    return $uuid;
}

/**
 * mcs.ts's setup runs as one synchronous `$(document).ready()` block, so
 * any point inside it having been reached means all of it has. The
 * spinner is hidden partway through, which makes it the cheapest signal
 * that the block ran at all -- and it is set by mcs.ts alone, never by the
 * server-rendered markup.
 */
function filterPanelRestoreWaitForSetup(Webpage|PendingAwaitablePage|AwaitableWebpage $page): void
{
    $page->script(<<<'JS'
    new Promise((resolve, reject) => {
        const deadline = Date.now() + 10000;
        const check = () => {
            const spinner = document.querySelector('.filter-spinner');
            if (spinner !== null && getComputedStyle(spinner).display === 'none') {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for mcs.ts to run its filter-panel setup'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);
}

/**
 * One round-trip, three typed maps: `flags` is every boolean the setup
 * pass is responsible for (a ticked box, a `filter-filled` badge, a
 * `selected` custom date), `texts` every badge label it writes, and
 * `chips` the album breadcrumbs it appends.
 *
 * A missing element is an error rather than a `false`: every selector
 * below is server-rendered markup that must exist before mcs.ts can do
 * anything to it, so "not found" and "found but not ticked" are different
 * failures and only one of them is this test's subject.
 *
 * @return array{flags: array<string, bool>, texts: array<string, string>, chips: int}
 */
function filterPanelRestoreProbe(Webpage|PendingAwaitablePage|AwaitableWebpage $page, int $year): array
{
    /** @var string $json */
    $json = $page->script(<<<JS
    (() => {
        const el = (sel) => {
            const found = document.querySelector(sel);
            if (found === null) {
                throw new Error('filter-panel probe: no element matched ' + sel);
            }
            return found;
        };
        return JSON.stringify({
            flags: {
                word_name: el('#name').checked,
                word_file: el('#file').checked,
                word_tags: el('#tags').checked,
                word_comment: el('#comment').checked,
                word_mode_or: el(".word-search-options input[value='OR']").checked,
                date_year: el('#date_posted_{$year}').checked,
                date_year_selected: el('#date_posted_{$year}').classList.contains('selected'),
                date_custom_radio: el('#date_posted-custom').checked,
                date_filled: el('.filter-date_posted').classList.contains('filter-filled'),
                ratio_square: el('#ratio-square').checked,
                rating_no_rate: el('#rating-0').checked,
                rating_3: el('#rating-3').checked,
                filetype_jpg: el('#filetype-jpg').checked,
                expert_filled: el('.filter-expert').classList.contains('filter-filled'),
                album_subs: el('#search-sub-cats').checked,
                album_filled: el('.filter-album').classList.contains('filter-filled'),
                filesize_filled: el('.filter-filesize').classList.contains('filter-filled'),
                height_filled: el('.filter-height').classList.contains('filter-filled'),
                width_filled: el('.filter-width').classList.contains('filter-filled'),
            },
            texts: {
                word_input: el('#word-search').value,
                word_words: el('.filter-word .search-words').textContent.trim(),
                date_words: el('.filter.filter-date_posted .search-words').textContent.trim(),
                ratio_words: el('.filter.filter-ratios .search-words').textContent.trim(),
                rating_words: el('.filter.filter-ratings .search-words').textContent.trim(),
                filetype_words: el('.filter.filter-filetypes .search-words').textContent.trim(),
                expert_input: el('#expert-search').value,
                expert_words: el('.filter.filter-expert .search-words').textContent.trim(),
                album_chip_text: el('.selected-categories-container .link-path').textContent.trim(),
            },
            chips: document.querySelectorAll('.selected-categories-container .link-path').length,
        });
    })()
    JS);

    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || ! is_array($decoded['flags'] ?? null) || ! is_array($decoded['texts'] ?? null) || ! is_int($decoded['chips'] ?? null)) {
        throw new RuntimeException('filter-panel probe did not return the expected shape: ' . $json);
    }

    $flags = [];
    foreach ($decoded['flags'] as $key => $value) {
        if (! is_string($key) || ! is_bool($value)) {
            throw new RuntimeException('filter-panel probe returned a non-boolean flag: ' . $json);
        }
        $flags[$key] = $value;
    }

    $texts = [];
    foreach ($decoded['texts'] as $key => $value) {
        if (! is_string($key) || ! is_string($value)) {
            throw new RuntimeException('filter-panel probe returned a non-string text: ' . $json);
        }
        $texts[$key] = $value;
    }

    return [
        'flags' => $flags,
        'texts' => $texts,
        'chips' => $decoded['chips'],
    ];
}

it('restores every filter panel client-side from a saved search rules payload', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'rate']);

    $filtersViews = [];
    foreach ([
        'words', 'tags', 'post_date', 'creation_date', 'album', 'author',
        'added_by', 'file_type', 'ratio', 'rating', 'file_size', 'height',
        'width', 'expert',
    ] as $filterName) {
        $filtersViews[$filterName] = [
            'access' => 'everybody',
            'default' => true,
        ];
    }
    H::setConfigValue('filters_views', json_encode($filtersViews, JSON_THROW_ON_ERROR));
    // The ratings panel is the one filter mcs.ts gates on a second,
    // separate page-data flag (`show_filter_ratings`), which
    // SearchFiltersView carries straight from this config value -- an
    // ambient-disabled `rate` silently skips the whole ratings restore
    // branch, assertions and all.
    H::setConfigValue('rate', 'true');

    try {
        // Every per-filter row/counter list this test asserts on is served
        // through the shared search-results pool, which outlives a fixture
        // reimport -- a stale entry from an earlier run renders the
        // file-type and rating option lists empty. Same throwaway-instance
        // clear SearchFilterRendererDataFiltersTest.php's own tests use
        // (pool identity carries no correctness risk; the namespace is
        // what identifies the backing store).
        new SearchResultsCachePool(CacheFactory::create(namespace: 'piwigo.search_results', defaultLifetime: 30))
            ->clear();

        $page = H::asAdmin($this);

        $albumName = 'Filter Panel Restore Album ' . uniqid();
        $album = H::createCategory($page, [
            'name' => $albumName,
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];

        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Filter Panel Restore Photo');
        @unlink($image);

        // ratio 300/300 = 1.0 -> the "square" bucket; rating_score 2.5 ->
        // the r=3 bucket. Both are set directly: there is no API setter
        // for either column (the same established fact
        // SearchFilterRendererDataFiltersTest.php's own helpers document).
        $db = H::connect();
        H::dbQuery($db, sprintf('UPDATE images SET width = 300, height = 300, rating_score = 2.5, filesize = 1500 WHERE id = %d', $imageId));
        H::dbClose($db);

        // The custom date entries are year-prefixed (`y2026`), and the
        // matching checkbox's id drops that one-character prefix
        // (`#date_posted_2026`) -- the exact pairing the setup pass has to
        // reproduce. The photo was uploaded a moment ago, so the current
        // year is in the rendered year list.
        $year = (int) date('Y');

        $uuid = filterPanelRestoreInsertSearchRow([
            'fields' => [
                'allwords' => [
                    'words' => ['restore'],
                    'mode' => 'OR',
                    'fields' => ['name', 'file', 'tags'],
                ],
                'expert' => [
                    'string' => 'Restore',
                ],
                'filetypes' => ['jpg'],
                'cat' => [
                    'words' => [$albumId],
                    'sub_inc' => true,
                ],
                'date_posted' => [
                    'preset' => 'custom',
                    'custom' => ['y' . $year],
                ],
                'ratios' => ['square'],
                'ratings' => ['0', '3'],
                'filesize_min' => 100,
                'filesize_max' => 4000,
                'width_min' => 50,
                'width_max' => 1000,
                'height_min' => 50,
                'height_max' => 1000,
            ],
        ]);

        $page = H::navigateOk($page, '/index.php?/search/' . $uuid);
        H::assertNoServerErrors($page, 'search filter panel restore');
        $page->assertNoJavaScriptErrors();

        filterPanelRestoreWaitForSetup($page);
        $probe = filterPanelRestoreProbe($page, $year);

        // Asserted as whole maps rather than field by field: a broken
        // setup pass usually breaks several filters at once, and a
        // per-field chain would stop at the first one and hide the rest.
        expect($probe['flags'])->toBe([
            // `allwords.fields` drives the "Search in :" checkboxes. The
            // three named fields tick; `comment`, which the payload leaves
            // out, must not.
            'word_name' => true,
            'word_file' => true,
            'word_tags' => true,
            'word_comment' => false,
            'word_mode_or' => true,
            // The custom-date entry: `y2026` ticks `#date_posted_2026` and
            // marks it selected.
            'date_year' => true,
            'date_year_selected' => true,
            'date_custom_radio' => true,
            'date_filled' => true,
            'ratio_square' => true,
            'rating_no_rate' => true,
            'rating_3' => true,
            'filetype_jpg' => true,
            'expert_filled' => true,
            'album_subs' => true,
            'album_filled' => true,
            // The three numeric-range panels fill on their own bounds,
            // which arrive as `int|string` and were being compared against
            // 0 through JS coercion.
            'filesize_filled' => true,
            'height_filled' => true,
            'width_filled' => true,
        ]);

        expect($probe['texts'])->toBe([
            'word_input' => 'restore',
            'word_words' => 'restore',
            // That year row's own label, not the "Added on" placeholder.
            'date_words' => 'year ' . $year,
            'ratio_words' => 'Square',
            // `ratings` entries are strings: "0" is the "no rate" bucket
            // and "3" renders through the two-number template, both after
            // the arithmetic that string entries only survive by coercion.
            'rating_words' => 'no rate, between 2 and 3',
            'filetype_words' => 'JPG',
            'expert_input' => 'Restore',
            'expert_words' => 'Restore',
            'album_chip_text' => $albumName,
        ]);

        expect($probe['chips'])->toBe(1);
    } finally {
        H::restoreConfig($snapshot);
    }
});
