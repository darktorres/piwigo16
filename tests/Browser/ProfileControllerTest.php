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
 * FIXED AT THE SOURCE: tests/Fixtures/
 * piwigo-17.0.sql ships an entirely empty `themes` table by design (a
 * real fresh install never activates AppInfo::DEFAULT_TEMPLATE --
 * ExtensionLifecycle::performThemeAction()'s own $id === 'default' guard
 * keeps it out permanently), but ThemeCatalog::getPwgThemes() used to
 * read that table with no exception for it, so profile.latte's theme
 * <select> rendered with zero <option>s and saveFromPost()'s own
 * `in_array($post['theme'], array_keys(getPwgThemes()), true)` guard
 * always failed -- turning every profile.php submission into a real 500
 * "Unrecognized value for parameter \"theme\"" fatalError(), reproduced
 * directly with raw curl independent of this file. Not specific to
 * ProfileController (the same saveFromPost() is also reached from
 * Controller\Admin\ConfigurationSubController's "default" tab). Fixed by
 * making getPwgThemes() itself always include AppInfo::DEFAULT_TEMPLATE
 * -- this file no longer needs to seed a `themes` row before each test.
 */
const PROFILE_TEST_USER = 'regular_user';

const PROFILE_TEST_PASS = 'regular_user_pass';

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

/**
 * @return array{nb_image_page: int, recent_period: int}
 */
function profileUserSettings(): array
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT ui.nb_image_page, ui.recent_period FROM user_infos ui INNER JOIN users u ON u.id = ui.user_id WHERE u.username = '%s'", H::dbEscape($db, PROFILE_TEST_USER)));
    if (! is_array($row)) {
        throw new RuntimeException('regular_user user_infos row not found');
    }

    return [
        'nb_image_page' => (int) $row['nb_image_page'],
        'recent_period' => (int) $row['recent_period'],
    ];
}

/**
 * The 3 tinyint "boolean" user_infos columns saveFromPost()'s own
 * $boolFields normalization loop (~L285-297) touches -- read together so
 * a test can assert none of them moved after a POST that deliberately
 * omits all 3, distinct from profileUserSettings()'s int columns above.
 *
 * @return array{expand: int, show_nb_hits: int, show_nb_comments: int}
 */
function profileUserToggleSettings(): array
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT ui.expand, ui.show_nb_hits, ui.show_nb_comments FROM user_infos ui INNER JOIN users u ON u.id = ui.user_id WHERE u.username = '%s'", H::dbEscape($db, PROFILE_TEST_USER)));
    H::dbClose($db);
    if (! is_array($row)) {
        throw new RuntimeException('regular_user user_infos row not found');
    }

    // expand/show_nb_hits/show_nb_comments are genuine boolean columns on
    // Postgres (pg_fetch_assoc() represents them as 't'/'f', which a
    // naive (int) cast mishandles) despite this file's own "tinyint"
    // docblock framing above -- H::dbToBool() normalizes correctly on
    // either driver.
    return [
        'expand' => H::dbToBool($row['expand']) ? 1 : 0,
        'show_nb_hits' => H::dbToBool($row['show_nb_hits']) ? 1 : 0,
        'show_nb_comments' => H::dbToBool($row['show_nb_comments']) ? 1 : 0,
    ];
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
    // ProfileController::__invoke()'s $this->urlService->makeIndexUrl(),
    // which this fixture's
    // own mount/URL config resolves to exactly the site root.
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)
        ->toBe(H::baseUrl() . '/');

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
    expect($currentUrl)
        ->toContain('profile.php');

    $after = profileUserSettings();
    expect($after)
        ->toBe($before);
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
    // for this string).
    $page->assertSee('The number of photos per page must be a non-zero integer');
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)
        ->toContain('profile.php');

    $after = profileUserSettings();
    expect($after)
        ->toBe($before);
});

/**
 * @return array{email: string, password: string}|null
 */
