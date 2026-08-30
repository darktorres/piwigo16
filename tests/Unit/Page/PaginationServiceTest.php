<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\PaginationService;
use Piwigo\Core\Projection\Navbar;

// CurrentConfig is a constructor-injected instance -- each test builds
// its own fresh instance rather than mutating/resetting shared static
// state (same pattern as tests/Unit/Controller/ImageDerivativeControllerTest.php).
// A fresh instance's paginatePagesAround() already defaults to 2, so most
// tests below don't need to touch it at all.
test('createNavigationBar returns an empty bar when everything fits on one page', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 10, 0, 20);

    expect($navbar)
        ->toEqual(Navbar::none());
});

test('createNavigationBar computes the current page and total page count', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    expect($navbar->currentPage)
        ->toBe(3)
        ->and($navbar->nbPage)
        ->toBe(5);
});

test('createNavigationBar omits URL_FIRST/URL_PREV on the first page', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 0, 20);

    expect($navbar->urlFirst)
        ->toBeNull();
    expect($navbar->urlPrev)
        ->toBeNull();
    expect($navbar->urlNext)
        ->not->toBeNull();
    expect($navbar->urlLast)
        ->not->toBeNull();
});

test('createNavigationBar omits URL_NEXT/URL_LAST on the last page', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 80, 20);

    expect($navbar->urlFirst)
        ->not->toBeNull();
    expect($navbar->urlPrev)
        ->not->toBeNull();
    expect($navbar->urlNext)
        ->toBeNull();
    expect($navbar->urlLast)
        ->toBeNull();
});

test('createNavigationBar clamps a negative start to zero', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, -5, 20);

    expect($navbar->currentPage)
        ->toBe(1);
});

/**
 * Confirmed-equivalent: `$start <= 0` (SmallerToSmallerOrEqual) and
 * `$start < 1` (IncrementInteger) in place of the `$start < 0` clamp.
 * Both differ from real code ONLY at $start === 0 exactly, and in that
 * one case they re-clamp $start to a value it already holds. Assigning 0
 * to an int already 0 is invisible downstream, so no test can ever kill
 * either.
 *
 * Re-verified live (P58-B3) by applying `<= 0` to the real source and
 * running the WHOLE Unit suite, not just this file: identical results.
 * Worth doing that way -- `pest --mutate` reports this one as KILLED,
 * which it is not. A 100% score on this class is not by itself proof
 * that every mutant died.
 */
test('createNavigationBar clamps a start of exactly -1, one below the real boundary', function (): void {
    // Kills DecrementInteger on the clamp (`$start < -1` instead of `<
    // 0`): a start of exactly -1 is the one value real code's `< 0`
    // check still clamps (to 0) but the mutant's `< -1` check does
    // NOT (`-1 < -1` is false) -- the unclamped -1
    // survives into the current-page arithmetic, producing 0.95
    // instead of the real, clamped 1.0.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, -1, 20);

    expect($navbar->currentPage)
        ->toBe(1);
});

test('createNavigationBar returns an empty bar when nbElement exactly equals nbElementPage', function (): void {
    // Kills GreaterToGreaterOrEqual on the `$nbElement > $nbElementPage`
    // gate (`>=` instead of `>`):
    // exactly one page's worth of elements means no navigation is
    // needed (matches the "everything fits on one page" test's own
    // intent, but that test's nbElement=10 vs nbElementPage=20 never
    // reaches the boundary itself). The mutant instead
    // treats an exact match as "more than one page".
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 20, 0, 20);

    expect($navbar)
        ->toEqual(Navbar::none());
});

test('createNavigationBar rounds the total page count up, not down or to nearest, for a non-exact division', function (): void {
    // Kills CeilToFloor and CeilToRound on $maximum: 101 elements at 20
    // per page needs 6 pages (5 full + 1 partial), not 5 -- floor(5.05)
    // and round(5.05) both wrongly give 5. Every other test in this
    // file uses an exact multiple (100/20, 1000/20), where all three
    // functions happen to agree.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 101, 0, 20);

    expect($navbar->nbPage)
        ->toBe(6);
});

/**
 * The `(int)` normalization of $nbElement/$start is load-bearing, and
 * this test is what kills RemoveIntegerCast on it. It did NOT used to be:
 * every downstream use was arithmetic or comparison, which coerces a
 * numeric string by itself, so dropping the casts changed nothing and
 * the mutation was documented here as equivalent. `intdiv()` ended that
 * (P58-B3) -- it declares `int` parameters and this file is
 * strict_types, so a numeric string reaches it as a TypeError rather
 * than being coerced.
 */
test('createNavigationBar accepts numeric strings for nbElement and start', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', '100', '40', 20);

    expect($navbar->currentPage)
        ->toBe(3);
});

test('createNavigationBar builds clean-url-style page links when requested', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php/category/1', 100, 40, 20, true);

    expect($navbar->urlNext)
        ->toBe('index.php/category/1/start-60');
});

test('createNavigationBar builds query-string-style page links by default', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    expect($navbar->urlNext)
        ->toBe('index.php?start=60');
});

