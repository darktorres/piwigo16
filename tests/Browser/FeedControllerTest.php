<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Cache\CachePools;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\FeedController (feed.php) -- generates the RSS2 gallery
 * feed. Asserts on the real response Content-Type header and the actual
 * XML structure/item count, not just "the page loads".
 *
 * NotificationService::getRecentPostDates() groups images by the exact
 * literal `date_available` value (`GROUP BY date_available`) -- with
 * PIWIGO_TEST_NOW frozen, every image ever uploaded through this suite's
 * own test-mode requests already shares one identical `date_available`
 * (confirmed live: this fixture's own 5 seeded photos all carry
 * '2026-08-01 00:00:00', matching Env::now()'s frozen value read by
 * Admin\Upload\UploadService::addUploadedFile()). In practice though, the
 * live dev DB this suite runs against also carries stray rows from
 * Integration-test runs that hand-set arbitrary literal dates (e.g.
 * CalendarRepositoryTest) directly, unrelated to this frozen clock -- so
 * the *number* of distinct dates (and therefore <item> count) is not
 * reliably 1 without actively normalizing it first. This test forces that
 * determinism itself (temporarily collapsing every image's date_available
 * to one fixed value, matching the same "direct-DB-fixture-manipulation,
 * then restore" shape BrowserTestHelpers::setCategoryPrivate()/
 * freezeImageHits() already establish -- done locally here since
 * BrowserTestHelpers.php itself is out of scope for this change) rather
 * than asserting against whatever the shared dev DB happens to currently
 * contain.
 */

/**
 * @return array<int, array{id: string, date_available: ?string}>
 */
function feedAllImageDates(mysqli|Connection $db): array
{
    $fetchedRows = H::dbFetchAll($db, 'SELECT id, date_available FROM images');

    $rows = [];
    foreach ($fetchedRows as $row) {
        $dateAvailable = $row['date_available'];
        $rows[] = [
            'id' => (string) $row['id'],
            'date_available' => is_string($dateAvailable) ? $dateAvailable : null,
        ];
    }

    return $rows;
}

function feedDbConnect(): mysqli|Connection
{
    return H::connect();
}

/**
 * @return array{status: int, headers: string, body: string}
 */
function feedRawGet(string $query = ''): array
{
    $ch = curl_init(H::baseUrl() . '/feed.php' . $query);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    if (! is_string($response)) {
        throw new RuntimeException('curl_exec returned no response');
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    [$headers, $body] = explode("\r\n\r\n", $response, 2) + ['', ''];

    return [
        'status' => $status,
        'headers' => strtolower($headers),
        'body' => $body,
    ];
}

/**
 * Extracts the 50-char feed id from the U_FEED href notification.tpl renders.
 */
function feedExtractFeedId(string $html): string
{
    if (preg_match('/feed\.php\?feed=([0-9A-Za-z]{50})/', $html, $matches) !== 1) {
        throw new RuntimeException('Could not find a feed.php?feed=<id> link in: ' . $html);
    }

    return $matches[1];
}

/**
 * @return array{lastCheck: ?string}|null
 */
function feedUserFeedRow(string $feedId): ?array
{
    $db = feedDbConnect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT last_check FROM user_feed WHERE id = '%s'", H::dbEscape($db, $feedId)));
    H::dbClose($db);

    if (! is_array($row)) {
        return null;
    }

    $lastCheck = $row['last_check'];

    return [
        'lastCheck' => is_string($lastCheck) ? $lastCheck : null,
    ];
}

/**
 * Logs into a real curl cookie jar (ws.php pwg.session.login), mirroring
 * BrowserTestHelpers::uploadPhotoViaApi()'s own cookie-jar-login shape --
 * needed here for a real, non-guest *session* (rather than an
 * un-authenticated plain curl GET, which is already indistinguishable
 * from a guest) hitting feed.php directly, outside of pest-plugin-
 * browser's own Playwright context (which has no cookie-jar access for
 * reuse against a raw curl request).
 * @return non-empty-string
 */
function feedAdminCookieJar(): string
{
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_feed_cookies_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    $ch = curl_init(H::baseUrl() . '/ws.php?format=json');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'method' => 'pwg.session.login',
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
    ]);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_exec($ch);
    unset($ch);

    return $cookieJar;
}

