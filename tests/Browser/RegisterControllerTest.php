<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\RegisterController (register.php) -- the new-user
 * self-registration form + POST handler. Every submission's form key is an
 * EphemeralKeyService one, keyed on the REAL wall clock (microtime(true)),
 * not PIWIGO_TEST_NOW -- register.php's own initial GET mints a
 * generate(6) key (a 6-second anti-bot minimum age), and a failed
 * resubmission's own re-rendered key is generate(2) -- so a real
 * `sleep()` between GET and POST is genuinely necessary here, matching how
 * a real browser filling the form out would naturally take at least that
 * long.
 *
 * Driven with plain curl + a cookie jar (matching RegenerateFixtureTest.php's
 * own established style), not Pest's Playwright-driven $page->fill()/
 * ->click() API used by every other file in this suite -- a plain, isolated
 * HTTP client makes the confirmed bug documented below (and its
 * reproduction) unambiguous and independent of any browser-tooling
 * behavior.
 *
 * Regression coverage for a fixed bug, found while writing this file
 * (reproduced directly with a bare curl request, independent of Pest/
 * Playwright entirely, before writing any assertion around it): submitting
 * the register form with `send_password_by_mail` checked -- register.tpl's
 * own checkbox is `checked="checked"` UNCONDITIONALLY, so this is what
 * every real browser submission sends by default, not an edge case -- used
 * to make the request hang for minutes (observed >2 minutes before being
 * killed; never confirmed to resolve on its own) instead of responding.
 * UserService::registerUser()'s mail-sending path went through
 * MailService::mail()'s underlying transport with no timeout of its own in
 * this environment (no smtp_host configured -> Symfony Mailer's own
 * SendmailTransport, an unbounded proc_open() around the local `sendmail`
 * binary), so a slow/unreachable local MTA blocked the entire HTTP
 * response indefinitely. Fixed by Piwigo\Mail\BoundedSendmailTransport
 * (see its own docblock) -- MailService::mail() now always completes (or
 * fails) within a bounded ~10s regardless of transport health. Most POSTs
 * below still omit `send_password_by_mail` to stay fast when they're
 * testing something else entirely; the one dedicated test below exercises
 * the checked-by-default path itself.
 */

/**
 * @param array<string, string> $fields
 * @return array{status: int, body: string}
 */
function registerCurl(string $cookieJar, string $path, array $fields = [], ?int $timeoutSeconds = null): array
{
    if ($cookieJar === '') {
        throw new RuntimeException('registerCurl(): cookieJar must not be empty');
    }

    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Piwigo-Env: test']);
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
    unset($ch);

    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/** Extracts register.tpl's hidden F_KEY value from a GET /register.php response body. */
function registerExtractKey(string $html): string
{
    if (preg_match('/name="key" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Could not find the register form\'s hidden key field in: ' . $html);
    }

    return $matches[1];
}

function registerUserCount(string $username): int
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
        "SELECT COUNT(*) AS c FROM %susers WHERE username = '%s'",
        $prefix,
        $db->real_escape_string($username)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) ? (int) $row['c'] : -1;
}

function registerUserExists(string $username): bool
{
    return registerUserCount($username) > 0;
}

/** @return string a fresh cookie-jar path */
function registerFreshCookieJar(): string
{
    $jar = tempnam(sys_get_temp_dir(), 'pwg_register_test_');
    if ($jar === false) {
        throw new RuntimeException('tempnam failed');
    }

    return $jar;
}

it('registers a brand-new user, auto-logs them in, and creates the real DB row', function (): void {
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7); // clear the 6-second anti-bot minimum form-key age

    $username = 'browser_reg_' . uniqid();
    $email = $username . '@example.test';
    $password = 'S3cure!Pass_' . uniqid();

    expect(registerUserExists($username))->toBeFalse();

    // send_password_by_mail omitted here -- this test isn't about mail,
    // see the dedicated regression test below for that checked-by-default
    // path specifically.
    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        'password' => $password,
        'password_conf' => $password,
        'mail_address' => $email,
        'key' => $key,
        'submit' => 'Register',
    ]);

    // A real success redirects to the gallery home and auto-logs the new
    // user in (RegisterController's own [SEC-57]-adjacent auto-login step)
    // -- the same authenticated-session marker loginAsAdmin() itself
    // checks for.
    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('act=logout');
    expect(registerUserExists($username))->toBeTrue();

    @unlink($jar);
});

