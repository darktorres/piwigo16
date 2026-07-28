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
