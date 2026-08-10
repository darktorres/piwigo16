<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\NotificationController (notification.php) -- mints a
 * new per-user feed subscription (a piwigo_user_feed row) and shows its
 * URL. Asserts the real DB row this creates, not just that the page
 * renders a link.
 */

/** @return array{id: string, userId: int, lastCheck: ?string}|null */
function userFeedRow(string $feedId): ?array
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT id, user_id, last_check FROM user_feed WHERE id = '%s'", H::dbEscape($db, $feedId)));
    H::dbClose($db);

    if (! is_array($row)) {
        return null;
    }

    $lastCheck = $row['last_check'];

    return [
        'id' => (string) $row['id'],
        'userId' => (int) $row['user_id'],
        'lastCheck' => is_string($lastCheck) ? $lastCheck : null,
    ];
}

/** Extracts the 50-char feed id from the U_FEED href notification.tpl renders. */
function extractFeedId(string $html): string
{
    if (preg_match('/feed\.php\?feed=([0-9A-Za-z]{50})/', $html, $matches) !== 1) {
        throw new RuntimeException('Could not find a feed.php?feed=<id> link in: ' . $html);
    }

    return $matches[1];
}

it('creates a real per-user feed subscription row with no last_check yet', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/notification.php');

    $html = H::rawWebpage($page)->content();
    $feedId = extractFeedId($html);

    $row = userFeedRow($feedId);
    if ($row === null) {
        throw new RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    expect($row['userId'])->toBe(1); // fixture_admin's real user id
    expect($row['lastCheck'])->toBeNull();

    $page->assertTitleContains('Notification');
    $page->assertPresent('body#theNotificationPage');
});

it('updates last_check once the feed is actually fetched', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/notification.php');
    $feedId = extractFeedId(H::rawWebpage($page)->content());

    $rowBefore = userFeedRow($feedId);
    if ($rowBefore === null) {
        throw new RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    expect($rowBefore['lastCheck'])->toBeNull();

    $page = H::navigateOk($page, '/feed.php?feed=' . $feedId);
    $page->assertNoJavaScriptErrors();

    $rowAfter = userFeedRow($feedId);
    if ($rowAfter === null) {
        throw new RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    expect($rowAfter['lastCheck'])->not->toBeNull();
});

/**
 * Closes the guest branch (lines ~59-61): for a guest visitor,
 * U_FEED_IMAGE_ONLY is the bare feed.php root URL (no `?feed=` query at
 * all -- feed.php's own guest-identity default already forces
 * image_only), distinct from the logged-in branch's
 * `feed_url . '&amp;image_only'` shape the 3 tests above exercise via
 * H::loginAsAdmin().
 */
it('gives a guest visitor a bare feed.php URL (no query string) for the image-only link', function (): void {
    $html = H::httpBody('/notification.php');
    $feedId = extractFeedId($html);

    $row = userFeedRow($feedId);
    if ($row === null) {
        throw new RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    // Config::guestId()'s real user id, not the fixture admin's.
    expect($row['userId'])->not->toBe(1);

    // getRootUrl() resolves to '' for a plain root-level page like
    // notification.php (no gallery SectionContextRegistry context, 0
    // RequestMountDepth), so U_FEED_IMAGE_ONLY is the bare relative
    // "feed.php" href -- no leading slash/domain, no query string at all.
    expect($html)->toContain('href="feed.php">')
        ->and($html)->toContain('feed.php?feed=' . $feedId);
});

it('mints a distinct feed id on each visit', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/notification.php');
    $firstId = extractFeedId(H::rawWebpage($page)->content());

    $page = H::navigateOk($page, '/notification.php');
    $secondId = extractFeedId(H::rawWebpage($page)->content());

    expect($secondId)->not->toBe($firstId);
    expect(userFeedRow($firstId))->not->toBeNull();
    expect(userFeedRow($secondId))->not->toBeNull();
});
