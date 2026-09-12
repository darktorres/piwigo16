<?php

declare(strict_types=1);

use Piwigo\Menu\Projection\MenubarQuerySearchPageContext;

/**
 * What is left of MenubarIdentificationPageContextTest. The three cases it
 * carried asserted the guest/identified-user key sets, which are now
 * MenubarIdentificationView's `$guest`/`$user` and typed rather than
 * conditionally-present array keys.
 */
test('toArray assigns an empty string when there is no query search, never omitting the key', function (): void {
    // Always assigned, not conditionally omitted (P29.6, modus): a
    // theme's own menubar.latte override reads $QUERY_SEARCH
    // unconditionally, and Latte's compiler rewrites a declared-type
    // variable's own isset() check into `!== null`, so an omitted key
    // still throws "Undefined variable" at render time no matter how
    // defensively the template checks for it -- see this class's own
    // docblock.
    expect((new MenubarQuerySearchPageContext(null))->toArray())
        ->toBe([
            'QUERY_SEARCH' => '',
        ]);
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
