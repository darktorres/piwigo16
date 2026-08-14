<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\ThemesStandardPagesSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\ThemesStandardPagesSubController -- page slug
 * "themes_standard_pages", a pure delegate onto
 * Piwigo\Admin\ThemesStandardPagesPageRenderer::render(). admin.php
 * itself already gates every page slug behind
 * checkStatus(AccessLevel::Administrator) before dispatch (see the
 * renderer's own docblock), so this class performs no checkStatus() of
 * its own -- unlike CatListSubController's own delegate
 * (CatListPageRenderer), which has no early guard and always runs a real
 * category-tree DB read, this renderer's real happy path (no form
 * submitted, no logo upload) needs no DB at all: an empty, real
 * `themesDir()` (same `setThemesDir()` pattern as
 * `ThemeSubControllerTest.php`) makes `ExtensionScanner::scan()`'s real
 * `opendir()`/`readdir()` loop deterministically empty.
 */
function themesStandardPagesSubControllerTestSubject(): ThemesStandardPagesSubController
{
    $subject = Kernel::container()->get(ThemesStandardPagesSubController::class);
    if (! $subject instanceof ThemesStandardPagesSubController) {
        throw new LogicException('Container returned an unexpected type for ' . ThemesStandardPagesSubController::class);
    }

    return $subject;
}

function themesStandardPagesSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? themesStandardPagesSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle renders the real standard-pages config screen with no themes installed and no form submitted', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-themes-standard-pages-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    $post = $_POST;
    $files = $_FILES;
    unset($_POST['submit'], $_FILES['std_pgs_logo']);

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';

        $emptyThemesDir = $root . 'empty-themes/';
        mkdir($emptyThemesDir, 0o777, true);
        $originalThemesDir = CurrentConfigTestFactory::get()->themesDir;
        CurrentConfigTestFactory::get()->themesDir = rtrim($emptyThemesDir, '/');

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(1),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Webmaster,
            enabledHigh: false,
        ));

        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'themes_standard_pages.latte', 'themes_standard_pages_rendered=yes');
        $template->setTemplateDir($tplDir);

        $subController = themesStandardPagesSubControllerTestSubject();

        $subController->handle(new ServerRequest('GET', '/admin.php?page=themes_standard_pages'));

        // assignVarFromTemplate() wraps ADMIN_CONTENT in Latte\Runtime\Html
        // (see that method's own docblock), not a plain string.
        $adminContent = $template->getTemplateVars('ADMIN_CONTENT');
        expect($adminContent)
            ->toBeInstanceOf(Html::class);
        if (! $adminContent instanceof Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect((string) $adminContent)
            ->toContain('themes_standard_pages_rendered=yes');

        CurrentConfigTestFactory::get()->themesDir = $originalThemesDir;
    } finally {
        $_POST = $post;
        $_FILES = $files;
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        themesStandardPagesSubControllerTestRrmdir($root);
    }
});
