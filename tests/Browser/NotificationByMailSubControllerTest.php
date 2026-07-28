<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\NotificationByMailSubController (admin.php?page=
 * notification_by_mail) -- the 3-tab (param/subscribe/send) notification
 * management page, plus the always-runs-on-a-plain-GET
 * insertNewDataUserMailNotification() self-heal. Fixture gives 2
 * pre-seeded `user_mail_notification` rows: user 1 (fixture_admin,
 * check_key 'abcdef1234567890', enabled) and user 3 (regular_user,
 * check_key 'ghijkl9876543210', disabled) -- real, restorable state to
 * drive the subscribe/unsubscribe/send actions without needing new users.
 *
 * Deliberately skips the timeout/"must repost" branch
 * (NotificationByMailSender::isSendmailTimeout(), ~20 lines) -- it fires
 * only when real mail-sending exceeds an internal wall-clock threshold,
 * not practically triggerable from a fast, deterministic test.
 */
function nbmDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function nbmDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

/** @return array{check_key: string, enabled: int, last_send: ?string}|null */
function nbmUserMailNotificationRow(int $userId): ?array
{
    $db = nbmDbConnect();
    $result = $db->query(sprintf(
        'SELECT check_key, enabled, last_send FROM %suser_mail_notification WHERE user_id = %d',
        nbmDbPrefix(),
        $userId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    if (! is_array($row) || ! is_string($row['check_key'] ?? null)) {
        return null;
    }

    return [
        'check_key' => $row['check_key'],
        'enabled' => (int) $row['enabled'],
        'last_send' => is_string($row['last_send'] ?? null) ? $row['last_send'] : null,
    ];
}

/** @param array{check_key: string, enabled: int, last_send: ?string}|null $row */
function nbmSetUserMailNotificationRow(int $userId, ?array $row): void
{
    $db = nbmDbConnect();
    $db->query(sprintf('DELETE FROM %suser_mail_notification WHERE user_id = %d', nbmDbPrefix(), $userId));
    if ($row !== null) {
        $lastSend = $row['last_send'] === null ? 'NULL' : "'" . $db->real_escape_string($row['last_send']) . "'";
        $db->query(sprintf(
            "INSERT INTO %suser_mail_notification (user_id, check_key, enabled, last_send) VALUES (%d, '%s', %d, %s)",
            nbmDbPrefix(),
            $userId,
            $db->real_escape_string($row['check_key']),
            $row['enabled'],
            $lastSend
        ));
    }
    $db->close();
}

/**
 * @param  array<string, mixed>  $fields
 * @return array{status: int, body: string}
 */
function nbmPost(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $mode, array $fields): array
{
    return H::adminPost($page, '/admin.php?page=notification_by_mail&mode=' . $mode, $fields);
}

it('renders the send tab by default', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=notification_by_mail');
    $page->assertNoJavaScriptErrors();
});

it('renders the param tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=notification_by_mail&mode=param');
    $page->assertNoJavaScriptErrors();
});

it('renders the subscribe tab', function (): void {
    $page = H::loginAsAdmin($this);
    // Deliberately not navigateOk(): notification_by_mail.tpl's subscribe
    // tab legitimately renders the literal copy "Warning: subscribing or
    // unsubscribing will send mails to users" -- a false positive for
    // navigateOk()'s generic server-error-marker body scan, not a real
    // PHP warning.
    H::rawWebpage($page)->navigate(H::baseUrl() . '/admin.php?page=notification_by_mail&mode=subscribe');
    $page->assertNoJavaScriptErrors();
});

