<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Admin\CoreTabs;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\MenubarSubController;
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
 * Piwigo\Controller\Admin\MenubarSubController -- page slug "menubar", a
 * pure delegate onto `Admin\MenubarPageRenderer::render()`. No
 * dedicated Integration/Browser spec of its own.
 *
 * No `checkStatus()` call at all (admin.php's own dispatch layer already
 * gates every page slug) -- only an `isWebmaster()` warning, so this
 * covers the real happy path. Same "no listener has registered any
 * block" mechanism as `Menu\MenubarRendererTest.php` makes
 * `BlockManager::load_registered_blocks()`/`get_registered_blocks()` a
 * real no-op here too, and no `$_POST` submission skips the real DB
 * `ConfigEntry` upsert entirely. Needs the same `CoreTabs::addCoreTabs()`
 * `TabsheetBeforeSelect` registration as `GroupListSubControllerTest.php`
 * since this renderer's own `Tabsheet::select()` call has no guard before
 * it either.
 */
function menubarSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-menubar-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function menubarSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? menubarSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle renders the real menubar-order admin screen with no registered blocks and no form submitted', function (): void {
    $root = menubarSubControllerTestRoot();

    $post = $_POST;
    unset($_POST['submit']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'tabsheet.tpl', 'tabsheet');
        file_put_contents($tplDir . 'menubar.tpl', 'menubar_admin_rendered=yes');
        $template->set_template_dir($tplDir);

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(1),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: '',
            status: UserStatus::Webmaster,
            enabledHigh: false,
        ));

        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
        }
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $coreTabs instanceof CoreTabs) {
            throw new LogicException('Container returned an unexpected type for ' . CoreTabs::class);
        }
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = Kernel::container()->get(MenubarSubController::class);
        if (! $subController instanceof MenubarSubController) {
            throw new LogicException('Container returned an unexpected type for ' . MenubarSubController::class);
        }

        $subController->handle(new ServerRequest('GET', '/admin.php?page=menubar'));

        expect($template->get_template_vars('ADMIN_CONTENT'))
            ->toContain('menubar_admin_rendered=yes');
    } finally {
        $_POST = $post;
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        menubarSubControllerTestRrmdir($root);
    }
});
