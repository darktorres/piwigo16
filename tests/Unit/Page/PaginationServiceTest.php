<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\PaginationService;

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
        ->toBe([]);
});

test('createNavigationBar computes the current page and total page count', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    $currentPage = $navbar['CURRENT_PAGE'] ?? null;
    Assert::assertIsFloat($currentPage);
    expect($currentPage)
        ->toBe(3.0);

    $nbPage = $navbar['NB_PAGE'] ?? null;
    Assert::assertIsInt($nbPage);
    expect($nbPage)
        ->toBe(5);
});

test('createNavigationBar omits URL_FIRST/URL_PREV on the first page', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 0, 20);

    expect($navbar)
        ->not->toHaveKey('URL_FIRST');
    expect($navbar)
        ->not->toHaveKey('URL_PREV');
    expect($navbar)
        ->toHaveKey('URL_NEXT');
    expect($navbar)
        ->toHaveKey('URL_LAST');
});

test('createNavigationBar omits URL_NEXT/URL_LAST on the last page', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 80, 20);

    expect($navbar)
        ->toHaveKey('URL_FIRST');
    expect($navbar)
        ->toHaveKey('URL_PREV');
    expect($navbar)
        ->not->toHaveKey('URL_NEXT');
    expect($navbar)
        ->not->toHaveKey('URL_LAST');
});

test('createNavigationBar clamps a negative start to zero', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, -5, 20);

    $currentPage = $navbar['CURRENT_PAGE'] ?? null;
    Assert::assertIsFloat($currentPage);
    expect($currentPage)
        ->toBe(1.0);
});

/**
 * Confirmed-equivalent: line 57's SmallerToSmallerOrEqual (`$start <=
 * 0` instead of `< 0`) and IncrementInteger (`$start < 1` instead of
 * `< 0`). Both differ from real code ONLY at $start === 0 exactly, and
 * in that one case they wrongly re-clamp $start to 0 -- a value it
 * already was. Assigning 0 to an int already holding 0 is invisible to
 * every downstream computation; confirmed live both mutations produce
 * byte-identical output to real code for $start = 0 (and, by the same
 * reasoning, every other integer, since `< 0` and `<= 0`/`< 1` agree
 * everywhere except exactly at 0).
 */
test('createNavigationBar clamps a start of exactly -1, one below the real boundary', function (): void {
    // Kills line 57's DecrementInteger (`$start < -1` instead of `<
    // 0`): a start of exactly -1 is the one value real code's `< 0`
    // check still clamps (to 0) but the mutant's `< -1` check does
    // NOT (`-1 < -1` is false) -- confirmed live the unclamped -1
    // survives into the current-page arithmetic, producing 0.95
    // instead of the real, clamped 1.0.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, -1, 20);

    $currentPage = $navbar['CURRENT_PAGE'] ?? null;
    Assert::assertIsFloat($currentPage);
    expect($currentPage)
        ->toBe(1.0);
});

test('createNavigationBar returns an empty bar when nbElement exactly equals nbElementPage', function (): void {
    // Kills line 62's GreaterToGreaterOrEqual (`>=` instead of `>`):
    // exactly one page's worth of elements means no navigation is
    // needed (matches the "everything fits on one page" test's own
    // intent, but that test's nbElement=10 vs nbElementPage=20 never
    // reaches the boundary itself). Confirmed live the mutant instead
    // treats an exact match as "more than one page".
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 20, 0, 20);

    expect($navbar)
        ->toBe([]);
});

test('createNavigationBar rounds the total page count up, not down or to nearest, for a non-exact division', function (): void {
    // Kills line 66's CeilToFloor and CeilToRound: 101 elements at 20
    // per page needs 6 pages (5 full + 1 partial), not 5 -- floor(5.05)
    // and round(5.05) both wrongly give 5. Every other test in this
    // file uses an exact multiple (100/20, 1000/20), where all three
    // functions happen to agree.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 101, 0, 20);

    expect($navbar['NB_PAGE'] ?? null)->toBe(6);
});