it('registers successfully within a bounded time even with send_password_by_mail checked', function (): void {
    // Regression test for a fixed bug -- see this file's own docblock.
    // This environment has no smtp_host configured, so the welcome email
    // goes through Piwigo\Mail\BoundedSendmailTransport, whose real local
    // `sendmail` binary hangs trying to actually deliver -- registration
    // must still complete (the email send failure is tolerated, not
    // fatal) well within BoundedSendmailTransport's own bound, not the
    // >2-minute hang this used to take.
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7);

    $username = 'browser_reg_mail_' . uniqid();
    $email = $username . '@example.test';
    $password = 'S3cure!Pass_' . uniqid();

    expect(registerUserExists($username))->toBeFalse();

    $start = hrtime(true);
    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        'password' => $password,
        'password_conf' => $password,
        'mail_address' => $email,
        'key' => $key,
        'submit' => 'Register',
        'send_password_by_mail' => 'on',
    ], timeoutSeconds: 30);
    $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('act=logout');
    expect(registerUserExists($username))->toBeTrue();
    expect($elapsedSeconds)->toBeLessThan(20.0);

    @unlink($jar);
});

it('shows "passwords do not match" and does not create an account', function (): void {
    // Regression test for a fixed bug: RegisterController's own
    // password-mismatch check (`$post_password_raw !== $post_password_conf_raw`)
    // used to only ever append to $errors['register_form_error'] without
    // gating the very next, unconditional call to
    // `$userService->registerUser($post_login, $post_password, ...)` --
    // that call (and everything through the final redirect) now only runs
    // when `$errors === []`, so a mismatched confirmation never reaches
    // registerUser() at all and no account is created.
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7);

    $username = 'browser_reg_mismatch_' . uniqid();

    expect(registerUserExists($username))->toBeFalse();

    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        'password' => 'FirstPassword123!',
        'password_conf' => 'ADifferentPassword456!',
        'mail_address' => $username . '@example.test',
        'key' => $key,
        'submit' => 'Register',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('The passwords do not match');
    expect(registerUserExists($username))->toBeFalse();

    @unlink($jar);
});

// registerUser()'s own duplicate-username branch calls
// notifyExistingAccountOfDuplicateRegistration(), which sends a real
// "someone tried to register your username" email to fixture_admin's own
// real address -- the same slow/unreachable local mail transport this
// file's own docblock documents for send_password_by_mail, just hit from a
// different call site. Before the BoundedSendmailTransport fix this one
// consistently took ~127s (confirmed reproducible across separate runs,
// not one-off contention); now bounded to Piwigo\Mail\MailService::
// MAIL_TRANSPORT_TIMEOUT_SECONDS instead.
it('[SEC-31] handles a duplicate username indistinguishably from a real success, without creating a second account', function (): void {
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7);

    // 'fixture_admin' already exists (the fixture's own seeded admin
    // account) -- registerUser()'s own duplicate-username branch must
    // behave EXACTLY like a real success here (same redirect, no visible
    // error) rather than leak that the username is taken.
    $result = registerCurl($jar, '/register.php', [
        'login' => H::ADMIN_USER,
        'password' => 'AnotherPassword789!',
        'password_conf' => 'AnotherPassword789!',
        'mail_address' => 'duplicate-attempt-' . uniqid() . '@example.test',
        'key' => $key,
        'submit' => 'Register',
    ]);

    // Behaves exactly like the real-success case above: 200 (redirect
    // followed), no visible error -- the requester gets no signal at all
    // that 'fixture_admin' was already taken. The current curl session
    // itself stays anonymous throughout (unlike a real new registration's
    // auto-login), since registerUser()'s own duplicate branch returns
    // userId: null and RegisterController never logs a null id in -- so,
    // unlike the real-success case above, 'act=logout' must NOT appear.
    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('act=logout');
    expect($result['body'])->not->toContain('Fatal error');
    expect($result['body'])->not->toContain('Uncaught');

    // Still exactly 1 -- no duplicate row was ever created.
    expect(registerUserCount(H::ADMIN_USER))->toBe(1);

    @unlink($jar);
});