test('createNavigationBar respects a custom param name', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20, false, 'offset');

    expect($navbar->urlNext)
        ->toBe('index.php?offset=60');
});

test('createNavigationBar builds the full "pages" link array around the current page, on a middle page', function (): void {
    // No prior test ever inspected $navbar->pages content -- only
    // CURRENT_PAGE/NB_PAGE/URL_NEXT were checked. This one comprehensive
    // assertion (page 3 of 5, pagesAround=2, so every page 1-5 shows)
    // closes most of the for-loop-bounds arithmetic (PlusToMinus/
    // MinusToPlus on the +1.0/-2.0 terms, the (float)/(int) casts,
    // Decrement/IncrementFloat and Decrement/IncrementInteger) and line
    // 93's own URL-building concatenation (MinusToPlus on $i-1,
    // MultiplicationToDivision, Decrement/IncrementInteger on the loop
    // index arithmetic, and all 3 ConcatRemoveLeft/Right/SwitchSides
    // mutations on the url/start_str/offset concatenation).
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    expect($navbar->pages)
        ->toBe([
            1 => 'index.php',
            2 => 'index.php?start=20',
            3 => 'index.php?start=40',
            4 => 'index.php?start=60',
            5 => 'index.php?start=80',
        ]);
});

test('createNavigationBar\'s "pages" array clamps its lower bound to page 2 near the start, not going negative or below', function (): void {
    // Kills the for-loop bounds' MinToMax (max(...) -> min(...)) and MaxToMin
    // (min(...) -> max(...)) on the loop's OWN lower-bound clamp: on
    // page 1 of 5 with pagesAround=2, floor(1) - 2 = -1, which the real
    // max(-1, 2) clamps up to 2 -- without that clamp (or with it
    // backwards), the loop would start at a negative/zero index instead,
    // either fataling on a negative array key range or producing extra,
    // wrong entries.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 0, 20);

    expect($navbar->pages)
        ->toBe([
            1 => 'index.php',
            2 => 'index.php?start=20',
            3 => 'index.php?start=40',
            5 => 'index.php?start=80',
        ]);
});

test('createNavigationBar\'s "pages" array clamps its upper bound to the last page near the end, not going past it', function (): void {
    // Kills the SAME MinToMax/MaxToMin pair on the loop's UPPER-bound
    // clamp (the `min(..., $maximum)` in $stop's own computation): on
    // page 5 of 5 with pagesAround=2, ceil(5) + 2 + 1 = 8, which the
    // real min(8, 5) clamps down to 5 -- without that clamp, the loop
    // would try to build links past the real last page.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 80, 20);

    expect($navbar->pages)
        ->toBe([
            1 => 'index.php',
            3 => 'index.php?start=40',
            4 => 'index.php?start=60',
            5 => 'index.php?start=80',
        ]);
});

test('createNavigationBar snaps an off-boundary offset to the page holding its first element', function (): void {
    // The two cases that used to pin round()-vs-floor() snapping and
    // floor()/ceil() window bounds over a fractional current page. Neither
    // exists now: the current page is the page element $start falls on, so
    // there is one number, and every link and bound is derived from it.
    //
    // 30 and 26 are kept because they are exactly where the old rounding
    // disagreed with that -- round(30/20) snapped UP to page 3 while
    // element 30 is on page 2, and the bar then linked page 3's neighbours
    // while highlighting nothing.
    $currentConfig = new CurrentConfig();
    $currentConfig->paginatePagesAround = 0;
    $service = new PaginationService($currentConfig);

    foreach ([26, 30, 39] as $start) {
        $navbar = $service->createNavigationBar('index.php', 1000, $start, 20);

        expect($navbar->currentPage)
            ->toBe(2, "element {$start} is on page 2")
            ->and($navbar->pages)
            ->toBe([
                1 => 'index.php',
                2 => 'index.php?start=20',
                50 => 'index.php?start=980',
            ])
            // Page 2's own neighbours, not page 3's: the first page (emitted
            // bare) and start=40.
            ->and($navbar->urlPrev)
            ->toBe('index.php')
            ->and($navbar->urlNext)
            ->toBe('index.php?start=40');
    }
});

test('createNavigationBar centres the "pages" window on the current page', function (): void {
    // $pages_around means "this many either side", which only holds once the
    // centre is a single page. Over a fraction the old floor()/ceil() pair
    // widened the window by one on whichever side the offset leaned.
    $currentConfig = new CurrentConfig();
    $currentConfig->paginatePagesAround = 2;
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 1000, 200, 20);

    expect($navbar->currentPage)
        ->toBe(11)
        ->and(array_keys($navbar->pages))
        ->toBe([1, 9, 10, 11, 12, 13, 50]);
});

