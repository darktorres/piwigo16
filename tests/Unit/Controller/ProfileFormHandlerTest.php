<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\ProfileFormHandler;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\ProfileFormHandler -- the user-profile edit form's
 * save/load logic, shared by the front-end profile page and the admin
 * "edit user" page. Resolved via `Kernel::container()->get()` (same
 * rationale as `UpdatesSubControllerTest.php`) -- 16 constructor deps,
 * most untouched by either branch under test.
 *
 * `saveFromPost()` covers its own cheap, pure first guard: no real
 * `$_POST['validate']` key means the form was never submitted, returns
 * `false` immediately with `$errors = []`, before touching any real
 * service. `loadIntoTemplate()` covers a real, full happy-path render:
 * a temp root with no `themes/`/`language/` real subdirectories makes
 * `ThemeCatalog::getPwgThemes()`/`LangService::getLanguages()`'s own
 * real DB-row-then-real-filesystem-check pattern deterministically
 * filter every real row out (proven safe/covered independently by
 * `ThemeCatalogTest.php`), and `SqlDialectExecutor::fetchTomorrow()`/
 * `fetchFutureDatesFor()` (already covered directly by
 * `SqlDialectExecutorTest.php`) are real, side-effect-free DB reads.
 */
function profileFormHandlerTestSubject(): ProfileFormHandler
{
    $subject = Kernel::container()->get(ProfileFormHandler::class);
    if (! $subject instanceof ProfileFormHandler) {
        throw new LogicException('Container returned an unexpected type for ' . ProfileFormHandler::class);
    }

    return $subject;
}

function profileFormHandlerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-profile-form-handler-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function profileFormHandlerTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        // is_link() checked first, not just is_dir() -- a symlink to a
        // real directory (see profileFormHandlerTestRootWithRealThemesAndLanguage()
        // below) also satisfies is_dir(), so recursing into it instead of
        // unlinking the link itself would walk into and delete whatever
        // real directory it points at.
        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            profileFormHandlerTestRrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * Same throwaway-temp-root shape as profileFormHandlerTestRoot(), but
 * symlinks `themes/`/`language/` to this checkout's own real directories
 * instead of leaving them absent. checkThemeInstalled()/LangService::
 * getLanguages() both do a genuine file_exists()/is_dir() check against
 * Paths::root -- using the real project root directly for that (instead
 * of symlinking into a temp one) would also redirect dataLocation's own
 * templates_c compile-cache into the real checkout (a
 * stray data/templates_c/ appeared in the repo root the one time this
 * was tried directly).
 */
function profileFormHandlerTestRootWithRealThemesAndLanguage(): string
{
    $root = profileFormHandlerTestRoot();
    symlink(dirname(__DIR__, 3) . '/themes', $root . 'themes');
    symlink(dirname(__DIR__, 3) . '/language', $root . 'language');

    return $root;
}

test('saveFromPost returns false immediately when the form was never submitted', function (): void {
    $root = profileFormHandlerTestRoot();
    unset($_POST['validate']);

    try {
        $handler = profileFormHandlerTestSubject();
        $errors = [];

        $result = $handler->saveFromPost(User::fromUserArray([
            'id' => 1,
        ]), $errors);

        expect($result)
            ->toBeFalse()
            ->and($errors)
            ->toBe([]);
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        profileFormHandlerTestRrmdir($root);
    }
});

test('loadIntoTemplate returns the real profile form data', function (): void {
    $root = profileFormHandlerTestRoot();
    unset($_POST['submit']);

    try {
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(1),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $handler = profileFormHandlerTestSubject();

        $formData = $handler->loadIntoTemplate('admin.php?page=user_list', 'admin.php?page=user_list', User::fromUserArray([
            'id' => 1,
            'username' => 'fixture_user',
            'email' => null,
            'theme' => 'default',
            'language' => 'en_UK',
            'nb_image_page' => 15,
            'recent_period' => 7,
            'expand' => false,
            'show_nb_comments' => false,
            'show_nb_hits' => false,
        ]));

        expect($formData->username)
            ->toBe('fixture_user')
            ->and($formData->fAction)
            ->toBe('admin.php?page=user_list');
    } finally {
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        profileFormHandlerTestRrmdir($root);
    }
});

