<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Core\Env;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\IntroSubController (admin.php, page slug
 * "intro" -- the admin dashboard: pending-comments/orphans/locked-album
 * warnings, the activity chart, and the storage chart). Already ~65%
 * covered incidentally (every admin-page test lands here by default), so
 * this file targets specifically what a bare visit never exercises: the
 * newsletter-hide action, and the orphan-photo/locked-album warning
 * banners (neither of which the fixture triggers by default -- category
 * 1/2 are both visible, and all 5 fixture photos are linked to a
 * category).
 *
 * Deliberately skips: the newsletter-promotion panel (needs an account
 * 2+ weeks old with 3+ albums and 30+ photos -- unrealistic to fabricate
 * for this fixture), and the "latest Piwigo news" panel
 * (`show_piwigo_latest_news`, fixture default false) -- enabling it would
 * make `getLatestNews()` perform a real outbound HTTP request to
 * piwigo.org, which has no place in a deterministic test.
 */
function introDbConnect(): mysqli|Connection
{
    return H::connect();
}

/** Inserts an unlinked (orphan) image row and returns its id. */
function introInsertOrphanImage(): int
{
    $db = introDbConnect();
    $file = 'ct_orphan_' . uniqid() . '.jpg';
    H::dbQuery($db, sprintf("INSERT INTO images (file, path, hit, level) VALUES ('%s', '%s', 0, 0)", H::dbEscape($db, $file), H::dbEscape($db, $file)));
    $id = H::dbInsertId($db);
    H::dbClose($db);

    return $id;
}

function introDeleteImage(int $imageId): void
{
    $db = introDbConnect();
    H::dbQuery($db, sprintf('DELETE FROM images WHERE id = %d', $imageId));
    H::dbClose($db);
}

function introSetCategoryVisible(int $categoryId, bool $visible): void
{
    $db = introDbConnect();
    // categories.visible is a genuine boolean column on Postgres -- a
    // bare 0/1 literal is valid MySQL tinyint(1) input but Postgres
    // rejects it outright.
    $sqlValue = $db instanceof mysqli ? ($visible ? '1' : '0') : ($visible ? 'true' : 'false');
    H::dbQuery($db, sprintf('UPDATE categories SET visible = %s WHERE id = %d', $sqlValue, $categoryId));
    H::dbClose($db);
}

it('renders the admin dashboard', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php');
    $page->assertNoJavaScriptErrors();
});

it('renders the admin dashboard twice in the same session, exercising the activity-cache hit', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php');
    $page->assertNoJavaScriptErrors();
    $page = H::navigateOk($page, '/admin.php');
    $page->assertNoJavaScriptErrors();
});

it('hides the newsletter subscription banner via the action param', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=intro&action=hide_newsletter_subscription', []);

    expect($result['status'])->toBe(200);
});

it('shows an orphans warning when orphan photos exist', function (): void {
    // ImageService::countOrphans() memoizes its result into the
    // `count_orphans` config param and only recomputes while that value
    // is NULL (AdminShell.php's own "only calculate ... if not huge"
    // comment) -- global, persistent, shared-across-the-whole-suite state,
    // not a per-request count. Any earlier admin-page visit this run
    // already cached a (likely zero) value, so inserting a fresh orphan
    // row alone would be invisible until the cache is cleared.
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['count_orphans']);
    $imageId = introInsertOrphanImage();

    try {
        H::setConfigValue('count_orphans', null);

        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Orphans');
    } finally {
        introDeleteImage($imageId);
        H::restoreConfig($snapshot);
    }
});

it('shows a locked-album warning when an album is hidden', function (): void {
    $page = H::loginAsAdmin($this);
    introSetCategoryVisible(2, false);

    try {
        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Locked album');
    } finally {
        introSetCategoryVisible(2, true);
    }
});

