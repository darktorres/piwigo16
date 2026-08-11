<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Admin\CoreTabs;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\ThemesSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\ThemesSubController -- page slug "themes", a tab
 * dispatch shell. No dedicated Integration/Browser spec of its own.
 *
 * The "installed"/"new"/"update" tabs all need heavier real setup
 * (`ThemesInstalledPageRenderer` alone needs 4+ domain services -- see
 * project memory, still deferred), but the "standard_pages" tab
 * (`?tab=standard_pages`) dispatches to the exact same
 * `ThemesStandardPagesPageRenderer::render()` already proven tractable in
 * `ThemesStandardPagesSubControllerTest.php` -- same empty, real
 * `themesDir()` + no-`$_POST`-submission recipe, reached here via the
 * tab-dispatch shell instead of the standalone route. Needs the same
 * `CoreTabs::addCoreTabs()` `TabsheetBeforeSelect` registration as
 * `GroupListSubControllerTest.php` since `Tabsheet::select()` has no guard
 * before it.
 */
function themesSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? themesSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle dispatches ?tab=standard_pages to the real standard-pages renderer', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-themes-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    $get = $_GET;
    $post = $_POST;
    $files = $_FILES;
    $_GET['tab'] = 'standard_pages';
    unset($_POST['submit'], $_FILES['std_pgs_logo']);

    try {
        CurrentConfigTestFactory::get()->dataLocation = 'data/';
        CurrentConfigTestFactory::get()->dataDirChecked = '1';

        $emptyThemesDir = $root . 'empty-themes/';
        mkdir($emptyThemesDir, 0o777, true);
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
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'tabsheet.tpl', 'tabsheet');
        file_put_contents($tplDir . 'themes_standard_pages.tpl', 'themes_standard_pages_rendered=yes');
        $template->setTemplateDir($tplDir);

        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
        }
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $coreTabs instanceof CoreTabs) {
            throw new LogicException('Container returned an unexpected type for ' . CoreTabs::class);
        }
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = Kernel::container()->get(ThemesSubController::class);
        if (! $subController instanceof ThemesSubController) {
            throw new LogicException('Container returned an unexpected type for ' . ThemesSubController::class);
        }

        $subController->handle(new ServerRequest('GET', '/admin.php?page=themes&tab=standard_pages'));

        expect($template->getTemplateVars('ADMIN_CONTENT'))
            ->toContain('themes_standard_pages_rendered=yes');
    } finally {
        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        themesSubControllerTestRrmdir($root);
    }
});