/**
 * [Mutation] Real gap, found via mutation testing: saveFromPost()'s own
 * theme-validation guard (`in_array($post['theme'], array_keys(
 * ThemeCatalog::getPwgThemes(...)), true)`) had zero direct Unit
 * coverage before this test -- both tests above only reach the
 * cheap-early-return / loadIntoTemplate() paths. AppInfo::DEFAULT_TEMPLATE
 * ('default') is a real production account's only ever theme value
 * today (see ThemeCatalog::getPwgThemes()'s own docblock) -- uses
 * profileFormHandlerTestRootWithRealThemesAndLanguage() (not the plain
 * empty-root helper the sibling tests use) specifically because
 * checkThemeInstalled()/LangService::getLanguages() both need real
 * `themes/default/`/`language/en_UK/` directories on disk to resolve
 * 'default'/'en_UK' as valid at all, and because getPwgThemes()'s own
 * synthesized entry for 'default' still passes through that same real
 * filesystem check. Submits user 1's own real, current field values
 * so the resulting user_infos
 * UPDATE is a genuine no-op, not a real state change needing its own
 * before/after restore.
 */
test('saveFromPost accepts the default theme even though it has no themes-table row', function (): void {
    $root = profileFormHandlerTestRootWithRealThemesAndLanguage();
    unset($_POST['submit'], $_POST['mail_address'], $_POST['redirect']);
    $_POST['validate'] = '1';
    $_POST['theme'] = 'default';
    $_POST['language'] = 'en_UK';
    $_POST['nb_image_page'] = '15';
    $_POST['recent_period'] = '7';
    $_POST['expand'] = 'false';
    $_POST['show_nb_hits'] = 'false';
    $_POST['show_nb_comments'] = 'false';

    try {
        $handler = profileFormHandlerTestSubject();
        $errors = [];

        $result = $handler->saveFromPost(User::fromUserArray([
            'id' => 1,
            'username' => 'fixture_admin',
            'email' => null,
            'language' => 'en_UK',
        ]), $errors);

        expect($result)
            ->toBeTrue()
            ->and($errors)
            ->toBe([]);
    } finally {
        unset(
            $_POST['validate'],
            $_POST['theme'],
            $_POST['language'],
            $_POST['nb_image_page'],
            $_POST['recent_period'],
            $_POST['expand'],
            $_POST['show_nb_hits'],
            $_POST['show_nb_comments'],
        );
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        profileFormHandlerTestRrmdir($root);
    }
});

/**
 * [Mutation] Render-side counterpart to the test above -- closes the
 * same gap for loadIntoTemplate()'s own `templateOptions` (the theme
 * <select>'s real option list, see ProfileFormData), not just the
 * submit-time guard. Reuses
 * profileFormHandlerTestRootWithRealThemesAndLanguage() rather than the
 * sibling `loadIntoTemplate` test's own plain-empty-root helper, since
 * that emptiness is exactly what would make checkThemeInstalled() filter
 * the synthesized 'default' entry back out again.
 */
test('loadIntoTemplate includes the default theme as a real, selectable dropdown option', function (): void {
    $root = profileFormHandlerTestRootWithRealThemesAndLanguage();
    unset($_POST['submit']);

    try {
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(1),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: false,
        ));

        $handler = profileFormHandlerTestSubject();

        $formData = $handler->loadIntoTemplate('admin.php?page=user_list', 'admin.php?page=user_list', User::fromUserArray([
            'id' => 1,
            'username' => 'fixture_user',
            'email' => null,
            'theme' => 'default',
            'language' => 'en_UK',
            'nb_image_page' => 15,
            'recent_period' => 7,
            'expand' => false,
            'show_nb_comments' => false,
            'show_nb_hits' => false,
        ]));

        expect($formData->templateOptions)
            ->toBe([
                'default' => 'Default',
            ]);
    } finally {
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        profileFormHandlerTestRrmdir($root);
    }
});
