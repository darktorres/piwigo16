<?php

declare(strict_types=1);

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\HelpPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Template\CurrentTemplate;
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
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

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
        theme: '',
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
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'help.tpl', 'content={$HELP_CONTENT}|title={$HELP_SECTION_TITLE}');
        file_put_contents($tplDir . 'tabsheet.tpl', 'tabsheet');
        $template->set_template_dir($tplDir);

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
            theme: '',
            status: UserStatus::Admin,
            enabledHigh: false,
        ));

        new HelpPageRenderer()->render(LangTestFactory::get(), helpPageTestAccessControl(), UrlServiceTestFactory::build(), $coreTabs, $eventDispatcher, $pageState, $currentUser, CurrentTemplate::current());

        expect($template->get_template_vars('HELP_CONTENT'))->toBe('')
            ->and($template->get_template_vars('HELP_SECTION_TITLE'))->toBe('Add Photos')
            ->and($template->get_template_vars('ADMIN_CONTENT'))->toBe('content=|title=Add Photos')
            ->and($pageState->messages)->toHaveCount(1)
            ->and($pageState->messages[0])->toContain('Check the online documentation');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        helpPageTestRrmdir($root);
    }
});
