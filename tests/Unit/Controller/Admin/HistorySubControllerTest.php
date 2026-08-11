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
use Piwigo\Controller\Admin\HistorySubController;
use Piwigo\Core\Env;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Controller\Admin\HistorySubController -- a genuinely thin
 * delegate, all 8 constructor deps standard/already-factory-covered (B3
 * Tier 2's real shape). No dedicated Integration/Browser spec of its
 * own -- reached only via the "history" page slug.
 *
 * Reuses the exact default-happy-path construction/assertions
 * HistoryPageRendererTest.php already established, just reached through
 * handle() with the page slug hardcoded to 'history' (matching this
 * class's own sole real caller contract) instead of a direct render()
 * call.
 */
function historySubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-history-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function historySubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? historySubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function historySubControllerTestAccessControl(): AccessControl
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

test('handle() delegates to HistoryPageRenderer::render() with page slug hardcoded to history', function (): void {
    $root = historySubControllerTestRoot();
    unset($_GET['filter_ip'], $_GET['filter_image_id'], $_GET['filter_user_id'], $_COOKIE['pwg_display_thumbnail']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'history.tpl', 'start={$START}');
        file_put_contents($tplDir . 'tabsheet.tpl', 'tabsheet');
        $template->set_template_dir($tplDir);

        $coreTabs = new CoreTabs(LangTestFactory::get(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = new HistorySubController(
            LangTestFactory::get(),
            historySubControllerTestAccessControl(),
            UrlServiceTestFactory::build(),
            $coreTabs,
            CurrentTemplate::current(),
            CurrentConfigTestFactory::get(),
            $eventDispatcher,
            new InputValidator(),
        );

        $subController->handle(new ServerRequest('GET', '/admin.php'));

        $today = Env::now()->format('Y-m-d');
        expect($template->get_template_vars('ADMIN_PAGE_TITLE'))
            ->toBe('History')
            ->and($template->get_template_vars('START'))
            ->toBe($today)
            ->and($template->get_template_vars('ADMIN_CONTENT'))
            ->toBe('start=' . $today);
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        historySubControllerTestRrmdir($root);
    }
});
