<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\LanguagesInstalledPageRenderer (admin.php?page=languages,
 * the default "installed" tab) -- already GET-tested by the extension-tabs
 * smoke route. fr_FR is a real, on-disk-but-not-default language in this
 * environment (see language/ directory), so activate/deactivate is a
 * genuinely safe, reversible real-lifecycle test here -- unlike
 * ThemesInstalledPageRendererTest's own deliberate avoidance of a live
 * theme toggle (this env only has one theme on disk at all).
 */
function languagesInstalledDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function languagesInstalledIsActive(string $languageId): bool
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $result = $db->query(sprintf(
        "SELECT COUNT(*) AS c FROM %slanguages WHERE id = '%s'",
        languagesInstalledDbPrefix(),
        $db->real_escape_string($languageId)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && (int) $row['c'] > 0;
}

it('activates and deactivates a real, non-default language', function (): void {
    expect(languagesInstalledIsActive('fr_FR'))->toBeFalse();

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $activateResult = H::rawGet($page, '/admin.php?page=languages&action=activate&language=fr_FR&pwg_token=' . $token);
        expect($activateResult['status'])->toBe(0);
        expect(languagesInstalledIsActive('fr_FR'))->toBeTrue();

        $listPage = H::navigateOk($page, '/admin.php?page=languages');
        $listPage->assertSee('fr_FR');
    } finally {
        H::rawGet($page, '/admin.php?page=languages&action=deactivate&language=fr_FR&pwg_token=' . $token);
    }

    expect(languagesInstalledIsActive('fr_FR'))->toBeFalse();
});

it('rejects an activate action without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=languages&action=activate&language=fr_FR');

    expect($result['status'])->toBe(400);
    expect(languagesInstalledIsActive('fr_FR'))->toBeFalse();
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
    expect(languagesInstalledIsActive('en_UK'))->toBeTrue();
});

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'languages_installed_admin_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = wsAddedUserId($addResult);

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = languagesInstalledDbPrefix();
    $db->query(sprintf("UPDATE %suser_infos SET status = 'admin' WHERE user_id = %d", $prefix, $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=languages');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        $db->query(sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        $db->query(sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        $db->close();
    }
});