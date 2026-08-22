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
 * Regression coverage for a fixed bug: submitting
 * the register form with `send_password_by_mail` checked -- register.latte's
 * own checkbox is `checked="checked"` UNCONDITIONALLY, so this is what
 * every real browser submission sends by default, not an edge case -- used
 * to make the request hang for minutes instead of responding.
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
 * Every test below needs self-registration genuinely open to reach
 * anything past RegisterController::__invoke()'s own pageForbidden() gate
 * -- `allow_user_registration` is shared, global config across the whole
 * Browser suite run (same caveat every H::setConfigValue()-using file in
 * this suite already documents): a concurrent process leaving it set to
 * `false` makes a bare curl request against /register.php come back the
 * real "User registration closed" 403 instead of the
 * register form. Resetting it to the fixture's own documented default
 * ('true', see tests/Fixtures/piwigo-17.0.sql) both before AND after each
 * test (not a snapshot/restore round trip) matches
 * CatOptionsPageRendererTest.php's own established pattern of restoring a
 * contended piece of shared state to its known-good fixture value rather
 * than whatever a snapshot happened to capture.
 */
beforeEach(function (): void {
    H::setConfigValue('allow_user_registration', 'true');
});

afterEach(function (): void {
    H::setConfigValue('allow_user_registration', 'true');
});

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
    unset($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * Extracts register.latte's hidden F_KEY value from a GET /register.php response body.
 */
function registerExtractKey(string $html): string
{
    if (preg_match('/name="key" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Could not find the register form\'s hidden key field in: ' . $html);
    }

    return $matches[1];
}

function registerUserCount(string $username): int
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT COUNT(*) AS c FROM users WHERE username = '%s'", H::dbEscape($db, $username)));
    H::dbClose($db);

    return is_array($row) ? (int) $row['c'] : -1;
}

function registerUserExists(string $username): bool
{
    return registerUserCount($username) > 0;
}

/**
 * @return string a fresh cookie-jar path
 */
function registerFreshCookieJar(): string
{
    $jar = tempnam(sys_get_temp_dir(), 'pwg_register_test_');
    if ($jar === false) {
        throw new RuntimeException('tempnam failed');
    }

    return $jar;
}

/**
 * Plain anonymous GET carrying a raw `Cookie:` header, for exercising
 * RegisterController's own $_COOKIE['lang'] handling directly --
 * registerCurl()'s CURLOPT_COOKIEFILE/COOKIEJAR pair only ever replays a
 * real Set-Cookie the server itself sent, it can't send an arbitrary,
 * hand-crafted Cookie header like the ones below need (including one that
 * isn't even a valid string value).
 *
 * @return array{status: int, body: string}
 */
function registerCurlWithRawCookie(string $path, string $rawCookieHeader): array
{
    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(H::testHeaders(), ['Cookie: ' . $rawCookieHeader]));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * Temporarily registers a language row in `languages` -- this
 * fixture only ever ships `en_UK`,
 * but LangService::getLanguages() requires a real DB row (on top of a
 * real on-disk `language/<code>/` directory, which `fr_FR` genuinely has)
 * before RegisterController's own `array_key_exists($lang_cookie, ...)`
 * check accepts it. Caller must pair this with registerRemoveLanguage().
 */
function registerAddLanguage(string $code, string $name): void
{
    $db = H::connect();
    $upsertSql = $db instanceof mysqli
        ? "INSERT INTO languages (id, version, name) VALUES ('%s', '16.3.0', '%s') ON DUPLICATE KEY UPDATE version = VALUES(version)"
        : "INSERT INTO languages (id, version, name) VALUES ('%s', '16.3.0', '%s') ON CONFLICT (id) DO UPDATE SET version = EXCLUDED.version";
    H::dbQuery($db, sprintf(
        $upsertSql,
        H::dbEscape($db, $code),
        H::dbEscape($db, $name)
    ));
    H::dbClose($db);
}

/**
 * Reverts registerAddLanguage().
 */
function registerRemoveLanguage(string $code): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf("DELETE FROM languages WHERE id = '%s'", H::dbEscape($db, $code)));
    H::dbClose($db);
}

it('registers a brand-new user, auto-logs them in, and creates the real DB row', function (): void {
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7); // clear the 6-second anti-bot minimum form-key age

    $username = 'browser_reg_' . uniqid();
    $email = $username . '@example.test';
    $password = 'S3cure!Pass_' . uniqid();

    expect(registerUserExists($username))
        ->toBeFalse();

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
    expect(registerUserExists($username))
        ->toBeTrue();

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

    expect(registerUserExists($username))
        ->toBeFalse();

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
    expect(registerUserExists($username))
        ->toBeTrue();
    expect($elapsedSeconds)
        ->toBeLessThan(20.0);

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

    expect(registerUserExists($username))
        ->toBeFalse();

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
    expect(registerUserExists($username))
        ->toBeFalse();

    @unlink($jar);
});

