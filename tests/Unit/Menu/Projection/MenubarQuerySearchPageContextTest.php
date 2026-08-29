<?php

declare(strict_types=1);

use Piwigo\Menu\Projection\MenubarQuerySearchPageContext;

/**
 * What is left of MenubarIdentificationPageContextTest. The three cases it
 * carried asserted the guest/identified-user key sets, which are now
 * MenubarIdentificationView's `$guest`/`$user` and typed rather than
 * conditionally-present array keys; the omit-when-null behaviour that
 * remains is `QUERY_SEARCH`'s alone.
 */
test('toArray omits the key entirely when there is no query search', function (): void {
    expect((new MenubarQuerySearchPageContext(null))->toArray())
        ->toBe([]);
});

test('toArray carries the query search when a search section set one', function (): void {
    expect((new MenubarQuerySearchPageContext('sunset'))->toArray())
        ->toBe([
            'QUERY_SEARCH' => 'sunset',
        ]);
});

/**
 * The value reaches this context already escaped by MenubarRenderer (it is
 * user input from the qsearch details), so the context must not re-encode
 * or otherwise touch it -- index.latte renders it through `|noescape`.
 */
test('toArray passes an already-escaped value through untouched', function (): void {
    expect((new MenubarQuerySearchPageContext('&lt;script&gt;'))->toArray())
        ->toBe([
            'QUERY_SEARCH' => '&lt;script&gt;',
        ]);
});
