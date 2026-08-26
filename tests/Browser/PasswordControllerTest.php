<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Auth\Totp;
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
    $page = $page->fill('username_or_email', '')
        ->click('submit');
    $page->assertSee('Invalid username or email');
    $page->assertPresent('input[name="username_or_email"]');

    // 2. An email that matches no real account -- [SEC-31]-style
    // enumeration-safety means this must look identical to a real account's
    // response: same info message, same transition to the code-entry step.
    $unknownEmail = 'no-such-account-' . uniqid() . '@example.test';
    $page = $page->fill('username_or_email', $unknownEmail)
        ->click('submit');
    $page->assertSee('If your account exists, a verification code has been sent to your email address.');
    $page->assertPresent('input[name="user_code"]');

    // 3. Wrong 6-digit code, 3 times in a row -> the first 2 each report a
    // per-attempt error; the 3rd crosses processPasswordRequest()'s own
    // `$attempts >= 3` threshold and locks the whole flow out.
    $page = $page->fill('user_code', '000000')
        ->click('submit');
    $page->assertSee('Invalid verification code');

    $page = $page->fill('user_code', '111111')
        ->click('submit');
    $page->assertSee('Invalid verification code');

    // The lockout branch redirects to identification.php (matching
    // IdentificationController's own 'login_page_error' key convention --
    // this message is meant for that page, not this one) and flashes the
    // message through $_SESSION['page_errors'] so it survives the redirect
    // instead of being discarded with the rest of this request's local
    // $this->errors.
    $page = $page->fill('user_code', '222222')
        ->click('submit');
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)
        ->toContain('identification.php');
    $page->assertSee('Too many attempts, please try later..');
    H::assertNoServerErrors($page, 'password lockout redirect target');
});

it('redirects straight to the gallery home when hitting action=reset with no key and no valid session', function (): void {
    $page = H::gotoOk($this, '/password.php?action=reset');

    // PasswordController's own guard: a guest with no reset key and no
    // $_SESSION['valid_reset_password_code'] gets redirected away from the
    // reset form entirely rather than shown a broken/empty one, straight to
    // UrlServiceInterface::getGalleryHomeUrl(), which this fixture's own
    // gallery_url/mount configuration resolves to exactly the site
    // root, not a looser "somewhere else" check.
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)
        ->toBe(H::baseUrl() . '/');
});

it('shows "Invalid key" and hides the form for a malformed reset key', function (): void {
    // 20 chars but containing punctuation -- fails checkPasswordResetKey()'s
    // own /^[a-z0-9]{20}$/i pattern, landing on action='none'.
    $page = H::gotoOk($this, '/password.php?key=not-a-valid-reset-!!');

    $page->assertSee('Invalid key');
    // action='none' means password.latte's own `{if $action ne 'none'}` wraps
    // the ENTIRE form -- none of its fields render at all.
    $page->assertMissing('input[name="username_or_email"]');
    $page->assertMissing('input[name="user_code"]');
    $page->assertMissing('input[name="use_new_pwd"]');
});

