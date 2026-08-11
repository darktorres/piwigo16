<?php

declare(strict_types=1);

use Piwigo\Admin\StatsPageRenderer;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\History\HistoryService;

/**
 * StatsPageRenderer::getMonthOfLastYears()'s own `$last === 'all'` branches
 * -- private, and genuinely UNREACHABLE from the one real call site
 * (StatsPageRenderer::render() always passes
 * \Piwigo\Config\CurrentConfig::statCompareYearDisplayed(), a SCHEMA-typed
 * `int` with no 'all' sentinel -- see that call site's own comment in the
 * renderer: "getMonthOfLastYears()'s own 'all' default is unreachable from
 * this call site, not dead code to resurrect here"). Reached here
 * reflectively instead, same shape as
 * tests/Integration/Admin/IntroSubControllerGetLatestNewsTest.php's own
 * identical "private method + unreachable default parameter" precedent.
 *
 * Needs a real, container-resolved HistoryService (Kernel::boot()), not a
 * hand-constructed one -- getMonthOfLastYears() takes it as an explicit
 * parameter, resolved here the same way. Same minimal "just Kernel::boot(),
 * no IntegrationTestCase base class" shape as
 * tests/Integration/InstallationStatsTest.php, which resolves through the
 * same Bootstrap accessor family -- getMonthlyRows()/setMissingValues()/
 * getDateObject() touch no CurrentUser/Lang/Template state at all, so
 * nothing beyond a booted container and a real DB connection is needed.
 *
 * tests/Browser/StatsPageRendererTest.php covers the *other* uncovered gap
 * in this same class (render()'s own reachable
 * `count(self::getLast(60, 'year')) > 1` branch) via a real HTTP request;
 * this file is Integration-tier only because reaching the 'all' branch
 * means bypassing render() entirely.
 */
/**
 * @return array<int|string, float|int>
 */
function statsGetMonthOfLastYearsInvoke(): array
{
    $method = new ReflectionMethod(StatsPageRenderer::class, 'getMonthOfLastYears');

    $historyService = Kernel::container()->get(HistoryService::class);
    if (! $historyService instanceof HistoryService) {
        throw new LogicException('Container returned an unexpected type for ' . HistoryService::class);
    }

    $result = $method->invoke(null, $historyService, 'all');
    if (! is_array($result)) {
        throw new RuntimeException('getMonthOfLastYears() did not return an array: ' . var_export($result, true));
    }

    $validated = [];
    foreach ($result as $key => $value) {
        if (! (is_float($value) || is_int($value))) {
            throw new RuntimeException('getMonthOfLastYears() returned an unexpected shape: ' . var_export($result, true));
        }
        $validated[$key] = $value;
    }

    return $validated;
}

beforeEach(function (): void {
    // AccessControl is part of HistoryService's own constructor chain
    // (AccessControl -> RedirectService -> UserService -> Paths) -- a bare
    // Kernel::boot() with no Paths does not resolve.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
    // getMonthlyRows(null) (no $limit) reads every month-level row in the
    // WHOLE table -- this method's own count($allRows) > 1 vs <= 1
    // branching can only be pinned deterministically by starting from a
    // genuinely empty slate, same reasoning as HistoryServiceTest's own
    // tearDown() and HistoryRepositoryTest's own clearSummary().
    DbConnection::build()->executeStatement('DELETE FROM history_summary');
});

afterEach(function (): void {
    DbConnection::build()->executeStatement('DELETE FROM history_summary');
    Kernel::reset();
});

test('getMonthOfLastYears(\'all\') takes the <= 1 row fallback branch, zero-filling exactly one year back from today when no month-level row exists', function (): void {
    // history_summary is empty (beforeEach already cleared it), so
    // getMonthlyRows(null) returns [] -- count($allRows) === 0 takes the
    // `else` branch: setMissingValues('month', $allRows, now - 1 year, now).
    $result = statsGetMonthOfLastYearsInvoke();

    $today = new DateTime();
    $oneYearAgo = (new DateTime())->sub(new DateInterval('P1Y'));

    expect(array_key_first($result))
        ->toBe($oneYearAgo->format('Y-m'));
    expect(array_key_last($result))
        ->toBe($today->format('Y-m'));
    // Every bucket is the zero-fill from setMissingValues()'s own first
    // loop -- there is no real row anywhere in $allRows to overlay.
    expect(array_sum($result))
        ->toBe(0);
});

test('getMonthOfLastYears(\'all\') takes the count > 1 branch, spanning exactly the real month-level rows\' own oldest-to-newest range', function (): void {
    $conn = DbConnection::build();
    $table = 'history_summary';
    $conn->executeStatement(
        "INSERT INTO {$table} (year, month, day, hour, nb_pages, history_id_from, history_id_to) VALUES (2019, 3, NULL, NULL, 11, 1, 10)"
    );
    $conn->executeStatement(
        "INSERT INTO {$table} (year, month, day, hour, nb_pages, history_id_from, history_id_to) VALUES (2019, 5, NULL, NULL, 4, 11, 20)"
    );

    // count($allRows) === 2 > 1 takes the `if` branch: setMissingValues(
    // 'month', $allRows) with no explicit dates, spanning from the oldest
    // real row (2019-03) to the newest (2019-05), zero-filling the gap
    // month (2019-04) in between.
    $result = statsGetMonthOfLastYearsInvoke();

    expect($result)
        ->toBe([
            '2019-03' => 11,
            '2019-04' => 0,
            '2019-05' => 4,
        ]);
});