function profileUserAuthRow(): ?array
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT mail_address, password FROM users WHERE username = '%s'", H::dbEscape($db, PROFILE_TEST_USER)));
    H::dbClose($db);

    if (! is_array($row)) {
        return null;
    }

    return [
        'email' => (string) ($row['mail_address'] ?? ''),
        'password' => (string) ($row['password'] ?? ''),
    ];
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

it('fatal-errors on an unrecognized language value', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');

    $result = H::adminPost($page, '/profile.php', profileBaselineFields([
        'pwg_token' => H::pwgToken($page),
        'language' => 'not_a_real_language_' . uniqid(),
    ]));

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Unrecognized value for parameter "language"');
});

it('fatal-errors on an unrecognized theme value', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');

    $result = H::adminPost($page, '/profile.php', profileBaselineFields([
        'pwg_token' => H::pwgToken($page),
        'theme' => 'not_a_real_theme_' . uniqid(),
    ]));

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Unrecognized value for parameter "theme"');
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
    expect(profileUserAuthRow())
        ->toBe($before);
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
    expect(profileUserAuthRow())
        ->toBe($before);
});

it('rejects a malformed mail_address and leaves the stored email address untouched', function (): void {
    // Exercises UserService::validateMailAddress()'s own format-check
    // failure surfacing through ProfileFormHandler::saveFromPost()'s
    // `if ($mail_error !== '' && $mail_error !== '0') { $errors[] = $mail_error; }`
    // (~L154-155) -- distinct from the password-mismatch/wrong-password
    // $errors[] sites above, and from the "already in use" branch of the
    // same validateMailAddress() call (not reachable without a 2nd real
    // account's email on hand; the format-check failure needs no such
    // fixture).
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');
    $before = profileUserAuthRow();

    $result = H::adminPost($page, '/profile.php', profileBaselineFields([
        'pwg_token' => H::pwgToken($page),
        'mail_address' => 'not-a-valid-email-format',
    ]));

    expect($result['status'])->toBe(200);
    // The rendered text is the real language/en_UK/common.po translation
    // ("example:" with no space before the colon), not the literal
    // Lang::t() source-code key ("example :") -- same rationale as this
    // file's own 'rejects an empty nb_image_page...' test above.
    expect($result['body'])->toContain('mail address must be like xxx@yyy.eee (example: jack@altern.org)');
    expect(profileUserAuthRow())
        ->toBe($before);
});

it('omits the 3 boolFields from the POST and leaves expand/show_nb_hits/show_nb_comments untouched', function (): void {
    // Exercises saveFromPost()'s own
    // `foreach ($fields as $field) { if (! isset($post[$field])) { continue; } ... }`
    // guard (~L286-289) for each of 'expand'/'show_nb_hits'/
    // 'show_nb_comments' -- a real HTML form submission always sends every
    // one of these (profile_content.latte renders a real <input> for each),
    // so only a POST built by hand (not driven through the rendered form,
    // matching this file's other adminPost()-based tests) can omit them.
    // Confirms the continue really skips the column entirely -- via
    // UserService::updateInfosForUser()'s own partial-SET semantics (see
    // UserRepository::updateInfosForUsers()) -- rather than the loop
    // defaulting an absent checkbox to false/0 and zeroing it out.
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');

    $beforeSettings = profileUserSettings();
    $beforeToggles = profileUserToggleSettings();

    try {
        $result = H::adminPost($page, '/profile.php', [
            'pwg_token' => H::pwgToken($page),
            'validate' => '1',
            'nb_image_page' => '23',
            'recent_period' => '9',
            'language' => 'en_UK',
            'theme' => 'default',
            'redirect' => '',
            // 'expand' / 'show_nb_hits' / 'show_nb_comments' deliberately
            // OMITTED -- the whole point of this test.
        ]);

        expect($result['status'])->toBe(200);

        $afterSettings = profileUserSettings();
        expect($afterSettings['nb_image_page'])->toBe(23);
        expect($afterSettings['recent_period'])->toBe(9);

        // The 3 boolFields columns must be byte-for-byte unchanged -- not
        // just "still a valid tinyint" -- proving the continue really
        // skipped them rather than coercing an absent value to 0/false.
        expect(profileUserToggleSettings())
            ->toBe($beforeToggles);
    } finally {
        profileSetImageSettings($beforeSettings['nb_image_page'], $beforeSettings['recent_period']);
    }
});

