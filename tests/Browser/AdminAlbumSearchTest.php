<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// The album search on admin.php?page=albums is entirely client-side: it
// filters the album tree already embedded in the page and builds each result
// row by cloning a hidden template. Nothing about it reaches the server, so
// golden-html sees the empty result container and the untouched template,
// and visual regression photographs the page before anyone has typed. Every
// behaviour below exists only after a keystroke.

it('filters the album tree as you type and restores it when cleared', function (): void {
    $db = H::connect();

    try {
        // Take a real album from the fixture rather than hard-coding a name,
        // so this follows the fixture instead of pinning it.
        $album = H::dbFetchAssoc(
            $db,
            'SELECT id, name FROM categories ORDER BY id LIMIT 1'
        );
        expect($album)
            ->not->toBeNull('the fixture has no albums to search for');
        $albumId = (int) ($album['id'] ?? 0);
        $albumName = (string) ($album['name'] ?? '');
    } finally {
        H::dbClose($db);
    }

    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=albums');

    // Before typing, the tree is what is on screen.
    $page->assertMissing('.album-search-result-container');

    $page->fill('#cat_search_input', $albumName);

    $page->assertVisible('.album-search-result-container');
    $page->assertMissing('.tree');

    /** @var array{rows: int, href: string, icon: string, name: string, count: string} $result */
    $result = $page->script(<<<JS
    (() => {
        const rows = document.querySelectorAll('.search-album-result .search-album-elem');
        const mine = Array.from(rows).find(
            row => (row.querySelector('.search-album-edit') || {}).getAttribute
                && row.querySelector('.search-album-edit').getAttribute('href') === 'admin.php?page=album-{$albumId}'
        );

        return {
            rows: rows.length,
            href: mine ? mine.querySelector('.search-album-edit').getAttribute('href') : '',
            icon: mine ? mine.querySelector('.search-album-icon').className : '',
            name: mine ? mine.querySelector('.search-album-name').textContent.trim() : '',
            count: (document.querySelector('.search-album-num-result') || {}).textContent || '',
        };
    })()
    JS);

    // A row was built from the template for this album, with its edit link
    // pointing at the album's own admin page.
    expect($result['rows'])->toBeGreaterThan(0);
    expect($result['href'])->toBe("admin.php?page=album-{$albumId}");
    expect($result['name'])->toContain($albumName);

    // The icon carries two classes: the folder/sitemap shape and one of the
    // five colours, picked by `id % 5`. Both come from `.find()` reaching
    // every matching descendant of the cloned row.
    $expectedColour = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'][$albumId % 5];
    expect($result['icon'])->toContain($expectedColour);
    expect($result['icon'])->toMatch('/icon-(sitemap|folder-open)/');

    // The running count is rendered from a translated string, so assert it
    // says something rather than pinning the wording.
    expect(trim($result['count']))->not->toBe('');

    // Clearing the box puts the tree back and empties the results.
    $page->fill('#cat_search_input', '');

    $page->assertMissing('.album-search-result-container');

    /** @var int $leftover */
    $leftover = $page->script(
        "document.querySelectorAll('.search-album-result .search-album-elem').length"
    );

    expect($leftover)
        ->toBe(0);

    $page->assertNoJavaScriptErrors();
});

it('reports no results for a term that matches nothing', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=albums');

    $page->fill('#cat_search_input', 'zzz-no-such-album-zzz');

    $page->assertVisible('.search-album-noresult');

    /** @var int $rows */
    $rows = $page->script(
        "document.querySelectorAll('.search-album-result .search-album-elem').length"
    );

    expect($rows)
        ->toBe(0);

    $page->assertNoJavaScriptErrors();
});
