<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\PasswordController (password.php) -- the "forgot
 * password" verification-code + reset-key flow. Real mail delivery isn't
 * observable from a Browser test in this environment, so this exercises
 * every branch that IS fully observable without reading the emailed code:
 * the enumeration-safe "lost" transition (processVerificationCode() always
 * reports success, even for an unknown email -- see UserService's own
 * [SEC-31]-style rationale), the wrong-code/lockout path, the guarded
 * direct-to-"reset" redirect, and the invalid-reset-key rejection.
 */

it('shows the "forgot your password" form to a guest', function (): void {
    $page = H::gotoOk($this, '/password.php');

    $page->assertTitleContains('Forgot your password?');
    $page->assertPresent('input[name="username_or_email"]');
});

it('rejects an empty username_or_email, then enumeration-safely accepts an unknown one, then locks out after 3 wrong codes', function (): void {
    $page = H::gotoOk($this, '/password.php');

    // 1. Empty input -> real validation error, form stays on the 'lost' step.
    $page = $page->fill('username_or_email', '')->click('submit');
    $page->assertSee('Invalid username or email');
    $page->assertPresent('input[name="username_or_email"]');

    // 2. An email that matches no real account -- [SEC-31]-style
    // enumeration-safety means this must look identical to a real account's
    // response: same info message, same transition to the code-entry step.
    $unknownEmail = 'no-such-account-' . uniqid() . '@example.test';
    $page = $page->fill('username_or_email', $unknownEmail)->click('submit');
    $page->assertSee('If your account exists, a verification code has been sent to your email address.');
    $page->assertPresent('input[name="user_code"]');

    // 3. Wrong 6-digit code, 3 times in a row -> the first 2 each report a
    // per-attempt error; the 3rd crosses processPasswordRequest()'s own
    // `$attempts >= 3` threshold and locks the whole flow out.
    $page = $page->fill('user_code', '000000')->click('submit');
    $page->assertSee('Invalid verification code');

    $page = $page->fill('user_code', '111111')->click('submit');
    $page->assertSee('Invalid verification code');

    // CONFIRMED REAL BUG (not a test-assertion issue -- reproduced directly
    // with raw curl, independent of this Browser test, before writing this
    // assertion): the lockout branch's own
    // `$this->errors['login_page_error'] = Lang::t('Too many attempts, please
    // try later..')` is set, but the lockout branch ALSO
    // `unset($_SESSION['reset_password_code'])` first -- and __invoke()'s
    // later, unconditional guard
    // `if ($this->action === 'lost_code' and ! isset($_SESSION['reset_password_code']))`
    // then redirects to identification.php before ever reaching
    // flushKeyedErrors($formErrors), discarding that error message entirely.
    // A real user who triggers this lockout is silently bounced to the
    // login page with NO visible explanation of why -- this asserts the
    // real (buggy) observed behavior rather than the intended one.
    $page = $page->fill('user_code', '222222')->click('submit');
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)->toContain('identification.php');
    H::assertNoServerErrors($page, 'password lockout redirect target');
});

it('redirects straight to the gallery home when hitting action=reset with no key and no valid session', function (): void {
    $page = H::gotoOk($this, '/password.php?action=reset');

    // PasswordController's own guard: a guest with no reset key and no
    // $_SESSION['valid_reset_password_code'] gets redirected away from the
    // reset form entirely rather than shown a broken/empty one, straight to
    // UrlServiceInterface::getGalleryHomeUrl() -- confirmed live via a raw
    // curl (independent of Pest/Playwright) that this fixture's own
    // gallery_url/mount configuration resolves that to exactly the site
    // root, not a looser "somewhere else" check.
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)->toBe(H::baseUrl() . '/');
});

it('shows "Invalid key" and hides the form for a malformed reset key', function (): void {
    // 20 chars but containing punctuation -- fails checkPasswordResetKey()'s
    // own /^[a-z0-9]{20}$/i pattern, landing on action='none'.
    $page = H::gotoOk($this, '/password.php?key=not-a-valid-reset-!!');

    $page->assertSee('Invalid key');
    // action='none' means password.tpl's own `{if $action ne 'none'}` wraps
    // the ENTIRE form -- none of its fields render at all.
    $page->assertMissing('input[name="username_or_email"]');
    $page->assertMissing('input[name="user_code"]');
    $page->assertMissing('input[name="use_new_pwd"]');
});

function passwordDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function passwordDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

