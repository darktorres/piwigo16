<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\HelpPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Admin\HelpPageRenderer -- zero-constructor, method-param-
 * injected (B3 Tier 1 shape). No dedicated Integration/Browser spec --
 * reached only via the "help" page slug.
 *
 * A disposable temp Paths root (no real language/ tree) exercises
 * Lang::load()'s own graceful "no matching help file found" fallback
 * (`is_string($x) ? $x : ''`) -- this class's own real decision logic,
 * matching PhotosAddFtpPageRendererTest.php's established reasoning: the
 * bundled help/*.html content itself is stable, unbranching data this
 * class doesn't own.
 *
 * Same Tabsheet::select()/CoreTabs::addCoreTabs() wiring already
 * established -- CoreTabs's own 'help' case populates 'add_photos',
 * matching HelpSectionRequest::fromArray()'s own no-$_GET['section']
 * default.
 */
function helpPageTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-help-page-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function helpPageTestRrmdir(string $dir): void
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
        is_dir($path) ? helpPageTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function helpPageTestAccessControl(): AccessControl
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

test('render() shows the English documentation message for an en_ user and defaults to the add_photos section', function (): void {
    $root = helpPageTestRoot();
    unset($_GET['section']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'help.latte', 'content={$HELP_CONTENT}|title={$HELP_SECTION_TITLE}');
        file_put_contents($tplDir . 'tabsheet.latte', 'tabsheet');
        $template->setTemplateDir($tplDir);

        $coreTabs = new CoreTabs(LangTestFactory::get(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));
        $pageState = new PageState();
        $currentUser = new CurrentUser(new CurrentConfig());
        $currentUser->set(new User(
            id: UserId::from(1),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Admin,
            enabledHigh: false,
        ));

        new HelpPageRenderer()
            ->render(LangTestFactory::get(), helpPageTestAccessControl(), UrlServiceTestFactory::build(), $coreTabs, $eventDispatcher, $pageState, $currentUser, CurrentTemplateTestFactory::get());

        // assignVarFromTemplate() wraps ADMIN_CONTENT in Latte\Runtime\Html
        // (see that method's own docblock), not a plain string.
        $adminContent = $template->getTemplateVars('ADMIN_CONTENT');
        expect($adminContent)
            ->toBeInstanceOf(Html::class);
        if (! $adminContent instanceof Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect($template->getTemplateVars('HELP_CONTENT'))
            ->toBe('')
            ->and($template->getTemplateVars('HELP_SECTION_TITLE'))
            ->toBe('Add Photos')
            ->and((string) $adminContent)
            ->toBe('content=|title=Add Photos')
            ->and($pageState->messages)
            ->toHaveCount(1)
            ->and($pageState->messages[0])->toContain('Check the online documentation');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        helpPageTestRrmdir($root);
    }
});
