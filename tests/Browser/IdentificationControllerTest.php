<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\IdentificationController (identification.php) -- had
 * no dedicated test file despite being driven, incidentally, by nearly
 * every other Browser test through H::loginAsAdmin()'s own real form
 * submission (see that helper's own implementation). That gives the
 * *successful* login path enormous incidental line coverage already;
 * this file targets the controller's own distinct branches
 * H::loginAsAdmin() never reaches: a failed login attempt (the
 * 'login_form_error' branch) and the already-authenticated redirect
 * guard.
 *
 * This file also covers the `$has_session_cookie` "cookies blocked"
 * branch, the `insensitive_case_logon` -> UserService::searchCaseUsername()
 * branch, and the `$_COOKIE['lang']` handling block (non-string/
 * unregistered/valid-value branches, plus the French help-link swap) --
 * all driven with plain curl (mirroring
 * RegisterControllerTest.php's own established style for the same class
 * of test) rather than Pest's Playwright-driven $page API, since several
 * of these need precise control over which cookies are (or aren't) sent,
 * which a real browser context doesn't expose.
 */
it('shows an error and re-renders the form for invalid credentials', function (): void {
    $page = H::visitPwg($this, '/identification.php');
    H::assertNoServerErrors($page, 'identification page');

    $page = $page
        ->fill('username', H::ADMIN_USER)
        ->fill('password', 'definitely-the-wrong-password')
        ->click('login');

    H::assertNoServerErrors($page, 'post-failed-login page');
    $page->assertSee('Invalid username or password');
    // Still on the login form, not redirected to the gallery -- the
    // logout link (only present once actually authenticated) must be
    // absent.
    $page->assertNotPresent('a[href*="act=logout"]');
});

it('redirects an already-authenticated session away from the login form', function (): void {
    $page = H::loginAsAdmin($this);

    H::rawWebpage($page)->navigate(H::baseUrl() . '/identification.php');
    H::assertNoServerErrors($page, 'identification page while already logged in');

    // AccessControl::isAGuest() being false redirects straight to the
    // gallery home -- the login form itself must never render.
    $page->assertNotPresent('input[name="username"]');
});

/**
 * @param array<string, string> $fields
 * @return array{status: int, body: string}
 */
function identCurl(string $cookieJar, array $fields = []): array
{
    if ($cookieJar === '') {
        throw new RuntimeException('identCurl(): cookieJar must not be empty');
    }

    // IdentificationController's own cookies-blocked check (line ~76) is
    // isset($_COOKIE[session_name()]) -- true only when *this* request
    // sends back a session cookie an *earlier* response already set.
    // login=Submit alone, straight into a brand-new/empty $cookieJar, has
    // nothing to send back yet (the server can't set a cookie the client
    // returns within the very same request), so it always hits the
    // "cookies blocked" branch instead of the real username/
    // password check, regardless of credentials. A real browser visits
    // the login page first (receiving the session cookie), then submits
    // the form -- this priming GET reproduces that, matching what this
    // function's own docblock elsewhere calls "a real round trip", unlike
    // identCurlNoCookies()'s deliberate single-request no-cookie case.
    if ($fields !== []) {
        $primeCh = curl_init(H::baseUrl() . '/identification.php');
        if ($primeCh === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($primeCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($primeCh, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($primeCh, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($primeCh, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_exec($primeCh);
        unset($primeCh);
    }

    $ch = curl_init(H::baseUrl() . '/identification.php');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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
 * Same POST as identCurl(), but with NO CURLOPT_COOKIEJAR/COOKIEFILE at
 * all -- unlike every other curl helper in this suite (all of which round-
 * trip a real Set-Cookie the server itself issued), this never sends a
 * `Cookie:` header of any kind, for
 * IdentificationController::__invoke()'s own `$has_session_cookie` check
 * ($_COOKIE[session_name()] genuinely absent, simulating a client whose
 * cookies are blocked/unsupported rather than one that simply hasn't
 * visited yet).
 *
 * @param array<string, string> $fields
 * @return array{status: int, body: string}
 */
function identCurlNoCookies(array $fields): array
{
    $ch = curl_init(H::baseUrl() . '/identification.php');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * @return string a fresh cookie-jar path
 */
function identFreshCookieJar(): string
{
    $jar = tempnam(sys_get_temp_dir(), 'pwg_ident_test_');
    if ($jar === false) {
        throw new RuntimeException('tempnam failed');
    }

    return $jar;
}

/**
 * Plain anonymous GET carrying a raw `Cookie:` header, for exercising
 * IdentificationController's own $_COOKIE['lang'] handling directly --
 * same rationale/shape as RegisterControllerTest.php's
 * registerCurlWithRawCookie(), duplicated locally (rather than shared)
 * because CURLOPT_COOKIEFILE/COOKIEJAR can only ever replay a real
 * Set-Cookie the server itself sent, never an arbitrary hand-crafted one.
 *
 * @return array{status: int, body: string}
 */
function identCurlWithRawCookie(string $rawCookieHeader): array
{
    $ch = curl_init(H::baseUrl() . '/identification.php');
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
 * Temporarily registers a language row in `languages` -- see
 * RegisterControllerTest.php's identical registerAddLanguage() for the
 * full rationale (this fixture only ships `en_UK` in the DB, though
 * `fr_FR` is a real on-disk core language pack). Caller must pair this
 * with identRemoveLanguage().
 */
function identAddLanguage(string $code, string $name): void
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
 * Reverts identAddLanguage().
 */
function identRemoveLanguage(string $code): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf("DELETE FROM languages WHERE id = '%s'", H::dbEscape($db, $code)));
    H::dbClose($db);
}

it('shows a cookies-blocked error and does not attempt to log in when no session cookie is sent', function (): void {
    // identCurlNoCookies() never attaches
    // a Set-Cookie the server already issued (unlike identCurl()'s
    // cookie-jar round trip, or a real browser's very first request, which
    // Playwright still replays any cookie it already stored within the
    // same context) -- $_COOKIE[session_name()] is genuinely unset on this
    // request, matching a client whose cookies really are blocked.
    $result = identCurlNoCookies([
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Submit',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Cookies are blocked');
    // Real admin credentials were sent, but the cookie check short-circuits
    // before try_log_user() is ever called -- no session gets authenticated.
    expect($result['body'])->not->toContain('act=logout');
});

it('rejects a case-mismatched username by default (bin-collation username column, insensitive_case_logon disabled)', function (): void {
    // Baseline for the paired test below: `users`.`username` is
    // `utf8mb4_bin` (case-sensitive, per the fixture's own
    // CREATE TABLE), so an exact-equality lookup for 'FIXTURE_ADMIN' genuinely
    // does not match the stored 'fixture_admin' row, and it isn't
    // email-shaped either -- AuthRepository::findByUsernameOrEmail() finds
    // no user at all. Explicitly (re)disabled first in case a concurrent
    // Browser-suite process left this shared config flag enabled.
    H::setConfigValue('insensitive_case_logon', 'false');

    $jar = identFreshCookieJar();
    $result = identCurl($jar, [
        'username' => strtoupper(H::ADMIN_USER),
        'password' => H::ADMIN_PASS,
        'login' => 'Submit',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Invalid username or password');
    expect($result['body'])->not->toContain('act=logout');

    @unlink($jar);
});

it('resolves a case-mismatched username via UserService::searchCaseUsername() when insensitive_case_logon is enabled, and logs in', function (): void {
    // `insensitive_case_logon` is shared, global config across the whole
    // Browser suite run (same caveat every H::setConfigValue()-using file
    // documents) -- always reset back to 'false' afterward regardless of
    // how this test finishes.
    H::setConfigValue('insensitive_case_logon', 'true');

    try {
        $jar = identFreshCookieJar();
        $result = identCurl($jar, [
            'username' => strtoupper(H::ADMIN_USER),
            'password' => H::ADMIN_PASS,
            'login' => 'Submit',
        ]);

        // Real DB round trip through UserService::searchCaseUsername():
        // UserService::userService()'s findAllUsernames() lowercases every
        // real stored username and finds exactly one case-insensitive
        // match ('fixture_admin'), so $username is rewritten to that exact
        // stored form BEFORE tryLogUser() ever runs its own bin-collation
        // lookup -- login succeeds despite the mismatched case sent, unlike
        // the disabled-by-default case above.
        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('act=logout');

        @unlink($jar);
    } finally {
        H::setConfigValue('insensitive_case_logon', 'false');
    }
});

it('treats a non-string lang cookie (PHP array syntax) as a hacking attempt and returns a fatal 500', function (): void {
    // `Cookie: lang[]=x` parses into $_COOKIE['lang'] as a genuine PHP
    // array (PHP applies the same bracket-name parsing to cookies as it
    // does to GET/POST params) -- same shared $_COOKIE['lang'] handling
    // shape as RegisterControllerTest's identical assertion.
    // HtmlService::fatalError() always responds 500 regardless of
    // its specific message.
    $result = identCurlWithRawCookie('lang[]=fr_FR');

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('[Hacking attempt] the input parameter "lang" is not valid');
});

it('treats an unregistered lang cookie value as a hacking attempt and returns a fatal 500', function (): void {
    // A syntactically fine string, but not a language LangService::
    // getLanguages() recognizes (the fixture only ships `en_UK` in the DB)
    // -- a different fatalError() call than the array case above, with the
    // attempted value interpolated into the message.
    $result = identCurlWithRawCookie('lang=zz_ZZ');

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('[Hacking attempt] the input parameter "zz_ZZ" is not valid');
});

it("applies a valid, different lang cookie: switches CurrentUser's language, loads its translations, and swaps in the French help link", function (): void {
    // The fixture only ships `en_UK` in `languages` -- `fr_FR` is a
    // real, on-disk core language pack (language/fr_FR/common.po exists),
    // just not registered in this fixture's DB -- registering it
    // temporarily is the minimal state needed to exercise
    // CurrentUser::updateLanguage()/Lang::load() for real, rather than just
    // inferring they ran from a bare 200 status. Identical rationale to
    // RegisterControllerTest.php's own version of this test.
    identAddLanguage('fr_FR', 'Français');

    // The default theme's own identification.latte never references
    // {$HELP_LINK} or {$current_language}/{$language_options} at all
    // -- only standard_pages' identification.latte
    // renders them, so swapping the guest's theme is what makes this
    // test's assertions real, visible behavior rather than inference.
    H::setGuestTheme('standard_pages');

    try {
        $result = identCurlWithRawCookie('lang=fr_FR');

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('[Hacking attempt]');
        // The French help-link branch itself (str_starts_with(..., 'fr')).
        expect($result['body'])->toContain('https://upstream.example.invalid/help/fr/');
        // Lang::load('common.lang', ..., ['language' => 'fr_FR']) really
        // loaded French translations -- standard_pages' own login heading
        // is real, translated body content, not just metadata.
        expect($result['body'])->toContain('Connexion');
        // current_language template var reflects CurrentUser::updateLanguage().
        expect($result['body'])->toContain('id="selected-language">Français<');
    } finally {
        H::setGuestTheme('default');
        identRemoveLanguage('fr_FR');
    }
});