/**
 * @param non-empty-string $cookieJar
 * @return array{status: int, headers: string, body: string}
 */
function feedRawGetWithCookies(string $cookieJar, string $query = ''): array
{
    $ch = curl_init(H::baseUrl() . '/feed.php' . $query);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    $response = curl_exec($ch);
    if (! is_string($response)) {
        throw new RuntimeException('curl_exec returned no response');
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    [$headers, $body] = explode("\r\n\r\n", $response, 2) + ['', ''];

    return [
        'status' => $status,
        'headers' => strtolower($headers),
        'body' => $body,
    ];
}

const FEED_FIXED_DATE = '2020-06-15 12:00:00';

it('serves a well-formed RSS2 XML feed with the real Content-Type header and exactly 1 recent-post-date item', function (): void {
    // 1. Guarantee at least one real, visible image exists, regardless of
    // ambient DB state.
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Feed Test Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Feed Test Photo');
    H::uploadPhotoViaApi($image, $albumId, 'Feed Test Photo');
    @unlink($image);

    // 2. Force every image's date_available to one fixed value so the
    // feed's recent-post-dates grouping collapses to exactly 1 date,
    // saving the original values to restore afterward.
    $db = feedDbConnect();
    $originalDates = feedAllImageDates($db);
    expect($originalDates)
        ->not->toBe([]);
    H::dbQuery($db, sprintf("UPDATE images SET date_available = '%s'", FEED_FIXED_DATE));
    H::dbClose($db);
    CachePools::notifications()->clear();

    try {
        $result = feedRawGet();

        expect($result['status'])->toBe(200);
        expect($result['headers'])->toContain('content-type: application/rss+xml')
            ->and($result['headers'])->toContain('charset=utf-8')
            ->and($result['headers'])->toContain('content-disposition: inline; filename=feed.xml');

        $xml = simplexml_load_string($result['body']);
        if ($xml === false) {
            throw new RuntimeException('feed body is not well-formed XML: ' . $result['body']);
        }

        expect($xml->getName())
            ->toBe('rss');
        expect((string) $xml['version'])->toBe('2.0');

        $channel = $xml->channel;
        expect($channel)
            ->not->toBeNull();
        // Anonymous, non-personalized feed.php always switches identity to
        // guest (FeedController::__invoke()) -- the title always reflects
        // that, regardless of the configurable gallery_title.
        expect((string) $channel->title)
            ->toContain(' (as guest)');

        $items = $channel->item;
        expect(count($items))
            ->toBe(1);

        $item = $items[0];
        // guid is 'pics-' . the literal date_available string
        // (FeedController's own rss_items construction), htmlspecialchars()'d
        // -- a space/colon aren't special chars, so this is an exact match.
        expect((string) $item->guid)
            ->toBe('pics-' . FEED_FIXED_DATE);
        expect((string) $item->guid['isPermaLink'])->toBe('false');

        // getTitleRecentPostDate(): "%d new photo(s) (<Month> <day>)" --
        // FEED_FIXED_DATE's day-of-month is 15.
        expect((string) $item->title)
            ->toMatch('/^\d+ new photos? \(\S+ 15\)$/');

        // getHtmlDescriptionRecentPostDate()'s own real text.
        expect((string) $item->description)
            ->toContain('Recent photos');

        // the recent-post-date link always chronologically filters on
        // "posted" (FeedController's own chronology_field=posted param) --
        // rendered as a pretty/rewritten URL segment
        // ('posted-monthly-calendar-<date>'), not a literal
        // chronology_field=posted query string.
        expect((string) $item->link)
            ->toContain('posted-monthly-calendar-' . substr(FEED_FIXED_DATE, 0, 10));
    } finally {
        // 3. Restore every image's original date_available.
        $db = feedDbConnect();
        foreach ($originalDates as $row) {
            $value = $row['date_available'];
            if ($value === null) {
                H::dbQuery($db, sprintf('UPDATE images SET date_available = NULL WHERE id = %d', (int) $row['id']));
            } else {
                H::dbQuery($db, sprintf("UPDATE images SET date_available = '%s' WHERE id = %d", H::dbEscape($db, $value), (int) $row['id']));
            }
        }
        H::dbClose($db);
        CachePools::notifications()->clear();
    }
});

it('returns a 404 page-not-found for a well-formed but unknown personal feed id', function (): void {
    $unknownFeedId = bin2hex(random_bytes(25)); // 50 hex chars -- matches /^[0-9a-z]{50}$/i but never inserted
    expect(H::httpStatus('feed.php?feed=' . $unknownFeedId))->toBe(404);
    expect(H::httpBody('feed.php?feed=' . $unknownFeedId))->toContain('Page not found');
});

/**
 * Closes the "feed owner differs from the request's own current user"
 * branch (lines ~67-71, plus private userService() itself, line ~41): an
 * admin-owned personal feed token, fetched by a genuinely anonymous
 * (guest-identity) visitor, forces this request's CurrentUser over to the
 * feed's real owner (buildUser() + CurrentUser::set()) so the feed
 * content reflects the admin's own view, not the guest's -- distinct from
 * NotificationControllerTest.php's own "updates last_check" test, which
 * always re-fetches with the SAME admin session that minted the id (so
 * `$feed_row['userId'] !== CurrentUser::get()->id->value` is never true
 * there).
 */
it('switches the current user to a personal feed\'s real owner when fetched anonymously', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/notification.php');
    $feedId = feedExtractFeedId(H::rawWebpage($page)->content());

    // Genuinely anonymous curl GET -- no cookie jar at all, unlike the
    // admin session that minted $feedId above.
    $result = feedRawGet('?feed=' . $feedId);

    expect($result['status'])->toBe(200);
    $xml = simplexml_load_string($result['body']);
    if ($xml === false) {
        throw new RuntimeException('feed body is not well-formed XML: ' . $result['body']);
    }
    // rss_title's " (as <username>)" suffix reflects the feed OWNER
    // (fixture_admin), not the anonymous requester's own guest identity.
    expect((string) $xml->channel->title)
        ->toContain(' (as ' . H::ADMIN_USER . ')');
});

