<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\ProfileFormHandler;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
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
        is_dir($path) ? profileFormHandlerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('saveFromPost returns false immediately when the form was never submitted', function (): void {
    $root = profileFormHandlerTestRoot();
    unset($_POST['validate']);

    try {
        $handler = profileFormHandlerTestSubject();
        $errors = [];

        $result = $handler->saveFromPost([
            'id' => 1,
        ], $errors);

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

test('loadIntoTemplate populates the real profile form template context', function (): void {
    $root = profileFormHandlerTestRoot();
    unset($_POST['submit']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
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

        $handler->loadIntoTemplate('admin.php?page=user_list', 'admin.php?page=user_list', [
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
        ]);

        expect($template->get_template_vars('USERNAME'))
            ->toBe('fixture_user')
            ->and($template->get_template_vars('F_ACTION'))
            ->toBe('admin.php?page=user_list');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        profileFormHandlerTestRrmdir($root);
    }
});