/**
 * Confirmed-equivalent: line 65's 2 RemoveDoubleCast mutations (on
 * `(float) $start` and, independently, if the OTHER `(float)
 * $nbElementPage` cast were the one dropped -- same reasoning either
 * way). $start is already an int (normalized at the top of the
 * method) and $nbElementPage is a plain `int` parameter; PHP's own
 * int/float division promotes the whole expression to float the
 * moment EITHER side is cast, so which specific side carries the
 * explicit `(float)` makes no difference to the computed
 * $cur_page. Confirmed live: every test in this file passes
 * identically with this cast removed.
 */

/**
 * Confirmed-equivalent: lines 50/51's RemoveIntegerCast (dropping the
 * `(int)` normalization of $nbElement/$start entirely). Every
 * downstream use -- comparisons (`$start < 0`), arithmetic (`(float)
 * $start / ...`) -- already coerces a numeric string the same way PHP's
 * own operators always do, regardless of these top-level casts.
 * Confirmed live: every test in this file, including the numeric-
 * string one directly below, passes identically with both casts
 * removed.
 */
test('createNavigationBar accepts numeric strings for nbElement and start', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', '100', '40', 20);

    $currentPage = $navbar['CURRENT_PAGE'] ?? null;
    Assert::assertIsFloat($currentPage);
    expect($currentPage)
        ->toBe(3.0);
});

test('createNavigationBar builds clean-url-style page links when requested', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php/category/1', 100, 40, 20, true);

    $urlNext = $navbar['URL_NEXT'] ?? null;
    Assert::assertIsString($urlNext);
    expect($urlNext)
        ->toBe('index.php/category/1/start-60');
});

test('createNavigationBar builds query-string-style page links by default', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    $urlNext = $navbar['URL_NEXT'] ?? null;
    Assert::assertIsString($urlNext);
    expect($urlNext)
        ->toBe('index.php?start=60');
});

test('createNavigationBar respects a custom param name', function (): void {
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20, false, 'offset');

    $urlNext = $navbar['URL_NEXT'] ?? null;
    Assert::assertIsString($urlNext);
    expect($urlNext)
        ->toBe('index.php?offset=60');
});

test('createNavigationBar builds the full "pages" link array around the current page, on a middle page', function (): void {
    // No prior test ever inspected $navbar['pages'] content -- only
    // CURRENT_PAGE/NB_PAGE/URL_NEXT were checked. This one comprehensive
    // assertion (page 3 of 5, pagesAround=2, so every page 1-5 shows)
    // closes most of line 91's for-loop-bounds arithmetic (PlusToMinus/
    // MinusToPlus on the +1.0/-2.0 terms, the (float)/(int) casts,
    // Decrement/IncrementFloat and Decrement/IncrementInteger) and line
    // 93's own URL-building concatenation (MinusToPlus on $i-1,
    // MultiplicationToDivision, Decrement/IncrementInteger on the loop
    // index arithmetic, and all 3 ConcatRemoveLeft/Right/SwitchSides
    // mutations on the url/start_str/offset concatenation) -- each
    // independently confirmed live via a sed-applied mutation rerun.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    expect($navbar['pages'] ?? null)->toBe([
        1 => 'index.php',
        2 => 'index.php?start=20',
        3 => 'index.php?start=40',
        4 => 'index.php?start=60',
        5 => 'index.php?start=80',
    ]);
});