function profileRestoreAuthRow(string $email, string $passwordHash): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE users SET mail_address = %s, password = '%s' WHERE username = '%s'", $email === '' ? 'NULL' : "'" . H::dbEscape($db, $email) . "'", H::dbEscape($db, $passwordHash), H::dbEscape($db, PROFILE_TEST_USER)));
    H::dbClose($db);
}

it('changes both the email address and password given the correct current password', function (): void {
    $page = profileLogin($this);
    H::navigateOk($page, '/profile.php');
    $before = profileUserAuthRow();
    expect($before)
        ->not->toBeNull();
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
        expect($after)
            ->not->toBeNull();
        assert(is_array($after));
        expect($after['email'])->toBe($newEmail);
        expect($after['email'])->not->toBe($before['email']);
        expect($after['password'])->not->toBe($before['password']);

        // Log back in with the NEW password to confirm it was really
        // hashed and persisted (self::passwordService()->hash()), not
        // just accepted and discarded.
        $freshPage = H::visitPwg($this, '/identification.php');
        $freshPage = $freshPage->fill('username', PROFILE_TEST_USER)
            ->fill('password', 'a-brand-new-password-9')
            ->click('login');
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
 *    string -- PHP's cookie parser honors bracket
 *    notation in cookie names exactly like it does for GET/POST, matching
 *    Integration\AuthServiceTest's own identical rationale for the sibling
 *    guard in AuthService::logUser();
 *  - sending a plain `Cookie: lang=...` value ALONGSIDE the session cookie
 *    needs curl's CURLOPT_COOKIE specifically, not a manual `Cookie:` entry
 *    in CURLOPT_HTTPHEADER -- a
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

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => $username,
        'password' => $password,
        'login' => 'Login',
    ]);

    return [
        'curl' => $curl,
        'cookieJar' => $cookieJar,
        'baseUrl' => $baseUrl,
    ];
}

it('fatal-errors on an array-valued lang cookie (invalid request parameter)', function (): void {
    $session = profileCurlLoginSession(PROFILE_TEST_USER, PROFILE_TEST_PASS);
    $curl = $session['curl'];

    // Exercises ProfileController's own `! is_string($cookie_lang)` guard
    // (~L167-170) -- distinct from the "valid string but unrecognized
    // language code" guard covered by the next test (~L171-174).
    $result = $curl($session['baseUrl'] . '/profile.php', [], 'lang[]=x; lang[]=y');

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Invalid request parameter "lang"');

    @unlink($session['cookieJar']);
});

it('fatal-errors on an unrecognized lang cookie value', function (): void {
    $session = profileCurlLoginSession(PROFILE_TEST_USER, PROFILE_TEST_PASS);
    $curl = $session['curl'];

    $bogusLang = 'not_a_real_language_' . uniqid();
    $result = $curl($session['baseUrl'] . '/profile.php', [], 'lang=' . $bogusLang);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Unrecognized value for parameter "lang"');

    @unlink($session['cookieJar']);
});

function profileUserLanguage(): string
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT ui.language FROM user_infos ui INNER JOIN users u ON u.id = ui.user_id WHERE u.username = '%s'", H::dbEscape($db, PROFILE_TEST_USER)));
    H::dbClose($db);
    if (! is_array($row)) {
        throw new RuntimeException('regular_user user_infos row not found');
    }

    return (string) $row['language'];
}