it('updates notification parameters via the param tab', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(['nbm_send_html_mail', 'nbm_send_detailed_content', 'nbm_send_recent_post_dates', 'nbm_send_mail_as']);

    try {
        $result = nbmPost($page, 'param', [
            'pwg_token' => $token,
            'param_submit' => '1',
            'nbm_send_html_mail' => 'true',
            'nbm_send_detailed_content' => 'true',
            'nbm_send_recent_post_dates' => 'false',
            'nbm_send_mail_as' => 'notify@example.test',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect(H::configValue('nbm_send_html_mail'))->toBe(json_encode('true'));
        expect(H::configValue('nbm_send_recent_post_dates'))->toBe(json_encode('false'));
        expect(H::configValue('nbm_send_mail_as'))->toBe(json_encode('notify@example.test'));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('unsubscribes a user from category-based notifications', function (): void {
    // NotificationByMailSender::doSubscribeUnsubscribeNotificationByMail()
    // only flips `enabled` for a user it could actually email a
    // confirmation to ($doUpdate stays true only when mailAddress === ''
    // -- mail-send skipped entirely -- or the real send succeeded); user 1
    // (fixture_admin) has a real, but undeliverable, fixture email
    // address, so unsubscribing it would non-deterministically depend on
    // whether the local MTA accepts-then-bounces or outright rejects.
    // User 4 (power_user) has no email at all, guaranteeing the
    // deterministic "mail-send skipped" path -- a temporary row, not a
    // fixture mutation, since neither user 2 nor user 4 has one already.
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    nbmSetUserMailNotificationRow(4, ['check_key' => 'ct00unsubscrib', 'enabled' => 1, 'last_send' => null]);

    try {
        $result = nbmPost($page, 'subscribe', [
            'pwg_token' => $token,
            'falsify' => '1',
            'cat_true' => ['ct00unsubscrib'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        $updated = nbmUserMailNotificationRow(4);
        expect($updated)->not->toBeNull();
        assert($updated !== null);
        expect($updated['enabled'])->toBe(0);
    } finally {
        nbmSetUserMailNotificationRow(4, null);
    }
});

it('subscribes a user to category-based notifications', function (): void {
    // User 3 (regular_user) has no email either -- same deterministic
    // "mail-send skipped" reasoning as the unsubscribe test above; this
    // one reuses its real pre-seeded (disabled) fixture row instead of a
    // temporary one just to also cover the "existing row" shape.
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $snapshot = nbmUserMailNotificationRow(3);
    expect($snapshot)->not->toBeNull();
    assert($snapshot !== null);

    try {
        $result = nbmPost($page, 'subscribe', [
            'pwg_token' => $token,
            'trueify' => '1',
            'cat_false' => [$snapshot['check_key']],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        $updated = nbmUserMailNotificationRow(3);
        expect($updated)->not->toBeNull();
        assert($updated !== null);
        expect($updated['enabled'])->toBe(1);
    } finally {
        nbmSetUserMailNotificationRow(3, $snapshot);
    }
});

it('sends a notification email to selected users', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $snapshot = nbmUserMailNotificationRow(1);
    expect($snapshot)->not->toBeNull();
    assert($snapshot !== null);

    try {
        $result = nbmPost($page, 'send', [
            'pwg_token' => $token,
            'send_submit' => '1',
            'send_selection' => [$snapshot['check_key']],
            'send_customize_mail_content' => 'CT notification content ' . uniqid(),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        nbmSetUserMailNotificationRow(1, $snapshot);
    }
});

it('rejects a mutating submission with a missing CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = nbmPost($page, 'param', ['param_submit' => '1']);

    expect($result['status'])->toBe(400);
});

it('self-heals a missing notification-subscription row on a plain page load', function (): void {
    $page = H::loginAsAdmin($this);

    $snapshot = nbmUserMailNotificationRow(1);
    expect($snapshot)->not->toBeNull();
    assert($snapshot !== null);
    nbmSetUserMailNotificationRow(1, null);

    try {
        $page = H::navigateOk($page, '/admin.php?page=notification_by_mail');
        $page->assertNoJavaScriptErrors();

        $recreated = nbmUserMailNotificationRow(1);
        expect($recreated)->not->toBeNull();
    } finally {
        nbmSetUserMailNotificationRow(1, $snapshot);
    }
});