// registerUser()'s own duplicate-username branch calls
// notifyExistingAccountOfDuplicateRegistration(), which sends a real
// "someone tried to register your username" email to fixture_admin's own
// real address -- the same slow/unreachable local mail transport this
// file's own docblock documents for send_password_by_mail, just hit from a
// different call site. Before the BoundedSendmailTransport fix this one
// consistently took ~127s; now bounded to Piwigo\Mail\MailService::
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

it('shows a Forbidden page and never renders the form when registration is closed', function (): void {
    // beforeEach() above already forced allow_user_registration to 'true'
    // for this test like every other one in this file -- override it back
    // to 'false' here, this test's own subject. afterEach() resets it back
    // to 'true' again afterward regardless of how this test finishes.
    H::setConfigValue('allow_user_registration', 'false');

    $jar = registerFreshCookieJar();
    $result = registerCurl($jar, '/register.php');

    // RegisterController::__invoke()'s own pageForbidden() call -- unlike
    // the invalid-key branch below, this real 403 already threads through
    // correctly (HtmlService::pageForbidden() passes its status straight
    // into RedirectServiceInterface::redirectHtml(), which builds its own
    // Response and throws before this controller ever reaches its own
    // final return).
    expect($result['status'])->toBe(403);
    expect($result['body'])->toContain('Forbidden');
    expect($result['body'])->toContain('User registration closed');
    // Never got as far as building the actual register form.
    expect($result['body'])->not->toContain('name="register_form"');

    @unlink($jar);
});

it('rejects an invalid/expired form key with a real 403 and does not attempt registration', function (): void {
    // Regression test for a fixed bug:
    // RegisterController::__invoke()'s invalid-key branch used to call
    // HtmlService::setStatusHeader(403) directly -- a bare header() call
    // that Http\ResponseEmitter::emit() always overwrites with the final
    // Response's own status code (see PictureController's identical,
    // already-documented recent_pics bug for the same root cause). Since
    // this method's own final `return ResponseFactory::html($body)` used
    // to hard-code 200, an invalid/expired key always came back 200, never
    // the intended 403. Fixed by threading a local $status through to
    // that final Response instead of the dead setStatusHeader() call; this
    // test both closes the coverage gap and pins the fix.
    //
    // No real GET-then-sleep(7) dance needed here (unlike every other test
    // in this file) -- an arbitrary malformed key fails
    // EphemeralKeyService::verify()'s very first structural check
    // (explode(':', $key) not having exactly 3 parts) regardless of age,
    // so there's no real form key to mint first.
    $jar = registerFreshCookieJar();

    $username = 'browser_reg_badkey_' . uniqid();

    expect(registerUserExists($username))
        ->toBeFalse();

    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        'password' => 'SomePassword123!',
        'password_conf' => 'SomePassword123!',
        'mail_address' => $username . '@example.test',
        'key' => 'not-a-real-key-at-all',
        'submit' => 'Register',
    ]);

    expect($result['status'])->toBe(403);
    expect($result['body'])->toContain('Invalid/expired form key');
    expect(registerUserExists($username))
        ->toBeFalse();

    @unlink($jar);
});

it('shows "password is missing" and does not create an account when the password is empty', function (): void {
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7);

    $username = 'browser_reg_emptypw_' . uniqid();

    expect(registerUserExists($username))
        ->toBeFalse();

    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        'password' => '',
        'password_conf' => '',
        'mail_address' => $username . '@example.test',
        'key' => $key,
        'submit' => 'Register',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Password is missing. Please enter the password.');
    expect(registerUserExists($username))
        ->toBeFalse();

    @unlink($jar);
});

it('shows "password confirmation is missing" and does not create an account when only password_conf is empty', function (): void {
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7);

    $username = 'browser_reg_emptypwconf_' . uniqid();

    expect(registerUserExists($username))
        ->toBeFalse();

    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        // Non-empty and NOT equal to password_conf -- this must hit the
        // dedicated "confirmation is missing" branch, not fall through to
        // the (already-covered) "passwords do not match" one.
        'password' => 'SomePassword123!',
        'password_conf' => '',
        'mail_address' => $username . '@example.test',
        'key' => $key,
        'submit' => 'Register',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Password confirmation is missing. Please confirm the chosen password.');
    expect(registerUserExists($username))
        ->toBeFalse();

    @unlink($jar);
});