it('computes zero comments when comments are disabled', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['activate_comments']);

    try {
        H::setConfigValue('activate_comments', 'false');

        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * @param array<int, array{object: string, action: string, daysAgo: int}> $rows
 *
 * "daysAgo" is anchored on Env::now() (frozen to PIWIGO_TEST_NOW in test
 * mode), matching IntroSubController's own activity-chart "today" --
 * anchoring on the DB's real-clock NOW() instead would drift a full day
 * off from the chart whenever real wall-clock time crosses into the next
 * calendar day mid-run.
 */
function introInsertActivityRows(array $rows): void
{
    $db = introDbConnect();
    $now = Env::now();
    foreach ($rows as $row) {
        $occuredOn = (clone $now)->modify('-' . $row['daysAgo'] . ' day')->format('Y-m-d H:i:s');
        H::dbQuery($db, sprintf("INSERT INTO activity (object, object_id, action, session_idx, occured_on) VALUES ('%s', 1, '%s', 'ct_intro_session', '%s')", H::dbEscape($db, $row['object']), H::dbEscape($db, $row['action']), H::dbEscape($db, $occuredOn)));
    }
    H::dbClose($db);
}

function introDeleteActivityRows(): void
{
    $db = introDbConnect();
    H::dbQuery($db, sprintf("DELETE FROM activity WHERE session_idx = 'ct_intro_session'"));
    H::dbClose($db);
}

it('smooths the activity chart into size groups when daily counts vary by more than 120%', function (): void {
    // 3 distinct days with escalating counts (1, 3, 20): sorted ascending
    // by cmpDay(), the day-over-day ratios are 300% and ~667%, both over
    // the 120% split threshold -- exercises the diff_x compression
    // while-loop across *multiple* iterations (not just a single split),
    // and the final chart-size-assignment loop that follows it. A fresh
    // login starts a session with no `cache_activity_last_weeks` yet, so
    // this recomputes straight from these rows rather than reusing a
    // stale/empty cached value from an earlier test in this run.
    //
    // daysAgo=1, not 0 ("today"): "today" is also where every OTHER
    // test's login (and the fixture's own install/seed events) log their
    // own real activity rows, all sharing the same frozen Env::now()
    // timestamp. Across a full Browser suite run that ambient noise
    // accumulates into dozens of extra same-day rows, inflating "1
    // Activity" into "N Activities" and breaking this test's own
    // escalating 1/3/20 scenario. daysAgo=1 lands one day earlier than
    // the fixture/login noise ever touches (no piwigo_activity rows
    // exist before "today" at all), while staying on its own distinct
    // day-of-week from the other two seeded days.
    introInsertActivityRows([
        ['object' => 'photo', 'action' => 'ct_intro_a', 'daysAgo' => 1],
        ['object' => 'photo', 'action' => 'ct_intro_b1', 'daysAgo' => 2],
        ['object' => 'photo', 'action' => 'ct_intro_b2', 'daysAgo' => 2],
        ['object' => 'photo', 'action' => 'ct_intro_b3', 'daysAgo' => 2],
        ['object' => 'photo', 'action' => 'ct_intro_c1', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c2', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c3', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c4', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c5', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c6', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c7', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c8', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c9', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c10', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c11', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c12', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c13', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c14', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c15', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c16', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c17', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c18', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c19', 'daysAgo' => 4],
        ['object' => 'photo', 'action' => 'ct_intro_c20', 'daysAgo' => 4],
    ]);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();

        // Each day's own hover-tooltip literally renders its real activity
        // count (singular "Activity" for exactly 1, plural otherwise) --
        // a real, exact-value signal that $activity_last_weeks/$temp_data
        // picked up all 3 seeded days with their real per-day totals,
        // rather than just checking the page didn't error. The tooltip
        // markup is only CSS-visible on hover, so this reads the raw
        // response body directly instead of assertSee()'s visible-text
        // check.
        $html = H::rawWebpage($page)->content();
        expect($html)->toContain('1 Activity<');
        expect($html)->toContain('3 Activities');
        expect($html)->toContain('20 Activities');
    } finally {
        introDeleteActivityRows();
    }
});

function introInsertFakeImage(string $path): int
{
    $db = introDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO images (file, path, hit, level) VALUES ('%s', '%s', 0, 0)", H::dbEscape($db, basename($path)), H::dbEscape($db, $path)));
    $id = H::dbInsertId($db);
    H::dbClose($db);

    return $id;
}

function introInsertImageFormat(int $imageId, string $ext, int $filesize): void
{
    $db = introDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO image_format (image_id, ext, filesize) VALUES (%d, '%s', %d)", $imageId, H::dbEscape($db, $ext), $filesize));
    H::dbClose($db);
}

