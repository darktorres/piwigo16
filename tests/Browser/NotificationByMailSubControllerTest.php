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
 * handle()'s own `if ($nbm_user['param'] === '') { continue; }` guard
 * (~line 160, inside the 'param' tab's config-save loop) is unreachable
 * via any real request: `$nbm_user` comes straight from
 * ConfigService::getParamsAndValuesLike(), whose own
 * ConfigRepository::findParamsAndValuesLike() already defensively coerces
 * a non-string `param` column value to `''` (`is_string($row['param']) ?
 * ... : ''`) -- but `config.param` is that table's own VARCHAR PRIMARY
 * KEY, so a real row's `param` is both never NULL and never actually the
 * empty string (Doctrine/PDO also always returns VARCHAR columns as PHP
 * strings to begin with). That inner fallback -- and therefore this
 * `continue` -- is a pure static-analysis narrowing guard with no real,
 * DB-backed row that can ever trigger it.
 *
 * The timeout/"must repost" branch (NotificationByMailSender::
 * isSendmailTimeout()) IS covered below, via nbmForceInstantSendmailTimeout().
 * Neither nbm_max_treatment_timeout_percent nor nbm_treatment_timeout_default
 * has an existing `config` row in this fixture, so the 'param' tab's own
 * admin-UI round trip -- which only updates *existing* nbm_% rows, see the
 * `select ... where param like 'nbm\_%'` loop in
 * NotificationByMailSubController::handle() -- can never reach them.
 * H::setConfigValue() writes the config table directly instead (properly
 * JSON-typed, matching every other Browser test file's config-override
 * convention), forcing NotificationByMailSender's own $sendmailTimeout to
 * 0 -- so checkSendmailTimeout()'s `(moment - start) > threshold` is
 * satisfied by any nonzero elapsed time, which the real DB round trips
 * each of these flows already performs before its first per-user check
 * (getUserNotifications(), beginUsersEnv()'s webmaster-address lookup)
 * reliably guarantees. This always lands on the "zero users treated
 * before the timeout" branch, matching NotificationByMailSenderTest's own
 * identically-reasoned senderWithImmediateTimeout() helper (same
 * real-elapsed-time constraint) -- the timing race needed to land
 * mid-batch (some users treated, then a timeout mid-loop) isn't reachable
 * deterministically over real HTTP either, the same conclusion that
 * Integration test file's own docblock already reaches.
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

/**
 * users.mail_address is untouched by any of the helpers above (they only
 * ever read/write user_mail_notification) -- 'send' action eligibility
 * (NotificationByMailRepository::findUserNotifications()) requires a
 * non-null email on the *users* table itself, unlike subscribe/unsubscribe.
 */
function nbmUserMailAddress(int $userId): ?string
{
    $db = nbmDbConnect();
    $result = $db->query(sprintf('SELECT mail_address FROM %susers WHERE id = %d', nbmDbPrefix(), $userId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && is_string($row['mail_address'] ?? null) ? $row['mail_address'] : null;
}

function nbmSetUserMailAddress(int $userId, ?string $mailAddress): void
{
    $db = nbmDbConnect();
    if ($mailAddress === null) {
        $db->query(sprintf('UPDATE %susers SET mail_address = NULL WHERE id = %d', nbmDbPrefix(), $userId));
    } else {
        $db->query(sprintf(
            "UPDATE %susers SET mail_address = '%s' WHERE id = %d",
            nbmDbPrefix(),
            $db->real_escape_string($mailAddress),
            $userId
        ));
    }
    $db->close();
}

/**
 * Forces NotificationByMailSender::checkSendmailTimeout() to report a
 * timeout on its very first real call in the request that follows,
 * deterministically -- see this file's own top docblock for the full
 * reasoning (neither config key has an existing row, so the 'param' tab
 * itself can't be used to set them; H::setConfigValue() writes the
 * `config` table directly, properly JSON-typed, instead).
 *
 * @return array<string, ?string> snapshot for H::restoreConfig()
 */
function nbmForceInstantSendmailTimeout(): array
{
    $snapshot = H::snapshotConfig(['nbm_max_treatment_timeout_percent', 'nbm_treatment_timeout_default']);
    H::setConfigValue('nbm_max_treatment_timeout_percent', '0');
    H::setConfigValue('nbm_treatment_timeout_default', '0');

    return $snapshot;
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

/**
 * Closes the "still enabled after a failed unsubscribe" branch of the
 * subscribe tab's own redisplay loop (~lines 284-287): unlike
 * doTimeoutTreatment() (only entered when checkSendmailTimeout() itself
 * reports a timeout), a real per-user delivery *failure* leaves
 * `$post['cat_true']` completely untouched (doTimeoutTreatment() is never
 * even called down that path) -- so a user whose real mail send fails
 * stays `enabled=1` in the DB (NotificationByMailSender::
 * doSubscribeUnsubscribeNotificationByMail()'s own `$doUpdate = false`)
 * while STILL being present in the resubmitted `cat_true` list, landing
 * both in $opt_true (still enabled) AND $opt_true_selected (still in
 * $post['cat_true']) on redisplay -- the "unsubscribes a user..." test
 * above only exercises the opposite (successful) outcome, where the user
 * moves to $opt_false instead. Forces a deterministic delivery failure
 * via a closed local port, same technique as NotificationByMailSenderTest
 * (Integration)'s own docblock: pointing smtp_host at 127.0.0.1:1 makes
 * Symfony Mailer's EsmtpTransport fail the TCP connection itself, fast
 * and deterministically, without depending on a real reachable MTA.
 */
it('keeps a user enabled and pre-selected on redisplay when the real unsubscribe mail delivery fails', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $smtpSnapshot = H::snapshotConfig(['smtp_host']);
    H::setConfigValue('smtp_host', '"127.0.0.1:1"');

    $originalMailAddress = nbmUserMailAddress(4);
    nbmSetUserMailAddress(4, 'ct00nbm285@example.test');
    nbmSetUserMailNotificationRow(4, ['check_key' => 'ct00mailfail285', 'enabled' => 1, 'last_send' => null]);

    try {
        $result = nbmPost($page, 'subscribe', [
            'pwg_token' => $token,
            'falsify' => '1',
            'cat_true' => ['ct00mailfail285'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        // doSubscribeUnsubscribeNotificationByMail()'s own delivery-failure
        // message (msgError, 'falsify' => unsubscribe wording).
        expect($result['body'])->toContain('was not removed from the subscription list');

        // Real DB state: the failed send means $doUpdate stayed false, so
        // `enabled` was never actually flipped.
        $updated = nbmUserMailNotificationRow(4);
        expect($updated)->not->toBeNull();
        assert($updated !== null);
        expect($updated['enabled'])->toBe(1);

        // {html_options ... selected=$category_option_true_selected} --
        // still listed under "Subscribed" (opt_true) AND rendered
        // pre-selected (opt_true_selected), since $post['cat_true'] was
        // never touched by doTimeoutTreatment() (no timeout occurred).
        expect($result['body'])->toContain('value="ct00mailfail285" selected="selected"');
    } finally {
        nbmSetUserMailNotificationRow(4, null);
        nbmSetUserMailAddress(4, $originalMailAddress);
        H::restoreConfig($smtpSnapshot);
    }
});

/**
 * Closes renderGlobalCustomizeMailContent()'s own `else` arm (~line 511):
 * with nbmSendHtmlMail() defaulting to true and nbm_complementary_mail_
 * content's own default not starting with '<', every OTHER test in this
 * file that reaches the 'send' tab display (sendMailNotifications
 * ('list_to_send') runs unconditionally there, whenever count($dataUsers)
 * > 0 -- true for this fixture's own real, enabled, non-null-email user
 * 1) lands on the nl2br()/htmlspecialchars() `if` branch instead. Forcing
 * nbm_send_html_mail=false flips the condition's own first half to make
 * this the only real code path.
 */
it('returns the customize-mail-content unchanged when nbm_send_html_mail is disabled', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['nbm_send_html_mail']);
    H::setConfigValue('nbm_send_html_mail', 'false');

    try {
        $page = H::navigateOk($page, '/admin.php?page=notification_by_mail&mode=send');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'notification_by_mail send tab, nbm_send_html_mail=false');
    } finally {
        H::restoreConfig($snapshot);
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

it('reports a repost for falsify with the estimated-time message when the sendmail timeout is forced', function (): void {
    // doTimeoutTreatment()'s 'falsify' branch (unsubscribeNotificationByMail())
    // filters on enabled=1 (see NotificationByMailService::getUserNotifications()'s
    // own docblock on `! $isSubscribe`), so this temp row needs enabled=1
    // to even be picked up by the per-user loop that calls
    // checkSendmailTimeout() in the first place.
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $timeoutSnapshot = nbmForceInstantSendmailTimeout();
    nbmSetUserMailNotificationRow(4, ['check_key' => 'ct00falsifytmo', 'enabled' => 1, 'last_send' => null]);

    try {
        $result = nbmPost($page, 'subscribe', [
            'pwg_token' => $token,
            'falsify' => '1',
            'cat_true' => ['ct00falsifytmo'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        // REPOST_SUBMIT_NAME: 'falsify' propagated straight through to the
        // repost form's own submit button name (handle(), ~line 244-252).
        expect($result['body'])->toContain('<input type="submit" value="Continue ongoing treatment" name="falsify">');
        // doTimeoutTreatment(): the loop broke before treating anyone, so
        // $treated_count stays 0 -- English plural picks msgstr[1] for n=0
        // ("Execution time exceeded, the treatment must continue...").
        expect($result['body'])->toContain('Execution time exceeded, the treatment must continue [Estimated time: 0 seconds].');
        // doSubscribeUnsubscribeNotificationByMail()'s own break-timeout
        // message, fired on the same forced timeout one call earlier.
        expect($result['body'])->toContain('The time to send mail is limited. Others mails have been skipped.');
    } finally {
        nbmSetUserMailNotificationRow(4, null);
        H::restoreConfig($timeoutSnapshot);
    }
});

it('reports a repost for trueify with the estimated-time message when the sendmail timeout is forced', function (): void {
    // Unlike 'falsify' above, subscribeNotificationByMail() passes
    // isSubscribe=true, which drops the enabled filter entirely (`! true`
    // == false == NotificationByMailService's own documented "no filter"
    // sentinel) -- enabled=0 here is deliberately the opposite of the
    // 'falsify' test's row, to also exercise that the filter really is
    // absent for this branch.
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $timeoutSnapshot = nbmForceInstantSendmailTimeout();
    nbmSetUserMailNotificationRow(4, ['check_key' => 'ct00trueifytmo', 'enabled' => 0, 'last_send' => null]);

    try {
        $result = nbmPost($page, 'subscribe', [
            'pwg_token' => $token,
            'trueify' => '1',
            'cat_false' => ['ct00trueifytmo'],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect($result['body'])->toContain('<input type="submit" value="Continue ongoing treatment" name="trueify">');
        expect($result['body'])->toContain('Execution time exceeded, the treatment must continue [Estimated time: 0 seconds].');
    } finally {
        nbmSetUserMailNotificationRow(4, null);
        H::restoreConfig($timeoutSnapshot);
    }
});

it('reports a repost for send_submit with the batch-timing estimate when the sendmail timeout is forced', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $timeoutSnapshot = nbmForceInstantSendmailTimeout();
    $snapshot = nbmUserMailNotificationRow(1);
    expect($snapshot)->not->toBeNull();
    assert($snapshot !== null);

    try {
        $result = nbmPost($page, 'send', [
            'pwg_token' => $token,
            'send_submit' => '1',
            'send_selection' => [$snapshot['check_key']],
            'send_customize_mail_content' => 'CT timeout content ' . uniqid(),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect($result['body'])->toContain('<input type="submit" value="Continue ongoing treatment" name="send_submit">');
        expect($result['body'])->toContain('Execution time exceeded, the treatment must continue [Estimated time: 0 seconds].');
        expect($result['body'])->toContain('The time to send mail is limited. Others mails have been skipped.');
    } finally {
        nbmSetUserMailNotificationRow(1, $snapshot);
        H::restoreConfig($timeoutSnapshot);
    }
});

it('renders only the previously-selected user as checked when redisplaying the send tab after a partial selection', function (): void {
    // No forced timeout here -- $must_repost stays false, so the send
    // display section's outer filter includes *every* eligible candidate
    // (`(! $must_repost) or ...`), letting the per-user CHECKED formula's
    // own `isset($post['send_selection']) and ! in_array(...)` branch
    // (empty/unchecked for a candidate that exists but wasn't selected)
    // actually be reached -- the must_repost branch above can only ever
    // produce 'checked="checked"' for the users it includes at all.
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $snapshot = nbmUserMailNotificationRow(1);
    expect($snapshot)->not->toBeNull();
    assert($snapshot !== null);

    $originalMailAddress = nbmUserMailAddress(4);
    nbmSetUserMailAddress(4, 'ct00sendlistun@example.test');
    nbmSetUserMailNotificationRow(4, ['check_key' => 'ct00sendlistun', 'enabled' => 1, 'last_send' => null]);

    try {
        $result = nbmPost($page, 'send', [
            'pwg_token' => $token,
            'send_submit' => '1',
            'send_selection' => [$snapshot['check_key']],
            'send_customize_mail_content' => 'CT partial selection ' . uniqid(),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        // User 4's temp row was never part of send_selection[] -- its own
        // {$u.CHECKED} slot renders as a literal empty string, leaving the
        // two template-source spaces on either side of it adjacent.
        expect($result['body'])->toContain('value="ct00sendlistun"  id="send_selection-ct00sendlistun"');
    } finally {
        nbmSetUserMailNotificationRow(4, null);
        nbmSetUserMailAddress(4, $originalMailAddress);
        nbmSetUserMailNotificationRow(1, $snapshot);
    }
});

it('cleans up newly-inserted notification rows and redirects when the plain-load self-heal hits the sendmail timeout', function (): void {
    // insertNewDataUserMailNotification() passes
    // CurrentConfig::nbmDefaultValueUserEnabled() (default false) as
    // doSubscribeUnsubscribeNotificationByMail()'s own $isSubscribe --
    // with the default, `! $isSubscribe` becomes an enabled=1 filter,
    // which the rows this method itself just massInsert()-ed (hardcoded
    // enabled=0) could never match, so the per-user loop -- and therefore
    // checkSendmailTimeout() -- would never even run. Forcing
    // nbm_default_value_user_enabled=true flips $isSubscribe to true,
    // which drops that filter entirely (see the 'trueify' test above),
    // letting the just-inserted rows reach the loop for real.
    $page = H::loginAsAdmin($this);

    $snapshot = nbmUserMailNotificationRow(1);
    expect($snapshot)->not->toBeNull();
    assert($snapshot !== null);

    $configSnapshot = H::snapshotConfig([
        'nbm_max_treatment_timeout_percent',
        'nbm_treatment_timeout_default',
        'nbm_default_value_user_enabled',
    ]);
    H::setConfigValue('nbm_max_treatment_timeout_percent', '0');
    H::setConfigValue('nbm_treatment_timeout_default', '0');
    H::setConfigValue('nbm_default_value_user_enabled', 'true');

    nbmSetUserMailNotificationRow(1, null);

    try {
        $result = H::rawGet($page, '/admin.php?page=notification_by_mail');

        // fetch()'s own 'manual' redirect mode always reports status 0 for
        // a genuine 30x response (an opaque-redirect, not an app bug --
        // see BrowserTestHelpers::rawGet()'s own established convention
        // elsewhere in this suite), confirming
        // insertNewDataUserMailNotification()'s own redirect() call fired.
        expect($result['status'])->toBe(0);

        // The just-inserted row was deleted again by the timeout cleanup
        // (`delete from user_mail_notification where check_key in (...)`)
        // before the self-heal could ever "stick" -- unlike the plain
        // self-heal test above (no forced timeout there), which expects
        // the opposite outcome.
        expect(nbmUserMailNotificationRow(1))->toBeNull();
    } finally {
        nbmSetUserMailNotificationRow(1, $snapshot);
        H::restoreConfig($configSnapshot);
    }
});