/**
 * Closes the "no feed token, but the request arrived with a real
 * non-guest session" branch (lines ~75-78): the very first
 * "well-formed RSS2 feed" test above already exercises the `$feed_id ===
 * ''` path, but via a genuinely anonymous curl GET (no cookie jar) --
 * already-guest, so `! isAGuest()` is false there and this identity-reset
 * never actually runs. A real logged-in cookie jar (ws.php
 * pwg.session.login) hitting bare feed.php (no `feed` param) is the only
 * way to make `! isAGuest()` true going into this branch.
 */
it('resets an authenticated session back to guest identity for the generic (tokenless) feed', function (): void {
    $cookieJar = feedAdminCookieJar();

    try {
        $result = feedRawGetWithCookies($cookieJar);

        expect($result['status'])->toBe(200);
        $xml = simplexml_load_string($result['body']);
        if ($xml === false) {
            throw new RuntimeException('feed body is not well-formed XML: ' . $result['body']);
        }
        // Same guest-identity title suffix the anonymous test above
        // asserts -- proving the admin session got reset for this
        // request, not just that an anonymous request happens to already
        // be guest.
        expect((string) $xml->channel->title)
            ->toContain(' (as guest)');
    } finally {
        @unlink($cookieJar);
    }
});

/**
 * Closes the periodic last_check "touch" branch (lines ~140-147): with
 * `image_only` forcing `$news` to stay the empty array it's initialized
 * to (never even calling notificationService->news()), the
 * `count($news) === 0` condition is deterministic regardless of the
 * live dev DB's own ambient comment/upload history -- unlike
 * NotificationControllerTest.php's own "updates last_check" test, whose
 * pass/fail is silent either way this branch or the real-news branch
 * (lines ~108-136) is the one that actually sets last_check.
 */
it('touches last_check via the periodic-refresh path (not real news) when image_only forces an empty news list', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/notification.php');
    $feedId = feedExtractFeedId(H::rawWebpage($page)->content());

    $rowBefore = feedUserFeedRow($feedId);
    if ($rowBefore === null) {
        throw new RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    expect($rowBefore['lastCheck'])->toBeNull();

    $result = feedRawGet('?feed=' . $feedId . '&image_only');
    expect($result['status'])->toBe(200);

    $rowAfter = feedUserFeedRow($feedId);
    if ($rowAfter === null) {
        throw new RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    expect($rowAfter['lastCheck'])->not->toBeNull();
});