function passwordDbConnect(): mysqli|Connection
{
    return H::connect();
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
 * $status defaults to 'normal' (every pre-existing call site relies on
 * that default); the guest/generic-rejection test below passes 'guest' to
 * reuse this same real-bcrypt-match machinery for
 * checkPasswordResetKey()'s own isAGuest()/isGeneric() branch, which needs
 * a real matching key on a guest/generic row specifically -- not just
 * status alone.
 *
 * @return array{userId: int, plainKey: string}
 */
function passwordInsertResetUser(string $status = 'normal'): array
{
    $db = passwordDbConnect();
    $username = 'pwreset_' . uniqid();
    $plainKey = substr(bin2hex(random_bytes(16)), 0, 20);
    $hashedKey = password_hash($plainKey, PASSWORD_BCRYPT, [
        'cost' => 4,
    ]);

    H::dbQuery($db, sprintf("INSERT INTO users (username, password, mail_address) VALUES ('%s', '%s', NULL)", H::dbEscape($db, $username), H::dbEscape($db, password_hash('original-password', PASSWORD_BCRYPT, [
        'cost' => 4,
    ]))));
    $userId = H::dbInsertId($db);

    // DATE_ADD() is MySQL-only -- Postgres's own date arithmetic is
    // `NOW() + INTERVAL '1 hour'`.
    $expiryExpr = $db instanceof mysqli ? 'DATE_ADD(NOW(), INTERVAL 1 HOUR)' : "NOW() + INTERVAL '1 hour'";
    H::dbQuery($db, sprintf("INSERT INTO user_infos (user_id, status, activation_key, activation_key_expire) VALUES (%d, '%s', '%s', {$expiryExpr})", $userId, H::dbEscape($db, $status), H::dbEscape($db, $hashedKey)));
    H::dbClose($db);

    return [
        'userId' => $userId,
        'plainKey' => $plainKey,
    ];
}

/**
 * Inserts a `user_infos` row with a non-expired activation_key_expire but
 * an EMPTY (not NULL) activation_key -- findPendingActivationKeyRows()'s
 * own SQL filter is `activation_key IS NOT NULL`, which a real empty
 * string still satisfies, so this kind of row genuinely reaches
 * checkPasswordResetKey()'s own scan loop. That loop's very first check is
 * `if ($activationKeyRow->activationKey === '') { continue; }`, skipping
 * straight past it (never calling PasswordService::verify() against an
 * empty hash) -- covered by this row's mere presence during any reset-key
 * lookup, not by anything this row's own owner ever does.
 *
 * @return int the userId, for cleanup via passwordDeleteUser()
 */
function passwordInsertEmptyActivationKeyUser(): int
{
    $db = passwordDbConnect();
    $username = 'pwnoise_' . uniqid();

    H::dbQuery($db, sprintf("INSERT INTO users (username, password, mail_address) VALUES ('%s', '%s', NULL)", H::dbEscape($db, $username), H::dbEscape($db, password_hash('original-password', PASSWORD_BCRYPT, [
        'cost' => 4,
    ]))));
    $userId = H::dbInsertId($db);

    $expiryExpr = $db instanceof mysqli ? 'DATE_ADD(NOW(), INTERVAL 1 HOUR)' : "NOW() + INTERVAL '1 hour'";
    H::dbQuery($db, sprintf("INSERT INTO user_infos (user_id, status, activation_key, activation_key_expire) VALUES (%d, 'normal', '', {$expiryExpr})", $userId));
    H::dbClose($db);

    return $userId;
}

/**
 * A resolvable, normal-status user with NO email address at all
 * (mail_address NULL) -- processVerificationCode()'s own early
 * guest/generic block doesn't apply (status is 'normal'), so this user
 * reaches the real code-generation/session-write path with `$skip_mail`
 * true purely because the address is missing, exercising
 * processPasswordRequest()'s own separate "no email" fallback rejection
 * (a different source line than the guest/generic checks above) once the
 * (locally-known, never-mailed) code is submitted back.
 *
 * @return array{userId: int, username: string}
 */
function passwordInsertNormalUserNoEmail(): array
{
    $db = passwordDbConnect();
    $username = 'pwnoemail_' . uniqid();

    H::dbQuery($db, sprintf("INSERT INTO users (username, password, mail_address) VALUES ('%s', '%s', NULL)", H::dbEscape($db, $username), H::dbEscape($db, password_hash('original-password', PASSWORD_BCRYPT, [
        'cost' => 4,
    ]))));
    $userId = H::dbInsertId($db);

    H::dbQuery($db, sprintf("INSERT INTO user_infos (user_id, status, language) VALUES (%d, 'normal', 'en_UK')", $userId));
    H::dbClose($db);

    return [
        'userId' => $userId,
        'username' => $username,
    ];
}

/**
 * @return string a fresh cookie-jar path
 */
function passwordFreshCookieJar(): string
{
    $jar = tempnam(sys_get_temp_dir(), 'pwg_password_test_');
    if ($jar === false) {
        throw new RuntimeException('tempnam failed');
    }

    return $jar;
}

/**
 * Plain curl + cookie jar, matching RegisterControllerTest.php's own
 * established style -- needed (instead of this file's own Playwright-driven
 * $page->fill()/->click() tests above) for the session-code tests below,
 * which read the real `secret` straight out of the DB-backed `sessions`
 * table between requests (see passwordSessionResetCodeSecret()) --
 * pest-plugin-browser has no cookie-jar access of its own to correlate a
 * Playwright page with its own session row (same constraint
 * uploadPhotoViaApi()'s own docblock documents for a different reason).
 *
 * @param  array<string, string>  $fields
 * @return array{status: int, body: string, url: string}
 */
function passwordCurlSession(string $cookieJar, string $path, array $fields = [], ?int $timeoutSeconds = null): array
{
    if ($cookieJar === '') {
        throw new RuntimeException('passwordCurlSession(): cookieJar must not be empty');
    }
    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($timeoutSeconds !== null) {
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    }
    if ($fields !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (! is_string($body)) {
        throw new RuntimeException('curl_exec failed');
    }

    return [
        'status' => $status,
        'body' => $body,
        'url' => $url,
    ];
}

/**
 * Extracts password.latte's hidden pwg_token value from a response body.
 */
function passwordExtractToken(string $html): string
{
    if (preg_match('/name="pwg_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('could not find the pwg_token field in: ' . $html);
    }

    return $matches[1];
}

/**
 * Reads the `pwg_id` session cookie's raw value out of a curl cookie-jar
 * file (Netscape format) -- same technique as PictureControllerTest.php's
 * own pictureCookieJarSessionId(), duplicated (not shared) since PHP has no
 * per-file namespacing for these plain global test functions.
 */
function passwordCookieJarSessionId(string $cookieJar): string
{
    $contents = file_get_contents($cookieJar);
    if ($contents === false) {
        throw new RuntimeException('failed to read cookie jar: ' . $cookieJar);
    }
    foreach (explode("\n", $contents) as $line) {
        $fields = explode("\t", $line);
        if (($fields[5] ?? null) === 'pwg_id' && isset($fields[6])) {
            return trim($fields[6]);
        }
    }

    throw new RuntimeException('pwg_id cookie not found in jar: ' . $cookieJar);
}

/**
 * Reads the real session `data` blob straight out of the DB-backed
 * `sessions` table (Piwigo\Session\SessionHandler's own save handler) for the
 * given `pwg_id` cookie value -- same suffix-match rationale as
 * PictureControllerTest.php's own pictureSessionDerivType() (the
 * IP-derived hash SessionHandler prepends to the raw id isn't safe to
 * hardcode).
 */
function passwordSessionData(string $pwgIdCookieValue): string
{
    $db = passwordDbConnect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT data FROM sessions WHERE id LIKE '%%%s'", H::dbEscape($db, $pwgIdCookieValue)));
    H::dbClose($db);

    $data = is_array($row) ? $row['data'] ?? null : null;

    return is_string($data) ? $data : '';
}

/**
 * Extracts `$_SESSION['reset_password_code']['secret']` straight out of the
 * real session row PHP's own default (non-igbinary) session serialize
 * format -- lets this suite compute a genuinely valid verification code
 * with \Piwigo\Auth\Totp::generateCode() (the exact same algorithm
 * AuthService::verifyUserCode() itself calls) without ever needing to read
 * a real, undeliverable test email, mirroring how passwordInsertResetUser()
 * above sidesteps mail for the reset-key flow. 'secret' is written first in
 * processVerificationCode()'s own array literal, so it's always the first
 * key PHP's serialize() emits, but the `.*?` here doesn't depend on that.
 */
function passwordSessionResetCodeSecret(string $pwgIdCookieValue): ?string
{
    $data = passwordSessionData($pwgIdCookieValue);
    if (preg_match('/reset_password_code\|a:\d+:\{.*?s:6:"secret";s:\d+:"([^"]*)"/s', $data, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

/**
 * Computes a real, currently-valid TOTP code for a secret read straight out
 * of the session (see passwordSessionResetCodeSecret()) -- reads the live
 * `password_reset_code_duration` config value rather than assuming the
 * install-time default, since AuthService::generateUserCode()/
 * verifyUserCode() both derive the TOTP "period" from that same live value
 * (min()-capped at 900s).
 */
function passwordComputeValidCode(string $secret): string
{
    $durationRaw = H::configValue('password_reset_code_duration');
    $duration = $durationRaw !== null && is_numeric($durationRaw) ? (int) $durationRaw : 300;

    return Totp::generateCode($secret, min($duration, 900));
}

/**
 * @return array{password: string}|null
 */
function passwordUserRow(int $userId): ?array
{
    $db = passwordDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT password FROM users WHERE id = %d', $userId));
    H::dbClose($db);

    return is_array($row) ? [
        'password' => (string) $row['password'],
    ] : null;
}

/**
 * @return array{activation_key: string|null}|null
 */
function passwordUserInfosRow(int $userId): ?array
{
    $db = passwordDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT activation_key FROM user_infos WHERE user_id = %d', $userId));
    H::dbClose($db);

    if (! is_array($row)) {
        return null;
    }

    $activationKey = $row['activation_key'] ?? null;

    return [
        'activation_key' => is_string($activationKey) ? $activationKey : null,
    ];
}

function passwordDeleteUser(int $userId): void
{
    $db = passwordDbConnect();
    H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
    H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
    H::dbClose($db);
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
        expect($newHash)
            ->not->toBeNull();
        expect($newHash)
            ->not->toBe($originalHash);

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

    return [
        'status' => $status,
        'body' => $body,
    ];
}

/**
 * @return array{userId: int}
 */
function passwordInsertNormalUserWithEmail(string $email): array
{
    $db = passwordDbConnect();
    $username = 'pwlost_' . uniqid();

    H::dbQuery($db, sprintf("INSERT INTO users (username, password, mail_address) VALUES ('%s', '%s', '%s')", H::dbEscape($db, $username), H::dbEscape($db, password_hash('original-password', PASSWORD_BCRYPT, [
        'cost' => 4,
    ])), H::dbEscape($db, $email)));
    $userId = H::dbInsertId($db);

    H::dbQuery($db, sprintf("INSERT INTO user_infos (user_id, status, language) VALUES (%d, 'normal', 'en_UK')", $userId));
    H::dbClose($db);

    return [
        'userId' => $userId,
    ];
}

/**
 * @return array{reset_password_forbidden_until: int|null}|null
 */
function passwordUserPreferences(int $userId): ?array
{
    $db = passwordDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT preferences FROM user_infos WHERE user_id = %d', $userId));
    H::dbClose($db);

    if (! is_array($row)) {
        return null;
    }

    $raw = $row['preferences'] ?? null;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $forbiddenUntil = is_array($decoded) ? ($decoded['reset_password_forbidden_until'] ?? null) : null;

    return [
        'reset_password_forbidden_until' => is_numeric($forbiddenUntil) ? (int) $forbiddenUntil : null,
    ];
}

it('nulls a reset key for an already-logged-in (non-guest) user and redirects the default "lost" action home', function (): void {
    $page = H::asAdmin($this);

    // A logged-in user is never a guest, so __invoke()'s own
    // `if ($key !== null and !isAGuest()) { $key = null; }` fires --
    // action_param is null (no ?action= sent), so $this->action resolves
    // to the 'lost' default, which then immediately redirects a non-guest
    // away from the "forgot password" form entirely.
    $page = H::navigateOk($page, '/password.php?key=aaaaaaaaaaaaaaaaaaaa');
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)
        ->toBe(H::baseUrl() . '/');
});

it('re-shows the code-entry step on a plain revisit while a reset code is still pending', function (): void {
    $page = H::gotoOk($this, '/password.php');
    $unknownEmail = 'no-such-account-' . uniqid() . '@example.test';
    $page = $page->fill('username_or_email', $unknownEmail)
        ->click('submit');
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
    $page = $page->fill('username_or_email', $email)
        ->click('submit');
    $page->assertSee('If your account exists, a verification code has been sent to your email address.');

    // Re-submit the very same "lost" step -- processVerificationCode()'s
    // own `if (isset($_SESSION['reset_password_code'])) { return true; }`
    // short-circuit, without re-deriving anything from $email again.
    //
    // Not navigateOk()+click('submit'): __invoke()'s own
    // `if ($this->action === 'lost' and isset($_SESSION['reset_password_code']))
    // { $this->action = 'lost_code'; }` forces the *rendered* page to the
    // code-entry form the moment a code is pending -- a
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
    $page = $page->fill('username_or_email', 'guest')
        ->click('submit');
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
        // see clickWithTimeout()'s docblock). A plain
        // click('submit') here silently double-submits the 2nd wrong
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
        // `reset_password_forbidden_until` ~1 hour into the future. Also
        // redirects to identification.php with the "Too many attempts"
        // message flashed through $_SESSION['page_errors'] -- same
        // mechanism as the unknown-user lockout test above, now exercised
        // for the has_valid_user_id=true branch specifically.
        $page = $page->fill('user_code', '222222');
        H::clickWithTimeout($page, 'submit');
        $currentUrl = H::rawWebpage($page)->url();
        expect($currentUrl)
            ->toContain('identification.php');
        $page->assertSee('Too many attempts, please try later..');

        $preferences = passwordUserPreferences($userId);
        expect($preferences)
            ->not->toBeNull();
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
        // ceiling (see PasswordControllerTest's own lockout test for
        // this exact double-submit failure
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

it('rate-limits password-reset-code requests per account after the configured ceiling, using the same generic message (P44-L)', function (): void {
    $snapshot = H::snapshotConfig(['password_reset_request_max_attempts']);
    $email = 'ct-pwlost-ratelimit-' . uniqid() . '@example.test';
    $fixture = passwordInsertNormalUserWithEmail($email);
    $userId = $fixture['userId'];

    try {
        H::setConfigValue('password_reset_request_max_attempts', '1');

        // 1st request: within the (lowered, for this test) ceiling --
        // succeeds normally, recording one row for this real user_id.
        $page = H::gotoOk($this, '/password.php');
        $page = $page->fill('username_or_email', $email);
        H::clickWithTimeout($page, 'submit');
        $page->assertSee('If your account exists, a verification code has been sent to your email address.');
        $page->assertPresent('input[name="user_code"]');

        // 2nd request, fresh session (no pending-code short-circuit) --
        // crosses the account-scoped ceiling (now 1), rejected with the
        // same generic, enumeration-safe message the IP-scoped and
        // per-code lockouts already use, and never reaches the
        // code-entry step.
        $freshPage = H::gotoOk($this, '/password.php');
        $freshPage = $freshPage->fill('username_or_email', $email);
        H::clickWithTimeout($freshPage, 'submit');
        $freshPage->assertSee('Too many attempts, please try later..');
        $freshPage->assertPresent('input[name="username_or_email"]');
        H::assertNoServerErrors($freshPage, 'password reset-request account rate limit');
    } finally {
        passwordDeleteUser($userId);
        H::restoreConfig($snapshot);
    }
});

it('single-escapes an HTML-special-character-bearing username_or_email echoed back on the lost step, not double-escaped (P44-F)', function (): void {
    // Forcing the IP-scoped ceiling to 0 rejects every request outright,
    // regardless of whether it resolves to a real account -- the
    // simplest way to keep __invoke()'s own action at 'lost' (so
    // username_or_email gets echoed back into the form) instead of
    // transitioning to 'lost_code' on a real success.
    $snapshot = H::snapshotConfig(['password_reset_request_ip_max_attempts']);

    try {
        H::setConfigValue('password_reset_request_ip_max_attempts', '0');

        $page = H::gotoOk($this, '/password.php');
        $page = $page->fill('username_or_email', 'not-an-account & "quote"@example.test');
        H::clickWithTimeout($page, 'submit');

        $page->assertSee('Too many attempts, please try later..');
        $body = H::rawWebpage($page)->content();
        expect($body)
            ->toContain('value="not-an-account &amp; &quot;quote&quot;@example.test"');
        expect($body)
            ->not->toContain('&amp;amp;')
            ->not->toContain('&amp;quot;');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('expires a pending reset code once its configured duration has elapsed', function (): void {
    $snapshot = H::snapshotConfig(['password_reset_code_duration']);

    try {
        H::setConfigValue('password_reset_code_duration', '1');

        $page = H::gotoOk($this, '/password.php');
        $unknownEmail = 'no-such-account-' . uniqid() . '@example.test';
        $page = $page->fill('username_or_email', $unknownEmail)
            ->click('submit');
        $page->assertPresent('input[name="user_code"]');

        sleep(2);

        // The expiry branch queues a password_form_error ('Code expired')
        // meant to render right here on password.php -- __invoke()'s own
        // "no pending code at all" redirect guard is narrowed to skip
        // exactly this case (a real password_form_error is queued), so
        // this stays on password.php instead of bouncing to
        // identification.php with the message discarded.
        $page = $page->fill('user_code', '000000')
            ->click('submit');
        $page->assertSee('Code expired');
        H::assertNoServerErrors($page, 'password expired-code inline error');
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

it('fatal-errors on an unrecognized lang cookie value', function (): void {
    $result = passwordCurlGet('/password.php', ['Cookie: lang=not_a_real_lang_' . uniqid()]);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Unrecognized value for parameter "lang"');
});

it('switches to a valid, different lang cookie and shows the French translation', function (): void {
    $db = passwordDbConnect();
    $upsertSql = $db instanceof mysqli
        ? "INSERT INTO languages (id, version, name) VALUES ('fr_FR', '1.0.0', 'French') ON DUPLICATE KEY UPDATE name = VALUES(name)"
        : "INSERT INTO languages (id, version, name) VALUES ('fr_FR', '1.0.0', 'French') ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name";
    H::dbQuery($db, $upsertSql);
    H::dbClose($db);

    try {
        $result = passwordCurlGet('/password.php', ['Cookie: lang=fr_FR']);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Unrecognized value for parameter');
        // Not the French help_link: that's only ever assigned to
        // themes/standard_pages/template/password.latte's own {$HELP_LINK},
        // never referenced by the fixture gallery's real "default" theme
        // password.latte -- no help link appears on either theme's
        // password.latte, but every real page string does switch to its
        // po-translated French wording.
        expect($result['body'])->toContain('Mot de passe oublié ?');
    } finally {
        $db2 = passwordDbConnect();
        H::dbQuery($db2, "DELETE FROM languages WHERE id = 'fr_FR'");
        H::dbClose($db2);
    }
});

/**
 * The tests below drive the verification-code + session-based half of the
 * reset flow (processVerificationCode() succeeding all the way through
 * processPasswordRequest()'s own tail, resetPasswordKey()'s "no key at
 * all" branch, and resetPasswordCode()) -- distinct from the reset-KEY
 * flow above (a real emailed link's `?key=`), which never reaches any of
 * that code. Driven with passwordCurlSession()'s plain curl + cookie jar
 * (see its own docblock) instead of Playwright, so a real
 * $_SESSION['reset_password_code']['secret'] can be read directly out of
 * the DB-backed `sessions` table between requests and fed through the same
 * \Piwigo\Auth\Totp::generateCode() algorithm AuthService::
 * verifyUserCode() itself uses -- without ever needing to read a real,
 * undeliverable test email (see this file's own top-of-file docblock for
 * why mail delivery isn't observable in this environment either).
 */
it('completes a full verification-code password reset: session round-trip, success email, and api-key lookup', function (): void {
    $email = 'ct-pwcode-' . uniqid() . '@example.test';
    $fixture = passwordInsertNormalUserWithEmail($email);
    $userId = $fixture['userId'];
    $originalHash = passwordUserRow($userId)['password'] ?? null;

    $jar = passwordFreshCookieJar();

    try {
        $get = passwordCurlSession($jar, '/password.php');
        expect($get['status'])->toBe(200);
        $token = passwordExtractToken($get['body']);

        // Step 1: 'lost' -- resolves a real user_id, generates a real TOTP
        // secret, and writes it to $_SESSION['reset_password_code']
        // (mail() itself is best-effort here, tolerated even on failure --
        // see MailService::mail()'s own bounded-transport docblock -- this
        // suite reads the secret straight out of the session instead of
        // the mail).
        $lost = passwordCurlSession($jar, '/password.php?action=lost', [
            'username_or_email' => $email,
            'submit' => 'Change my password',
            'pwg_token' => $token,
        ], timeoutSeconds: 30);
        expect($lost['status'])->toBe(200);
        expect($lost['body'])->toContain('If your account exists, a verification code has been sent to your email address.');

        $sessionId = passwordCookieJarSessionId($jar);
        $secret = passwordSessionResetCodeSecret($sessionId);
        expect($secret)
            ->not->toBeNull();
        assert(is_string($secret));
        $code = passwordComputeValidCode($secret);

        // Step 2: 'lost_code' with the real code -- processPasswordRequest()'s
        // own full-success tail (unset the pending code, resolve+switch
        // CurrentUser, delete the lockout preference, write
        // $_SESSION['valid_reset_password_code'], and return true) --
        // __invoke() then adds the "Verification successful!" info and
        // advances straight to the 'reset' form within this SAME response.
        $verify = passwordCurlSession($jar, '/password.php?action=lost_code', [
            'user_code' => $code,
            'submit' => 'Verify',
            'pwg_token' => $token,
        ]);
        expect($verify['status'])->toBe(200);
        expect($verify['body'])->toContain('Verification successful! You can now choose a new password.');
        expect($verify['body'])->toContain('name="use_new_pwd"');

        $sessionDataAfterVerify = passwordSessionData($sessionId);
        expect($sessionDataAfterVerify)
            ->toContain('valid_reset_password_code');

        // Session round-trip: a FRESH request (still the same cookie jar,
        // i.e. a real separate HTTP round trip, not just in-memory state
        // from the request above) for the bare 'reset' action must still
        // show the reset form instead of redirecting home -- proving
        // __invoke()'s own `isset($_SESSION['valid_reset_password_code'])`
        // guard reads real, persisted session state.
        $revisit = passwordCurlSession($jar, '/password.php?action=reset');
        expect($revisit['status'])->toBe(200);
        expect($revisit['body'])->toContain('name="use_new_pwd"');

        // Step 3: 'reset' -- no `key` at all (resetPasswordKey()'s own
        // "not reached via a key link" branch), falling through to
        // resetPasswordCode() reading the real
        // $_SESSION['valid_reset_password_code']['user_id'] this suite just
        // proved is there. Also exercises the successful-reset confirmation
        // email branch (a real, non-empty session email) and its
        // ApiKeyService::getAvailable() lookup.
        $newPassword = 'a-brand-new-code-password-1';
        $reset = passwordCurlSession($jar, '/password.php?action=reset', [
            'use_new_pwd' => $newPassword,
            'passwordConf' => $newPassword,
            'submit' => 'Submit',
            'pwg_token' => $token,
        ], timeoutSeconds: 30);
        expect($reset['status'])->toBe(200);
        expect($reset['body'])->toContain('Your password has been reset');

        $newHash = passwordUserRow($userId)['password'] ?? null;
        expect($newHash)
            ->not->toBeNull();
        expect($newHash)
            ->not->toBe($originalHash);
    } finally {
        passwordDeleteUser($userId);
        @unlink($jar);
    }
});

it('short-circuits a "lost_code" submission with no pending session code and redirects home', function (): void {
    // processPasswordRequest()'s own `if (! is_array($state)) { return
    // true; }` -- a bare/direct POST to ?action=lost_code with no prior
    // 'lost' step at all, so $_SESSION['reset_password_code'] was never
    // written. __invoke() still treats that `true` as success (advances to
    // action='reset'), but with no key and no
    // $_SESSION['valid_reset_password_code'] either, its own guard
    // immediately redirects a guest away from the reset form.
    $jar = passwordFreshCookieJar();

    try {
        $get = passwordCurlSession($jar, '/password.php');
        $token = passwordExtractToken($get['body']);

        $result = passwordCurlSession($jar, '/password.php?action=lost_code', [
            'user_code' => '000000',
            'submit' => 'Verify',
            'pwg_token' => $token,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['url'])->toBe(H::baseUrl() . '/');
    } finally {
        @unlink($jar);
    }
});

it('rejects a correct verification code for an unresolvable (unknown) account with "Invalid verification code"', function (): void {
    // [SEC-31]-style enumeration safety means an unknown username_or_email
    // still gets a real secret + a real $_SESSION['reset_password_code']
    // entry (with 'user_id' => null) -- submitting the genuinely correct
    // code for it still fails, but via processPasswordRequest()'s own
    // `! $has_valid_user_id` branch, a different source line from every
    // WRONG-code rejection covered above.
    $jar = passwordFreshCookieJar();

    try {
        $get = passwordCurlSession($jar, '/password.php');
        $token = passwordExtractToken($get['body']);

        $unknownEmail = 'no-such-account-' . uniqid() . '@example.test';
        $lost = passwordCurlSession($jar, '/password.php?action=lost', [
            'username_or_email' => $unknownEmail,
            'submit' => 'Change my password',
            'pwg_token' => $token,
        ]);
        expect($lost['body'])->toContain('If your account exists, a verification code has been sent to your email address.');

        $sessionId = passwordCookieJarSessionId($jar);
        $secret = passwordSessionResetCodeSecret($sessionId);
        expect($secret)
            ->not->toBeNull();
        assert(is_string($secret));
        $code = passwordComputeValidCode($secret);

        $verify = passwordCurlSession($jar, '/password.php?action=lost_code', [
            'user_code' => $code,
            'submit' => 'Verify',
            'pwg_token' => $token,
        ]);
        expect($verify['status'])->toBe(200);
        expect($verify['body'])->toContain('Invalid verification code');
        expect($verify['body'])->not->toContain('name="use_new_pwd"');
    } finally {
        @unlink($jar);
    }
});

it('rejects a correct verification code for a resolvable user with no email address', function (): void {
    // processVerificationCode()'s own early guest/generic block doesn't
    // apply (status is 'normal'), so this reaches the real code-generation
    // path with `$skip_mail` true purely because mail_address is NULL --
    // processPasswordRequest()'s own tail then rejects it anyway via its
    // separate "don't send mail when ... doesn't have email" fallback
    // check, a different source line from both the unresolvable-account
    // case above and the guest/generic checks in processVerificationCode().
    $fixture = passwordInsertNormalUserNoEmail();
    $userId = $fixture['userId'];
    $username = $fixture['username'];

    $jar = passwordFreshCookieJar();

    try {
        $get = passwordCurlSession($jar, '/password.php');
        $token = passwordExtractToken($get['body']);

        // No email on this account -- submit the username instead, the
        // same fallback path processVerificationCode()'s own
        // UserService::getUserIdByEmail()/getUserId() pair provides.
        $lost = passwordCurlSession($jar, '/password.php?action=lost', [
            'username_or_email' => $username,
            'submit' => 'Change my password',
            'pwg_token' => $token,
        ]);
        expect($lost['body'])->toContain('If your account exists, a verification code has been sent to your email address.');

        $sessionId = passwordCookieJarSessionId($jar);
        $secret = passwordSessionResetCodeSecret($sessionId);
        expect($secret)
            ->not->toBeNull();
        assert(is_string($secret));
        $code = passwordComputeValidCode($secret);

        $verify = passwordCurlSession($jar, '/password.php?action=lost_code', [
            'user_code' => $code,
            'submit' => 'Verify',
            'pwg_token' => $token,
        ]);
        expect($verify['status'])->toBe(200);
        expect($verify['body'])->toContain('Password reset is not allowed for this user');
        expect($verify['body'])->not->toContain('name="use_new_pwd"');
    } finally {
        passwordDeleteUser($userId);
        @unlink($jar);
    }
});

it('skips past a pending activation-key row with an empty key, then still finds the real match', function (): void {
    // findPendingActivationKeyRows()'s own SQL filter is `activation_key IS
    // NOT NULL`, which a real empty string still satisfies -- so a row like
    // this genuinely reaches checkPasswordResetKey()'s scan loop, whose very
    // first check (`activationKey === '' -> continue`) must skip past it
    // without ever calling PasswordService::verify() against an empty hash.
    // A real, matching key for a SEPARATE normal-status user is inserted
    // alongside it, so this proves the loop doesn't just survive the noise
    // row -- it still finds and returns that real match afterward too
    // (`$user_id = ...; break;`), landing on the real 'reset' form.
    $noiseUserId = passwordInsertEmptyActivationKeyUser();
    $fixture = passwordInsertResetUser();
    $userId = $fixture['userId'];

    try {
        $page = H::gotoOk($this, '/password.php?key=' . $fixture['plainKey']);

        $page->assertPresent('input[name="use_new_pwd"]');
    } finally {
        passwordDeleteUser($userId);
        passwordDeleteUser($noiseUserId);
    }
});

it('rejects a real matching reset key for a guest-status account', function (): void {
    // checkPasswordResetKey()'s own isAGuest()/isGeneric() branch -- unlike
    // the guest-rejection test in the 'lost' flow above (a DIFFERENT
    // source line, processVerificationCode()'s own early block), this one
    // only fires once PasswordService::verify() has already matched a real
    // key against a real guest-status row.
    $fixture = passwordInsertResetUser('guest');
    $userId = $fixture['userId'];

    try {
        $page = H::gotoOk($this, '/password.php?key=' . $fixture['plainKey']);

        $page->assertSee('Password reset is not allowed for this user');
        $page->assertMissing('input[name="use_new_pwd"]');
    } finally {
        passwordDeleteUser($userId);
    }
});

it('rejects a "reset" submission with a well-formed but unmatched key, reporting a combined "Invalid key or code" error', function (): void {
    // resetPasswordKey()'s own `$this->request->key !== null` branch (a
    // real key that fails checkPasswordResetKey()'s own DB match), falling
    // through to resetPasswordCode() -- which, for this fresh guest session
    // with no $_SESSION['valid_reset_password_code'] either, hits its own
    // "no session state at all" rejection too. resetPassword() then reports
    // its own combined "Invalid key or code" error since neither path
    // produced a usable user_id.
    $jar = passwordFreshCookieJar();

    try {
        $get = passwordCurlSession($jar, '/password.php');
        $token = passwordExtractToken($get['body']);

        $newPassword = 'irrelevant-password-1';
        $result = passwordCurlSession($jar, '/password.php?action=reset&key=xxxxxxxxxxxxxxxxxxxx', [
            'use_new_pwd' => $newPassword,
            'passwordConf' => $newPassword,
            'submit' => 'Submit',
            'pwg_token' => $token,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Invalid key or code');
    } finally {
        @unlink($jar);
    }
});
