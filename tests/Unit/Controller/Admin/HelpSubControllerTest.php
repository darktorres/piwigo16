<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Admin\CoreTabs;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\HelpSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\HelpSubController -- a genuinely thin delegate,
 * all 8 constructor deps standard/already-factory-covered. No dedicated
 * Integration/Browser spec of its own.
 *
 * Reuses the exact HelpPageRenderer construction/assertions
 * HelpPageRendererTest.php already established, just reached through
 * handle() instead of a direct render() call.
 */
function helpSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-help-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function helpSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? helpSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function helpSubControllerTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('handle() delegates to HelpPageRenderer::render() and defaults to the add_photos section', function (): void {
    $root = helpSubControllerTestRoot();
    unset($_GET['section']);
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'help.latte', 'title={$HELP_SECTION_TITLE}');
        file_put_contents($tplDir . 'tabsheet.latte', 'tabsheet');
        $template->setTemplateDir($tplDir);

        $coreTabs = new CoreTabs(LangTestFactory::get(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = new HelpSubController(
            LangTestFactory::get(),
            helpSubControllerTestAccessControl(),
            UrlServiceTestFactory::build(),
            $coreTabs,
            $eventDispatcher,
            new PageState(),
            CurrentUserTestFactory::get(),
            CurrentTemplateTestFactory::get(),
        );

        $subController->handle(new ServerRequest('GET', '/admin.php'));

        // assignVarFromTemplate() wraps ADMIN_CONTENT in Latte\Runtime\Html
        // (see that method's own docblock), not a plain string.
        $adminContent = $template->getTemplateVars('ADMIN_CONTENT');
        expect($adminContent)
            ->toBeInstanceOf(Latte\Runtime\Html::class);
        if (! $adminContent instanceof Latte\Runtime\Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect($template->getTemplateVars('HELP_SECTION_TITLE'))
            ->toBe('Add Photos')
            ->and((string) $adminContent)
            ->toBe('title=Add Photos');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        helpSubControllerTestRrmdir($root);
    }
});