/**
 * Directly inserts a real user + user_infos row with a real, valid
 * activation_key -- this is the same bcrypt hashing PasswordService::
 * hash() itself uses (plain PHP password_hash(), no app-class dependency,
 * matching this suite's black-box-over-HTTP convention), so
 * checkPasswordResetKey()'s own PasswordService::verify() call succeeds
 * against $plainKey without ever needing to read a real, undeliverable
 * test email. `last_visit` stays NULL (never logged in, never visited) so
 * AuthService::hasAlreadyLoggedIn() -- countLoginActivity() === 0 -- is
 * true, exercising __invoke()'s $first_login/"Welcome" branch too.
 *
 * @return array{userId: int, plainKey: string}
 */
function passwordInsertResetUser(): array
{
    $db = passwordDbConnect();
    $prefix = passwordDbPrefix();
    $username = 'pwreset_' . uniqid();
    $plainKey = substr(bin2hex(random_bytes(16)), 0, 20);
    $hashedKey = password_hash($plainKey, PASSWORD_BCRYPT, ['cost' => 4]);

    $db->query(sprintf(
        "INSERT INTO %susers (username, password, mail_address) VALUES ('%s', '%s', NULL)",
        $prefix,
        $db->real_escape_string($username),
        $db->real_escape_string(password_hash('original-password', PASSWORD_BCRYPT, ['cost' => 4]))
    ));
    $userId = (int) $db->insert_id;

    $db->query(sprintf(
        "INSERT INTO %suser_infos (user_id, status, activation_key, activation_key_expire) VALUES (%d, 'normal', '%s', DATE_ADD(NOW(), INTERVAL 1 HOUR))",
        $prefix,
        $userId,
        $db->real_escape_string($hashedKey)
    ));
    $db->close();

    return ['userId' => $userId, 'plainKey' => $plainKey];
}