it("surfaces registerUser()'s own validation errors (invalid email format) and does not create an account", function (): void {
    // Distinct from the [SEC-31] duplicate-username case above:
    // registerUser() returns a real, non-empty errors array here (not the
    // duplicate-username userId:null/errors:[] shape), which is the branch
    // that appends implode(' ', $registration_errors) onto
    // $errors['register_form_error'].
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7);

    $username = 'browser_reg_bademail_' . uniqid();

    expect(registerUserExists($username))
        ->toBeFalse();

    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        'password' => 'SomePassword123!',
        'password_conf' => 'SomePassword123!',
        'mail_address' => 'not-an-email-address',
        'key' => $key,
        'submit' => 'Register',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('mail address must be like');
    expect(registerUserExists($username))
        ->toBeFalse();

    @unlink($jar);
});

it('single-escapes an HTML-special-character-bearing formEmail on a failed registration, not double-escaped (P44-F)', function (): void {
    // Same "invalid email format" failure branch as the test above --
    // login stays a plain, Username-VO-valid string (that VO now rejects
    // <>&"' outright, P44-H), while mail_address carries the HTML-special
    // characters this test actually checks the echo-back escaping of.
    $jar = registerFreshCookieJar();
    $get = registerCurl($jar, '/register.php');
    $key = registerExtractKey($get['body']);
    sleep(7);

    $username = 'browser_reg_escaping_' . uniqid();

    $result = registerCurl($jar, '/register.php', [
        'login' => $username,
        'password' => 'SomePassword123!',
        'password_conf' => 'SomePassword123!',
        'mail_address' => 'not-an-email & "quote"',
        'key' => $key,
        'submit' => 'Register',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('mail address must be like');
    expect($result['body'])->toContain('value="not-an-email &amp; &quot;quote&quot;"');
    expect($result['body'])->not->toContain('&amp;amp;');
    expect(registerUserExists($username))
        ->toBeFalse();

    @unlink($jar);
});

it('treats a non-string lang cookie (PHP array syntax) as an invalid request parameter and returns a fatal 500', function (): void {
    // `Cookie: lang[]=x` parses into $_COOKIE['lang'] as a genuine PHP
    // array (PHP applies the same bracket-name parsing to cookies as it
    // does to GET/POST params). HtmlService::fatalError() always responds 500 regardless
    // of its specific message (see that method's own docblock) --
    // independent of the $status-threading fix above, since this path
    // throws its own ResponseReadyException long before RegisterController's
    // own final `return` is ever reached.
    $result = registerCurlWithRawCookie('/register.php', 'lang[]=fr_FR');

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Invalid request parameter "lang"');
});

it('treats an unregistered lang cookie value as an unrecognized value and returns a fatal 500', function (): void {
    // A syntactically fine string, but not a language LangService::
    // getLanguages() recognizes (the fixture only ships `en_UK`) -- a
    // different fatalError() call than the array case above.
    $result = registerCurlWithRawCookie('/register.php', 'lang=zz_ZZ');

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Unrecognized value for parameter "lang"');
});

it("applies a valid, different lang cookie: switches CurrentUser's language, loads its translations, and swaps in the French help link", function (): void {
    // The fixture only ships `en_UK` in `languages` (this file's
    // own docblock) -- LangService::getLanguages()'s own
    // array_key_exists() check against a `lang` cookie never accepts
    // anything else without a real DB row first. `fr_FR` is a real,
    // on-disk core language pack (language/fr_FR/common.po exists), just
    // not registered in this fixture's DB -- registering it temporarily is
    // the minimal state needed to exercise
    // CurrentUser::updateLanguage()/Lang::load() for real, rather than
    // just inferring they ran from a bare 200 status.
    registerAddLanguage('fr_FR', 'Français');

    // The default theme's own register.latte never references {$HELP_LINK}
    // at all -- only standard_pages' register.latte
    // renders it (`<a href="{$HELP_LINK}">`), so swapping the guest's
    // theme is what makes this test's own French-help-link assertion a
    // real, visible behavior rather than an inference. Same rationale as
    // H::setGuestTheme()'s own docblock.
    H::setGuestTheme('standard_pages');

    try {
        $result = registerCurlWithRawCookie('/register.php', 'lang=fr_FR');

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Invalid request parameter');
        expect($result['body'])->not->toContain('Unrecognized value for parameter');
        // The French help-link branch itself (str_starts_with(..., 'fr')).
        expect($result['body'])->toContain('https://upstream.example.invalid/help/fr/');
        // Lang::load('common.lang', ..., ['language' => 'fr_FR']) really
        // loaded French translations -- standard_pages' own register.latte
        // heading is real, translated body content, not just metadata.
        expect($result['body'])->toContain('Créez un compte');
        // current_language template var reflects CurrentUser::updateLanguage().
        // Two independent checks, not one literal HTML-shaped string -- P32's
        // reformat wraps this <span>'s id attribute onto its own line, so the
        // closing '>' is never adjacent to the attribute in the real output.
        expect($result['body'])->toContain('id="selected-language"');
        expect($result['body'])->toContain('>Français<');
    } finally {
        H::setGuestTheme('default');
        registerRemoveLanguage('fr_FR');
    }
});