function profileSetUserLanguage(string $language): void
{
    $db = H::connect();
    $escapedLanguage = H::dbEscape($db, $language);
    $escapedUsername = H::dbEscape($db, PROFILE_TEST_USER);
    // MySQL's own multi-table UPDATE...INNER JOIN...SET syntax has no
    // Postgres equivalent -- UPDATE...SET...FROM...WHERE is the real
    // portable form.
    $sql = $db instanceof mysqli
        ? "UPDATE user_infos ui INNER JOIN users u ON u.id = ui.user_id SET ui.language = '{$escapedLanguage}' WHERE u.username = '{$escapedUsername}'"
        : "UPDATE user_infos ui SET language = '{$escapedLanguage}' FROM users u WHERE u.id = ui.user_id AND u.username = '{$escapedUsername}'";
    H::dbQuery($db, $sql);
    H::dbClose($db);
}

it('switches the interface language via a valid, different lang cookie and persists it to user_infos', function (): void {
    $db = H::connect();
    // The fixture's languages
    // table only ever seeds 'en_UK', but LangService::getLanguages()
    // requires a real DB row (AND a real `language/<code>/` directory,
    // already present on disk for fr_FR) before it counts as a known
    // language -- without this insert, ProfileController's own
    // `array_key_exists($cookie_lang, LangService::getLanguages())` guard
    // would always fail and this couldn't reach the real switch path at
    // all.
    $upsertSql = $db instanceof mysqli
        ? "INSERT INTO languages (id, version, name) VALUES ('fr_FR', '1.0.0', 'French') ON DUPLICATE KEY UPDATE name = VALUES(name)"
        : "INSERT INTO languages (id, version, name) VALUES ('fr_FR', '1.0.0', 'French') ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name";
    H::dbQuery($db, $upsertSql);
    H::dbClose($db);

    $originalLanguage = profileUserLanguage();
    expect($originalLanguage)
        ->toBe('en_UK');

    try {
        $session = profileCurlLoginSession(PROFILE_TEST_USER, PROFILE_TEST_PASS);
        $curl = $session['curl'];

        $result = $curl($session['baseUrl'] . '/profile.php', [], 'lang=fr_FR');

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Invalid request parameter');
        expect($result['body'])->not->toContain('Unrecognized value for parameter');
        // Confirms Lang::load() really reloaded the French catalog for
        // THIS request's own template rendering (profile_content.latte is
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
        expect(profileUserLanguage())
            ->toBe('fr_FR');

        @unlink($session['cookieJar']);
    } finally {
        profileSetUserLanguage($originalLanguage);
        $db2 = H::connect();
        H::dbQuery($db2, sprintf("DELETE FROM languages WHERE id = 'fr_FR'"));
        H::dbClose($db2);
    }
});

/**
 * @return array{nb_image_page: int, recent_period: int} the guest (default_user_id=2) row's own current custom-settings values
 */
function profileGuestDefaults(): array
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, 'SELECT nb_image_page, recent_period FROM user_infos WHERE user_id = 2');
    H::dbClose($db);
    if (! is_array($row)) {
        throw new RuntimeException('guest (user_id=2) user_infos row not found');
    }

    return [
        'nb_image_page' => (int) $row['nb_image_page'],
        'recent_period' => (int) $row['recent_period'],
    ];
}