/** @return array{password: string}|null */
function passwordUserRow(int $userId): ?array
{
    $db = passwordDbConnect();
    $prefix = passwordDbPrefix();
    $result = $db->query(sprintf('SELECT password FROM %susers WHERE id = %d', $prefix, $userId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) ? ['password' => (string) $row['password']] : null;
}

/** @return array{activation_key: string|null}|null */
function passwordUserInfosRow(int $userId): ?array
{
    $db = passwordDbConnect();
    $prefix = passwordDbPrefix();
    $result = $db->query(sprintf('SELECT activation_key FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    if (! is_array($row)) {
        return null;
    }

    $activationKey = $row['activation_key'] ?? null;

    return ['activation_key' => is_string($activationKey) ? $activationKey : null];
}

function passwordDeleteUser(int $userId): void
{
    $db = passwordDbConnect();
    $prefix = passwordDbPrefix();
    $db->query(sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
    $db->query(sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
    $db->close();
}

it('completes a full reset-key password reset for a never-logged-in user, welcoming them and clearing the key', function (): void {
    $fixture = passwordInsertResetUser();
    $userId = $fixture['userId'];
    $originalHash = passwordUserRow($userId)['password'] ?? null;

    try {
        $page = H::gotoOk($this, '/password.php?key=' . $fixture['plainKey']);

        // hasAlreadyLoggedIn() === true for this brand new, never-visited
        // user -- __invoke()'s $action === 'reset' and $first_login branch.
        $page->assertTitleContains('Welcome');
        $page->assertPresent('input[name="use_new_pwd"]');

        $page = $page->fill('use_new_pwd', 'a-brand-new-password-1')
            ->fill('passwordConf', 'a-brand-new-password-1')
            ->click('submit');

        $page->assertSee('Your password has been reset');
        $page->assertSee('Login');

        $newHash = passwordUserRow($userId)['password'] ?? null;
        expect($newHash)->not->toBeNull();
        expect($newHash)->not->toBe($originalHash);

        // resetPasswordKey() deactivates the reset key as soon as it's
        // consumed, regardless of whether the password-confirmation match
        // succeeds afterward.
        $infosRow = passwordUserInfosRow($userId);
        if ($infosRow === null) {
            throw new RuntimeException("expected a real user_infos row for user {$userId}");
        }
        expect($infosRow['activation_key'])->toBeNull();
    } finally {
        passwordDeleteUser($userId);
    }
});

it('rejects a reset submission whose passwords do not match, without consuming the key', function (): void {
    $fixture = passwordInsertResetUser();
    $userId = $fixture['userId'];

    try {
        $page = H::gotoOk($this, '/password.php?key=' . $fixture['plainKey']);

        $page = $page->fill('use_new_pwd', 'first-password-1')
            ->fill('passwordConf', 'a-different-password-2')
            ->click('submit');

        $page->assertSee('The passwords do not match');
        // resetPassword()'s own mismatch check returns before ever calling
        // resetPasswordKey() -- the key must still be intact.
        expect(passwordUserInfosRow($userId)['activation_key'] ?? null)->not->toBeNull();
    } finally {
        passwordDeleteUser($userId);
    }
});

/**
 * @return array{status: int, body: string}
 */
/**
 * @param  list<non-empty-string>  $extraHeaders
 * @return array{status: int, body: string}
 */
function passwordCurlGet(string $path, array $extraHeaders = []): array
{
    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), ...$extraHeaders]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (! is_string($body)) {
        throw new RuntimeException('curl_exec failed');
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * @return array{userId: int}
 */
function passwordInsertNormalUserWithEmail(string $email): array
{
    $db = passwordDbConnect();
    $prefix = passwordDbPrefix();
    $username = 'pwlost_' . uniqid();

    $db->query(sprintf(
        "INSERT INTO %susers (username, password, mail_address) VALUES ('%s', '%s', '%s')",
        $prefix,
        $db->real_escape_string($username),
        $db->real_escape_string(password_hash('original-password', PASSWORD_BCRYPT, ['cost' => 4])),
        $db->real_escape_string($email)
    ));
    $userId = (int) $db->insert_id;

    $db->query(sprintf(
        "INSERT INTO %suser_infos (user_id, status, language) VALUES (%d, 'normal', 'en_UK')",
        $prefix,
        $userId
    ));
    $db->close();

    return ['userId' => $userId];
}

/** @return array{reset_password_forbidden_until: int|null}|null */
function passwordUserPreferences(int $userId): ?array
{
    $db = passwordDbConnect();
    $prefix = passwordDbPrefix();
    $result = $db->query(sprintf('SELECT preferences FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    if (! is_array($row)) {
        return null;
    }

    $raw = $row['preferences'] ?? null;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $forbiddenUntil = is_array($decoded) ? ($decoded['reset_password_forbidden_until'] ?? null) : null;

    return ['reset_password_forbidden_until' => is_numeric($forbiddenUntil) ? (int) $forbiddenUntil : null];
}

it('nulls a reset key for an already-logged-in (non-guest) user and redirects the default "lost" action home', function (): void {
    $page = H::loginAsAdmin($this);

    // A logged-in user is never a guest, so __invoke()'s own
    // `if ($key !== null and !isAGuest()) { $key = null; }` fires --
    // action_param is null (no ?action= sent), so $this->action resolves
    // to the 'lost' default, which then immediately redirects a non-guest
    // away from the "forgot password" form entirely.
    $page = H::navigateOk($page, '/password.php?key=aaaaaaaaaaaaaaaaaaaa');
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)->toBe(H::baseUrl() . '/');
});

it('re-shows the code-entry step on a plain revisit while a reset code is still pending', function (): void {
    $page = H::gotoOk($this, '/password.php');
    $unknownEmail = 'no-such-account-' . uniqid() . '@example.test';
    $page = $page->fill('username_or_email', $unknownEmail)->click('submit');
    $page->assertPresent('input[name="user_code"]');

    // A plain GET back to password.php, with no ?action= at all -- the
    // default resolves to 'lost', which __invoke() then bumps to
    // 'lost_code' specifically because $_SESSION['reset_password_code']
    // is still set from the request above.
    $page = H::navigateOk($page, '/password.php');
    $page->assertPresent('input[name="user_code"]');
    $page->assertMissing('input[name="username_or_email"]');
});

it('re-submitting the "lost" step while a code is already pending short-circuits to the same success message', function (): void {
    $page = H::gotoOk($this, '/password.php');
    $email = 'no-such-account-' . uniqid() . '@example.test';
    $page = $page->fill('username_or_email', $email)->click('submit');
    $page->assertSee('If your account exists, a verification code has been sent to your email address.');

    // Re-submit the very same "lost" step -- processVerificationCode()'s
    // own `if (isset($_SESSION['reset_password_code'])) { return true; }`
    // short-circuit, without re-deriving anything from $email again.
    //
    // Not navigateOk()+click('submit'): __invoke()'s own
    // `if ($this->action === 'lost' and isset($_SESSION['reset_password_code']))
    // { $this->action = 'lost_code'; }` forces the *rendered* page to the
    // code-entry form the moment a code is pending -- confirmed live, a
    // plain GET reload (with or without `?action=lost` in the URL) always
    // shows that form, never the email one again, so there is no real
    // <form> left to click('submit') on that would actually resubmit
    // action=lost. A real user could still trigger this by resubmitting a
    // stale/bookmarked action=lost page (e.g. browser back + resubmit),
    // which is what a direct POST to that exact URL replicates.
    $result = H::adminPost($page, '/password.php?action=lost', [
        'username_or_email' => $email,
        'submit' => 'Change my password',
        'pwg_token' => H::pwgToken($page),
    ]);
    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('If your account exists, a verification code has been sent to your email address.');
});

it('rejects a "lost" submission for the guest account with a real (non-enumeration-safe) error', function (): void {
    $page = H::gotoOk($this, '/password.php');

    // 'guest' is a real, known username (fixture user_id 2, status
    // 'guest') -- AccessControl::isAGuest()/isGeneric() are excluded from
    // the enumeration-safety guarantee entirely, so this shows the real
    // "not allowed" error immediately instead of the generic success
    // message every unknown-account test above sees.
    $page = $page->fill('username_or_email', 'guest')->click('submit');
    $page->assertSee('Password reset is not allowed for this user');
    $page->assertPresent('input[name="username_or_email"]');
});

it('locks a real user out of password reset after 3 wrong codes, then rejects a later "lost" retry during the lockout window', function (): void {
    $email = 'ct-pwlost-' . uniqid() . '@example.test';
    $fixture = passwordInsertNormalUserWithEmail($email);
    $userId = $fixture['userId'];

    try {
        $page = H::gotoOk($this, '/password.php');
        // clickWithTimeout(), not a plain fill()->click(): every submit in
        // this test resolves a *real* user_id, exercising
        // CurrentUser::buildUser()/activity-record DB writes on each
        // attempt -- slow enough, at least intermittently, to exceed
        // pest-plugin-browser's own ~1s-per-attempt retry-wrap ceiling
        // (same class of issue as InstallTest.php's own click('install'),
        // see clickWithTimeout()'s docblock). Confirmed live: a plain
        // click('submit') here silently double-submitted the 2nd wrong
        // code, consuming 2 real attempts in one logical step and
        // triggering the lockout redirect a full step early.
        $page = $page->fill('username_or_email', $email);
        H::clickWithTimeout($page, 'submit');
        $page->assertPresent('input[name="user_code"]');

        // Attempts 1 and 2: per-attempt "Invalid verification code",
        // recording a `reset_password_failure_code` activity entry for
        // this real, resolvable user_id each time.
        $page = $page->fill('user_code', '000000');
        H::clickWithTimeout($page, 'submit');
        $page->assertSee('Invalid verification code');
        $page = $page->fill('user_code', '111111');
        H::clickWithTimeout($page, 'submit');
        $page->assertSee('Invalid verification code');

        // Attempt 3: crosses the lockout threshold -- unlike the
        // unknown-user lockout test above (no resolvable user_id, so the
        // CurrentUser-switch/PreferencesService/activity-record block is
        // skipped entirely), this real user_id hits all of it, persisting
        // `reset_password_forbidden_until` ~1 hour into the future.
        $page = $page->fill('user_code', '222222');
        H::clickWithTimeout($page, 'submit');

        $preferences = passwordUserPreferences($userId);
        expect($preferences)->not->toBeNull();
        assert(is_array($preferences));
        expect($preferences['reset_password_forbidden_until'])->not->toBeNull();
        assert(is_int($preferences['reset_password_forbidden_until']));
        expect($preferences['reset_password_forbidden_until'])->toBeGreaterThan(time());

        // A fresh "lost" submission for the very same email, still inside
        // the lockout window -- hits the *other* "Too many attempts"
        // message (processVerificationCode()'s own lockout check), a
        // different source line from the per-attempt lockout above.
        $freshPage = H::gotoOk($this, '/password.php');
        $freshPage = $freshPage->fill('username_or_email', $email);
        H::clickWithTimeout($freshPage, 'submit');
        $freshPage->assertSee('Too many attempts, please try later..');
        $freshPage->assertPresent('input[name="username_or_email"]');
    } finally {
        passwordDeleteUser($userId);
    }
});

it('sends a real verification-code email for a resolvable user with a real email address', function (): void {
    $email = 'ct-pwlost-mail-' . uniqid() . '@example.test';
    $fixture = passwordInsertNormalUserWithEmail($email);
    $userId = $fixture['userId'];

    try {
        $page = H::gotoOk($this, '/password.php');
        // clickWithTimeout(): this submit resolves a real user_id and
        // dispatches a real MailService::mail() call -- slow enough to
        // exceed pest-plugin-browser's own ~1s-per-attempt retry-wrap
        // ceiling (see PasswordControllerTest's own lockout test for a
        // confirmed-live instance of this exact double-submit failure
        // mode).
        $page = $page->fill('username_or_email', $email);
        H::clickWithTimeout($page, 'submit');

        // Same enumeration-safe wording as the unknown-email case, but
        // this time processVerificationCode() resolved a *real* user_id
        // (a genuinely different internal branch: is_user_found === true,
        // $skip_mail === false since the address is non-empty) and
        // actually queued a real MailService::mail() call.
        $page->assertSee('If your account exists, a verification code has been sent to your email address.');
        $page->assertPresent('input[name="user_code"]');
        H::assertNoServerErrors($page, 'password lost-step mail dispatch for a real user');
    } finally {
        passwordDeleteUser($userId);
    }
});

it('expires a pending reset code once its configured duration has elapsed', function (): void {
    $snapshot = H::snapshotConfig(['password_reset_code_duration']);

    try {
        H::setConfigValue('password_reset_code_duration', '1');

        $page = H::gotoOk($this, '/password.php');
        $unknownEmail = 'no-such-account-' . uniqid() . '@example.test';
        $page = $page->fill('username_or_email', $unknownEmail)->click('submit');
        $page->assertPresent('input[name="user_code"]');

        sleep(2);

        // CONFIRMED REAL BUG, same shape as the lockout test above (not a
        // test-assertion issue -- reproduced directly with raw curl,
        // independent of this Browser test, before writing this
        // assertion): the expiry branch's own
        // `unset($_SESSION['reset_password_code'])` runs before returning
        // false with the 'Code expired' error queued, and __invoke()'s
        // later, unconditional guard
        // `if ($this->action === 'lost_code' and ! isset($_SESSION['reset_password_code']))`
        // then redirects to identification.php before ever reaching
        // flushKeyedErrors($formErrors), discarding that error message
        // entirely -- a real user hitting an expired code is silently
        // bounced to the login page with no explanation. This asserts the
        // real (buggy) observed behavior rather than the intended one.
        $page = $page->fill('user_code', '000000')->click('submit');
        $currentUrl = H::rawWebpage($page)->url();
        expect($currentUrl)->toContain('identification.php');
        H::assertNoServerErrors($page, 'password expired-code redirect target');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows "Invalid key" for a well-formed reset key matching no real activation key', function (): void {
    // 20 lowercase-alnum chars -- passes checkPasswordResetKey()'s own
    // regex (unlike the punctuation-containing malformed-key test above,
    // which fails that regex before ever running the DB lookup loop), but
    // matches no real user_infos.activation_key row, exercising that
    // loop's own "no match found" tail instead.
    $page = H::gotoOk($this, '/password.php?key=zzzzzzzzzzzzzzzzzzzz');

    $page->assertSee('Invalid key');
    $page->assertMissing('input[name="use_new_pwd"]');
});

it('fatal-errors on a hacking-attempt invalid lang cookie', function (): void {
    $result = passwordCurlGet('/password.php', ['Cookie: lang=not_a_real_lang_' . uniqid()]);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Hacking attempt');
});

it('switches to a valid, different lang cookie and shows the French translation', function (): void {
    $db = passwordDbConnect();
    $prefix = passwordDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %slanguages (id, version, name) VALUES ('fr_FR', '1.0.0', 'French') ON DUPLICATE KEY UPDATE name = VALUES(name)",
        $prefix
    ));
    $db->close();

    try {
        $result = passwordCurlGet('/password.php', ['Cookie: lang=fr_FR']);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Hacking attempt');
        // Not the French help_link: that's only ever assigned to
        // themes/standard_pages/template/password.tpl's own {$HELP_LINK},
        // never referenced by the fixture gallery's real "default" theme
        // password.tpl (confirmed live by diffing the fr_FR response
        // against a plain English one -- no help link appears in either,
        // but every real page string does switch to its po-translated
        // French wording).
        expect($result['body'])->toContain('Mot de passe oublié ?');
    } finally {
        $db2 = passwordDbConnect();
        $db2->query(sprintf("DELETE FROM %slanguages WHERE id = 'fr_FR'", passwordDbPrefix()));
        $db2->close();
    }
});