test('createNavigationBar builds an exact URL_PREV/URL_NEXT/URL_LAST when nbElementPage is small enough to make the offsets themselves small', function (): void {
    // Kills IncrementInteger on URL_PREV's guard (`$previous > 1` instead of `>
    // 0`) and its ConcatRemoveLeft/ConcatRemoveRight/ConcatSwitchSides:
    // a small nbElementPage (1) makes $previous a small, non-zero
    // integer (1) that IncrementInteger's shifted boundary wrongly
    // excludes -- the boundary-exact test above only covers $previous
    // === 0, which IncrementInteger doesn't actually mis-handle (see
    // its own confirmed-equivalent note there).
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 10, 2, 1);

    expect($navbar->urlPrev)
        ->toBe('index.php?start=1')
        ->and($navbar->urlNext)
        ->toBe('index.php?start=3')
        ->and($navbar->urlLast)
        ->toBe('index.php?start=9');
});

/**
 * `$previous > 1` in place of `$previous > 0` is killed now, by the
 * off-boundary and out-of-range tests added in P58-B3. It used to be
 * documented here as equivalent, on the grounds that the only $previous
 * value this file reached was 0, where both agree. The two differ at
 * $previous === 1, which needs nbElementPage = 1 -- a case those tests
 * now cover.
 *
 * Also confirmed-equivalent: RemoveStringCast on URL_NEXT's own
 * `(string) $next` -- string concatenation coerces an operand to string
 * identically to an explicit cast, the same "cast redundant with
 * implicit operator coercion" pattern established elsewhere in this
 * suite.
 *
 * The note that used to sit here about `$next < $last ? $next : $last`
 * is gone with the ternary itself (P58-B3): once the current page is
 * clamped to $maximum, this branch only runs below the last page, so
 * $next cannot exceed $last and there was nothing left to cap.
 */
test('createNavigationBar treats an offset inside the last page as being on the last page', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    // start=85 with nbElement=100/nbElementPage=20: element 85 is on page
    // 5, and page 5 IS the last page, so there is no next and no last to
    // link to. This used to emit both, because the current page was the
    // unrounded 5.25 -- unequal to 5, so the block ran -- and URL_NEXT
    // then had to be capped back to $last, producing a "next" link
    // pointing at the page already being displayed.
    $navbar = $service->createNavigationBar('index.php', 100, 85, 20);

    expect($navbar->currentPage)
        ->toBe(5)
        ->and($navbar->urlNext)
        ->toBeNull()
        ->and($navbar->urlLast)
        ->toBeNull()
        // ...and the backward links are still there, since page 5 is not
        // page 1.
        ->and($navbar->urlFirst)
        ->toBe('index.php')
        ->and($navbar->urlPrev)
        ->toBe('index.php?start=60');
});

test('createNavigationBar clamps an out-of-range offset to the last real page', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    // $start is never validated against the element count upstream -- it
    // comes off the URL and is only clamped at < 0 -- so ?start=500 on a
    // 5-page gallery is reachable. Without the clamp this reports page 26,
    // which is absent from `pages`, leaving the template with nothing to
    // highlight for exactly the reason the fractional page did.
    $navbar = $service->createNavigationBar('index.php', 100, 500, 20);

    expect($navbar->currentPage)
        ->toBe(5)
        ->and(array_key_exists((int) $navbar->currentPage, $navbar->pages))
        ->toBeTrue()
        ->and($navbar->urlNext)
        ->toBeNull();
});

// The reason currentPage is an int rather than the exact fractional
// position: it is the page the template highlights, and it has to name the
// page this bar is actually built around. $start arrives off the URL and is
// only clamped at < 0, so an off-boundary offset used to export a fraction
// -- 30/20 + 1 = 2.5 -- which `{if $page === $navbar->currentPage}` matches
// against no key in `pages` at all, silently highlighting nothing.
//
// Membership alone is too weak a check: truncating 2.5 to 2 also names a
// real page. What pins it is agreement with the links, which are built from
// the SNAPPED start -- at start=30 those are prev=20 and next=60, the
// neighbours of page 3, not of page 2. So currentPage must be the page
// whose own neighbours those are.
test('createNavigationBar reports the current page the prev/next links are built around', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->paginatePagesAround = 2;
    $service = new PaginationService($currentConfig);

    // Off-boundary offsets only: these are the ones where snapping,
    // truncating and the exact position all disagree.
    foreach ([26, 30, 39, 45] as $start) {
        $navbar = $service->createNavigationBar('index.php', 1000, $start, 20);
        $page = $navbar->currentPage;

        expect($page)
            ->toBeInt()
            ->and(array_key_exists((int) $page, $navbar->pages))
            ->toBeTrue("start={$start}: page {$page} is not in the pages list");

        // (p-2)*20 for the previous page, p*20 for the next one. The
        // first page is emitted as the bare url rather than start=0.
        $prevStart = ((int) $page - 2) * 20;
        expect($navbar->urlPrev)
            ->toBe($prevStart > 0 ? 'index.php?start=' . $prevStart : 'index.php', "start={$start}: prev link disagrees with page {$page}")
            ->and($navbar->urlNext)
            ->toBe('index.php?start=' . ((int) $page * 20), "start={$start}: next link disagrees with page {$page}");
    }
});
