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
