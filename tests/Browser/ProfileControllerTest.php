<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\ProfileController + Piwigo\Controller\
 * ProfileFormHandler (profile.php) -- lets the current (Classic-or-above)
 * user customize their own gallery display settings. Uses the fixture's
 * 'regular_user' account (password 'regular_user_pass', seeded by
 * RegenerateFixtureTest) rather than fixture_admin, so this doesn't mutate
 * the shared admin account other Browser tests may depend on.
 *
 * A successful saveFromPost() redirects to the gallery home (the form's own
 * hidden 'redirect' field, always populated -- see ProfileController's own
 * `$this->urlService->makeIndexUrl()` assignment) -- so success is
 * observed via the real DB row + leaving profile.php, not via re-rendered
 * form content. A validation error skips that redirect entirely and
 * re-renders profile.php with the error message and the user_infos row
 * untouched.
 *
 * CONFIRMED REAL FIXTURE GAP (reproduced directly with raw curl,
 * independent of this file, before writing this workaround): tests/
 * Fixtures/piwigo-17.0.sql ships an entirely EMPTY `piwigo_themes` table
 * (no INSERT rows at all between its own DISABLE/ENABLE KEYS markers) --
 * ThemeCatalog::getPwgThemes() reads that table directly, so profile.tpl's
 * theme <select> renders with ZERO <option>s in this environment, and
 * saveFromPost()'s own `in_array($post['theme'], array_keys(getPwgThemes()),
 * true)` guard then ALWAYS fails (empty haystack), turning EVERY profile.php
 * submission -- from any account, regardless of any other field's value --
 * into a real 500 "[Hacking attempt] incorrect theme value" fatalError().
 * This isn't specific to ProfileController's own logic (the same
 * saveFromPost() is also called from Controller\Admin\
 * ConfigurationSubController's "default" tab, which would hit the exact
 * same wall), so it reads as an install/fixture-regen seeding gap rather
 * than a bug in the class under test -- worth fixing at the source
 * (RegenerateFixtureTest.php should seed at least the 'default' theme row),
 * but out of scope for this change (BrowserTestHelpers.php/
 * RegenerateFixtureTest.php aren't in this change's target list either).
 * Repaired here, matching every other direct-DB-fixture-manipulation
 * helper already established in this suite (freezeImageHits()/
 * setCategoryPrivate()), rather than silently working around it by
 * skipping the theme field or weakening an assertion.
 */

const PROFILE_TEST_USER = 'regular_user';

const PROFILE_TEST_PASS = 'regular_user_pass';

/** Idempotently registers the 'default' theme -- see this file's own docblock for why this is necessary. */
function profileEnsureDefaultThemeRegistered(): void
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf(
        "INSERT INTO %sthemes (id, version, name) VALUES ('default', '1.0.0', 'Default') ON DUPLICATE KEY UPDATE name = 'Default'",
        $prefix
    ));
    $db->close();
}

beforeEach(function (): void {
    profileEnsureDefaultThemeRegistered();
});

// PHPStan claims only Webpage is ever returned here ("never returns
// AwaitableWebpage/PendingAwaitablePage so it can be removed") -- empirically
// false: a real Browser run threw "profileLogin(): Return value must be of
// type Webpage, AwaitableWebpage returned" the one time this was narrowed to
// just Webpage. PHPStan can't fully trace the dynamic fill()/click() chain
// starting from H::visitPwg()'s own declared union, same root cause as this
// file's other Pest/Playwright-dynamic-dispatch limitations.
// @phpstan-ignore return.unusedType, return.unusedType
function profileLogin(object $test): Webpage|PendingAwaitablePage|AwaitableWebpage
{
    $page = H::visitPwg($test, '/identification.php');
    $page = $page
        ->fill('username', PROFILE_TEST_USER)
        ->fill('password', PROFILE_TEST_PASS)
        ->click('login');
    $page->assertPresent('a[href*="act=logout"]');

    return $page;
}

/** @return array{nb_image_page: int, recent_period: int} */
function profileUserSettings(): array
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
        "SELECT ui.nb_image_page, ui.recent_period FROM %suser_infos ui INNER JOIN %susers u ON u.id = ui.user_id WHERE u.username = '%s'",
        $prefix,
        $prefix,
        $db->real_escape_string(PROFILE_TEST_USER)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if (! is_array($row)) {
        throw new RuntimeException('regular_user user_infos row not found');
    }

    return ['nb_image_page' => (int) $row['nb_image_page'], 'recent_period' => (int) $row['recent_period']];
}

it('saves nb_image_page/recent_period and redirects to the gallery home on success', function (): void {
    $page = profileLogin($this);
    $page = H::navigateOk($page, '/profile.php');
    $page->assertPresent('input[name="nb_image_page"]');

    $page = $page
        ->fill('nb_image_page', '17')
        ->fill('recent_period', '12')
        ->click('validate');

    // ProfileFormHandler::saveFromPost()'s own redirect target is the
    // form's hidden 'redirect' field, populated from
    // ProfileController::__invoke()'s $this->urlService->makeIndexUrl() --
    // confirmed live (independent of this assertion) that this fixture's
    // own mount/URL config resolves that to exactly the site root.
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)->toBe(H::baseUrl() . '/');

    $settings = profileUserSettings();
    expect($settings['nb_image_page'])->toBe(17);
    expect($settings['recent_period'])->toBe(12);
});

it('rejects a negative recent_period and leaves the stored settings untouched', function (): void {
    $page = profileLogin($this);
    $page = H::navigateOk($page, '/profile.php');

    $before = profileUserSettings();

    $page = $page
        ->fill('nb_image_page', '9')
        ->fill('recent_period', '-5')
        ->click('validate');

    $page->assertSee('Recent period must be a positive integer value');
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)->toContain('profile.php');

    $after = profileUserSettings();
    expect($after)->toBe($before);
});

it('rejects an empty nb_image_page and leaves the stored settings untouched', function (): void {
    $page = profileLogin($this);
    $page = H::navigateOk($page, '/profile.php');

    $before = profileUserSettings();

    $page = $page
        ->fill('nb_image_page', '')
        ->fill('recent_period', '8')
        ->click('validate');

    // The rendered text is the real language/en_UK/common.po translation,
    // not the literal Lang::t() source-code key (the msgid/msgstr differ
    // for this string, confirmed by reading the .po file directly).
    $page->assertSee('The number of photos per page must be a non-zero integer');
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)->toContain('profile.php');

    $after = profileUserSettings();
    expect($after)->toBe($before);
});

/** @return array{email: string, password: string}|null */
function profileUserAuthRow(): ?array
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
        "SELECT mail_address, password FROM %susers WHERE username = '%s'",
        $prefix,
        $db->real_escape_string(PROFILE_TEST_USER)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    if (! is_array($row)) {
        return null;
    }

    return ['email' => (string) ($row['mail_address'] ?? ''), 'password' => (string) ($row['password'] ?? '')];
}

/** @param array<string, string> $overrides */
/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function profileBaselineFields(array $overrides = []): array
{
    return array_merge([
        'validate' => '1',
        'nb_image_page' => '15',
        'recent_period' => '7',
        'language' => 'en_UK',
        'theme' => 'default',
        'redirect' => '',
    ], $overrides);
}

it('fatal-errors on a hacking-attempt invalid language value', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');

    $result = H::adminPost($page, '/profile.php', profileBaselineFields([
        'pwg_token' => H::pwgToken($page),
        'language' => 'not_a_real_language_' . uniqid(),
    ]));

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Hacking attempt, incorrect language value');
});

it('fatal-errors on a hacking-attempt invalid theme value', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');

    $result = H::adminPost($page, '/profile.php', profileBaselineFields([
        'pwg_token' => H::pwgToken($page),
        'theme' => 'not_a_real_theme_' . uniqid(),
    ]));

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Hacking attempt, incorrect theme value');
});

it('rejects a new-password submission whose confirmation does not match', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');
    $before = profileUserAuthRow();

    $result = H::adminPost($page, '/profile.php', profileBaselineFields([
        'pwg_token' => H::pwgToken($page),
        'mail_address' => 'regular-user-unchanged@example.test',
        'password' => PROFILE_TEST_PASS,
        'use_new_pwd' => 'a-brand-new-password-1',
        'passwordConf' => 'a-different-password-2',
    ]));

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('The passwords do not match');
    expect(profileUserAuthRow())->toBe($before);
});

it('rejects a password change when the current password is wrong', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');
    $before = profileUserAuthRow();

    $result = H::adminPost($page, '/profile.php', profileBaselineFields([
        'pwg_token' => H::pwgToken($page),
        'mail_address' => 'regular-user-unchanged@example.test',
        'password' => 'definitely-the-wrong-current-password',
        'use_new_pwd' => 'a-brand-new-password-1',
        'passwordConf' => 'a-brand-new-password-1',
    ]));

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Current password is wrong');
    expect(profileUserAuthRow())->toBe($before);
});

function profileRestoreAuthRow(string $email, string $passwordHash): void
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf(
        "UPDATE %susers SET mail_address = %s, password = '%s' WHERE username = '%s'",
        $prefix,
        $email === '' ? 'NULL' : "'" . $db->real_escape_string($email) . "'",
        $db->real_escape_string($passwordHash),
        $db->real_escape_string(PROFILE_TEST_USER)
    ));
    $db->close();
}

it('changes both the email address and password given the correct current password', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');
    $before = profileUserAuthRow();
    expect($before)->not->toBeNull();
    assert(is_array($before));

    $newEmail = 'ct-regular-user-' . uniqid() . '@example.test';

    try {
        H::adminPost($page, '/profile.php', profileBaselineFields([
            'pwg_token' => H::pwgToken($page),
            'mail_address' => $newEmail,
            'password' => PROFILE_TEST_PASS,
            'use_new_pwd' => 'a-brand-new-password-9',
            'passwordConf' => 'a-brand-new-password-9',
            // The redirect must be a real, current-site URL for
            // RedirectServiceInterface to accept it -- reusing the site
            // root, same target ProfileController's own hidden field
            // already uses.
            'redirect' => H::baseUrl() . '/',
        ]));

        $after = profileUserAuthRow();
        expect($after)->not->toBeNull();
        assert(is_array($after));
        expect($after['email'])->toBe($newEmail);
        expect($after['email'])->not->toBe($before['email']);
        expect($after['password'])->not->toBe($before['password']);

        // Log back in with the NEW password to confirm it was really
        // hashed and persisted (self::passwordService()->hash()), not
        // just accepted and discarded.
        $freshPage = H::visitPwg($this, '/identification.php');
        $freshPage = $freshPage->fill('username', PROFILE_TEST_USER)->fill('password', 'a-brand-new-password-9')->click('login');
        $freshPage->assertPresent('a[href*="act=logout"]');
    } finally {
        // A raw DB restore, not a second app-level password-change round
        // trip -- guarantees the fixture's own documented credentials
        // (regular_user/regular_user_pass, relied on by every other test
        // in this file and by PictureControllerTest.php) are back exactly
        // as they were even if an assertion above threw partway through.
        profileRestoreAuthRow($before['email'], $before['password']);
    }
});

/**
 * Raw curl through a persistent cookie jar, logging in first -- same shape
 * as PictureControllerTest's own pictureCurlLoginSession(), needed here
 * (rather than a Playwright-driven page) for the 3 tests below that target
 * ProfileController::__invoke()'s own `$_COOKIE['lang']` handling
 * (~L165-191), distinct from the 'language' POST field handled by
 * ProfileFormHandler above:
 *
 *  - a real `Cookie: lang[]=x; lang[]=y` header is the only way a real
 *    HTTP request can make $_COOKIE['lang'] a PHP array rather than a
 *    string (confirmed live against a throwaway PHP built-in server before
 *    writing the first test below -- PHP's cookie parser honors bracket
 *    notation in cookie names exactly like it does for GET/POST), matching
 *    Integration\AuthServiceTest's own identical rationale for the sibling
 *    guard in AuthService::logUser();
 *  - sending a plain `Cookie: lang=...` value ALONGSIDE the session cookie
 *    needs curl's CURLOPT_COOKIE specifically, not a manual `Cookie:` entry
 *    in CURLOPT_HTTPHEADER -- confirmed live (same throwaway server) that a
 *    manual header REPLACES rather than merges with the cookie engine's own
 *    Cookie header, comma-joining and corrupting both instead of sending
 *    two real `; `-separated pairs, which would silently drop the session
 *    cookie and log the request out before ever reaching profile.php's own
 *    logic.
 *
 * @return array{curl: Closure(string, array<string, string>=, string=): array{status: int, body: string}, cookieJar: non-empty-string, baseUrl: string}
 */
function profileCurlLoginSession(string $username, string $password): array
{
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_profile_session_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    /**
     * @param  array<string, string>  $fields
     * @param  string  $extraCookie  raw `name=value[; name=value...]` string
     *   merged alongside the session cookie jar via CURLOPT_COOKIE -- see
     *   this function's own docblock for why that option (and not a manual
     *   CURLOPT_HTTPHEADER 'Cookie:' entry) is required here.
     */
    $curl = static function (string $url, array $fields = [], string $extraCookie = '') use ($cookieJar): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($extraCookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $extraCookie);
        }
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => $username,
        'password' => $password,
        'login' => 'Login',
    ]);

    return ['curl' => $curl, 'cookieJar' => $cookieJar, 'baseUrl' => $baseUrl];
}

it('fatal-errors on an array-valued lang cookie (hacking attempt)', function (): void {
    $session = profileCurlLoginSession(PROFILE_TEST_USER, PROFILE_TEST_PASS);
    $curl = $session['curl'];

    // Exercises ProfileController's own `! is_string($cookie_lang)` guard
    // (~L167-170) -- distinct from the "valid string but unrecognized
    // language code" guard covered by the next test (~L171-174).
    $result = $curl($session['baseUrl'] . '/profile.php', [], 'lang[]=x; lang[]=y');

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('[Hacking attempt] the input parameter "lang" is not valid');

    @unlink($session['cookieJar']);
});

it('fatal-errors on an unrecognized lang cookie value (hacking attempt)', function (): void {
    $session = profileCurlLoginSession(PROFILE_TEST_USER, PROFILE_TEST_PASS);
    $curl = $session['curl'];

    $bogusLang = 'not_a_real_language_' . uniqid();
    $result = $curl($session['baseUrl'] . '/profile.php', [], 'lang=' . $bogusLang);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('[Hacking attempt] the input parameter "' . $bogusLang . '" is not valid');

    @unlink($session['cookieJar']);
});

function profileUserLanguage(): string
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
        "SELECT ui.language FROM %suser_infos ui INNER JOIN %susers u ON u.id = ui.user_id WHERE u.username = '%s'",
        $prefix,
        $prefix,
        $db->real_escape_string(PROFILE_TEST_USER)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();
    if (! is_array($row)) {
        throw new RuntimeException('regular_user user_infos row not found');
    }

    return (string) $row['language'];
}

function profileSetUserLanguage(string $language): void
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf(
        "UPDATE %suser_infos ui INNER JOIN %susers u ON u.id = ui.user_id SET ui.language = '%s' WHERE u.username = '%s'",
        $prefix,
        $prefix,
        $db->real_escape_string($language),
        $db->real_escape_string(PROFILE_TEST_USER)
    ));
    $db->close();
}

it('switches the interface language via a valid, different lang cookie and persists it to user_infos', function (): void {
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    // Same fixture gap/workaround shape as this file's own
    // profileEnsureDefaultThemeRegistered(): the fixture's piwigo_languages
    // table only ever seeds 'en_UK' (confirmed by reading
    // tests/Fixtures/piwigo-17.0.sql directly), but LangService::getLanguages()
    // requires a real DB row (AND a real `language/<code>/` directory,
    // already present on disk for fr_FR) before it counts as a known
    // language -- without this insert, ProfileController's own
    // `array_key_exists($cookie_lang, LangService::getLanguages())` guard
    // would always fail and this couldn't reach the real switch path at
    // all.
    $db->query(sprintf(
        "INSERT INTO %slanguages (id, version, name) VALUES ('fr_FR', '1.0.0', 'French') ON DUPLICATE KEY UPDATE name = VALUES(name)",
        $prefix
    ));
    $db->close();

    $originalLanguage = profileUserLanguage();
    expect($originalLanguage)->toBe('en_UK');

    try {
        $session = profileCurlLoginSession(PROFILE_TEST_USER, PROFILE_TEST_PASS);
        $curl = $session['curl'];

        $result = $curl($session['baseUrl'] . '/profile.php', [], 'lang=fr_FR');

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Hacking attempt');
        // Confirms Lang::load() really reloaded the French catalog for
        // THIS request's own template rendering (profile_content.tpl is
        // parsed at the very end of __invoke(), after the language-switch
        // block runs) -- not just that no error occurred. $title
        // (Lang::t('Your Gallery Customization'), computed earlier in
        // __invoke() at L148, BEFORE the switch block) deliberately stays
        // English for this one request, so this checks a template string
        // instead.
        expect($result['body'])->toContain('Nombre de miniatures par page');

        // The authoritative check the task brief asks for: a real
        // BatchWriter DB UPDATE actually landed in user_infos, not just
        // CurrentUser::updateLanguage()'s in-memory state and not just "no
        // error was thrown".
        expect(profileUserLanguage())->toBe('fr_FR');

        @unlink($session['cookieJar']);
    } finally {
        profileSetUserLanguage($originalLanguage);
        $db2 = new mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $db2->query(sprintf("DELETE FROM %slanguages WHERE id = 'fr_FR'", $prefix));
        $db2->close();
    }
});

/** @return array{nb_image_page: int, recent_period: int} the guest (default_user_id=2) row's own current custom-settings values */
function profileGuestDefaults(): array
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $result = $db->query('SELECT nb_image_page, recent_period FROM ' . $prefix . 'user_infos WHERE user_id = 2');
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();
    if (! is_array($row)) {
        throw new RuntimeException('guest (user_id=2) user_infos row not found');
    }

    return ['nb_image_page' => (int) $row['nb_image_page'], 'recent_period' => (int) $row['recent_period']];
}

function profileSetImageSettings(int $nbImagePage, int $recentPeriod): void
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf(
        'UPDATE %suser_infos ui INNER JOIN %susers u ON u.id = ui.user_id SET ui.nb_image_page = %d, ui.recent_period = %d WHERE u.username = \'%s\'',
        $prefix,
        $prefix,
        $nbImagePage,
        $recentPeriod,
        $db->real_escape_string(PROFILE_TEST_USER)
    ));
    $db->close();
}

it('previews the guest-default values in the rendered form on reset-to-default, without persisting them', function (): void {
    $before = profileUserSettings();
    $guestDefaults = profileGuestDefaults();

    // Deliberately offset from the guest (default_user_id) row's own
    // CURRENT values (read live above, not hardcoded -- AdminConfigurationTest's
    // "default tab" test also mutates/restores this exact row) so the merge
    // assertion below is unambiguous: if ProfileController's own
    // `array_merge($userdata, $default_user)` (~L122-124) didn't run, the
    // form would keep re-showing these instead.
    profileSetImageSettings($guestDefaults['nb_image_page'] + 50, $guestDefaults['recent_period'] + 50);

    try {
        $page = profileLogin($this);
        $page = H::navigateOk($page, '/profile.php');

        // No 'validate' key in this POST -- a real browser only ever sends
        // the ONE submit button that was actually clicked
        // (profile_content.tpl's `<input type="submit" name="reset_to_default"
        // ...>` is a separate button from the main 'validate' one), so
        // ProfileFormSubmitRequest::isValidateSubmitted stays false and
        // ProfileFormHandler::saveFromPost() returns immediately without
        // touching the DB -- this is a pure preview render, verified below.
        $result = H::adminPost($page, '/profile.php', [
            'pwg_token' => H::pwgToken($page),
            'reset_to_default' => '1',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('id="nb_image_page" value="' . $guestDefaults['nb_image_page'] . '"');
        expect($result['body'])->toContain('id="recent_period" value="' . $guestDefaults['recent_period'] . '"');

        $unchanged = profileUserSettings();
        expect($unchanged['nb_image_page'])->toBe($guestDefaults['nb_image_page'] + 50);
        expect($unchanged['recent_period'])->toBe($guestDefaults['recent_period'] + 50);
    } finally {
        profileSetImageSettings($before['nb_image_page'], $before['recent_period']);
    }
});