function profileSetImageSettings(int $nbImagePage, int $recentPeriod): void
{
    $db = H::connect();
    $escapedUsername = H::dbEscape($db, PROFILE_TEST_USER);
    // Same MySQL-only UPDATE...INNER JOIN...SET syntax as
    // profileSetUserLanguage() above -- UPDATE...SET...FROM...WHERE is
    // the real Postgres equivalent.
    $sql = $db instanceof mysqli
        ? "UPDATE user_infos ui INNER JOIN users u ON u.id = ui.user_id SET ui.nb_image_page = {$nbImagePage}, ui.recent_period = {$recentPeriod} WHERE u.username = '{$escapedUsername}'"
        : "UPDATE user_infos ui SET nb_image_page = {$nbImagePage}, recent_period = {$recentPeriod} FROM users u WHERE u.id = ui.user_id AND u.username = '{$escapedUsername}'";
    H::dbQuery($db, $sql);
    H::dbClose($db);
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
        // (profile_content.latte's `<input type="submit" name="reset_to_default"
        // ...>` is a separate button from the main 'validate' one), so
        // ProfileFormSubmitRequest::isValidateSubmitted stays false and
        // ProfileFormHandler::saveFromPost() returns immediately without
        // touching the DB -- this is a pure preview render, verified below.
        $result = H::adminPost($page, '/profile.php', [
            'pwg_token' => H::pwgToken($page),
            'reset_to_default' => '1',
        ]);

        expect($result['status'])->toBe(200);
        // id= and value= sit on separate template-source lines after
        // P32's reformat -- 2 independent checks per field, not one
        // literal single-line string.
        expect($result['body'])->toContain('id="nb_image_page"');
        expect($result['body'])->toContain('value="' . $guestDefaults['nb_image_page'] . '"');
        expect($result['body'])->toContain('id="recent_period"');
        expect($result['body'])->toContain('value="' . $guestDefaults['recent_period'] . '"');

        $unchanged = profileUserSettings();
        expect($unchanged['nb_image_page'])->toBe($guestDefaults['nb_image_page'] + 50);
        expect($unchanged['recent_period'])->toBe($guestDefaults['recent_period'] + 50);
    } finally {
        profileSetImageSettings($before['nb_image_page'], $before['recent_period']);
    }
});

/**
 * ProfileFormHandler::saveFromPost()'s own username-change block
 * (src/Piwigo/Controller/ProfileFormHandler.php, roughly L211-249: the
 * `if (is_string($username_for_update) ...)` body -- the "this login is
 * already used" conflict check, the successful-rename field/data update,
 * and the username-change notification email) is PROVABLY UNREACHABLE
 * through either of this class's real production callers, verified
 * by reading both call sites directly rather than assumed from a coverage
 * report:
 *
 *  - ProfileController (this file's own subject, profile.php) never runs
 *    behind admin.php/admin/popuphelp.php's own AdminContext::mark() call
 *    -- those 2 entry shells are the only real callers of AdminContext::mark()
 *    anywhere in this codebase -- so
 *    saveFromPost()'s own `if (! \Piwigo\Core\AdminContext::isActive())
 *    { unset($post['username']); }` (~L112-114) unconditionally strips
 *    'username' from every profile.php submission before the
 *    username-change block can ever be reached.
 *  - Controller\Admin\ConfigurationSubController's "default" tab (this
 *    class's only other real caller, admin.php's "Guest" default-settings
 *    tab) always builds $edit_user from `UserId::from(CurrentConfig::guestId())`
 *    -- always a $special_user, so saveFromPost()'s own EARLIER
 *    `unset($post['username'], ...)` (~L99-107) strips it there too,
 *    regardless of AdminContext.
 *
 * There
 * is no 3rd real caller in this fork able to supply the one combination
 * (a NON-special user, WITH AdminContext active) this block needs --
 * there is no "admin renames another member's account" page wired
 * through this class (Admin\UserListSubController's own username
 * handling goes through `/api/v1/users`, a wholly different code path).
 *
 * Same house style as Integration\Admin\
 * IntroSubControllerGetLatestNewsTest.php's own 3rd test docblock (a
 * different class, a different branch, same shape of gap: "no reachable
 * path in this fork at all ... left uncovered rather than faked").
 * Forcing coverage here would mean calling saveFromPost() directly with
 * AdminContext::mark() plus a hand-picked non-special $userdata array --
 * a combination no real HTTP request against this app can ever produce,
 * i.e. exercising a scenario the real call graph doesn't have rather than
 * genuine behavior. Left uncovered rather than faked.
 */
