<?php

declare(strict_types=1);

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

/** @return array<int, array{id: string, date_available: ?string}> */
function feedAllImageDates(mysqli $db, string $prefix): array
{
    $result = $db->query(sprintf('SELECT id, date_available FROM %simages', $prefix));
    if (! $result instanceof mysqli_result) {
        throw new RuntimeException('SELECT id, date_available FROM images failed');
    }

    $rows = [];
    while (is_array($row = $result->fetch_assoc())) {
        $dateAvailable = $row['date_available'];
        $rows[] = ['id' => (string) $row['id'], 'date_available' => is_string($dateAvailable) ? $dateAvailable : null];
    }

    return $rows;
}

function feedDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function feedDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

/** @return array{status: int, headers: string, body: string} */
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

    return ['status' => $status, 'headers' => strtolower($headers), 'body' => $body];
}

const FEED_FIXED_DATE = '2020-06-15 12:00:00';

it('serves a well-formed RSS2 XML feed with the real Content-Type header and exactly 1 recent-post-date item', function (): void {
    // 1. Guarantee at least one real, visible image exists, regardless of
    // ambient DB state.
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Feed Test Album ' . uniqid()]);
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
    $prefix = feedDbPrefix();
    $originalDates = feedAllImageDates($db, $prefix);
    expect($originalDates)->not->toBe([]);
    $db->query(sprintf("UPDATE %simages SET date_available = '%s'", $prefix, FEED_FIXED_DATE));
    $db->close();
    CachePools::notifications()->clear();

    try {
        $result = feedRawGet();

        expect($result['status'])->toBe(200);
        expect($result['headers'])->toContain('content-type: application/rss+xml')
            ->and($result['headers'])->toContain('charset=utf-8')
            ->and($result['headers'])->toContain('content-disposition: inline; filename=feed.xml');

        $xml = simplexml_load_string($result['body']);
        if ($xml === false) {
            throw new \RuntimeException('feed body is not well-formed XML: ' . $result['body']);
        }

        expect($xml->getName())->toBe('rss');
        expect((string) $xml['version'])->toBe('2.0');

        $channel = $xml->channel;
        expect($channel)->not->toBeNull();
        // Anonymous, non-personalized feed.php always switches identity to
        // guest (FeedController::__invoke()) -- the title always reflects
        // that, regardless of the configurable gallery_title.
        expect((string) $channel->title)->toContain(' (as guest)');

        $items = $channel->item;
        expect(count($items))->toBe(1);

        $item = $items[0];
        // guid is 'pics-' . the literal date_available string
        // (FeedController's own rss_items construction), htmlspecialchars()'d
        // -- a space/colon aren't special chars, so this is an exact match.
        expect((string) $item->guid)->toBe('pics-' . FEED_FIXED_DATE);
        expect((string) $item->guid['isPermaLink'])->toBe('false');

        // getTitleRecentPostDate(): "%d new photo(s) (<Month> <day>)" --
        // FEED_FIXED_DATE's day-of-month is 15.
        expect((string) $item->title)->toMatch('/^\d+ new photos? \(\S+ 15\)$/');

        // getHtmlDescriptionRecentPostDate()'s own real text.
        expect((string) $item->description)->toContain('Recent photos');

        // the recent-post-date link always chronologically filters on
        // "posted" (FeedController's own chronology_field=posted param) --
        // rendered as a pretty/rewritten URL segment
        // ('posted-monthly-calendar-<date>'), not a literal
        // chronology_field=posted query string.
        expect((string) $item->link)->toContain('posted-monthly-calendar-' . substr(FEED_FIXED_DATE, 0, 10));
    } finally {
        // 3. Restore every image's original date_available.
        $db = feedDbConnect();
        foreach ($originalDates as $row) {
            $value = $row['date_available'];
            if ($value === null) {
                $db->query(sprintf('UPDATE %simages SET date_available = NULL WHERE id = %d', $prefix, (int) $row['id']));
            } else {
                $db->query(sprintf(
                    "UPDATE %simages SET date_available = '%s' WHERE id = %d",
                    $prefix,
                    $db->real_escape_string($value),
                    (int) $row['id']
                ));
            }
        }
        $db->close();
        CachePools::notifications()->clear();
    }
});

it('returns a 404 page-not-found for a well-formed but unknown personal feed id', function (): void {
    $unknownFeedId = bin2hex(random_bytes(25)); // 50 hex chars -- matches /^[0-9a-z]{50}$/i but never inserted
    expect(H::httpStatus('feed.php?feed=' . $unknownFeedId))->toBe(404);
    expect(H::httpBody('feed.php?feed=' . $unknownFeedId))->toContain('Page not found');
});
