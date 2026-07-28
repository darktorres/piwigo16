<?php

declare(strict_types=1);

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
function introDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function introDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

/** Inserts an unlinked (orphan) image row and returns its id. */
function introInsertOrphanImage(): int
{
    $db = introDbConnect();
    $file = 'ct_orphan_' . uniqid() . '.jpg';
    $db->query(sprintf(
        "INSERT INTO %simages (file, path, hit, level) VALUES ('%s', '%s', 0, 0)",
        introDbPrefix(),
        $db->real_escape_string($file),
        $db->real_escape_string($file)
    ));
    $id = (int) $db->insert_id;
    $db->close();

    return $id;
}

function introDeleteImage(int $imageId): void
{
    $db = introDbConnect();
    $db->query(sprintf('DELETE FROM %simages WHERE id = %d', introDbPrefix(), $imageId));
    $db->close();
}

function introSetCategoryVisible(int $categoryId, bool $visible): void
{
    $db = introDbConnect();
    $db->query(sprintf(
        'UPDATE %scategories SET visible = %d WHERE id = %d',
        introDbPrefix(),
        $visible ? 1 : 0,
        $categoryId
    ));
    $db->close();
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

/** @param array<int, array{object: string, action: string, daysAgo: int}> $rows */
function introInsertActivityRows(array $rows): void
{
    $db = introDbConnect();
    foreach ($rows as $row) {
        $db->query(sprintf(
            "INSERT INTO %sactivity (object, object_id, action, session_idx, occured_on) VALUES ('%s', 1, '%s', 'ct_intro_session', DATE_SUB(NOW(), INTERVAL %d DAY))",
            introDbPrefix(),
            $db->real_escape_string($row['object']),
            $db->real_escape_string($row['action']),
            $row['daysAgo']
        ));
    }
    $db->close();
}

function introDeleteActivityRows(): void
{
    $db = introDbConnect();
    $db->query(sprintf("DELETE FROM %sactivity WHERE session_idx = 'ct_intro_session'", introDbPrefix()));
    $db->close();
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
    introInsertActivityRows([
        ['object' => 'photo', 'action' => 'ct_intro_a', 'daysAgo' => 0],
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
    $db->query(sprintf(
        "INSERT INTO %simages (file, path, hit, level) VALUES ('%s', '%s', 0, 0)",
        introDbPrefix(),
        $db->real_escape_string(basename($path)),
        $db->real_escape_string($path)
    ));
    $id = (int) $db->insert_id;
    $db->close();

    return $id;
}

function introInsertImageFormat(int $imageId, string $ext, int $filesize): void
{
    $db = introDbConnect();
    $db->query(sprintf(
        "INSERT INTO %simage_format (image_id, ext, filesize) VALUES (%d, '%s', %d)",
        introDbPrefix(),
        $imageId,
        $db->real_escape_string($ext),
        $filesize
    ));
    $db->close();
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
    // explicitly call CurrentPaths::set()) -- CurrentPaths::get()/
    // Lang::langInfo() would either throw or return an empty default
    // here, so the app root is computed manually instead. It's the repo
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
