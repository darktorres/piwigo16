<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\StatsPageRenderer (admin.php?page=stats) -- the "history"
 * tabsheet group's stats/charts tab.
 *
 * The 2nd test below exercises the `count(self::getLast(60, 'year')) > 1`
 * "multiple years of real usage history already summarized" branch, plus
 * getMonthStats()'s own `foreach ($historyService->getDailyRowsForMonths(...)
 * as $value)` loop body -- both by directly seeding history_summary
 * rows rather than waiting on real elapsed time: year-only rows dated 2019/
 * 2020 (nothing in this suite's real traffic is ever dated outside "now",
 * so these can never collide with organically-summarized data) and one
 * day-level row dated *last* month specifically, for the same reason. Each
 * row's own history_id_to is a sentinel comfortably beyond any real
 * `history` row id this suite could produce, so HistoryService::
 * summarize()'s own findLastSummaryWithHistoryIdTo()-derived watermark
 * (called unconditionally at the top of every real render()) finds nothing
 * new to aggregate and leaves every seeded row exactly as inserted --
 * cleaned up in the same test's own finally block either way.
 *
 * Still NOT exercised here: StatsPageRenderer::getMonthOfLastYears()'s own
 * `$last === 'all'` branches -- genuinely UNREACHABLE through any real HTTP
 * request (CurrentConfig::statCompareYearDisplayed() is SCHEMA-typed 'int'
 * only, no 'all' sentinel ever reaches this call site -- see that method's
 * own call site comment in the renderer). Covered instead by
 * tests/Integration/StatsPageRendererGetMonthOfLastYearsTest.php, which
 * reaches it via ReflectionMethod (the only way in, since the method is
 * private and its default parameter value is what's unreachable).
 */
it('renders the stats page as a webmaster', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=stats');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'stats page');
});

it('renders more than one year of history summary data and a real day-level month bucket, covering the multi-year compare branch and the daily-stats accumulation loop', function (): void {
    $today = new DateTime();
    $lastMonth = (clone $today)->sub(new DateInterval('P1M'));
    $lastMonthYear = (int) $lastMonth->format('Y');
    $lastMonthMonth = (int) $lastMonth->format('n');

    $db = H::connect();

    // MySQL's UNIQUE KEY (year, month, day, hour) permits multiple rows
    // that share the same non-NULL columns whenever at least one indexed
    // column is NULL (NULL is never "equal" to NULL for uniqueness
    // purposes) -- every row seeded below has at least one NULL column, so
    // a leftover row from a previous aborted run would silently accumulate
    // as an extra duplicate (skewing nb_pages sums) rather than fail a
    // duplicate-key INSERT. Deleting first makes this idempotent for real,
    // not just in appearance.
    $deleteSeeds = function () use ($db, $lastMonthYear, $lastMonthMonth): void {
        H::dbQuery($db, 'DELETE FROM history_summary WHERE year IN (2019, 2020)');
        H::dbQuery($db, sprintf('DELETE FROM history_summary WHERE year = %d AND month = %d AND day = 10 AND hour IS NULL', $lastMonthYear, $lastMonthMonth));
    };
    $deleteSeeds();

    H::dbQuery($db, 'INSERT INTO history_summary (year, month, day, hour, nb_pages, history_id_from, history_id_to) VALUES (2019, NULL, NULL, NULL, 42, 1, 999000001)');
    H::dbQuery($db, 'INSERT INTO history_summary (year, month, day, hour, nb_pages, history_id_from, history_id_to) VALUES (2020, NULL, NULL, NULL, 7, 1, 999000002)');
    H::dbQuery($db, sprintf('INSERT INTO history_summary (year, month, day, hour, nb_pages, history_id_from, history_id_to) VALUES (%d, %d, 10, NULL, 99, 1, 999000003)', $lastMonthYear, $lastMonthMonth));

    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_stats_page_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    // Raw curl login (not the Playwright-driven H::loginAsAdmin()) so the
    // response body is read directly and synchronously, without racing
    // Playwright's own content()-timing (see
    // BrowserTestHelpers::assertNoServerErrors()'s own docblock for that
    // exact race) -- same cookie-jar precedent as
    // CatListPageRendererTest.php's own delete-and-redirect test.
    $curl = static function (string $url, array $fields = []) use ($cookieJar): string {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $response = curl_exec($ch);
        if (! is_string($response)) {
            throw new RuntimeException("curl to {$url} did not return a string response");
        }

        return $response;
    };

    try {
        $curl(H::baseUrl() . '/identification.php');
        $curl(H::baseUrl() . '/identification.php', [
            'username' => H::ADMIN_USER,
            'password' => H::ADMIN_PASS,
            'login' => 'Login',
        ]);

        $body = $curl(H::baseUrl() . '/admin.php?page=stats');

        // Same server-error markers as BrowserTestHelpers::
        // assertNoServerErrors() -- that helper only accepts a Playwright
        // page wrapper, not a raw curl body string, so this is a plain
        // inline equivalent for this curl-based flow.
        foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $marker) {
            expect($body)->not->toContain($marker);
        }

        // render()'s own `if (count(self::getLast(60, 'year')) > 1)` true
        // branch: setMissingValues('year', getLast(60, 'year')) with no
        // explicit dates spans from the oldest to the newest real
        // year-only row -- both seeded years must survive verbatim into
        // the rendered data-years JSON (stats.latte's
        // `data-years='{$lastYears|json_encode}'`).
        //
        // Asserted against the &quot;-encoded form, not a literal '"' --
        // Latte's HtmlHelpers::escapeQuotes() unconditionally HTML-escapes
        // quotes printed inside an attribute-value context (confirmed live
        // in commit 4b6cd2f448's own investigation; |noescape does not
        // suppress it there). This is not a functional bug: browsers
        // HTML-entity-decode attribute values before any JS reads them
        // (stats.js's own $("#data").data("years") call sees the
        // already-decoded string), so the literal-quote form this test
        // originally asserted can never appear in the raw response body.
        expect($body)
            ->toContain('&quot;2019&quot;:42');
        expect($body)
            ->toContain('&quot;2020&quot;:7');

        // getMonthStats()'s own foreach ($historyService->
        // getDailyRowsForMonths(...) as $value) loop body (getDateObject()
        // + the $months[...] bucket append) only runs when at least one
        // real day-level row matches one of its 3 (year, month) candidates
        // -- the seeded last-month bucket forces it, and its own
        // setMissingValues('day', ...) overlay carries the seeded value
        // through to data-month-stats. Same &quot;-encoding caveat as above.
        expect($body)
            ->toContain(sprintf('&quot;%04d-%02d-10&quot;:99', $lastMonthYear, $lastMonthMonth));
    } finally {
        $deleteSeeds();
        H::dbClose($db);
        @unlink($cookieJar);
    }
});