test('createNavigationBar\'s "pages" array clamps its lower bound to page 2 near the start, not going negative or below', function (): void {
    // Kills line 91's MinToMax (max(...) -> min(...)) and MaxToMin
    // (min(...) -> max(...)) on the loop's OWN lower-bound clamp: on
    // page 1 of 5 with pagesAround=2, floor(1) - 2 = -1, which the real
    // max(-1, 2) clamps up to 2 -- without that clamp (or with it
    // backwards), the loop would start at a negative/zero index instead,
    // either fataling on a negative array key range or producing extra,
    // wrong entries.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 100, 0, 20);

    expect($navbar['pages'] ?? null)->toBe([
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

    expect($navbar['pages'] ?? null)->toBe([
        1 => 'index.php',
        3 => 'index.php?start=40',
        4 => 'index.php?start=60',
        5 => 'index.php?start=80',
    ]);
});

test('createNavigationBar\'s "pages" array starts from floor(), not ceil() or round(), of a genuinely fractional current page', function (): void {
    // Kills line 91's FloorToCiel/FloorToRound (on the lower-bound's
    // own floor($cur_page)) and CeilToFloor (on the upper-bound's own
    // ceil($cur_page)): a 0 pagesAround keeps both bounds far from
    // their max()/min() clamps (avoiding the previous two tests' own
    // clamp-dominated ranges, which would mask a floor/ceil/round
    // substitution here), and start=30 with a 20-per-page size gives a
    // genuinely non-integer current page (2.5) where floor (2), ceil
    // (3), and round (3, PHP rounds .5 away from zero) disagree --
    // confirmed live each of these 3 mutations changes which pages
    // appear. CeilToRound isn't closable here since ceil(2.5) and
    // round(2.5) happen to agree -- see the dedicated test below.
    //
    // Also closes line 68's RoundToFloor/RoundToCeil (on $start's own
    // snap-to-page-boundary round()) via the URL_PREV/URL_NEXT
    // assertions: 30/20 = 1.5 rounds (away from zero) to page index 2,
    // snapping $start to 40 -- floor would wrongly snap to page 1
    // (start 20), producing a completely different previous/next pair.
    // Line 69's MinusToPlus and RemoveDoubleCast, and line 70's
    // RemoveDoubleCast, are closed the same way: real
    // previous/next are exact -20/+20 offsets from the snapped 40.
    $currentConfig = new CurrentConfig();
    $currentConfig->paginatePagesAround = 0;
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 1000, 30, 20);

    expect($navbar['CURRENT_PAGE'] ?? null)->toBe(2.5)
        ->and($navbar['pages'] ?? null)->toBe([
            1 => 'index.php',
            2 => 'index.php?start=20',
            3 => 'index.php?start=40',
            50 => 'index.php?start=980',
        ])
        ->and($navbar['URL_PREV'] ?? null)->toBe('index.php?start=20')
        ->and($navbar['URL_NEXT'] ?? null)->toBe('index.php?start=60');
});

test('createNavigationBar\'s "pages" array uses ceil(), not round(), for its upper bound when they genuinely disagree', function (): void {
    // Kills line 91's CeilToRound specifically: ceil(2.3) is 3 but
    // round(2.3) is 2 (rounds down, unlike the .5 case above) --
    // confirmed live the mutant's upper bound excludes page 3 that
    // real code correctly includes.
    //
    // Also closes line 68's RoundToCeil: round(1.3) is 1 but ceil(1.3)
    // is 2, snapping $start to a different page boundary (20 vs 40) --
    // confirmed live via the URL_PREV/URL_NEXT assertions below, which
    // the .5-boundary test above can't reach (round and ceil agree at
    // exactly .5).
    $currentConfig = new CurrentConfig();
    $currentConfig->paginatePagesAround = 0;
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 1000, 26, 20);

    expect($navbar['CURRENT_PAGE'] ?? null)->toBe(2.3)
        ->and($navbar['pages'] ?? null)->toBe([
            1 => 'index.php',
            2 => 'index.php?start=20',
            3 => 'index.php?start=40',
            50 => 'index.php?start=980',
        ])
        ->and($navbar['URL_PREV'] ?? null)->toBe('index.php')
        ->and($navbar['URL_NEXT'] ?? null)->toBe('index.php?start=40');
});

