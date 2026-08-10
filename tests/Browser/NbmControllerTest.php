<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\NbmController (public nbm.php) -- the public
 * "notification by mail" subscribe/unsubscribe confirmation link target
 * (distinct from admin/notification_by_mail.php). Exercises real DB state
 * changes on piwigo_user_mail_notification via the exact 16-char check_key
 * regex nbm.php validates against, not just "the page renders".
 *
 * Uses the fixture's 'regular_user' row (check_key 'ghijkl9876543210',
 * seeded disabled/enabled=0, mail_address NULL) rather than
 * 'fixture_admin's own row ('abcdef1234567890') -- a real email address on
 * the row (fixture_admin has one) would route through
 * NotificationByMailSender's actual mail-sending branch, whose real
 * success/failure depends on this environment's mail transport; regular_user
 * has no email, so the DB update always happens regardless of mail
 * infrastructure. Both nbm.php calls below are round-tripped (subscribe
 * then unsubscribe) so the fixture's own seeded enabled=0 state is restored
 * afterward.
 */

/** Reads the `enabled` column for a given check_key, or null if no such row exists. */
function nbmEnabledForKey(string $checkKey): ?int
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT enabled FROM user_mail_notification WHERE check_key = '%s'", H::dbEscape($db, $checkKey)));
    H::dbClose($db);

    // enabled is a genuine boolean column on Postgres -- pg_fetch_assoc()
    // represents it as 't'/'f', which a naive (int) cast silently
    // mishandles.
    return is_array($row) && isset($row['enabled']) ? (H::dbToBool($row['enabled']) ? 1 : 0) : null;
}

const NBM_REGULAR_USER_KEY = 'ghijkl9876543210';

it('subscribes then unsubscribes a real check_key, flipping `enabled` both ways', function (): void {
    // Self-contained round trip (matches TagCrudTest's own full-lifecycle
    // style) rather than 2 order-dependent tests -- restores the fixture's
    // seeded enabled=0 state by the end either way.
    expect(nbmEnabledForKey(NBM_REGULAR_USER_KEY))->toBe(0);

    $page = H::gotoOk($this, '/nbm.php?subscribe=' . NBM_REGULAR_USER_KEY);
    $page->assertNoJavaScriptErrors();
    expect(nbmEnabledForKey(NBM_REGULAR_USER_KEY))->toBe(1);

    $page = H::navigateOk($page, '/nbm.php?unsubscribe=' . NBM_REGULAR_USER_KEY);
    $page->assertNoJavaScriptErrors();
    expect(nbmEnabledForKey(NBM_REGULAR_USER_KEY))->toBe(0);
});

it('shows "Unknown identifier" and leaves the DB untouched for a malformed identifier', function (): void {
    $before = nbmEnabledForKey(NBM_REGULAR_USER_KEY);

    // 20 chars and containing punctuation -- fails nbm.php's own
    // /^[A-Za-z0-9]{16}$/ pattern for both subscribe and unsubscribe.
    $page = H::gotoOk($this, '/nbm.php?subscribe=not-a-valid-key!!!!');
    $page->assertSee('Unknown identifier');

    expect(nbmEnabledForKey(NBM_REGULAR_USER_KEY))->toBe($before);
});

it('shows "Unknown identifier" when neither subscribe nor unsubscribe is present', function (): void {
    $page = H::gotoOk($this, '/nbm.php');
    $page->assertSee('Unknown identifier');
    $page->assertTitleContains('Notification');
});
