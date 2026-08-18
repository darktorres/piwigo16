<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function languagesInstalledIsActive(string $languageId): bool
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT COUNT(*) AS c FROM languages WHERE id = '%s'", H::dbEscape($db, $languageId)));
    H::dbClose($db);

    return is_array($row) && (int) $row['c'] > 0;
}

it('activates and deactivates a real, non-default language', function (): void {
    expect(languagesInstalledIsActive('fr_FR'))
        ->toBeFalse();

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $activateResult = H::rawGet($page, '/admin.php?page=languages&action=activate&language=fr_FR&pwg_token=' . $token);
        expect($activateResult['status'])->toBe(0);
        expect(languagesInstalledIsActive('fr_FR'))
            ->toBeTrue();

        // The list shows the language's display name, not its raw code --
        // 'fr_FR' itself is only ever visible text nowhere on this page
        // (confirmed live: it only appears inside href query strings).
        $listPage = H::navigateOk($page, '/admin.php?page=languages');
        $listPage->assertSee('Français (France)');
    } finally {
        H::rawGet($page, '/admin.php?page=languages&action=deactivate&language=fr_FR&pwg_token=' . $token);
    }

    expect(languagesInstalledIsActive('fr_FR'))
        ->toBeFalse();
});

it('rejects an activate action without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=languages&action=activate&language=fr_FR');

    expect($result['status'])->toBe(400);
    expect(languagesInstalledIsActive('fr_FR'))
        ->toBeFalse();
});

it('cannot deactivate en_UK: it is the only active language and the default', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    // performAction() itself is expected to refuse and report an error --
    // the redirect-on-success branch only fires when $action_errors===[],
    // so a real refusal renders the listing page again (status 200) with
    // the deactivate link still gone rather than a redirect.
    $result = H::rawGet($page, '/admin.php?page=languages&action=deactivate&language=en_UK&pwg_token=' . $token);

    expect($result['status'])->toBe(200);
    expect(languagesInstalledIsActive('en_UK'))
        ->toBeTrue();
});

it('reassigns users off a missing-from-disk language and deletes its stale db row on page load', function (): void {
    $page = H::loginAsAdmin($this);
    $db = H::connect();

    // A language row with no matching fs entry -- render()'s own
    // `array_diff(db_languages, fs_languages)` cleanup loop reassigns any
    // user still set to it (to the gallery's default language) and deletes
    // the now-orphaned db row, every page load.
    H::dbQuery($db, sprintf("INSERT INTO languages (id, version, name) VALUES ('xx_XX', '1.0', 'Missing Language')"));

    $username = 'languages_installed_missing_lang_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::createUser($page, [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = addedUserId($addResult);
    H::dbQuery($db, sprintf("UPDATE user_infos SET language = 'xx_XX' WHERE user_id = %d", $userId));

    try {
        H::navigateOk($page, '/admin.php?page=languages');

        $langAssoc = H::dbFetchAssoc($db, sprintf("SELECT COUNT(*) AS c FROM languages WHERE id = 'xx_XX'"));
        expect(is_array($langAssoc) ? (int) $langAssoc['c'] : -1)->toBe(0);

        $userAssoc = H::dbFetchAssoc($db, sprintf('SELECT language FROM user_infos WHERE user_id = %d', $userId));
        expect(is_array($userAssoc) ? $userAssoc['language'] : 'MISSING')->toBe('en_UK');
    } finally {
        H::dbQuery($db, sprintf("DELETE FROM languages WHERE id = 'xx_XX'"));
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'languages_installed_admin_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::createUser($page, [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = addedUserId($addResult);

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE user_infos SET status = 'admin' WHERE user_id = %d", $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)
            ->fill('password', $password)
            ->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=languages');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});