/**
 * Confirmed-equivalent: line 91's 3 RemoveDoubleCast mutations (on
 * `(float) $pages_around` twice and `(float) $maximum` once, inside
 * the for-loop's own bounds computation) and its RemoveIntegerCast (on
 * the final `(int) max(...)` for $i's own starting value). All 4
 * involve arithmetic where the OTHER operand is already float
 * (floor()/ceil() always return float), so PHP's own int/float
 * promotion produces the identical numeric result regardless -- and
 * for the RemoveIntegerCast case specifically, PHP's own array-key
 * semantics already truncate a float key exactly like `(int)` would
 * for the always-positive page indices this loop produces. Confirmed
 * live: every test in this file passes identically with each of
 * these 4 mutations applied individually.
 */
test('createNavigationBar builds an exact URL_PREV/URL_NEXT/URL_LAST when nbElementPage is small enough to make the offsets themselves small', function (): void {
    // Kills line 80's IncrementInteger (`$previous > 1` instead of `>
    // 0`) and its ConcatRemoveLeft/ConcatRemoveRight/ConcatSwitchSides:
    // a small nbElementPage (1) makes $previous a small, non-zero
    // integer (1) that IncrementInteger's shifted boundary wrongly
    // excludes -- the boundary-exact test above only covers $previous
    // === 0, which IncrementInteger doesn't actually mis-handle (see
    // its own confirmed-equivalent note there).
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    $navbar = $service->createNavigationBar('index.php', 10, 2, 1);

    expect($navbar['URL_PREV'] ?? null)->toBe('index.php?start=1')
        ->and($navbar['URL_NEXT'] ?? null)->toBe('index.php?start=3')
        ->and($navbar['URL_LAST'] ?? null)->toBe('index.php?start=9');
});

/**
 * Confirmed-equivalent: line 80's IncrementInteger, at $previous === 0
 * specifically (the only boundary this whole file's own tests reach
 * without a small nbElementPage): `$previous > 1` and the real `>  0`
 * both correctly exclude 0, so this mutation is invisible right at the
 * boundary the "omits URL_FIRST/URL_PREV" test exercises -- see the
 * dedicated small-nbElementPage test above, which reaches a genuinely
 * different (non-zero, non-boundary) $previous value instead.
 *
 * Also confirmed-equivalent: line 84's RemoveStringCast (`$url_start .
 * ($next < $last ? ...)` instead of `. (string) (...)`) -- string
 * concatenation already coerces an operand to string identically to
 * an explicit cast, the same "cast redundant with implicit operator
 * coercion" pattern already established elsewhere in this test suite,
 * confirmed live here too. And line 84's SmallerToSmallerOrEqual (`<=`
 * instead of `<`): the ternary's two branches are only reachable with
 * different results when $next actually differs from $last; at the
 * one point where `<` and `<=` themselves disagree (`$next ===
 * $last`), both branches select the SAME value ($next, which equals
 * $last there) -- there is no input for which this mutation can ever
 * produce a different final URL.
 */
test('createNavigationBar caps URL_NEXT at the real last page when the unsnapped current page overshoots it', function (): void {
    // Also closes line 85's ConcatRemoveLeft/ConcatRemoveRight/
    // ConcatSwitchSides on URL_LAST's own concatenation, via the same
    // exact-value assertion.
    $currentConfig = new CurrentConfig();
    $service = new PaginationService($currentConfig);

    // start=85 with nbElement=100/nbElementPage=20 gives an unsnapped
    // current page of 5.25 -- not equal to the real maximum (5.0), so
    // the URL_NEXT/URL_LAST block runs even though $start snaps (via
    // round(4.25) = 4) to the actual last page's own boundary (80),
    // making the uncapped $next (100) genuinely exceed $last (80).
    $navbar = $service->createNavigationBar('index.php', 100, 85, 20);

    expect($navbar['URL_NEXT'] ?? null)->toBe('index.php?start=80')
        ->and($navbar['URL_LAST'] ?? null)->toBe('index.php?start=80');
});
