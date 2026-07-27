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
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $result = $db->query(sprintf(
        "SELECT id, user_id, last_check FROM %suser_feed WHERE id = '%s'",
        $prefix,
        $db->real_escape_string($feedId)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

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
        throw new \RuntimeException('expected a real user_feed row for feed id ' . $feedId);
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
        throw new \RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    expect($rowBefore['lastCheck'])->toBeNull();

    $page = H::navigateOk($page, '/feed.php?feed=' . $feedId);
    $page->assertNoJavaScriptErrors();

    $rowAfter = userFeedRow($feedId);
    if ($rowAfter === null) {
        throw new \RuntimeException('expected a real user_feed row for feed id ' . $feedId);
    }
    expect($rowAfter['lastCheck'])->not->toBeNull();
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