it('buckets storage-chart file extensions into Videos/Other/Formats groups', function (): void {
    $videoId = introInsertFakeImage('ct_intro_video_' . uniqid() . '.mp4');
    $otherId = introInsertFakeImage('ct_intro_other_' . uniqid() . '.zip');
    introInsertImageFormat($otherId, 'ct_intro_raw', 2048);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();

        $html = H::rawWebpage($page)->content();
        // The storage chart's own data is embedded verbatim as a JSON blob
        // (`const storage_details = {...}`) rather than rendered as plain
        // visible text, so this checks the raw response body directly.
        expect($html)->toContain('"Videos"');
        expect($html)->toContain('"MP4"');
        expect($html)->toContain('"Other"');
        expect($html)->toContain('"ZIP"');
        expect($html)->toContain('"Formats"');
        expect($html)->toContain('"CT_INTRO_RAW"');
    } finally {
        introDeleteImage($videoId);
        introDeleteImage($otherId);
    }
});

it('adds the cached filesystem cache size onto the storage chart when configured', function (): void {
    $snapshot = H::snapshotConfig(['add_cache_to_storage_chart', 'cache_sizes']);

    try {
        H::setConfigValue('add_cache_to_storage_chart', 'true');
        H::setConfigValue('cache_sizes', H::jsonEncode([['value' => 204800, 'time' => time()]]));

        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();

        $html = H::rawWebpage($page)->content();
        expect($html)->toContain('"Cache"');
        // 204800 / 1024 = 200.0 -- the exact filesize this test seeded.
        expect($html)->toContain('"filesize":200');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the latest Piwigo news message from a pre-seeded, still-fresh on-disk cache', function (): void {
    $snapshot = H::snapshotConfig(['show_piwigo_latest_news']);

    // Browser tests run as plain HTTP-driving PHP-CLI processes with no
    // app bootstrap of their own (unlike Integration tests, which
    // explicitly call Kernel::boot()) -- resolving a real, container-bound
    // Paths or calling Lang::langInfo() would either throw or return an
    // empty default here, so the app root is computed manually instead. It's the repo
    // root, one level *above* public/ -- public/*.php's own
    // Paths::fromRoot(dirname(__DIR__)) call resolves the same way, and
    // _data/ lives there, not under public/ (confirmed live: with a
    // public/_data/... path here, getLatestNews() never found this
    // seeded cache file and fell through to a real, slow piwigo.org
    // network fetch instead, which is exactly what seeding the cache is
    // meant to avoid). The lang code is the real one the admin dashboard
    // request itself resolves (fixture_admin's language is en_UK;
    // en_UK/common.po's own "X-Piwigo-Code" header is "en", confirmed by
    // reading that file directly rather than assumed).
    $root = dirname(__DIR__, 2) . '/';
    $cachePath = $root . '_data/cache/piwigo_latest_news-en.cache.php';
    $cacheDir = dirname($cachePath);
    $createdCacheDir = ! is_dir($cacheDir);
    if ($createdCacheDir) {
        mkdir($cacheDir, 0o777, true);
    }

    $news = [
        'id' => 987654,
        'subject' => 'CT Fresh Piwigo News ' . uniqid(),
        'posted_on' => time() - 3600,
        'posted' => 'a few hours ago',
        'url' => 'https://example.test/news/987654',
    ];
    file_put_contents($cachePath, serialize($news));
    touch($cachePath, time());

    try {
        H::setConfigValue('show_piwigo_latest_news', 'true');

        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Latest Piwigo news');
        $page->assertSee($news['subject']);
    } finally {
        @unlink($cachePath);
        H::restoreConfig($snapshot);
    }
});

/**
 * Bulk-inserts $count throwaway image rows in one round trip (a 5-column,
 * 10-value-each cross join generates up to 100000 distinct integers 0..99999
 * in a single INSERT ... SELECT) -- a 100000-row multi-VALUES literal would
 * be unwieldy and a 100000-iteration PHP loop would be one round trip per
 * row. None of these rows are linked into image_category, so every one of
 * them is also a genuine orphan.
 */
function introBulkInsertImages(int $count, string $marker): void
{
    $db = introDbConnect();
    $escapedMarker = H::dbEscape($db, $marker);
    H::dbQuery($db, sprintf("INSERT INTO images (file, path, hit, level)
         SELECT CONCAT('%s', n, '.jpg'), CONCAT('%s', n, '.jpg'), 0, 0
         FROM (
           SELECT (a.N + b.N*10 + c.N*100 + d.N*1000 + e.N*10000) AS n
           FROM (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
           , (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
           , (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c
           , (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d
           , (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) e
         ) nums
         WHERE n < %d", $escapedMarker, $escapedMarker, $count));
    H::dbClose($db);
}

function introDeleteMarkedImages(string $marker): void
{
    $db = introDbConnect();
    H::dbQuery($db, sprintf("DELETE FROM images WHERE file LIKE '%s%%'", H::dbEscape($db, $marker)));
    H::dbClose($db);
}

it('forces a real orphan recount via ImageService::countOrphans() once the gallery is "big" (>= 100000 photos)', function (): void {
    // AdminShell.php's own bootstrap-time orphan count only calls
    // countOrphans() "if the number of images is not huge" (< 100000) --
    // once nb_photos_total reaches 100000, it leaves PageState::nbOrphans
    // at its 0 default ("but has not been calculated on a big gallery, so
    // force it now" -- this class's own comment on the branch under test).
    // The ONLY way this page can show a nonzero orphan count under a big
    // gallery is IntroSubController::handle()'s own forced recompute, so a
    // real "Orphans" banner here is a genuine, differential proof that
    // branch ran -- not just that *some* orphan count happened to be
    // nonzero already.
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['count_orphans']);
    $marker = 'ct_intro_bignb_' . uniqid() . '_';

    $db = introDbConnect();
    $countRow = H::fetchAssocOrFail($db, 'SELECT COUNT(*) AS c FROM images');
    H::dbClose($db);
    $needed = max(1, 100000 - (int) $countRow['c']);

    introBulkInsertImages($needed, $marker);

    try {
        H::setConfigValue('count_orphans', null);

        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Orphans');
    } finally {
        introDeleteMarkedImages($marker);
        H::restoreConfig($snapshot);
    }
});

it('drops the storage-used decimal display once total disk usage exceeds 100GB', function (): void {
    // images.filesize/image_format.filesize are both `mediumint unsigned`
    // (max 16,777,215 KB, ~16GB) -- crossing the 100GB threshold this
    // branch checks for needs several max-sized rows, not one huge one.
    $page = H::loginAsAdmin($this);
    $db = introDbConnect();
    $marker = 'ct_intro_bigsize_' . uniqid();

    for ($i = 0; $i < 7; $i++) {
        H::dbQuery($db, sprintf("INSERT INTO images (file, path, hit, level, filesize) VALUES ('%s_%d.jpg', '%s_%d.jpg', 0, 0, 16777215)", $marker, $i, $marker, $i));
    }

    $totalRow = H::fetchAssocOrFail($db, 'SELECT (SELECT COALESCE(SUM(filesize),0) FROM images) + (SELECT COALESCE(SUM(filesize),0) FROM image_format) AS total_kb');
    H::dbClose($db);

    $totalKb = (float) $totalRow['total_kb'];
    $expectedGb = $totalKb / (1024.0 * 1024.0);
    expect($expectedGb)->toBeGreaterThan(100.0);
    // InstallationStats::getGeneralStatistics()'s disk_usage feeds the exact
    // same du_gb = disk_usage / (1024*1024) computation this asserts
    // against, so number_format() here with decimals forced to 0 must match
    // the rendered STORAGE_USED text byte-for-byte if (and only if) the
    // `$du_gb > 100` branch really set $du_decimals = 0.
    $expectedText = number_format($expectedGb, 0) . '&nbsp;GB';

    try {
        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();

        $html = H::rawWebpage($page)->content();
        expect($html)->toContain($expectedText);
    } finally {
        introDeleteMarkedImages($marker);
    }
});

function introSetUserColumn(int $userId, string $column, ?string $value): void
{
    $db = introDbConnect();
    if ($value === null) {
        H::dbQuery($db, sprintf('UPDATE user_infos SET %s = NULL WHERE user_id = %d', $column, $userId));
        H::dbClose($db);

        return;
    }
    H::dbQuery($db, sprintf("UPDATE user_infos SET %s = '%s' WHERE user_id = %d", $column, H::dbEscape($db, $value), $userId));
    H::dbClose($db);
}

it('shows the newsletter subscription promo panel for an account old enough with enough albums and photos', function (): void {
    // The fixture's own registration_date is stamped to the frozen test
    // "now" at build time (see RegenerateFixtureTest.php), so the "account
    // must be 2+ weeks old" half of this branch's condition needs a real,
    // deliberately-backdated row -- nb_cats/nb_images are already well over
    // the 3/30 thresholds from the regenerated fixture itself (152
    // categories, 131 photos), so only the date and the per-admin
    // 'show_newsletter_subscription' preference (persistently flipped to
    // false by this same file's own "hides the newsletter subscription
    // banner" test, with no cleanup of its own) need forcing here.
    $page = H::loginAsAdmin($this);
    $db = introDbConnect();

    $originalRegistration = H::fetchAssocOrFail($db, 'SELECT registration_date FROM user_infos WHERE user_id = 1')['registration_date'];
    $originalPreferences = H::fetchAssocOrFail($db, 'SELECT preferences FROM user_infos WHERE user_id = 1')['preferences'];
    $configSnapshot = H::snapshotConfig(['show_newsletter_subscription']);

    H::dbQuery($db, "UPDATE user_infos SET registration_date = '2020-01-01 00:00:00' WHERE user_id = 1");
    // JSON_SET()/JSON_OBJECT() are MySQL-only -- Postgres's own
    // jsonb_set() takes a `{key}` path array (not a `$.key` string) and
    // an already-jsonb new value, verified live.
    $preferencesExpr = $db instanceof mysqli
        ? "JSON_SET(COALESCE(preferences, JSON_OBJECT()), '\$.show_newsletter_subscription', TRUE)"
        : "jsonb_set(COALESCE(preferences, '{}'::jsonb), '{show_newsletter_subscription}', 'true'::jsonb)";
    H::dbQuery($db, "UPDATE user_infos SET preferences = {$preferencesExpr} WHERE user_id = 1");
    H::dbClose($db);

    try {
        H::setConfigValue('show_newsletter_subscription', 'true');

        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();

        $html = H::rawWebpage($page)->content();
        expect($html)->toContain('class="promote-newsletter"');
        expect($html)->toContain('value="fixture_admin@example.test"');
        // AdminUiHelper::getOldNewslettersBaseUrl() -- AppInfo::URL (the
        // fork-safe, RFC 2606 `.invalid` PEM-domain stand-in) + '/newsletter'.
        expect($html)->toContain('href="https://upstream.example.invalid/newsletter"');
    } finally {
        $originalRegistrationStr = is_string($originalRegistration) ? $originalRegistration : '2026-08-01 00:00:00';
        introSetUserColumn(1, 'registration_date', $originalRegistrationStr);
        introSetUserColumn(1, 'preferences', is_string($originalPreferences) ? $originalPreferences : null);
        H::restoreConfig($configSnapshot);
    }
});

// NOT exercised, deliberately: IntroSubController::handle()'s activity-chart
// "split days into circle sizes" while-loop (`$max_idx =
// array_search(max($diff_x), $diff_x, true); if ($max_idx === false) {
// break; }`) is a pure PHPStan by-ref/array_search-may-return-false
// narrowing guard, not real, reachable behavior: $diff_x only ever holds
// values built earlier in the SAME request from the SAME array (never
// re-fetched, never mutated by anything else concurrently), so
// max($diff_x) always returns one of $diff_x's own elements verbatim (same
// value, same type, whether still a float ratio or an already-replaced int
// -1 marker) -- a strict-mode array_search() for a value drawn from the
// very array being searched cannot legitimately return false. Confirmed by
// reading every assignment into $diff_x (IntroSubController.php ~372-392):
// no path ever produces a value that isn't already present in the array
// being searched. The `$split++`/`$split = 0` lines around it are real and
// already covered by this file's own "smooths the activity chart into size
// groups" test above (a real >120% jump), so only this one `break` is left
// uncovered.

it('skips malformed cached activity-week/day entries from a stale-but-still-"fresh" session without a fatal or warning', function (): void {
    // $_SESSION['cache_activity_last_weeks'] is DB-backed (PwgSession /
    // SessionService -- see Piwigo\Session\SessionRepository), keyed by the
    // exact same value PHP's own session cookie carries, so a real
    // malformed cache entry is reachable by writing directly into that row
    // -- no mock of IntroSubController itself, just genuine session-store
    // corruption (a real-world scenario: a stale row surviving a schema
    // change, or a half-written value from a crashed request). A raw curl
    // login (not the Playwright-driven H::loginAsAdmin(), which exposes no
    // cookie-jar/session-id access) mirrors this file's own
    // CatListPageRendererTest.php-style cookie-jar precedent.
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_intro_session_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

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

        $jarContents = file_get_contents($cookieJar);
        if ($jarContents === false || preg_match('/pwg_id\t([^\s]+)/', $jarContents, $m) !== 1) {
            throw new RuntimeException('Could not find the pwg_id session cookie in the cookie jar: ' . var_export($jarContents, true));
        }
        $sessionSuffix = $m[1];

        $db = introDbConnect();
        $sessionId = null;
        foreach (H::dbFetchAll($db, 'SELECT id FROM sessions') as $row) {
            $candidate = (string) $row['id'];
            if (str_ends_with($candidate, $sessionSuffix)) {
                $sessionId = $candidate;
                break;
            }
        }
        if ($sessionId === null) {
            H::dbClose($db);

            throw new RuntimeException('Could not find the DB-backed session row for cookie suffix: ' . $sessionSuffix);
        }

        // Frozen "now" (see Piwigo\Core\Env::now()) -- must match what the
        // live server considers fresh, not this test process's own real
        // wall-clock time, or the >300s staleness check would trigger a
        // real recompute instead of reading this malformed cache back.
        $frozenNow = getenv('PIWIGO_TEST_NOW');
        $frozenNow = $frozenNow !== false && $frozenNow !== '' ? $frozenNow : '2026-08-01T00:00:00';

        // PHP's "php" session serialize handler format is bare
        // `name|value` pairs concatenated with NO separator between them
        // (serialize()'s own output for a scalar already ends in `;`, and
        // for an array/object already ends in `}}` -- appending an extra
        // trailing `;` after serialize()'s own array output corrupts the
        // WHOLE session parse, silently wiping pwg_uid too, confirmed live
        // via session_decode() while building this test).
        $malformedValue = serialize([
            'calculated_on' => strtotime($frozenNow),
            'data' => [
                // A non-array $i -- hits `if (! is_array($i)) { continue; }`.
                0 => 'ct_intro_week_not_an_array',
                // An array $i whose own $j is non-array -- hits the inner
                // `if (! is_array($j)) { continue; }`.
                1 => [
                    0 => 'ct_intro_day_not_an_array',
                ],
            ],
        ]);
        $fragment = 'cache_activity_last_weeks|' . $malformedValue;

        H::dbQuery($db, sprintf("UPDATE sessions SET data = CONCAT(data, '%s') WHERE id = '%s'", H::dbEscape($db, $fragment), H::dbEscape($db, $sessionId)));
        H::dbClose($db);

        $html = $curl(H::baseUrl() . '/admin.php');

        foreach ([
            'Fatal error'       => '/Fatal error/i',
            'Parse error'       => '/Parse error/i',
            'Warning:'          => '/\bWarning:\s/',
            'Notice:'           => '/\bNotice:\s/',
            'Deprecated:'       => '/\bDeprecated:\s/',
            'Strict Standards:' => '/\bStrict Standards:\s/',
            'Stack trace:'      => '/Stack trace:/',
            'Uncaught'          => '/\bUncaught\s/',
        ] as $name => $pattern) {
            expect(preg_match($pattern, $html))->toBe(0, "Server error marker '{$name}' found in the admin.php response");
        }
        expect($html)->toContain('Piwigo Administration');
        // Both malformed entries were skipped via `continue` rather than
        // processed, so $activity_last_weeks ends up entirely empty -- no
        // real per-day activity count is ever rendered (contrast with this
        // file's own "smooths the activity chart..." test, which asserts
        // these exact patterns ARE present for genuine, well-formed data).
        expect(preg_match('/\d+ Activit(y|ies)/', $html))->toBe(0);
    } finally {
        @unlink($cookieJar);
    }
});
